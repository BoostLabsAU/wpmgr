<?php
/**
 * FontTranscodeClient — agent-side logic for the WOFF2 transcode pipeline
 * (M54 Phase 2, ADR-049-adjacent).
 *
 * Responsibilities:
 *   1. POST FontTranscodeRequest to {cp_base}/agent/v1/fonts/transcode
 *      (signed with the agent Signer, matching the CP contract).
 *      The CP derives all storage keys and supplies ready-made presigned URLs;
 *      the agent constructs no storage keys and presigns nothing.
 *   2. Cache the per-hash result (state + woff2 local path) in a WP option
 *      so repeated page builds do not re-hit the CP for known-ready/negative
 *      fonts, and pending fonts keep polling until the encoder finishes.
 *   3. On state=="pending" with a source_put_url: PUT the raw original font
 *      bytes to the CP-supplied presigned PUT URL, then cache state=pending
 *      and return null so the original is served this build.
 *   4. On state=="ready": GET the woff2 bytes from the CP-supplied woff2_get_url,
 *      validate, write to the local AssetCache, cache ready+woff2_name, and
 *      return the local woff2 URL so class-font.php rewrites the @font-face src.
 *
 * Safety contract: EVERY method that returns a result returns null on ANY
 * failure.  Callers must treat null as "serve the original, no-op".  This
 * class never throws to the caller; all exceptions are caught internally.
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
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Request (or check) a WOFF2 transcode for the given source font bytes.
     *
     * Returns an array with keys:
     *   state       : "ready" | "pending" | "negative"
     *   woff2_url   : local asset URL (non-empty only when state=="ready")
     *
     * Returns null on any internal failure; callers must serve the original.
     *
     * @param string $sourceBytes Raw bytes of the original font file.
     * @param string $ext         File extension hint: "ttf" | "otf" | "woff".
     * @return array{state:string,woff2_url:string}|null
     */
    public function resolve(string $sourceBytes, string $ext): ?array
    {
        try {
            return $this->doResolve($sourceBytes, $ext);
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
     * @return array{state:string,woff2_url:string}|null
     */
    private function doResolve(string $sourceBytes, string $ext): ?array
    {
        $len = strlen($sourceBytes);
        if ($len === 0 || $len > self::MAX_FONT_BYTES) {
            return null;
        }

        $hash = Blake3::hashHex($sourceBytes);
        if ($hash === '') {
            return null;
        }

        // Check the local cache first.
        $cached = $this->getCachedState($hash);
        if ($cached !== null) {
            if ($cached['state'] === 'ready') {
                // Verify the local woff2 file still exists in the asset cache.
                $name = $cached['woff2_name'] ?? '';
                if ($name !== '' && $this->cache->exists($name)) {
                    return ['state' => 'ready', 'woff2_url' => $this->cache->url($name)];
                }
                // Cache entry says ready but the file is gone; clear and re-fetch.
                $this->clearCachedState($hash);
            } elseif ($cached['state'] === 'negative') {
                // Permanently negative — serve original, no retry.
                return null;
            }
            // state == 'pending': fall through to re-check with CP below.
        }

        $base = $this->settings->controlPlaneUrl();
        if ($base === '') {
            return null;
        }

        $cleanExt = preg_replace('/[^a-z0-9]/i', '', strtolower($ext)) ?: 'bin';

        // POST the transcode request; the CP supplies all presigned URLs.
        $response = $this->postTranscodeRequest($base, $hash, $len, $cleanExt);
        if ($response === null) {
            // CP unreachable — serve original this build.
            return null;
        }

        $state = is_string($response['state'] ?? null) ? $response['state'] : '';
        if (!in_array($state, ['pending', 'ready', 'negative'], true)) {
            return null;
        }

        if ($state === 'negative') {
            $this->setCachedState($hash, ['state' => 'negative', 'woff2_name' => '']);
            return null;
        }

        if ($state === 'pending') {
            // If the CP just created the job it supplies source_put_url; upload
            // the font bytes so the encoder can start. When the URL is absent the
            // source was already known to the CP (re-poll); skip the PUT.
            $putUrl = is_string($response['source_put_url'] ?? null)
                ? $response['source_put_url']
                : '';

            if ($putUrl !== '') {
                // Treat PUT failure as a transient error; still cache pending so
                // the next page build re-polls rather than re-posting.
                $this->putBytes($putUrl, $sourceBytes);
            }

            $this->setCachedState($hash, ['state' => 'pending', 'woff2_name' => '']);
            return null; // serve original this build
        }

        // state == "ready": fetch the woff2 from the CP-supplied presigned GET URL.
        $getUrl = is_string($response['woff2_get_url'] ?? null) ? $response['woff2_get_url'] : '';
        if ($getUrl === '') {
            return null;
        }

        $woff2Bytes = $this->getBytes($getUrl);
        if ($woff2Bytes === null || $woff2Bytes === '') {
            return null;
        }

        // Write the woff2 into the asset cache, named by hash for stability.
        $woff2Name = $hash . '.woff2';
        if (!$this->cache->write($woff2Name, $woff2Bytes)) {
            return null;
        }

        $this->setCachedState($hash, ['state' => 'ready', 'woff2_name' => $woff2Name]);
        return ['state' => 'ready', 'woff2_url' => $this->cache->url($woff2Name)];
    }

    // -----------------------------------------------------------------------
    // Network helpers
    // -----------------------------------------------------------------------

    /**
     * POST the FontTranscodeRequest to {cp_base}/agent/v1/fonts/transcode.
     *
     * Request body fields sent:
     *   source_hash : 64-char lowercase-hex BLAKE3 of the original font bytes
     *   source_size : byte length (> 0)
     *   source_ext  : "ttf" | "otf" | "woff"
     *
     * No source_key field — the CP derives all storage keys from the verified
     * agent identity and the hash. The agent constructs no storage keys.
     *
     * @param string $base       CP base URL.
     * @param string $sourceHash 64-char lowercase-hex BLAKE3 of the source bytes.
     * @param int    $sourceSize Byte length of the source font.
     * @param string $sourceExt  Extension hint ("ttf"|"otf"|"woff").
     * @return array<string,mixed>|null Decoded 2xx response body, or null on failure.
     */
    private function postTranscodeRequest(
        string $base,
        string $sourceHash,
        int $sourceSize,
        string $sourceExt
    ): ?array {
        $path = '/agent/v1/fonts/transcode';
        $payload = [
            'source_hash' => $sourceHash,
            'source_size' => $sourceSize,
            'source_ext'  => $sourceExt,
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
                'method'               => 'PUT',
                'timeout'              => self::TIMEOUT,
                'headers'              => ['Content-Type' => 'application/octet-stream'],
                'body'                 => $bytes,
                'redirection'          => 0, // do not follow redirects
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
     * @param string $presignedUrl CP-supplied presigned GET URL for the woff2.
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
     * @return array<string,array{state:string,woff2_name:string}>
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
     * @param array<string,array{state:string,woff2_name:string}> $map
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
     * Get the cached state entry for a hash, or null if absent.
     *
     * @param string $hash Hex hash.
     * @return array{state:string,woff2_name:string}|null
     */
    private function getCachedState(string $hash): ?array
    {
        $map = $this->loadStateMap();
        $entry = $map[$hash] ?? null;
        if (!is_array($entry)) {
            return null;
        }
        $state = isset($entry['state']) && is_string($entry['state']) ? $entry['state'] : '';
        if (!in_array($state, ['pending', 'ready', 'negative'], true)) {
            return null;
        }
        return [
            'state'      => $state,
            'woff2_name' => isset($entry['woff2_name']) && is_string($entry['woff2_name']) ? $entry['woff2_name'] : '',
        ];
    }

    /**
     * Write a state entry for a hash.
     *
     * @param string                              $hash  Hex hash.
     * @param array{state:string,woff2_name:string} $entry State entry.
     * @return void
     */
    private function setCachedState(string $hash, array $entry): void
    {
        $map = $this->loadStateMap();
        $map[$hash] = $entry;
        $this->saveStateMap($map);
    }

    /**
     * Remove a state entry for a hash (used when a "ready" file disappears).
     *
     * @param string $hash Hex hash.
     * @return void
     */
    private function clearCachedState(string $hash): void
    {
        $map = $this->loadStateMap();
        unset($map[$hash]);
        $this->saveStateMap($map);
    }
}
