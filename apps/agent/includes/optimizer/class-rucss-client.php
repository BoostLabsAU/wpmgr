<?php
/**
 * RucssClient — Remove Unused CSS via the control plane.
 *
 * WPMgr does not compute used-CSS on the WordPress host (too CPU-heavy). Instead
 * the agent POSTs the rendered page HTML + the concatenated stylesheet CSS to the
 * control-plane RUCSS endpoint (signed with the site's Ed25519 key, exactly like
 * every other agent->CP call), keyed by a STRUCTURE HASH so the CP can dedup
 * identical page shapes.
 *
 * WIRE CONTRACT (matches the control plane)
 * -----------------------------------------
 *   POST {cp_base}/agent/v1/rucss
 *   Content-Type: multipart/form-data; boundary=...
 *   X-WPMgr-* Ed25519 signature headers (signed over the raw multipart body)
 *   parts:
 *     meta  application/json  {"site_id","url","structure_hash"}
 *     html  text/html         the rendered page HTML
 *     css   text/css          the concatenated stylesheet CSS
 *
 *   Responses:
 *     200  body IS the used CSS (Content-Type: text/css; possibly
 *          Content-Encoding: gzip — decode). Inline it + defer the originals.
 *     202  cache miss / still processing — serve this render UN-optimized
 *          (return the HTML UNCHANGED with full CSS).
 *     any other / timeout / exception — return the HTML UNCHANGED.
 *
 * CRITICAL GRACEFUL-DEGRADATION CONTRACT
 * --------------------------------------
 * RUCSS is an OPTIMIZATION, never a correctness requirement. This client MUST
 * NEVER throw to, or block, the render path:
 *   - Not enrolled / no CP URL / signing failure / network error / non-200 /
 *     202 / malformed response / empty used-css / ANY \Throwable
 *       => return the ORIGINAL HTML UNCHANGED (full CSS intact), log a short
 *          operational line, and move on.
 * The single public entry point optimize() wraps everything in try/catch and is
 * guaranteed side-effect-free on failure. The orchestrator can call it without
 * its own guard and the page is always served with working CSS.
 *
 * Original implementation. NOT copied from a third-party plugin.
 *
 * @package WPMgr\Agent\Optimizer
 */

declare(strict_types=1);

namespace WPMgr\Agent\Optimizer;

use WPMgr\Agent\Settings;
use WPMgr\Agent\Signer;

/**
 * Fetches used-CSS from the control plane and inlines it (degrades gracefully).
 */
final class RucssClient
{
    /** CP path the agent POSTs the page HTML+CSS to (multipart). */
    public const CP_PATH = '/agent/v1/rucss';

    /** Hard request timeout (seconds). RUCSS must never stall a render. */
    public const TIMEOUT = 8;

    private Signer $signer;

    private Settings $settings;

    /** @var list<string> Extra selectors the CP must always keep. */
    private array $includeSelectors;

    private UrlHelper $urls;

    /**
     * @param Signer        $signer           Ed25519 request signer.
     * @param Settings      $settings         Enrollment + CP URL source.
     * @param list<string>  $includeSelectors RUCSS safelist selectors.
     * @param UrlHelper|null $urls            Asset->path resolver (tests).
     */
    public function __construct(
        Signer $signer,
        Settings $settings,
        array $includeSelectors = [],
        ?UrlHelper $urls = null
    ) {
        $this->signer           = $signer;
        $this->settings         = $settings;
        $this->includeSelectors = $includeSelectors;
        $this->urls             = $urls ?? new UrlHelper();
    }

    /**
     * Replace the page's render-blocking CSS with the CP-computed used-CSS.
     *
     * GUARANTEE: returns the input HTML UNCHANGED (with full CSS) on ANY failure
     * (including a 202 cache-miss) and never throws. This is the load-bearing
     * graceful-degradation contract.
     *
     * @param string $html Full page HTML.
     * @return string Optimized HTML on a 200 hit; the original HTML otherwise.
     */
    public function optimize(string $html): string
    {
        try {
            if (!$this->settings->isEnrolled()) {
                return $html;
            }
            $usedCss = $this->fetchUsedCss($html);
            if ($usedCss === null || $usedCss === '') {
                // Cache miss (202) / no usable result — serve full CSS untouched.
                return $html;
            }
            return $this->applyUsedCss($html, $usedCss);
        } catch (\Throwable $e) {
            // The whole point: a RUCSS failure must NEVER break the page. Log a
            // short operational line (no secrets, no CSS) and return the input.
            if (function_exists('error_log')) {
                error_log('wpmgr-agent: rucss degraded (' . $e->getMessage() . ')');
            }
            return $html;
        }
    }

    /**
     * POST the HTML + concatenated CSS + structure hash to the CP as multipart;
     * return the used CSS on a 200 hit, or null on a 202 (cache miss) / any other
     * status. Never throws past optimize()'s catch, but is itself defensive.
     *
     * @param string $html Page HTML.
     * @return string|null Used CSS on a 200 hit, or null when unavailable.
     */
    private function fetchUsedCss(string $html): ?string
    {
        if (!function_exists('wp_remote_post')) {
            return null;
        }
        $base = $this->settings->controlPlaneUrl();
        if ($base === '') {
            return null;
        }

        $meta = function_exists('wp_json_encode')
            ? wp_json_encode([
                'site_id'        => $this->settings->siteId(),
                'url'            => $this->currentUrl(),
                'structure_hash' => StructureHash::compute($html, $this->includeSelectors),
            ])
            : null;
        if (!is_string($meta)) {
            return null;
        }

        $css      = $this->concatStylesheets($html);
        $boundary = 'wpmgr' . bin2hex(random_bytes(16));
        $body     = $this->buildMultipart($boundary, $meta, $html, $css);

        // Sign the EXACT multipart body bytes (same primitive as every other
        // agent->CP call): METHOD\nPATH\nTS\nNONCE\nhex(sha256(body)).
        $headers = $this->signer->signHeaders('POST', self::CP_PATH, $body);

        $response = wp_remote_post(
            $base . self::CP_PATH,
            [
                'timeout' => self::TIMEOUT,
                'headers' => array_merge(
                    [
                        'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
                        'Accept'       => 'text/css',
                    ],
                    $headers
                ),
                'body'    => $body,
            ]
        );

        if (function_exists('is_wp_error') && is_wp_error($response)) {
            return null;
        }
        $code = function_exists('wp_remote_retrieve_response_code')
            ? (int) wp_remote_retrieve_response_code($response)
            : 0;

        // 202 => cache miss / processing: serve this render with full CSS.
        if ($code !== 200) {
            return null;
        }

        $rawBody = function_exists('wp_remote_retrieve_body')
            ? (string) wp_remote_retrieve_body($response)
            : '';
        if ($rawBody === '') {
            return null;
        }

        $usedCss = $this->maybeGunzip($response, $rawBody);
        return $usedCss !== '' ? $usedCss : null;
    }

    /**
     * Build the multipart/form-data body for the meta/html/css parts.
     *
     * @param string $boundary Multipart boundary token.
     * @param string $meta     JSON meta part.
     * @param string $html     Page HTML part.
     * @param string $css      Concatenated CSS part.
     * @return string Raw multipart body.
     */
    private function buildMultipart(string $boundary, string $meta, string $html, string $css): string
    {
        $eol   = "\r\n";
        $parts = [
            ['name' => 'meta', 'type' => 'application/json', 'data' => $meta],
            ['name' => 'html', 'type' => 'text/html',        'data' => $html],
            ['name' => 'css',  'type' => 'text/css',         'data' => $css],
        ];

        $body = '';
        foreach ($parts as $part) {
            $body .= '--' . $boundary . $eol;
            $body .= 'Content-Disposition: form-data; name="' . $part['name'] . '"' . $eol;
            $body .= 'Content-Type: ' . $part['type'] . $eol . $eol;
            $body .= $part['data'] . $eol;
        }
        $body .= '--' . $boundary . '--' . $eol;

        return $body;
    }

    /**
     * Concatenate the CSS of the page's local stylesheets (best-effort). External
     * sheets that cannot be resolved to a local file are skipped; the CP still
     * gets whatever local CSS we could read. Never throws.
     *
     * @param string $html Page HTML.
     * @return string Concatenated CSS (possibly '').
     */
    private function concatStylesheets(string $html): string
    {
        $css = '';
        if (!preg_match_all('/<link\b[^>]*\brel=["\']stylesheet["\'][^>]*>/i', $html, $links)) {
            return $css;
        }
        foreach ($links[0] as $link) {
            $href = TagHelper::attr($link, 'href');
            if ($href === null || $href === '') {
                continue;
            }
            $path = $this->urls->localPath($href);
            if ($path === null || !is_file($path) || !is_readable($path)) {
                continue;
            }
            $contents = @file_get_contents($path);
            if (is_string($contents) && $contents !== '') {
                $css .= $contents . "\n";
            }
        }
        return $css;
    }

    /**
     * Gunzip the response body when the CP marked it Content-Encoding: gzip.
     * WP's HTTP API usually decodes transport gzip itself, but the CP may set the
     * header explicitly on the used-CSS payload; decode defensively. Falls back to
     * the raw body when decoding is unavailable or fails.
     *
     * @param mixed  $response The wp_remote_post response.
     * @param string $rawBody  Raw response body.
     * @return string Decoded CSS.
     */
    private function maybeGunzip($response, string $rawBody): string
    {
        $encoding = '';
        if (function_exists('wp_remote_retrieve_header')) {
            $header = wp_remote_retrieve_header($response, 'content-encoding');
            // WP may return an array of header values; collapse to a string.
            if (is_array($header)) {
                $header = implode(',', array_map('strval', $header));
            }
            $encoding = strtolower(is_string($header) ? $header : '');
        }
        if (strpos($encoding, 'gzip') === false) {
            return $rawBody;
        }
        if (!function_exists('gzdecode')) {
            return $rawBody;
        }
        $decoded = @gzdecode($rawBody);
        return is_string($decoded) && $decoded !== '' ? $decoded : $rawBody;
    }

    /**
     * The URL of the page currently being optimized (own host). Derived from the
     * request URI joined to home_url(); never trusted as an outbound target — it
     * is metadata only.
     *
     * @return string
     */
    private function currentUrl(): string
    {
        $home = function_exists('home_url') ? (string) home_url('/') : '';
        $base = rtrim($home, '/');
        $uri  = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI'])
            ? $_SERVER['REQUEST_URI']
            : '/';
        return $base . '/' . ltrim($uri, '/');
    }

    /**
     * Inline the used-CSS in <head> and defer the original stylesheets to load
     * on interaction (so the full CSS still arrives, just non-blocking).
     *
     * @param string $html    Page HTML.
     * @param string $usedCss Minimal used CSS.
     * @return string
     */
    private function applyUsedCss(string $html, string $usedCss): string
    {
        $styleTag = '<style id="wpmgr-used-css">' . $usedCss . '</style>';
        if (stripos($html, '</head>') !== false) {
            $html = (string) preg_replace('/<\/head>/i', $styleTag . '</head>', $html, 1);
        } else {
            $html = $styleTag . $html;
        }

        // Defer the original render-blocking stylesheets: rename href -> the
        // delay attribute so the delay runtime swaps them post-interaction.
        if (preg_match_all('/<link\b[^>]*\brel=["\']stylesheet["\'][^>]*>/i', $html, $links)) {
            foreach ($links[0] as $link) {
                // Keep print stylesheets as-is.
                if (strtolower((string) (TagHelper::attr($link, 'media') ?? '')) === 'print') {
                    continue;
                }
                if (TagHelper::attr($link, 'href') === null) {
                    continue;
                }
                $deferred = TagHelper::renameAttr($link, 'href', 'data-wpmgr-href');
                $html = str_replace($link, $deferred, $html);
            }
        }
        return $html;
    }
}
