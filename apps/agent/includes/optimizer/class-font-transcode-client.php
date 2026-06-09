<?php
/**
 * FontTranscodeClient — agent-side logic for the WOFF2 transcode pipeline.
 *
 * Responsibilities:
 *   1. POST FontTranscodeRequest to {cp_base}/agent/v1/fonts/transcode
 *      (signed with the agent Signer, matching the CP contract).
 *      The CP derives all storage keys and supplies ready-made presigned URLs;
 *      the agent constructs no storage keys and presigns nothing.
 *   2. Cache the per-hash result (state + woff2 local path + optional subset path)
 *      in a WP option so repeated page builds do not re-hit the CP for
 *      known-ready/negative fonts, and pending fonts keep polling until the
 *      encoder finishes.
 *   3. On state=="pending" with a source_put_url: PUT the raw original font bytes
 *      to the CP-supplied presigned PUT URL, then cache state=pending and return
 *      null so the original is served this build.
 *   4. On state=="ready": GET the woff2 bytes from the CP-supplied woff2_get_url,
 *      validate, write to the local AssetCache, cache ready+woff2_name, and return
 *      the local woff2 URL so class-font.php rewrites the @font-face src.
 *   5. On state=="subset": same as "ready" for the full WOFF2, PLUS fetch the subset
 *      from subset_get_url, write it to the AssetCache, and return the subset URL
 *      and unicode-range string so class-font.php can emit an additive @font-face.
 *   6. On state=="skipped": the media-encoder skipped subsetting (icon/variable font);
 *      return the full WOFF2 "ready" result without a subset URL.
 *   7. Fire-and-forget per-font result push to {cp_base}/agent/v1/fonts/results so
 *      the CP dashboard catalog is populated. Never blocks the page.
 *
 * Safety contract: EVERY method that returns a result returns null on ANY failure.
 * Callers must treat null as "serve the original, no-op". This class never throws
 * to the caller; all exceptions are caught internally.
 *
 * @package WPMgr\Agent\Optimizer
 */

declare(strict_types=1);

namespace WPMgr\Agent\Optimizer;

use WPMgr\Agent\Settings;
use WPMgr\Agent\Signer;
use WPMgr\Agent\Support\Blake3;

/**
 * Handles the agent side of the WOFF2 font transcode pipeline.
 */
final class FontTranscodeClient implements FontTranscodeClientInterface
{
    /**
     * WP option key for the local hash->state cache map.
     * Non-autoloaded; read only during font optimization passes.
     */
    public const OPTION_STATE_CACHE = 'wpmgr_font_transcode_cache';

    /** Maximum font bytes accepted by the CP (10 MiB). */
    private const MAX_FONT_BYTES = 10 * 1024 * 1024;

    /** Outbound request timeout in seconds. */
    private const TIMEOUT = 15;

    /** Signed-POST retries on transient failures. */
    private const MAX_RETRIES = 2;

    /**
     * Latin-extended unicode-range descriptor injected into the additive
     * @font-face rule when a subset is ready. Covers:
     *   U+0020-00FF : Basic Latin + Latin-1 Supplement
     *   U+0100-024F : Latin Extended-A and -B
     *   U+1E00-1EFF : Latin Extended Additional
     */
    private const UNICODE_RANGE_LATIN_EXT = 'U+0020-00FF,U+0100-024F,U+1E00-1EFF';

    /** Unicode-range for the "latin" (narrower) preset. */
    private const UNICODE_RANGE_LATIN = 'U+0020-007F,U+00A0-00FF';

    /**
     * Valid subset states returned by the CP/encoder that this client handles.
     *
     * @var list<string>
     */
    private const VALID_STATES = ['pending', 'ready', 'negative', 'subset', 'skipped'];

    private Signer $signer;

    private Settings $settings;

    private AssetCache $cache;

    /**
     * @param Signer     $signer   Agent signer (Ed25519).
     * @param Settings   $settings Settings accessor (provides CP URL).
     * @param AssetCache $cache    Asset cache for writing downloaded woff2 files.
     */
    public function __construct(Signer $signer, Settings $settings, AssetCache $cache)
    {
        $this->signer   = $signer;
        $this->settings = $settings;
        $this->cache    = $cache;
    }

    // -----------------------------------------------------------------------
    // Public API (FontTranscodeClientInterface)
    // -----------------------------------------------------------------------

    /**
     * Request (or check) a WOFF2 transcode for the given source font bytes.
     *
     * Returns an array with keys:
     *   state        : "ready" | "pending" | "negative" | "subset" | "skipped"
     *   woff2_url    : local asset URL (non-empty when state is "ready" or "subset")
     *   subset_url   : local subset asset URL (non-empty only when state == "subset")
     *   unicode_range: CSS unicode-range value (non-empty only when state == "subset")
     *
     * Returns null on any internal failure; callers must serve the original.
     *
     * @param string $sourceBytes  Raw bytes of the original font file.
     * @param string $ext          File extension hint: "ttf" | "otf" | "woff".
     * @param string $subsetMode   Subset mode: "" (none) | "range".
     * @param string $subsetRange  Range name: "latin-ext" | "latin".
     * @return array{state:string,woff2_url:string,subset_url:string,unicode_range:string}|null
     */
    public function resolve(
        string $sourceBytes,
        string $ext,
        string $subsetMode = '',
        string $subsetRange = ''
    ): ?array {
        try {
            return $this->doResolve($sourceBytes, $ext, $subsetMode, $subsetRange);
        } catch (\Throwable $e) {
            return null;
        }
    }

    // -----------------------------------------------------------------------
    // Internal implementation
    // -----------------------------------------------------------------------

    /**
     * @param string $sourceBytes
     * @param string $ext
     * @param string $subsetMode
     * @param string $subsetRange
     * @return array{state:string,woff2_url:string,subset_url:string,unicode_range:string}|null
     */
    private function doResolve(
        string $sourceBytes,
        string $ext,
        string $subsetMode,
        string $subsetRange
    ): ?array {
        $len = strlen($sourceBytes);
        if ($len === 0 || $len > self::MAX_FONT_BYTES) {
            return null;
        }

        $hash = Blake3::hashHex($sourceBytes);
        if ($hash === '') {
            return null;
        }

        // Normalise + validate the subset spec so the cache key is stable.
        $cleanMode  = in_array($subsetMode, ['range'], true) ? $subsetMode : '';
        $cleanRange = '';
        if ($cleanMode === 'range') {
            $cleanRange = in_array($subsetRange, ['latin-ext', 'latin'], true)
                ? $subsetRange
                : 'latin-ext';
        }

        // Build a stable cache key that includes the subset spec.
        // A full-only request uses $hash; a subset request uses $hash.range.$cleanRange.
        $cacheKey = $cleanMode === '' ? $hash : $hash . '.range.' . $cleanRange;

        // Check the local cache first.
        $cached = $this->getCachedState($cacheKey);
        if ($cached !== null) {
            $cachedResult = $this->resultFromCached($cached, $cacheKey, $hash, $cleanRange);
            if ($cachedResult !== false) {
                return $cachedResult;
            }
            // resultFromCached returns false to signal "re-fetch from CP".
        }

        $base = $this->settings->controlPlaneUrl();
        if ($base === '') {
            return null;
        }

        $cleanExt = preg_replace('/[^a-z0-9]/i', '', strtolower($ext)) ?: 'bin';

        // POST the transcode request; the CP supplies all presigned URLs.
        $response = $this->postTranscodeRequest($base, $hash, $len, $cleanExt, $cleanMode, $cleanRange);
        if ($response === null) {
            return null;
        }

        $state = is_string($response['state'] ?? null) ? $response['state'] : '';
        if (!in_array($state, self::VALID_STATES, true)) {
            return null;
        }

        if ($state === 'negative') {
            $this->setCachedState($cacheKey, ['state' => 'negative', 'woff2_name' => '', 'subset_name' => '', 'unicode_range' => '']);
            return null;
        }

        if ($state === 'pending') {
            $putUrl = is_string($response['source_put_url'] ?? null)
                ? $response['source_put_url']
                : '';
            if ($putUrl !== '') {
                $this->putBytes($putUrl, $sourceBytes);
            }
            $this->setCachedState($cacheKey, ['state' => 'pending', 'woff2_name' => '', 'subset_name' => '', 'unicode_range' => '']);
            return null;
        }

        // states: "ready", "subset", "skipped" — all require a full WOFF2 to be served.
        $getUrl = is_string($response['woff2_get_url'] ?? null) ? $response['woff2_get_url'] : '';
        if ($getUrl === '') {
            return null;
        }

        $woff2Bytes = $this->getBytes($getUrl);
        if ($woff2Bytes === null || $woff2Bytes === '') {
            return null;
        }

        $woff2Name = $hash . '.woff2';
        if (!$this->cache->write($woff2Name, $woff2Bytes)) {
            return null;
        }

        // Handle subset state: fetch + cache the subset WOFF2 as well.
        $subsetName    = '';
        $unicodeRange  = '';
        if ($state === 'subset') {
            $subsetGetUrl = is_string($response['subset_get_url'] ?? null) ? $response['subset_get_url'] : '';
            if ($subsetGetUrl !== '') {
                $subsetBytes = $this->getBytes($subsetGetUrl);
                if ($subsetBytes !== null && $subsetBytes !== '') {
                    $subsetName = $hash . '.' . ($cleanRange ?: 'subset') . '.woff2';
                    if (!$this->cache->write($subsetName, $subsetBytes)) {
                        $subsetName = '';
                    } else {
                        $unicodeRange = $this->unicodeRangeForPreset($cleanRange);
                    }
                }
            }
            // If subset fetch failed, fall back to "ready" (full WOFF2 only).
            if ($subsetName === '') {
                $state = 'ready';
            }
        }

        // "skipped" means the encoder confirmed it's an icon/variable font; the
        // full WOFF2 was still produced. Serve it as "ready" on this page.
        if ($state === 'skipped') {
            $state = 'ready';
        }

        $finalState = $subsetName !== '' ? 'subset' : 'ready';
        $this->setCachedState($cacheKey, [
            'state'         => $finalState,
            'woff2_name'    => $woff2Name,
            'subset_name'   => $subsetName,
            'unicode_range' => $unicodeRange,
        ]);

        return [
            'state'         => $finalState,
            'woff2_url'     => $this->cache->url($woff2Name),
            'subset_url'    => $subsetName !== '' ? $this->cache->url($subsetName) : '',
            'unicode_range' => $unicodeRange,
        ];
    }

    /**
     * Convert a cached entry to a resolve() result, verifying local files exist.
     *
     * Returns false to signal that the cache entry is stale and the CP should be
     * re-queried; returns null to signal "serve original"; returns the result array
     * on a valid warm cache hit.
     *
     * @param array<string,mixed> $cached
     * @param string $cacheKey
     * @param string $hash
     * @param string $cleanRange
     * @return array{state:string,woff2_url:string,subset_url:string,unicode_range:string}|null|false
     */
    private function resultFromCached(array $cached, string $cacheKey, string $hash, string $cleanRange)
    {
        $cachedState = $cached['state'] ?? '';

        if ($cachedState === 'negative') {
            return null;
        }

        if ($cachedState === 'pending') {
            return null; // still in flight
        }

        if (in_array($cachedState, ['ready', 'subset'], true)) {
            $woff2Name = is_string($cached['woff2_name'] ?? null) ? $cached['woff2_name'] : '';
            if ($woff2Name === '' || !$this->cache->exists($woff2Name)) {
                // Full WOFF2 gone — clear and re-fetch.
                $this->clearCachedState($cacheKey);
                return false;
            }

            $subsetName   = is_string($cached['subset_name'] ?? null) ? $cached['subset_name'] : '';
            $unicodeRange = is_string($cached['unicode_range'] ?? null) ? $cached['unicode_range'] : '';

            // If the cache says "subset" but the file is gone, downgrade to "ready".
            if ($cachedState === 'subset' && ($subsetName === '' || !$this->cache->exists($subsetName))) {
                $subsetName   = '';
                $unicodeRange = '';
                $cachedState  = 'ready';
                $this->setCachedState($cacheKey, [
                    'state'         => 'ready',
                    'woff2_name'    => $woff2Name,
                    'subset_name'   => '',
                    'unicode_range' => '',
                ]);
            }

            return [
                'state'         => $cachedState,
                'woff2_url'     => $this->cache->url($woff2Name),
                'subset_url'    => $subsetName !== '' ? $this->cache->url($subsetName) : '',
                'unicode_range' => $unicodeRange,
            ];
        }

        return false; // unknown state — re-query
    }

    /**
     * Return the CSS unicode-range value for a preset range name.
     *
     * @param string $preset "latin-ext" | "latin" | "".
     * @return string
     */
    private function unicodeRangeForPreset(string $preset): string
    {
        if ($preset === 'latin') {
            return self::UNICODE_RANGE_LATIN;
        }
        // Default to latin-ext (the recommended preset).
        return self::UNICODE_RANGE_LATIN_EXT;
    }

    // -----------------------------------------------------------------------
    // Results push (fire-and-forget)
    // -----------------------------------------------------------------------

    /**
     * Push a per-font result record to the CP catalog endpoint (fire-and-forget).
     *
     * This must NEVER block or fail the page. The payload matches the CP contract
     * for POST /agent/v1/fonts/results. tenant_id + site_id are derived from the
     * verified agent identity on the CP side — never sent in the body.
     *
     * @param string      $sourceHash   64-char BLAKE3 hex of the source font.
     * @param string      $fontFamily   Font-family name (from @font-face, may be empty).
     * @param string      $sourceFile   Source filename/URL basename.
     * @param string      $sourceExt    Original extension: "ttf" | "otf" | "woff".
     * @param int         $originalSize Byte length of the source font.
     * @param int|null    $woff2Size    Byte length of the produced WOFF2 (null if not yet ready).
     * @param int|null    $subsetSize   Byte length of the subset WOFF2 (null if no subset).
     * @param string|null $unicodeRange CSS unicode-range value (null if no subset).
     * @param string      $state        One of: pending|converting|ready|subset|skipped|negative.
     * @param string      $errorDetail  Error message on negative (empty otherwise).
     * @return void
     */
    public function pushResult(
        string $sourceHash,
        string $fontFamily,
        string $sourceFile,
        string $sourceExt,
        int $originalSize,
        ?int $woff2Size,
        ?int $subsetSize,
        ?string $unicodeRange,
        string $state,
        string $errorDetail = ''
    ): void {
        try {
            $base = $this->settings->controlPlaneUrl();
            if ($base === '') {
                return;
            }
            if (!function_exists('wp_json_encode') || !function_exists('wp_remote_post')) {
                return;
            }

            $path    = '/agent/v1/fonts/results';
            $payload = [
                'source_hash'   => $sourceHash,
                'font_family'   => $fontFamily,
                'source_file'   => $sourceFile,
                'source_ext'    => $sourceExt,
                'original_size' => $originalSize,
                'woff2_size'    => $woff2Size,
                'subset_size'   => $subsetSize,
                'unicode_range' => $unicodeRange,
                'state'         => $state,
                'error_detail'  => $errorDetail,
            ];

            $body = (string) wp_json_encode($payload);

            try {
                $auth = $this->signer->signHeaders('POST', $path, $body);
            } catch (\Throwable $e) {
                return;
            }

            $headers = array_merge(
                ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
                $auth
            );

            // blocking=false: fire-and-forget, never wait for a response.
            wp_remote_post($base . $path, [
                'timeout'  => 5,
                'headers'  => $headers,
                'body'     => $body,
                'blocking' => false,
            ]);
        } catch (\Throwable $e) {
            // Intentionally swallowed — results push must never affect the page build.
        }
    }

    // -----------------------------------------------------------------------
    // Network helpers
    // -----------------------------------------------------------------------

    /**
     * POST the FontTranscodeRequest to {cp_base}/agent/v1/fonts/transcode.
     *
     * Request body fields sent:
     *   source_hash  : 64-char lowercase-hex BLAKE3 of the original font bytes
     *   source_size  : byte length (> 0)
     *   source_ext   : "ttf" | "otf" | "woff"
     *   subset_mode  : "" | "range"
     *   subset_range : "latin-ext" | "latin" | ""
     *
     * No source_key field — the CP derives all storage keys from the verified
     * agent identity and the hash. The agent constructs no storage keys.
     *
     * @param string $base        CP base URL.
     * @param string $sourceHash  64-char lowercase-hex BLAKE3 of the source bytes.
     * @param int    $sourceSize  Byte length of the source font.
     * @param string $sourceExt   Extension hint ("ttf"|"otf"|"woff").
     * @param string $subsetMode  "" | "range".
     * @param string $subsetRange "latin-ext" | "latin" | "".
     * @return array<string,mixed>|null Decoded 2xx response body, or null on failure.
     */
    private function postTranscodeRequest(
        string $base,
        string $sourceHash,
        int $sourceSize,
        string $sourceExt,
        string $subsetMode,
        string $subsetRange
    ): ?array {
        $path    = '/agent/v1/fonts/transcode';
        $payload = [
            'source_hash'  => $sourceHash,
            'source_size'  => $sourceSize,
            'source_ext'   => $sourceExt,
            'subset_mode'  => $subsetMode,
            'subset_range' => $subsetRange,
        ];
        return $this->signedPostJson($base . $path, $path, $payload);
    }

    /**
     * Sign and POST a JSON payload to an absolute CP endpoint, returning the
     * decoded 2xx body or null on any failure.
     *
     * @param string              $url     Absolute endpoint URL.
     * @param string              $path    Path component for signing.
     * @param array<string,mixed> $payload JSON-encodable payload.
     * @return array<string,mixed>|null
     */
    private function signedPostJson(string $url, string $path, array $payload): ?array
    {
        if (!function_exists('wp_json_encode') || !function_exists('wp_remote_post')) {
            return null;
        }
        $body = (string) wp_json_encode($payload);

        try {
            $authHeaders = $this->signer->signHeaders('POST', $path, $body);
        } catch (\Throwable $e) {
            return null;
        }

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $response = wp_remote_post(
                $url,
                [
                    'timeout' => self::TIMEOUT,
                    'headers' => array_merge(
                        ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
                        $authHeaders
                    ),
                    'body' => $body,
                ]
            );

            if (function_exists('is_wp_error') && is_wp_error($response)) {
                if ($attempt < self::MAX_RETRIES) {
                    continue;
                }
                return null;
            }

            $status = function_exists('wp_remote_retrieve_response_code')
                ? (int) wp_remote_retrieve_response_code($response)
                : 0;

            if ($status >= 200 && $status < 300) {
                $raw  = function_exists('wp_remote_retrieve_body')
                    ? (string) wp_remote_retrieve_body($response)
                    : '';
                $data = json_decode($raw, true);
                return is_array($data) ? $data : [];
            }

            // Retry on transient server errors.
            if (($status >= 500 || $status === 408 || $status === 429) && $attempt < self::MAX_RETRIES) {
                continue;
            }

            return null;
        }

        return null;
    }

    /**
     * PUT raw bytes to a presigned URL supplied by the CP (no auth header;
     * the URL carries the auth credentials).
     *
     * @param string $presignedUrl CP-supplied presigned PUT URL.
     * @param string $bytes        Raw font bytes.
     * @return bool True on a 2xx response.
     */
    private function putBytes(string $presignedUrl, string $bytes): bool
    {
        if (!function_exists('wp_remote_request')) {
            return false;
        }
        $response = wp_remote_request(
            $presignedUrl,
            [
                'method'      => 'PUT',
                'timeout'     => self::TIMEOUT,
                'headers'     => ['Content-Type' => 'application/octet-stream'],
                'body'        => $bytes,
                'redirection' => 0, // do not follow redirects
            ]
        );
        if (function_exists('is_wp_error') && is_wp_error($response)) {
            return false;
        }
        $status = function_exists('wp_remote_retrieve_response_code')
            ? (int) wp_remote_retrieve_response_code($response)
            : 0;
        return $status >= 200 && $status < 300;
    }

    /**
     * GET bytes from a presigned URL supplied by the CP (no auth header;
     * the URL carries the auth credentials).
     *
     * @param string $presignedUrl CP-supplied presigned GET URL.
     * @return string|null Raw bytes, or null on failure.
     */
    private function getBytes(string $presignedUrl): ?string
    {
        if (!function_exists('wp_remote_get')) {
            return null;
        }
        $response = wp_remote_get($presignedUrl, ['timeout' => self::TIMEOUT]);
        if (function_exists('is_wp_error') && is_wp_error($response)) {
            return null;
        }
        $status = function_exists('wp_remote_retrieve_response_code')
            ? (int) wp_remote_retrieve_response_code($response)
            : 0;
        if ($status < 200 || $status >= 300) {
            return null;
        }
        $body = function_exists('wp_remote_retrieve_body')
            ? (string) wp_remote_retrieve_body($response)
            : '';
        return $body === '' ? null : $body;
    }

    // -----------------------------------------------------------------------
    // Local state cache (WP option)
    // -----------------------------------------------------------------------

    /**
     * Read the entire state-cache map from the WP option.
     *
     * @return array<string,array<string,string>>
     */
    private function loadStateMap(): array
    {
        if (!function_exists('get_option')) {
            return [];
        }
        $stored = get_option(self::OPTION_STATE_CACHE, []);
        return is_array($stored) ? $stored : [];
    }

    /**
     * Persist the state-cache map to the WP option.
     *
     * @param array<string,array<string,string>> $map
     * @return void
     */
    private function saveStateMap(array $map): void
    {
        if (!function_exists('update_option')) {
            return;
        }
        update_option(self::OPTION_STATE_CACHE, $map, false);
    }

    /**
     * Get the cached state entry for a cache key, or null if absent.
     *
     * @param string $cacheKey Hash or hash+range cache key.
     * @return array<string,string>|null
     */
    private function getCachedState(string $cacheKey): ?array
    {
        $map   = $this->loadStateMap();
        $entry = $map[$cacheKey] ?? null;
        if (!is_array($entry)) {
            return null;
        }
        $state = isset($entry['state']) && is_string($entry['state']) ? $entry['state'] : '';
        if (!in_array($state, self::VALID_STATES, true)) {
            return null;
        }
        return [
            'state'         => $state,
            'woff2_name'    => isset($entry['woff2_name']) && is_string($entry['woff2_name']) ? $entry['woff2_name'] : '',
            'subset_name'   => isset($entry['subset_name']) && is_string($entry['subset_name']) ? $entry['subset_name'] : '',
            'unicode_range' => isset($entry['unicode_range']) && is_string($entry['unicode_range']) ? $entry['unicode_range'] : '',
        ];
    }

    /**
     * Write a state entry for a cache key.
     *
     * @param string               $cacheKey Cache key.
     * @param array<string,string> $entry    State entry.
     * @return void
     */
    private function setCachedState(string $cacheKey, array $entry): void
    {
        $map              = $this->loadStateMap();
        $map[$cacheKey]   = $entry;
        $this->saveStateMap($map);
    }

    /**
     * Remove a state entry for a cache key (used when a "ready" file disappears).
     *
     * @param string $cacheKey Cache key.
     * @return void
     */
    private function clearCachedState(string $cacheKey): void
    {
        $map = $this->loadStateMap();
        unset($map[$cacheKey]);
        $this->saveStateMap($map);
    }
}
