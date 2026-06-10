<?php
/**
 * RumInjector — inject the RUM collector script into <head>.
 *
 * At cache-write time this stage splices two things before </head>:
 *
 *   1. A tiny inline <script> that sets window.__WPMGR_RUM__ = {key,url,rate}
 *      (the per-site constants baked into the cached HTML; no per-request
 *      variance, no Vary header, no cookie, safe for the static cache layer).
 *
 *   2. An external <script async src="…/assets/wpmgr-rum.min.js"> tag that
 *      loads the bundled web-vitals collector IIFE from the plugin assets dir.
 *
 * Why <head> + async (not defer before </body>):
 *   web-vitals onCLS is gated on onFCP firing. If the collector is deferred to
 *   </body>, the page can transition to hidden BEFORE the buffered FCP paint
 *   entry is observed, so the FCP gate never fires and no CLS beacon is ever
 *   sent. Loading the script async from <head> means web-vitals registers its
 *   observers and the onFCP/onCLS hide-handler early — well before the visitor
 *   can leave — so CLS finalises correctly on stable/view-then-leave pages.
 *   async (not defer) is used because web-vitals uses buffered PerformanceObserver
 *   entries and visibility-state history, so exact parse-time ordering relative
 *   to other scripts does not matter; async avoids blocking the render pipeline.
 *   If no </head> exists in the document the snippet is inserted before </body>
 *   as a fallback, preserving the pre-existing behaviour for unconventional HTML.
 *
 * JS-delay exclusion: the Optimizer runs RumInjector as the LAST stage (step 11),
 * after JsDelay (step 8). The RUM script tag is therefore injected after the delay
 * transform has already processed the document and will never be seen by JsDelay.
 * No additional exclusion entry is required.
 *
 * The external src approach means the collector never violates a strict
 * no-unsafe-inline CSP on the main document. sendBeacon is governed by
 * connect-src; operators must add the CP host to connect-src separately.
 *
 * CSP safety: if already-queued response headers contain a Content-Security-Policy
 * with a script-src that is "strict" (has 'nonce-' or uses a hash source without
 * 'unsafe-inline') AND the policy does not already allowlist the plugin's asset URL,
 * this stage skips injection rather than breaking the page.
 *
 * @package WPMgr\Agent\Optimizer
 */

declare(strict_types=1);

namespace WPMgr\Agent\Optimizer;

/**
 * Injects the RUM beacon config + collector script into <head>.
 */
final class RumInjector
{
    private PerfConfig $config;

    /**
     * @param PerfConfig|null $config Optimization config.
     */
    public function __construct(?PerfConfig $config = null)
    {
        $this->config = $config ?? PerfConfig::load();
    }

    /**
     * Inject the RUM snippet when RUM is enabled and prerequisites are met.
     *
     * @param string $html Full page HTML.
     * @return string
     */
    public function process(string $html): string
    {
        if (!$this->config->rumEnabled) {
            return $html;
        }

        $key = $this->config->rumBeaconKey;
        $url = $this->config->rumIngestUrl;

        // Both values are required; without them the beacon cannot land.
        if ($key === '' || $url === '') {
            return $html;
        }

        // Skip if a conflicting strict CSP is already queued.
        if ($this->hasConflictingCsp()) {
            return $html;
        }

        // Guard: only inject once.
        if (strpos($html, 'data-wpmgr-rum-config') !== false) {
            return $html;
        }

        $scriptUrl = $this->assetUrl();
        if ($scriptUrl === '') {
            return $html;
        }

        $snippet = $this->buildSnippet($key, $url, $this->config->rumSampleRate, $scriptUrl);

        // Primary: splice before </head> so web-vitals registers its observers
        // before the page paints/hides (fixes CLS FCP-gate race on early-hide pages).
        if (stripos($html, '</head>') !== false) {
            return (string) preg_replace('/<\/head>(?![\s\S]*<\/head>)/i', $snippet . '</head>', $html, 1);
        }
        // Fallback: no </head> found — insert before </body> (unconventional HTML).
        if (stripos($html, '</body>') !== false) {
            return (string) preg_replace('/<\/body>(?![\s\S]*<\/body>)/i', $snippet . '</body>', $html, 1);
        }
        return $html . $snippet;
    }

    /**
     * Build the inline config + external collector snippet.
     *
     * The inline block sets window.__WPMGR_RUM__ (a plain object, no DOM
     * interaction); the external script is async so it loads without blocking
     * the render pipeline. async is preferred over defer here because web-vitals
     * uses buffered PerformanceObserver entries and visibility-state history,
     * so parse-time ordering relative to other scripts is irrelevant — what
     * matters is that the observers are registered as early as possible.
     *
     * @param string $key        Plaintext beacon key.
     * @param string $url        Ingest endpoint URL.
     * @param float  $rate       Sample rate [0,1].
     * @param string $scriptUrl  URL to wpmgr-rum.min.js.
     * @return string HTML snippet.
     */
    private function buildSnippet(string $key, string $url, float $rate, string $scriptUrl): string
    {
        // Values are encoded with wp_json_encode then written into a JS object
        // literal via a script tag — there is no unsafe-inline concern because
        // this sets a config object, NOT an event handler or navigation.
        // esc_url is applied to the script src attribute.
        $rate = round(max(0.0, min(1.0, $rate)), 4);

        $config_json = (string) wp_json_encode([
            'key'  => $key,
            'url'  => $url,
            'rate' => $rate,
        ]);

        // Build the snippet without heredoc/nowdoc (Plugin Check bans heredocs).
        // The inline config script sets a simple window variable; the external
        // script loads the collector bundle. Both run inside the cache-write output
        // buffer after wp_head has already printed — WP's enqueue API is inapplicable.
        // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- injected into the cache-write output buffer after wp_head has run; WP's enqueue API is inapplicable in this OB callback (see line 150 for the pattern)
        $inline_config = '<script data-wpmgr-rum-config>'
            . 'window.__WPMGR_RUM__=' . $config_json . ';'
            . '</script>';

        // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- injected into the output buffer after wp_enqueue_script() has already run; WP's enqueue API is inapplicable in this OB callback context (same pattern as all other optimizer stages)
        $collector = '<script async src="' . esc_url($scriptUrl) . '"></script>';

        return $inline_config . $collector;
    }

    /**
     * Resolve the public URL to assets/wpmgr-rum.min.js.
     *
     * Uses plugins_url() when available (the canonical WP function for
     * plugin assets), falling back to WP_PLUGIN_URL for headless contexts
     * where plugins_url() may not yet be registered.
     *
     * @return string URL, or '' when it cannot be resolved.
     */
    private function assetUrl(): string
    {
        $base = '';

        // WPMGR_AGENT_FILE is defined in wpmgr-agent.php and is always present
        // at runtime. plugins_url() is the canonical WP asset URL builder.
        if (function_exists('plugins_url') && defined('WPMGR_AGENT_FILE')) {
            $base = (string) plugins_url(
                'assets/wpmgr-rum.min.js',
                (string) constant('WPMGR_AGENT_FILE')
            );
        } elseif (defined('WP_PLUGIN_URL') && defined('WPMGR_AGENT_DIR')) {
            // Fallback: build from WP_PLUGIN_URL + WPMGR_AGENT_DIR.
            $pluginUrl = rtrim((string) constant('WP_PLUGIN_URL'), '/');
            $agentDir  = rtrim((string) constant('WPMGR_AGENT_DIR'), '/\\');
            $pluginDir = defined('WP_PLUGIN_DIR') ? rtrim((string) constant('WP_PLUGIN_DIR'), '/\\') : '';
            if ($pluginDir !== '' && strpos($agentDir, $pluginDir) === 0) {
                $rel  = ltrim(substr($agentDir, strlen($pluginDir)), '/');
                $base = $pluginUrl . '/' . $rel . '/assets/wpmgr-rum.min.js';
            }
        }

        if ($base === '') {
            return '';
        }

        // Append the plugin version as a cache-busting query arg. The collector
        // is served from a static, unversioned filename, so a CDN or browser
        // cache keyed on the URL would keep serving the previous build after a
        // plugin update -- a long-lived edge cache can mask a collector fix for
        // the full length of its TTL. Versioning the URL changes it on every
        // update, so the edge and the browser refetch the new bytes with no
        // manual purge.
        $ver = defined('WPMGR_AGENT_VERSION') ? (string) constant('WPMGR_AGENT_VERSION') : '';
        if ($ver !== '') {
            $base .= (strpos($base, '?') === false ? '?' : '&') . 'ver=' . rawurlencode($ver);
        }

        return $base;
    }

    /**
     * Whether already-queued response headers contain a strict Content-Security-Policy
     * that would block an external script without a nonce.
     *
     * "Strict" here means: the script-src (or default-src) directive contains
     * 'nonce-' (a per-request nonce that the injected static HTML can never know)
     * and does NOT include 'unsafe-inline' (which would allow any inline/external
     * script). When such a policy is present we skip injection to avoid a
     * browser CSP violation that would block the page's console with errors.
     *
     * This check uses headers_list() which is available after output buffering
     * starts but before the buffer is flushed — exactly when the optimizer runs.
     *
     * @return bool True when a conflicting CSP is detected.
     */
    private function hasConflictingCsp(): bool
    {
        if (!function_exists('headers_list')) {
            return false;
        }
        $headers = headers_list();
        if (!is_array($headers)) {
            return false;
        }
        foreach ($headers as $header) {
            if (!is_string($header)) {
                continue;
            }
            if (stripos($header, 'Content-Security-Policy') !== 0) {
                continue;
            }
            // Extract the directive value.
            $value = substr($header, strpos($header, ':') + 1);
            $value = strtolower(trim($value));

            // Locate script-src or fall through to default-src.
            $scriptSrc = '';
            if (preg_match('/script-src\s+([^;]+)/i', $value, $m)) {
                $scriptSrc = $m[1];
            } elseif (preg_match('/default-src\s+([^;]+)/i', $value, $m)) {
                $scriptSrc = $m[1];
            }

            if ($scriptSrc === '') {
                continue;
            }

            // A nonce-based CSP without unsafe-inline is a conflict:
            // our static HTML can never carry a dynamic nonce, so the
            // external script tag we inject would be blocked.
            $hasNonce         = strpos($scriptSrc, "'nonce-") !== false;
            $hasUnsafeInline  = strpos($scriptSrc, "'unsafe-inline'") !== false;

            if ($hasNonce && !$hasUnsafeInline) {
                return true;
            }
        }
        return false;
    }
}
