<?php
/**
 * NginxHelper — nginx page-cache serving snippet generator.
 *
 * nginx ignores .htaccess, so the disk fast-path cannot be auto-installed. This
 * helper detects nginx and emits a copy-pasteable `location` snippet the site
 * operator adds to their server config manually. It never edits any server file.
 *
 * The snippet mirrors the .htaccess serve logic: for anonymous, cookie-less,
 * query-less GET requests it `try_files` the pre-gzipped index.html.gz on disk,
 * setting the gzip Content-Encoding so nginx streams the raw bytes.
 *
 * Standard nginx try_files cache-serving idiom.
 *
 * @package WPMgr\Agent\Cache
 */

declare(strict_types=1);

namespace WPMgr\Agent\Cache;

/**
 * Generates the manual nginx config snippet (never auto-applied).
 */
final class NginxHelper
{
    /** WP option flagging that an nginx notice should be shown to the admin. */
    public const OPTION_NGINX_NOTICE = 'wpmgr_cache_nginx_notice';

    /**
     * Detect nginx via SERVER_SOFTWARE.
     *
     * @return bool
     */
    public function isNginx(): bool
    {
        if (!isset($_SERVER['SERVER_SOFTWARE']) || !is_string($_SERVER['SERVER_SOFTWARE'])) {
            return false;
        }
        return str_contains( strtolower( sanitize_text_field( wp_unslash( (string) $_SERVER['SERVER_SOFTWARE'] ) ) ), 'nginx' );
    }

    /**
     * Build the manual nginx `location` snippet for the page cache. The cache
     * root is wp-content/cache/wpmgr/{host}/{uri}/index.html.gz.
     *
     * @param string $cachePathPrefix Document-root-relative cache prefix
     *   (defaults to the standard wp-content/cache/wpmgr).
     * @return string The snippet, or '' when $cachePathPrefix is invalid.
     */
    public function snippet(string $cachePathPrefix = '/wp-content/cache/wpmgr'): string
    {
        // Whitelist the prefix before it is interpolated into the generated
        // config: only path-safe characters (letters, digits, '/', '_', '-').
        // Anything else (whitespace, quotes, ';', '{', '}', '$', newlines, '..')
        // could break out of the path or inject directives — reject it.
        if (preg_match('#^[A-Za-z0-9/_-]+$#', $cachePathPrefix) !== 1
            || strpos($cachePathPrefix, '..') !== false
        ) {
            return '';
        }

        $prefix = '/' . trim($cachePathPrefix, '/');

        return implode("\n", [
            '# WPMgr Page Cache — nginx equivalent (add inside your server {} block manually).',
            '# nginx has no .htaccess; this serve rule must be installed by the operator.',
            '',
            'set $wpmgr_cache "";',
            '# Only consider the disk cache for anonymous, query-less, cacheable requests.',
            'if ($request_method ~ ^(GET|HEAD)$) { set $wpmgr_cache "M"; }',
            'if ($query_string != "") { set $wpmgr_cache ""; }',
            'if ($http_cookie ~* "wordpress_logged_in|wp-postpass|comment_author|woocommerce_items_in_cart") {',
            '    set $wpmgr_cache "";',
            '}',
            'if ($request_uri ~* "/wp-(admin|login|register|comments-post|cron|json)") {',
            '    set $wpmgr_cache "";',
            '}',
            '',
            'location / {',
            '    # Block direct hits on cache files.',
            '    location ~* ' . $prefix . '/.*\\.html\\.gz$ { internal; }',
            '',
            '    # Serve the pre-gzipped page when a cache file exists and $wpmgr_cache == "M".',
            '    if (-f "$document_root' . $prefix . '/$host$uri/index.html.gz") {',
            '        set $wpmgr_cache "${wpmgr_cache}F";',
            '    }',
            '    if ($wpmgr_cache = "MF") {',
            '        add_header Content-Encoding gzip;',
            '        add_header X-WPMgr-Cache HIT;',
            '        add_header Cache-Control "no-cache, must-revalidate";',
            '        rewrite ^ ' . $prefix . '/$host$uri/index.html.gz break;',
            '    }',
            '',
            '    try_files $uri $uri/ /index.php?$args;',
            '}',
            '',
            '# WPMgr Browser Cache — add inside your server {} block manually.',
            '# Serves all static assets with a 1-year public cache lifetime.',
            '# Assets served by WordPress include a ?ver= query string that changes on',
            '# update, so browsers and CDNs revalidate when the URL changes.',
            'location ~* \\.(css|js|mjs|woff2?|ttf|otf|eot|avif|webp|png|jpe?g|gif|svg|ico|mp4|webm|pdf)$ {',
            '    expires 1y;',
            '    add_header Cache-Control "public, max-age=31536000";',
            '    access_log off;',
            '}',
        ]);
    }
}
