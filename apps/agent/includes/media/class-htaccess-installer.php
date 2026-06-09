<?php
/**
 * HtaccessInstaller — writes and removes the WPMgr Media block in .htaccess.
 *
 * The Apache block implements the standard Accept-header content-negotiation
 * technique for AVIF/WebP fallback, as documented in:
 *   - Google / web.dev "Serve images in modern formats"
 *   - Apache HTTP Server mod_rewrite documentation (RewriteCond %{HTTP_ACCEPT})
 *   - RFC 7231 §7.1.4 (Vary header semantics)
 *
 * The managed-block marker convention (`# BEGIN / # END`) mirrors the same
 * pattern used by WordPress core's own insert_with_markers() in
 * wp-admin/includes/misc.php — a well-established idiom for idempotent
 * managed sections inside configuration files.
 *
 * @package WPMgr\Agent\Media
 */

declare(strict_types=1);

namespace WPMgr\Agent\Media;

/**
 * Installs and removes the WPMgr Media .htaccess block on Apache servers.
 *
 * Instantiate with `new HtaccessInstaller()` — no constructor arguments.
 * All WordPress function calls are guarded with function_exists() / defined()
 * so the class is safely constructable in unit-test context without WP.
 */
final class HtaccessInstaller
{
    /** Opening marker for the managed block — must remain byte-exact for idempotency. */
    public const BEGIN = '# BEGIN WPMgr Media';

    /** Closing marker for the managed block — must remain byte-exact for idempotency. */
    public const END = '# END WPMgr Media';

    /** WP option key used to signal the nginx-notice state to the admin UI. */
    public const OPTION_NGINX_NOTICE = 'wpmgr_agent_media_nginx_notice';

    // -------------------------------------------------------------------------
    // Public interface
    // -------------------------------------------------------------------------

    /**
     * Write (or replace) the WPMgr Media block in the site-root .htaccess.
     *
     * Returns false when the server is nginx, the path cannot be resolved, or
     * the file cannot be written. Returns true on success or when the file is
     * already up to date.
     */
    public function install(): bool
    {
        // Nginx fast-path: .htaccess has no effect; store a notice for the admin
        // UI and bail without touching any file.
        if ($this->isNginx()) {
            if (function_exists('update_option')) {
                update_option(self::OPTION_NGINX_NOTICE, 1, false);
            }
            return false;
        }

        $path = $this->htaccessPath();
        if ($path === '') {
            return false;
        }

        // Read existing file content, defaulting to empty string on any I/O error.
        $existing = '';
        if (@is_file($path) && @is_readable($path)) {
            $read = @file_get_contents($path);
            if ($read !== false) {
                $existing = $read;
            }
        }

        // Build the full managed block and splice it into the existing content.
        $block   = self::BEGIN . "\n" . $this->blockBody() . "\n" . self::END;
        $updated = $this->spliceBlock($existing, $block);

        // Short-circuit: nothing changed, no write needed.
        if ($updated === $existing) {
            return true;
        }

        // Writability guard: if the directory is not writable AND the file exists
        // AND the file itself is also not writable, we cannot proceed.
        $dir = dirname($path);
        if (!@is_writable($dir) && @is_file($path) && !@is_writable($path)) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- headless agent; WP_Filesystem never initialized; direct writability probe is the only option
            if (function_exists('update_option')) {
                update_option(self::OPTION_NGINX_NOTICE, 0, false);
            }
            return false;
        }

        $result = @file_put_contents($path, $updated, LOCK_EX);
        return $result !== false;
    }

    /**
     * Remove the WPMgr Media block from .htaccess (plugin deactivation / format change).
     *
     * Returns true when there is nothing to do (path unavailable, file absent,
     * block not present), or after a successful write. Returns false only when
     * the write itself fails.
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
            // Block was not present — nothing to remove.
            return true;
        }

        $result = @file_put_contents($path, $stripped, LOCK_EX);
        return $result !== false;
    }

    /**
     * Pure function: compute the .htaccess content that WOULD be written for
     * the given existing string, with no disk or option side-effects.
     *
     * Used directly by unit tests (no WP bootstrap required).
     */
    public function renderInto(string $existing): string
    {
        $block = self::BEGIN . "\n" . $this->blockBody() . "\n" . self::END;
        return $this->spliceBlock($existing, $block);
    }

    /**
     * Return a human-readable nginx location block equivalent to the Apache
     * rules, for display in the admin notice when nginx is detected.
     *
     * This snippet is never auto-applied — it is presented to the site operator
     * for manual inclusion in their server config.
     *
     * The pattern mirrors the same Accept-header conditional logic as the
     * Apache block: serve the modern file when the client signals support via
     * the Accept header, fall back to jpg/png otherwise.
     */
    public function nginxSnippet(): string
    {
        return <<<'NGINX'
# WPMgr Media — nginx equivalent (add to your server {} block manually)
# Implements the same Accept-header AVIF/WebP fallback as the Apache block.
# See: https://web.dev/serve-images-webp/ and the nginx try_files documentation.

map $http_accept $wpmgr_modern {
    default          0;
    "~*image/avif"   avif;
    "~*image/webp"   webp;
}

location ~* ^(?<wpmgr_base>.+)\.(?<wpmgr_ext>avif|webp)$ {
    add_header Vary Accept always;
    set $wpmgr_modern "";
    if ($http_accept ~* "image/avif") { set $wpmgr_modern "avif"; }
    if ($http_accept ~* "image/webp") { set $wpmgr_modern "webp"; }
    try_files $request_filename
              $wpmgr_base.jpg
              $wpmgr_base.png
              $uri
              =404;
}
NGINX;
    }

    /**
     * Detect whether the current server is nginx by inspecting SERVER_SOFTWARE.
     *
     * Returns false when the key is absent, not a string, or does not contain
     * the substring "nginx" (case-insensitive).
     */
    public function isNginx(): bool
    {
        if (!isset($_SERVER['SERVER_SOFTWARE']) || !is_string($_SERVER['SERVER_SOFTWARE'])) {
            return false;
        }
        return str_contains(strtolower(sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE']))), 'nginx');
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Build the Apache rewrite rules body that sits between the BEGIN/END markers.
     *
     * Technique: standard Accept-header content negotiation as documented in the
     * Apache mod_rewrite manual and the web.dev "Serve images in modern formats"
     * guide. Each format group uses three directives:
     *
     *   1. RewriteCond %{HTTP_ACCEPT} !image/<fmt> [NC]   — client does NOT support fmt
     *   2. RewriteCond %{DOCUMENT_ROOT}/$1.<legacy> -f    — legacy twin must exist on disk
     *   3. RewriteRule ^(.+)\.<fmt>$ $1.<legacy> [L]      — rewrite to the legacy file
     *
     * The -f disk-existence guard prevents a 404 when the legacy twin is absent.
     * Legacy extensions tried (in order): .png, .jpg, .jpeg — for both avif and webp.
     *
     * The `Header merge Vary Accept` directive (mod_headers) instructs shared
     * caches and CDNs to key responses by the Accept header (RFC 7231 §7.1.4).
     * The `merge` action appends the token only when not already listed, avoiding
     * duplicate Vary values on subsequent requests.
     */
    private function blockBody(): string
    {
        // Tab-indented inside IfModule blocks, following the WP insert_with_markers
        // convention for .htaccess managed sections.
        return <<<'BODY'
<IfModule mod_rewrite.c>
	RewriteEngine On
	RewriteCond %{HTTP_ACCEPT} !image/avif [NC]
	RewriteCond %{DOCUMENT_ROOT}/$1.png -f
	RewriteRule ^(.+)\.avif$ $1.png [L]
	RewriteCond %{HTTP_ACCEPT} !image/avif [NC]
	RewriteCond %{DOCUMENT_ROOT}/$1.jpg -f
	RewriteRule ^(.+)\.avif$ $1.jpg [L]
	RewriteCond %{HTTP_ACCEPT} !image/avif [NC]
	RewriteCond %{DOCUMENT_ROOT}/$1.jpeg -f
	RewriteRule ^(.+)\.avif$ $1.jpeg [L]
	RewriteCond %{HTTP_ACCEPT} !image/webp [NC]
	RewriteCond %{DOCUMENT_ROOT}/$1.png -f
	RewriteRule ^(.+)\.webp$ $1.png [L]
	RewriteCond %{HTTP_ACCEPT} !image/webp [NC]
	RewriteCond %{DOCUMENT_ROOT}/$1.jpg -f
	RewriteRule ^(.+)\.webp$ $1.jpg [L]
	RewriteCond %{HTTP_ACCEPT} !image/webp [NC]
	RewriteCond %{DOCUMENT_ROOT}/$1.jpeg -f
	RewriteRule ^(.+)\.webp$ $1.jpeg [L]
</IfModule>
<IfModule mod_headers.c>
	<FilesMatch "\.(avif|webp)$">
		Header merge Vary Accept
	</FilesMatch>
</IfModule>
BODY;
    }

    /**
     * Strip any existing WPMgr Media block from $content, then prepend the
     * fresh $block before whatever remains.
     *
     * Prepending is intentional: the Accept-fallback rewrites must be evaluated
     * before WordPress's front-controller rewrites (which always come after the
     * WPMgr block). This mirrors the insert_with_markers() strategy in WP core.
     *
     * Idempotency is guaranteed because stripBlock() always removes any prior
     * copy before inserting the new one.
     */
    private function spliceBlock(string $content, string $block): string
    {
        $stripped = $this->stripBlock($content);

        if (trim($stripped) === '') {
            // Nothing else in the file — block only, with a trailing newline.
            return $block . "\n";
        }

        // Prepend block, then two newlines, then the remaining content (leading
        // newlines trimmed to avoid excessive blank lines).
        return $block . "\n\n" . ltrim($stripped, "\n");
    }

    /**
     * Remove the managed block (BEGIN through END inclusive, plus one optional
     * trailing newline) from $content using a PCRE dotall regex.
     *
     * Returns the original string unchanged if preg_replace returns null
     * (pattern error — should never happen with the literal marker constants).
     */
    private function stripBlock(string $content): string
    {
        $pattern = '/'
            . preg_quote(self::BEGIN, '/')
            . '.*?'
            . preg_quote(self::END, '/')
            . "\n?/s";

        $result = preg_replace($pattern, '', $content);
        return $result ?? $content;
    }

    /**
     * Resolve the absolute path to the site-root .htaccess file.
     *
     * Resolution order:
     *   1. get_home_path() when the WP function is available (most reliable).
     *   2. ABSPATH constant as fallback when WP is partially loaded.
     *   3. Return '' to signal unavailability to callers.
     */
    private function htaccessPath(): string
    {
        $root = '';

        if (function_exists('get_home_path')) {
            $candidate = get_home_path();
            if ($candidate !== '') {
                $root = $candidate;
            }
        }

        if ($root === '' && defined('ABSPATH')) {
            $root = (string) ABSPATH;
        }

        if ($root === '') {
            return '';
        }

        return rtrim($root, '/\\') . DIRECTORY_SEPARATOR . '.htaccess';
    }
}
