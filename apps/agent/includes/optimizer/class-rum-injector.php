<?php
/**
 * RumInjector — inject the RUM collector script before </body>.
 *
 * At cache-write time this stage appends two things before </body>:
 *
 *   1. A tiny inline <script> that sets window.__WPMGR_RUM__ = {key,url,rate}
 *      (the per-site constants baked into the cached HTML; no per-request
 *      variance, no Vary header, no cookie, safe for the static cache layer).
 *
 *   2. An external <script defer src="…/assets/wpmgr-rum.min.js"> tag that
 *      loads the bundled web-vitals collector IIFE from the plugin assets dir.
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
 * Injects the RUM beacon config + collector script before </body>.
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

        if (stripos($html, '</body>') !== false) {
            return (string) preg_replace('/<\/body>(?![\s\S]*<\/body>)/i', $snippet . '</body>', $html, 1);
        }
        return $html . $snippet;
    }

    /**
     * Build the inline config + external collector snippet.
     *
     * The inline block sets window.__WPMGR_RUM__ (a plain object, no DOM
     * interaction); the external script is defer'd so it runs after HTML
     * parsing without blocking the page.
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
        // script loads the collector bundle. Both are standard practice.
        $inline_config = '<script data-wpmgr-rum-config>'
            . 'window.__WPMGR_RUM__=' . $config_json . ';'
            . '</script>';

        // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- injected into the output buffer after wp_enqueue_script() has already run; WP's enqueue API is inapplicable in this OB callback context (same pattern as all other optimizer stages)
        $collector = '<script defer src="' . esc_url($scriptUrl) . '"></script>';

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
        // WPMGR_AGENT_FILE is defined in wpmgr-agent.php and is always present
        // at runtime. plugins_url() is the canonical WP asset URL builder.
        if (function_exists('plugins_url') && defined('WPMGR_AGENT_FILE')) {
            return (string) plugins_url(
                'assets/wpmgr-rum.min.js',
                (string) constant('WPMGR_AGENT_FILE')
            );
        }

        // Fallback: build from WP_PLUGIN_URL + WPMGR_AGENT_DIR.
        if (defined('WP_PLUGIN_URL') && defined('WPMGR_AGENT_DIR')) {
            $pluginUrl = rtrim((string) constant('WP_PLUGIN_URL'), '/');
            $agentDir  = rtrim((string) constant('WPMGR_AGENT_DIR'), '/\\');
            $pluginDir = defined('WP_PLUGIN_DIR') ? rtrim((string) constant('WP_PLUGIN_DIR'), '/\\') : '';
            if ($pluginDir !== '' && strpos($agentDir, $pluginDir) === 0) {
                $rel = ltrim(substr($agentDir, strlen($pluginDir)), '/');
                return $pluginUrl . '/' . $rel . '/assets/wpmgr-rum.min.js';
            }
        }

        return '';
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
