<?php
/**
 * CacheWriter — the output-buffer handler that captures a fully-rendered HTML
 * page, checks cacheability, gzip-compresses it, and writes it atomically to the
 * disk cache under the deterministic CacheKey path.
 *
 * Flow:
 *   1. ob_start(handler) is opened early (template_redirect) when the request is
 *      a candidate (anonymous-or-allowed, GET, not admin/ajax, no bypass cookie).
 *   2. At buffer flush the handler receives the full body; it re-checks full
 *      cacheability (status 200, full HTML doc, password not required) and the
 *      cache key (logged-in caching gate), then gzencode + atomic write.
 *   3. The body is ALWAYS returned unmodified so the live response is unaffected
 *      whether or not it was cached.
 *
 * The written file name is byte-identical to what the serving drop-in computes
 * for the same request — both use CacheKey.
 *
 * Standard WordPress disk-cache writer technique.
 *
 * @package WPMgr\Agent\Cache
 */

declare(strict_types=1);

namespace WPMgr\Agent\Cache;

use WPMgr\Agent\Optimizer\Optimizer;

/**
 * Writes a rendered page to the gzip disk cache.
 */
final class CacheWriter
{
    /**
     * HTML comment appended to every buffer that is written to the disk cache.
     * Its presence in the served bytes is a definitive signal that WPMgr wrote
     * (and optionally optimized) this response. NEVER names a competitor plugin.
     */
    public const FOOTPRINT_MARKER = '<!-- Optimized and cached by WPMgr';

    /** Suffix appended when the optimizer transformed the buffer. */
    private const FOOTPRINT_OPTIMIZED_SUFFIX = ' (optimized)';
    private CacheConfig $config;

    private CacheKey $key;

    private Cacheability $cacheability;

    /** Absolute cache root (…/cache/wpmgr). */
    private string $cacheRoot;

    /**
     * Phase 4 — the optimization-layer orchestrator. Runs over the buffered HTML
     * on a cacheable MISS, BEFORE gzip + write, so the optimized bytes are both
     * cached AND served. Null disables the optimization pass entirely (the page
     * is cached/served verbatim). Lazily constructed so the request path pays
     * nothing on an inert site.
     */
    private ?Optimizer $optimizer;

    /** Whether {@see $optimizer} has been resolved yet (lazy). */
    private bool $optimizerResolved = false;

    /**
     * @param CacheConfig       $config       Page-cache config.
     * @param string            $cacheRoot    Absolute cache root.
     * @param CacheKey|null     $key          Injected for tests.
     * @param Cacheability|null $cacheability Injected for tests.
     * @param Optimizer|null    $optimizer    Phase-4 optimizer (tests/override).
     *                                         When omitted it is lazily loaded
     *                                         from the persisted perf config.
     */
    public function __construct(
        CacheConfig $config,
        string $cacheRoot,
        ?CacheKey $key = null,
        ?Cacheability $cacheability = null,
        ?Optimizer $optimizer = null
    ) {
        $this->config       = $config;
        $this->cacheRoot    = rtrim($cacheRoot, '/\\');
        $this->key          = $key ?? new CacheKey();
        $this->cacheability = $cacheability ?? new Cacheability(
            $config->includeQueries,
            $config->bypassUrls,
            $config->bypassCookies
        );
        if ($optimizer !== null) {
            $this->optimizer         = $optimizer;
            $this->optimizerResolved = true;
        } else {
            $this->optimizer = null;
        }
    }

    /**
     * The ob_start() callback. On a cacheable MISS it runs the Phase-4 optimizer
     * over the buffered HTML, writes the OPTIMIZED bytes to the gzip cache, and
     * returns those same optimized bytes so the live visitor and the cache are
     * byte-identical. The optimizer is config-gated and a no-op when off; any
     * failure (in optimize OR write) is swallowed so output is never harmed.
     *
     * @param string $buffer The fully-rendered page body.
     * @param int    $phase  PHP output-buffer phase bitmask.
     * @return string The (possibly optimized) buffer.
     */
    public function handle(string $buffer, int $phase = PHP_OUTPUT_HANDLER_FINAL): string
    {
        // Only act on the final flush so we capture the complete document.
        if (($phase & PHP_OUTPUT_HANDLER_FINAL) === 0 && ($phase & PHP_OUTPUT_HANDLER_END) === 0) {
            return $buffer;
        }

        try {
            $ctx = $this->resolveContext($buffer);
            $optimized = false;
            // Optimize ONLY when this request is actually cacheable (a MISS we
            // are about to store): the optimization pipeline is the same shape
            // we cache, so a non-cacheable request is served verbatim.
            if ($this->config->enabled
                && $this->isOptimizable($ctx)
                && $this->cacheability->isRequestCacheable($ctx + ['body' => $buffer])
            ) {
                $out = $this->runOptimizer($buffer);
                if ($out !== '' && $out !== $buffer) {
                    $buffer    = $out;
                    $optimized = true;
                    // Task 5: emit x-wpmgr-optimized header on an optimized MISS.
                    if (!headers_sent()) {
                        header('x-wpmgr-optimized: 1');
                    }
                }
                // Cache-FIRST, optimize-later. We DELIBERATELY do NOT skip the
                // write while RUCSS is still computing (a `202 processing`). Cache
                // serving is keyed by URL+device+cookies+query (CacheKey), NOT by
                // structure_hash, so writing the full-CSS render now means the very
                // next visitor gets a HIT instead of an uncached PHP render on every
                // hit. Once the CP finishes computing used-CSS, the post-compute
                // re-warm (RucssComputeCommand purges + re-fetches) — and any organic
                // re-visit that now gets a 200 — overwrites this entry with the
                // optimized variant. Serving a full-CSS cached page is strictly
                // better than serving uncached PHP, so we always persist here.
                // ($opt->rucssPending() is still surfaced for the x-wpmgr-optimized
                // header / observability, but it no longer gates the write.)
            }
            $this->maybeWrite($buffer, $ctx, $optimized);
        } catch (\Throwable $e) {
            // Never let a cache write / optimize break the page.
        }

        return $buffer;
    }

    /**
     * Cheap gate: is this a key that we both cache AND optimize (a non-logged-in
     * HTML doc)? Logged-in caching, if enabled, is stored verbatim — the
     * optimizer skips personalised responses internally too, but this avoids
     * even constructing it.
     *
     * @param array<string,mixed> $ctx Resolved request context.
     * @return bool
     */
    private function isOptimizable(array $ctx): bool
    {
        return empty($ctx['logged_in']);
    }

    /**
     * Run the lazily-resolved optimizer over the buffer (no-op when inactive).
     * Returns '' when the optimizer is off or produced no output, so the caller
     * can detect a genuine transformation by comparing against the original.
     *
     * @param string $buffer Rendered HTML.
     * @return string Optimized HTML, or '' to signal "leave the buffer as-is".
     */
    private function runOptimizer(string $buffer): string
    {
        $optimizer = $this->optimizer();
        if ($optimizer === null || !$optimizer->isActive()) {
            return '';
        }
        $out = $optimizer->run($buffer);
        return is_string($out) ? $out : '';
    }

    /**
     * Lazily resolve the optimizer from the persisted perf config. Returns null
     * when no transform is enabled so an inert site never builds the pipeline.
     *
     * @return Optimizer|null
     */
    private function optimizer(): ?Optimizer
    {
        if ($this->optimizerResolved) {
            return $this->optimizer;
        }
        $this->optimizerResolved = true;
        try {
            $optimizer = new Optimizer();
            $this->optimizer = $optimizer->isActive() ? $optimizer : null;
        } catch (\Throwable $e) {
            $this->optimizer = null;
        }
        return $this->optimizer;
    }

    /**
     * Decide-and-write for a resolved request context + body. Public + context-
     * injected so it is unit-testable with no superglobal/WP dependency.
     *
     * Appends an HTML comment footprint RIGHT BEFORE gzencode so it lands in
     * both the cached .html.gz AND the live served bytes. The marker's presence
     * is a definitive signal that WPMgr wrote this response.
     *
     * @param string              $buffer    The page body.
     * @param array<string,mixed> $ctx       Resolved request/response context (see Cacheability).
     * @param bool                $optimized Whether the optimizer transformed the buffer.
     * @return bool True when a cache file was written.
     */
    public function maybeWrite(string $buffer, array $ctx, bool $optimized = false): bool
    {
        if (!$this->config->enabled) {
            return false;
        }

        $ctx['body'] = $buffer;
        if (!$this->cacheability->isRequestCacheable($ctx)) {
            return false;
        }

        $fileName = $this->key->build(
            (array) ($ctx['cookies'] ?? []),
            (array) ($ctx['query'] ?? []),
            (string) ($ctx['user_agent'] ?? ''),
            $this->config->cacheLoggedIn,
            $this->config->cacheMobile,
            $this->config->includeCookies,
            $this->config->includeQueries
        );
        if ($fileName === null) {
            return false; // logged-in but logged-in caching disabled
        }

        $path = $this->key->path(
            $this->cacheRoot,
            (string) ($ctx['host'] ?? ''),
            (string) ($ctx['uri_path'] ?? '/'),
            $fileName
        );

        // Append the footprint marker BEFORE gzencode so it appears in both the
        // cached file bytes and the live served response. Only appended on an
        // actual cache write (so its presence is a true signal).
        $ts            = gmdate('Y-m-d\TH:i:s\Z');
        $footprintSuffix = $optimized ? self::FOOTPRINT_OPTIMIZED_SUFFIX : '';
        $buffer .= "\n" . self::FOOTPRINT_MARKER . $footprintSuffix . ' · ' . $ts . ' -->';

        $compressed = gzencode($buffer, 6);
        if ($compressed === false) {
            return false;
        }

        return $this->atomicWrite($path, $compressed);
    }

    /**
     * Resolve the request context from PHP superglobals + WP functions for the
     * live request path. Kept separate from {@see maybeWrite()} so tests can
     * bypass it entirely.
     *
     * @param string $buffer The page body (used for the DOCTYPE check downstream).
     * @return array<string,mixed>
     */
    private function resolveContext(string $buffer): array
    {
        $uri    = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        $host   = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
        $method = isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';
        $ua     = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';

        $loggedIn = function_exists('is_user_logged_in') ? (bool) is_user_logged_in() : false;
        $isAdmin  = function_exists('is_admin') ? (bool) is_admin() : false;
        $isAjax   = function_exists('wp_doing_ajax') ? (bool) wp_doing_ajax() : false;
        // http_response_code() returns FALSE when no status has been explicitly
        // set (the response IS a 200 in that case). Treat an unset/0 code as 200.
        $rawStatus = function_exists('http_response_code') ? http_response_code() : 200;
        $status    = is_int($rawStatus) && $rawStatus > 0 ? $rawStatus : 200;
        $pwd      = false;
        if (function_exists('is_singular') && is_singular()
            && function_exists('post_password_required') && post_password_required()
        ) {
            $pwd = true;
        }

        return [
            'url'               => $uri,
            'uri_path'          => $uri,
            'host'              => $host,
            'method'            => $method,
            'user_agent'        => $ua,
            'cookies'           => $_COOKIE,
            'query'             => $_GET,
            'is_admin'          => $isAdmin,
            'is_ajax'           => $isAjax,
            'status'            => $status,
            'logged_in'         => $loggedIn,
            'cache_logged_in'   => $this->config->cacheLoggedIn,
            'password_required' => $pwd,
        ];
    }

    /**
     * Atomic write: mkdir -p the variant directory, write to a temp file, rename
     * over the destination (atomic on POSIX).
     *
     * @param string $path       Destination cache file path.
     * @param string $compressed Gzip-encoded bytes.
     * @return bool
     */
    private function atomicWrite(string $path, string $compressed): bool
    {
        $dir = dirname($path);
        if (!@is_dir($dir) && !@mkdir($dir, 0o755, true) && !@is_dir($dir)) {
            return false;
        }

        $tmp = $path . '.tmp-' . getmypid() . '-' . mt_rand();
        if (@file_put_contents($tmp, $compressed, LOCK_EX) === false) {
            @unlink($tmp);
            return false;
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }

        return true;
    }
}
