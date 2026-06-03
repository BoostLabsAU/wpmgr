<?php
/**
 * HtaccessManager — writes / removes the `# BEGIN WPMgr Cache` fast-path block
 * in the site-root .htaccess.
 *
 * The block lets Apache serve a pre-gzipped `index.html.gz` (or
 * `index-mobile.html.gz`) straight from disk for anonymous, query-less,
 * cookie-less GET/HEAD requests — bypassing PHP entirely. Logged-in / role /
 * include-cookie / query-hash variants always fall through to the PHP drop-in
 * (the block only matches the plain variants by gating on empty cookie + empty
 * query string).
 *
 * Idempotent: the managed block is delimited by exact BEGIN/END markers and is
 * always stripped before re-insertion, so re-running never duplicates it.
 *
 * Two host quirks:
 *   - MOBILE flag: the desktop/mobile serve rules gate on an env var
 *     `MOBILE_CACHING_FLAG`. We render `:1` when mobile caching is on and `:0`
 *     when off, flipping behaviour without swapping rule sets.
 *   - OpenLiteSpeed: when `$_SERVER['LSWS_EDITION']` indicates OLS, the gzip /
 *     deflate directives are stripped (OLS handles .gz differently and the
 *     mod_deflate no-gzip directives conflict).
 *
 * Marker convention mirrors WordPress core's insert_with_markers(). Standard
 * disk-cache .htaccess technique (Cache Enabler / WP Super Cache, GPLv2).
 * Original implementation.
 *
 * @package WPMgr\Agent\Cache
 */

declare(strict_types=1);

namespace WPMgr\Agent\Cache;

/**
 * Manages the WPMgr Cache .htaccess managed block.
 */
final class HtaccessManager
{
    /** Opening marker — byte-exact for idempotency. */
    public const BEGIN = '# BEGIN WPMgr Cache';

    /** Closing marker — byte-exact for idempotency. */
    public const END = '# END WPMgr Cache';

    /** Cache subdirectory under wp-content/cache that the rewrite serves from. */
    public const CACHE_SUBDIR = 'cache/wpmgr';

    /** Path to the .htaccess template asset. */
    private string $templatePath;

    /**
     * @param string|null $templatePath Override (tests); defaults to the plugin asset.
     */
    public function __construct(?string $templatePath = null)
    {
        if ($templatePath !== null) {
            $this->templatePath = $templatePath;
        } elseif (defined('WPMGR_AGENT_DIR')) {
            $this->templatePath = rtrim((string) constant('WPMGR_AGENT_DIR'), '/\\')
                . '/assets/wpmgr-htaccess.txt';
        } else {
            $this->templatePath = '';
        }
    }

    /**
     * Install / refresh the managed block. Returns false on nginx (no .htaccess),
     * unresolved path, or write failure.
     *
     * @param string $hostname     The site hostname used in Cache-Tag.
     * @param bool   $mobileCaching Whether the mobile bucket is enabled.
     * @return bool
     */
    public function install(string $hostname, bool $mobileCaching): bool
    {
        if ($this->isNginx()) {
            return false;
        }

        $path = $this->htaccessPath();
        if ($path === '') {
            return false;
        }

        $existing = '';
        if (@is_file($path) && @is_readable($path)) {
            $read = @file_get_contents($path);
            if ($read !== false) {
                $existing = $read;
            }
        }

        $updated = $this->renderInto($existing, $hostname, $mobileCaching);
        if ($updated === $existing) {
            return true; // already current
        }

        $dir = dirname($path);
        if (!@is_writable($dir) && @is_file($path) && !@is_writable($path)) {
            return false;
        }

        $result = @file_put_contents($path, $updated, LOCK_EX);
        return $result !== false;
    }

    /**
     * Remove the managed block. Idempotent (absent ⇒ no-op true).
     *
     * @return bool
     */
    public function uninstall(): bool
    {
        $path = $this->htaccessPath();
        if ($path === '' || !@is_file($path)) {
            return true;
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            return true;
        }

        $stripped = $this->stripBlock($content);
        if ($stripped === $content) {
            return true;
        }

        $result = @file_put_contents($path, $stripped, LOCK_EX);
        return $result !== false;
    }

    /**
     * Pure transform: compute the .htaccess content that WOULD be written. Used
     * directly by unit tests with no disk access.
     *
     * @param string $existing      Existing .htaccess content.
     * @param string $hostname      Site hostname.
     * @param bool   $mobileCaching Mobile bucket toggle.
     * @return string
     */
    public function renderInto(string $existing, string $hostname, bool $mobileCaching): string
    {
        $block = self::BEGIN . "\n" . $this->blockBody($hostname, $mobileCaching) . "\n" . self::END;
        return $this->spliceBlock($existing, $block);
    }

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
        return str_contains(strtolower($_SERVER['SERVER_SOFTWARE']), 'nginx');
    }

    /**
     * Detect OpenLiteSpeed via the LSWS_EDITION server var.
     *
     * @return bool
     */
    public function isOpenLiteSpeed(): bool
    {
        if (!isset($_SERVER['LSWS_EDITION']) || !is_string($_SERVER['LSWS_EDITION'])) {
            return false;
        }
        return preg_match('/openlitespeed/i', $_SERVER['LSWS_EDITION']) === 1;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Render the block body from the template, substituting the HOSTNAME,
     * MOBILE flag, and CACHE_CONTENT_PATH placeholders. On OpenLiteSpeed,
     * strip the gzip section.
     *
     * CACHE_CONTENT_PATH is the WP_CONTENT_DIR-relative subdirectory the cache
     * lives in (e.g. `wp-content/cache/wpmgr` on a standard install, or a
     * relocated path when WP_CONTENT_DIR is non-default). This makes the Apache
     * fast-path correct on sites that move wp-content outside the webroot.
     *
     * @param string $hostname      Site hostname.
     * @param bool   $mobileCaching Mobile toggle.
     * @return string
     */
    private function blockBody(string $hostname, bool $mobileCaching): string
    {
        $template = $this->loadTemplate();

        $hostname = preg_replace('/[^a-zA-Z0-9\.\-]/', '', $hostname) ?? '';

        // Resolve the WP_CONTENT_DIR-relative cache path so the rewrite rules
        // point at the real cache directory even when wp-content is relocated.
        $cachePath = $this->cacheContentPath();

        $body = str_replace(
            ['HOSTNAME', 'MOBILE_CACHING_FLAG:0', 'CACHE_CONTENT_PATH'],
            [$hostname, 'MOBILE_CACHING_FLAG:' . ($mobileCaching ? '1' : '0'), $cachePath],
            $template
        );

        if ($this->isOpenLiteSpeed()) {
            $body = $this->stripGzipSection($body);
        }

        return rtrim($body, "\n");
    }

    /**
     * Compute the document-root-relative path to the WPMgr cache directory.
     *
     * On a standard install this is `wp-content/cache/wpmgr`. When WP_CONTENT_DIR
     * is relocated (e.g. `app/` instead of `wp-content/`) the returned path
     * reflects the real location so the Apache RewriteCond points at it correctly.
     *
     * Returns the default `wp-content/cache/wpmgr` when ABSPATH / WP_CONTENT_DIR
     * are unavailable (unit-test context).
     *
     * @return string Document-root-relative path (no leading/trailing slash).
     */
    private function cacheContentPath(): string
    {
        $default = 'wp-content/cache/wpmgr';

        if (!defined('WP_CONTENT_DIR') || !defined('ABSPATH')) {
            return $default;
        }

        $contentDir = rtrim((string) constant('WP_CONTENT_DIR'), '/\\');
        $abspath    = rtrim((string) constant('ABSPATH'), '/\\');

        if ($contentDir === '' || $abspath === '') {
            return $default;
        }

        // Strip the ABSPATH prefix to get the document-root-relative content dir.
        if (strpos($contentDir, $abspath) === 0) {
            $rel = ltrim(substr($contentDir, strlen($abspath)), '/\\');
        } else {
            // WP_CONTENT_DIR is outside ABSPATH (hosted separately). Fall back to
            // the default so the template is not broken; the PHP drop-in is
            // already correct (it uses WP_CONTENT_DIR directly).
            return $default;
        }

        if ($rel === '') {
            return $default;
        }

        return $rel . '/cache/wpmgr';
    }

    /**
     * Load the .htaccess template, falling back to an embedded copy when the
     * asset file is unavailable (keeps the manager usable in minimal contexts).
     *
     * @return string
     */
    private function loadTemplate(): string
    {
        if ($this->templatePath !== '' && @is_file($this->templatePath)) {
            $read = @file_get_contents($this->templatePath);
            if ($read !== false && trim($read) !== '') {
                return $this->stripOuterMarkers($read);
            }
        }
        return $this->embeddedTemplate();
    }

    /**
     * Remove any BEGIN/END marker lines from a raw template so the manager owns
     * marker placement (the asset file ships with markers for readability).
     *
     * @param string $raw Raw template content.
     * @return string
     */
    private function stripOuterMarkers(string $raw): string
    {
        $lines = preg_split('/\r?\n/', $raw) ?: [];
        $kept  = [];
        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === self::BEGIN || $trim === self::END) {
                continue;
            }
            $kept[] = $line;
        }
        return trim(implode("\n", $kept));
    }

    /**
     * Strip the gzip/deflate IfModule section (OpenLiteSpeed conflict). Removes
     * everything between the gzip start comment and its end comment, inclusive.
     *
     * @param string $body Block body.
     * @return string
     */
    private function stripGzipSection(string $body): string
    {
        $pattern = '/# GZIP compression.*?# End GZIP compression\n?/s';
        $result  = preg_replace($pattern, '', $body);
        return $result ?? $body;
    }

    /**
     * Splice the fresh block in, stripping any prior copy first. The block is
     * prepended (before WordPress's own rewrites) so the disk fast-path wins.
     *
     * @param string $content Existing .htaccess.
     * @param string $block   Fresh managed block.
     * @return string
     */
    private function spliceBlock(string $content, string $block): string
    {
        $stripped = $this->stripBlock($content);
        if (trim($stripped) === '') {
            return $block . "\n";
        }
        return $block . "\n\n" . ltrim($stripped, "\n");
    }

    /**
     * Remove the managed block (BEGIN..END inclusive, one optional trailing
     * newline).
     *
     * @param string $content .htaccess content.
     * @return string
     */
    private function stripBlock(string $content): string
    {
        $pattern = '/' . preg_quote(self::BEGIN, '/') . '.*?' . preg_quote(self::END, '/') . "\n?/s";
        $result  = preg_replace($pattern, '', $content);
        return $result ?? $content;
    }

    /**
     * Resolve the site-root .htaccess path (get_home_path → ABSPATH).
     *
     * @return string
     */
    private function htaccessPath(): string
    {
        $root = '';
        if (function_exists('get_home_path')) {
            $candidate = get_home_path();
            if (is_string($candidate) && $candidate !== '') {
                $root = $candidate;
            }
        }
        if ($root === '' && defined('ABSPATH')) {
            $root = (string) constant('ABSPATH');
        }
        if ($root === '') {
            return '';
        }
        return rtrim($root, '/\\') . DIRECTORY_SEPARATOR . '.htaccess';
    }

    /**
     * The fallback embedded template (kept in sync with assets/wpmgr-htaccess.txt
     * minus the BEGIN/END markers). HOSTNAME, MOBILE_CACHING_FLAG, and
     * CACHE_CONTENT_PATH placeholders are intact; substituted by blockBody().
     *
     * @return string
     */
    private function embeddedTemplate(): string
    {
        return <<<'HT'
# GZIP compression for text files (stripped on OpenLiteSpeed)
<IfModule mod_deflate.c>
	AddOutputFilterByType DEFLATE text/html text/plain text/css text/javascript
	AddOutputFilterByType DEFLATE application/javascript application/json application/xml
	AddOutputFilterByType DEFLATE font/woff2 image/svg+xml
	SetEnvIfNoCase Request_URI \.gz$ no-gzip
</IfModule>
# End GZIP compression

# Response headers for cached .html.gz pages
<FilesMatch "\.html\.gz$">
	<IfModule mod_headers.c>
		Header set Content-Encoding "gzip"
		Header set Content-Type "text/html; charset=UTF-8"
		Header set Cache-Tag "HOSTNAME"
		Header set CDN-Cache-Control "max-age=2592000"
		Header set Cache-Control "no-cache, must-revalidate"
		Header set x-wpmgr-cache "HIT"
		Header set x-wpmgr-source "Web Server"
	</IfModule>
	<IfModule mod_mime.c>
		AddType text/html .gz
		AddCharset UTF-8 .gz
		AddEncoding gzip .gz
	</IfModule>
</FilesMatch>

<IfModule mod_rewrite.c>
	RewriteEngine On
	RewriteBase /

	# Block direct access to cached files
	RewriteCond %{THE_REQUEST} "/CACHE_CONTENT_PATH/.*\.html\.gz" [NC]
	RewriteRule ^ - [F]

	# Mobile caching flag (flipped to :1 by the manager when enabled)
	RewriteRule ^ - [E=MOBILE_CACHING_FLAG:0]

	# Serve mobile cache
	RewriteCond %{REQUEST_METHOD} GET|HEAD
	RewriteCond %{QUERY_STRING} =""
	RewriteCond %{HTTP:Cookie} =""
	RewriteCond %{REQUEST_URI} !^/(wp-(?:admin|login|register|comments-post|cron|json))/ [NC]
	RewriteCond %{HTTP_USER_AGENT} "android|blackberry|ipad|iphone|ipod|iemobile|opera mobile|palmos|webos" [NC]
	RewriteCond %{ENV:MOBILE_CACHING_FLAG} =1
	RewriteCond %{DOCUMENT_ROOT}/CACHE_CONTENT_PATH/%{HTTP_HOST}%{REQUEST_URI}/index-mobile.html.gz -f
	RewriteRule ^(.*)$ CACHE_CONTENT_PATH/%{HTTP_HOST}%{REQUEST_URI}/index-mobile.html.gz [L]

	# Serve desktop cache
	RewriteCond %{REQUEST_METHOD} GET|HEAD
	RewriteCond %{QUERY_STRING} =""
	RewriteCond %{HTTP:Cookie} =""
	RewriteCond %{REQUEST_URI} !^/(wp-(?:admin|login|register|comments-post|cron|json))/ [NC]
	RewriteCond %{HTTP_USER_AGENT} "!(android|blackberry|ipad|iphone|ipod|iemobile|opera mobile|palmos|webos)" [NC,OR]
	RewriteCond %{ENV:MOBILE_CACHING_FLAG} !=1
	RewriteCond %{DOCUMENT_ROOT}/CACHE_CONTENT_PATH/%{HTTP_HOST}%{REQUEST_URI}/index.html.gz -f
	RewriteRule ^(.*)$ CACHE_CONTENT_PATH/%{HTTP_HOST}%{REQUEST_URI}/index.html.gz [L]
</IfModule>
HT;
    }
}
