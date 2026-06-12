<?php
/**
 * WPMgr Page Cache drop-in (advanced-cache.php).
 *
 * WordPress loads this file VERY early (from wp-content/advanced-cache.php) when
 * the WP_CACHE constant is true — before plugins, before the theme, before most
 * of WordPress. It therefore runs with almost nothing loaded and must be fully
 * self-contained: the live cache config is inlined into $wpmgr_config below at install
 * time (the CacheManager var_export()s it over the CONFIG_TO_REPLACE token), so
 * this file makes zero DB/plugin calls on a cache hit.
 *
 * On a HIT it streams the pre-gzipped page straight from disk and exit()s. On a
 * MISS (or any non-cacheable request) it `return false` and hands control back
 * to WordPress, which boots normally and (via the plugin's output-buffer writer)
 * may populate the cache for next time.
 *
 * The cache-key algorithm here MUST stay byte-identical to the PHP-side builder
 * (WPMgr\Agent\Cache\CacheKey): same logged-in/role/include-cookie/mobile/query
 * segments, same ksort + md5(serialize()) query hash, same path normalisation.
 *
 * Standard WordPress disk-cache serving technique.
 *
 * Direct access is blocked by the ABSPATH guard below. WordPress includes this
 * drop-in from wp-settings.php, by which point ABSPATH is already defined, so
 * the guard never fires during a normal cache hit; it only stops a direct web
 * request to the file.
 *
 * @package WPMgr\Agent\Cache
 */

if (!defined('ABSPATH')) {
    exit; // No direct access.
}

if (!defined('WP_CACHE')) {
    return;
}

// The live config is inlined here at install time.
$wpmgr_config = CONFIG_TO_REPLACE; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

if (!is_array($wpmgr_config)) {
    return false;
}

// Emit the MISS markers up-front; overwritten to HIT on a cache hit below.
if (!headers_sent()) {
    header('x-wpmgr-cache: MISS');
    header('x-wpmgr-source: PHP');
}

// --- Skip gates: hand back to WordPress (return false) -----------------------

// WP-CLI requests must never serve a cached page.
if (defined('WP_CLI') && WP_CLI) {
    return false;
}

// ---------------------------------------------------------------------------
// NOTE ON $_SERVER SANITIZATION IN THIS FILE
//
// This drop-in runs before WordPress loads — sanitize_text_field(), wp_unslash(),
// and all other core helper functions are unavailable here. Every $_SERVER value
// is therefore validated and sanitized using strict plain-PHP primitives:
//   - allowlists via in_array() with strict comparison
//   - character-class whitelists via preg_match() / preg_replace()
//   - control-character stripping via preg_replace('/[\x00-\x1F\x7F]/', '', ...)
//   - length caps before any value reaches cache-key or header logic
// Validation failures fall through to the existing bypass or default value —
// never fatal. See each read below for its per-value rationale.
// ---------------------------------------------------------------------------

// Preload warming: let WordPress render fresh HTML so the writer can store it.
// Only the presence of the header is checked; the value is not consumed.
if (isset($_SERVER['HTTP_X_WPMGR_PRELOAD'])) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- value not consumed; presence-check only; no WP sanitizers available at drop-in load time
    return false;
}

// Only GET / HEAD are cacheable. Allowlist via strtoupper + in_array —
// no WP sanitizers available at drop-in load time.
// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- advanced-cache drop-in runs pre-WP; wp_unslash/sanitize_* unavailable; value allowlisted via strtoupper+in_array below
$wpmgr_method_raw = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
$wpmgr_method     = in_array($wpmgr_method_raw, array('GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'), true)
    ? $wpmgr_method_raw : 'GET';
if ($wpmgr_method !== 'GET' && $wpmgr_method !== 'HEAD') {
    return false;
}

// Bypass cookies: any matching cookie name disables the cache for this request.
// When woo_cacheable_session is ON the three WooCommerce cart/session cookie
// patterns are listed in woo_ignore_cookies instead of bypass_cookies, so they
// neither bypass nor key the cache — the anonymous visitor maps to the same
// shared shell as a no-cookie visitor. When the flag is OFF this array is empty
// and the bypass set is byte-identical to the pre-feature behaviour.
$wpmgr_bypass_cookies = isset($wpmgr_config['bypass_cookies']) && is_array($wpmgr_config['bypass_cookies'])
    ? $wpmgr_config['bypass_cookies'] : array();
$wpmgr_woo_ignore = isset($wpmgr_config['woo_ignore_cookies']) && is_array($wpmgr_config['woo_ignore_cookies'])
    ? array_map('strtolower', $wpmgr_config['woo_ignore_cookies']) : array();
if (!empty($_COOKIE) && $wpmgr_bypass_cookies) {
    $wpmgr_cookie_names = array_keys($_COOKIE);
    foreach ($wpmgr_bypass_cookies as $wpmgr_bypass) {
        if ($wpmgr_bypass === '') {
            continue;
        }
        foreach ($wpmgr_cookie_names as $wpmgr_cn) {
            if (stripos((string) $wpmgr_cn, (string) $wpmgr_bypass) !== false) {
                return false;
            }
        }
    }
}
// Logged-in guard: even when woo_cacheable_session is ON, a wordpress_logged_in_*
// cookie always forces a cache bypass (logged-in users never receive a shared shell).
// This is already in the bypass_cookies list above, but we make it explicit as a
// defence-in-depth guard for readability and to document the invariant.
// (No additional code needed — wordpress_logged_in_ remains in bypass_cookies.)

// --- Build the cache file name (mirrors CacheKey::build) ----------------------

$wpmgr_name = 'index';

// 1. logged-in segment.
$wpmgr_logged_in = false;
if (!empty($_COOKIE)) {
    foreach (array_keys($_COOKIE) as $wpmgr_ck) {
        if (preg_match('/^wordpress_logged_in_/i', (string) $wpmgr_ck) === 1) {
            $wpmgr_logged_in = true;
            break;
        }
    }
}
if ($wpmgr_logged_in) {
    if (empty($wpmgr_config['cache_logged_in'])) {
        return false; // logged-in caching disabled — serve via PHP
    }
    $wpmgr_name .= '-logged-in';

    // 2. role segment (from the non-HTTPOnly role cookie).
    if (isset($_COOKIE['wpmgr_logged_in_roles'])) {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- advanced-cache drop-in runs pre-WP; wp_unslash/sanitize_* unavailable; value regex-filtered below
        $wpmgr_role = strtolower((string) $_COOKIE['wpmgr_logged_in_roles']);
        $wpmgr_role = preg_replace('/[^a-z0-9\-_]/', '', $wpmgr_role);
        if ($wpmgr_role !== '' && $wpmgr_role !== null) {
            $wpmgr_name .= '-' . $wpmgr_role;
        }
    }
}

// 3. include-cookie segments, in configured order.
$wpmgr_include_cookies = isset($wpmgr_config['include_cookies']) && is_array($wpmgr_config['include_cookies'])
    ? $wpmgr_config['include_cookies'] : array();
foreach ($wpmgr_include_cookies as $wpmgr_inc) {
    if ($wpmgr_inc !== '' && isset($_COOKIE[$wpmgr_inc]) && is_scalar($_COOKIE[$wpmgr_inc])) {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- advanced-cache drop-in runs pre-WP; wp_unslash/sanitize_* unavailable; value regex-filtered below
        $wpmgr_val = strtolower((string) $_COOKIE[$wpmgr_inc]);
        $wpmgr_val = preg_replace('/[^a-z0-9\-_]/', '', $wpmgr_val);
        if ($wpmgr_val !== '' && $wpmgr_val !== null) {
            $wpmgr_name .= '-' . $wpmgr_val;
        }
    }
}

// 4. mobile segment.
// Strip control characters and cap length before regex matching — no WP
// sanitizers available at drop-in load time. The regex match is read-only
// (produces a name suffix); the raw UA value is never echoed or stored.
if (!empty($wpmgr_config['cache_mobile']) && isset($_SERVER['HTTP_USER_AGENT'])) {
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- advanced-cache drop-in runs pre-WP; wp_unslash/sanitize_* unavailable; control chars stripped + length capped before use
    $wpmgr_ua_raw = (string) $_SERVER['HTTP_USER_AGENT'];
    $wpmgr_ua     = substr(preg_replace('/[\x00-\x1F\x7F]/', '', $wpmgr_ua_raw), 0, 512);
    if (preg_match(
        '/Mobile|Android|Silk\/|Kindle|BlackBerry|Opera (Mini|Mobi)|iPhone|iPad|iPod|IEMobile/i',
        $wpmgr_ua
    ) === 1) {
        $wpmgr_name .= '-mobile';
    }
}

// 5. query-hash segment (drop marketing params, ksort, md5(serialize())).
// MUST stay byte-identical to WPMgr\Agent\Cache\CacheKey, including the
// 12-distinct-key cap: over the cap the request is non-cacheable (return false)
// so an attacker cannot mint unbounded cache files via arbitrary distinct params.
$wpmgr_ignore = isset($wpmgr_config['ignore_queries']) && is_array($wpmgr_config['ignore_queries'])
    ? array_map('strtolower', $wpmgr_config['ignore_queries']) : array();
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- advanced-cache drop-in runs pre-WP; nonce verification unavailable; query keys are key-hashed for cache routing only (read-only, no state change)
if (!empty($_GET)) {
    $wpmgr_kept = array();
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- advanced-cache drop-in runs pre-WP; nonce verification unavailable; query keys are key-hashed for cache routing only
    foreach ($_GET as $wpmgr_qk => $wpmgr_qv) {
        if (in_array(strtolower((string) $wpmgr_qk), $wpmgr_ignore, true)) {
            continue;
        }
        $wpmgr_kept[(string) $wpmgr_qk] = $wpmgr_qv;
    }
    if (count($wpmgr_kept) > 12) {
        return false; // non-cacheable — too many cache-varying query keys
    }
    if (!empty($wpmgr_kept)) {
        ksort($wpmgr_kept);
        $wpmgr_name .= '-' . md5(serialize($wpmgr_kept));
    }
}

// --- Locate the cache file ----------------------------------------------------

// HTTP_HOST: strict charset validation guards against cache-poisoning via a
// crafted Host header. Accept only hostname characters + optional port; reject
// (treat as cache bypass via 'unknown-host') anything else. No WP sanitizers
// available at drop-in load time.
// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- advanced-cache drop-in runs pre-WP; wp_unslash/sanitize_* unavailable; value strictly validated via preg_match allowlist below
$wpmgr_host_raw = isset($_SERVER['HTTP_HOST']) ? strtolower((string) $_SERVER['HTTP_HOST']) : '';
if ($wpmgr_host_raw !== '' && preg_match('/^[a-z0-9.-]+(:[0-9]{1,5})?$/', $wpmgr_host_raw) === 1) {
    $wpmgr_host = $wpmgr_host_raw;
} else {
    $wpmgr_host = 'unknown-host';
}

// REQUEST_URI: strip control characters and cap length before use as a
// cache-key component. Path-traversal sequences are removed below.
// No WP sanitizers available at drop-in load time.
// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- advanced-cache drop-in runs pre-WP; wp_unslash/sanitize_* unavailable; control chars stripped + length capped before use as cache key
$wpmgr_uri_raw = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
$wpmgr_uri     = substr(preg_replace('/[\x00-\x1F\x7F]/', '', $wpmgr_uri_raw), 0, 2048);
unset($wpmgr_uri_raw);
// Treat an empty URI (after stripping) as root.
if ($wpmgr_uri === '') {
    $wpmgr_uri = '/';
}
$wpmgr_qpos = strpos($wpmgr_uri, '?');
if ($wpmgr_qpos !== false) {
    $wpmgr_uri = substr($wpmgr_uri, 0, $wpmgr_qpos);
}
$wpmgr_path = strtolower(rawurldecode($wpmgr_uri));
$wpmgr_path = str_replace(array('\\', "\0"), array('/', ''), $wpmgr_path);
$wpmgr_path = preg_replace('#/+#', '/', $wpmgr_path);
$wpmgr_path = preg_replace('#(\.\./|/\.\.)#', '', (string) $wpmgr_path);
$wpmgr_path = '/' . ltrim((string) $wpmgr_path, '/');
$wpmgr_path = rtrim($wpmgr_path, '/'); // '' for root

$wpmgr_content = defined('WP_CONTENT_DIR') ? (string) WP_CONTENT_DIR : (dirname(__DIR__));
$wpmgr_file = rtrim($wpmgr_content, '/\\')
    . '/cache/wpmgr/' . $wpmgr_host . $wpmgr_path . '/' . $wpmgr_name . '.html.gz';

// Resolve the tally metrics dir once, before the HIT/MISS branch.
// Stored in a local var so both branches share the same computation.
$wpmgr_metrics_dir = rtrim($wpmgr_content, '/\\') . '/cache/wpmgr/.metrics';
$wpmgr_tally_hour  = gmdate('YmdH');

if (!is_file($wpmgr_file)) {
    // MISS — append one line to the hour-bucket miss file, then hand back to WordPress.
    // One file_put_contents per miss: no DB, no WP calls, no flock.
    // The mkdir guard runs only when the bucket file is new (first miss of the hour).
    $wpmgr_miss_file = $wpmgr_metrics_dir . '/miss-' . $wpmgr_tally_hour;
    if (!@file_exists($wpmgr_miss_file)) {
        @mkdir($wpmgr_metrics_dir, 0755, true); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir,WordPress.Security.PluginDirectoryWrite.PluginDirectoryWrite -- advanced-cache drop-in runs pre-WP; wp_mkdir_p unavailable; writes to wp-content/cache/wpmgr, a persistent location outside the plugin folder
    }
    @file_put_contents($wpmgr_miss_file, "\n", FILE_APPEND); // phpcs:ignore PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite -- writes to wp-content/cache, a persistent install target outside the plugin folder
    return false; // MISS — boot WordPress
}

// --- Serve the cache hit ------------------------------------------------------

// HIT — append one line to the hour-bucket hit file BEFORE any early exits
// (304 Not Modified, HEAD). This counts every request served by the drop-in —
// full body, 304-revalidation, and HEAD — as a hit, matching the intended metric
// "served by the drop-in without booting WordPress".
// One file_put_contents: no DB, no WP calls, no flock.
// The mkdir guard runs only when the bucket file is new (first hit of the hour).
$wpmgr_hit_file = $wpmgr_metrics_dir . '/hit-' . $wpmgr_tally_hour;
if (!@file_exists($wpmgr_hit_file)) {
    @mkdir($wpmgr_metrics_dir, 0755, true); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir,WordPress.Security.PluginDirectoryWrite.PluginDirectoryWrite -- advanced-cache drop-in runs pre-WP; wp_mkdir_p unavailable; writes to wp-content/cache/wpmgr, a persistent location outside the plugin folder
}
@file_put_contents($wpmgr_hit_file, "\n", FILE_APPEND); // phpcs:ignore PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite -- writes to wp-content/cache, a persistent install target outside the plugin folder

if (function_exists('ini_set')) {
    @ini_set('zlib.output_compression', '0'); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- required runtime ini tweak; @-guarded
}

if (!headers_sent()) {
    header('Content-Encoding: gzip');
    header('Cache-Tag: ' . $wpmgr_host);
    header('CDN-Cache-Control: max-age=2592000');
    header('Cache-Control: no-cache, must-revalidate');
    header('x-wpmgr-cache: HIT');
    header('x-wpmgr-source: PHP');

    $wpmgr_mtime = filemtime($wpmgr_file);
    if ($wpmgr_mtime !== false) {
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $wpmgr_mtime) . ' GMT');

        // HTTP_IF_MODIFIED_SINCE: strip control characters and cap length before
        // passing to strtotime() — garbage input already returns false, but
        // control chars could confuse logging. No WP sanitizers available.
        $wpmgr_ims_hdr = isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ? (string) $_SERVER['HTTP_IF_MODIFIED_SINCE'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- advanced-cache drop-in runs pre-WP; wp_unslash/sanitize_* unavailable; control chars stripped + length capped on next line before strtotime()
        $wpmgr_ims_raw = substr(preg_replace('/[\x00-\x1F\x7F]/', '', $wpmgr_ims_hdr), 0, 128);
        $wpmgr_ims = ($wpmgr_ims_raw !== '') ? strtotime($wpmgr_ims_raw) : 0;
        if ($wpmgr_ims !== false && $wpmgr_ims >= $wpmgr_mtime) {
            // SERVER_PROTOCOL: allowlist via preg_match before emitting in the
            // 304 status line — defense-in-depth against header injection.
            // No WP sanitizers available at drop-in load time.
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- advanced-cache drop-in runs pre-WP; wp_unslash/sanitize_* unavailable; value validated via preg_match allowlist before header() emission
            $wpmgr_proto_raw = isset($_SERVER['SERVER_PROTOCOL']) ? (string) $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';
            // Accept HTTP/1.0, HTTP/1.1, HTTP/2, HTTP/2.0, HTTP/3, HTTP/3.0.
            $wpmgr_proto = preg_match('#^HTTP/[0-9](\.[0-9])?$#', $wpmgr_proto_raw) === 1
                ? $wpmgr_proto_raw : 'HTTP/1.1';
            header($wpmgr_proto . ' 304 Not Modified', true, 304);
            exit();
        }
    }

    header('Content-Type: text/html; charset=UTF-8');
}

// HEAD requests get headers only.
if ($wpmgr_method === 'HEAD') {
    exit();
}

readfile($wpmgr_file); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- advanced-cache drop-in streams the cached body before WordPress is loaded; readfile is the canonical low-memory emit
exit();
