<?php
/**
 * ServerConfigWriter — writes / removes the WPMgr Security managed blocks in
 * the site-root .htaccess (Apache / LiteSpeed) and generates a display-only
 * nginx snippet.
 *
 * Block structure mirrors the HtaccessManager pattern in the cache suite:
 * delimited by exact BEGIN/END markers so blocks are cleanly replaceable and
 * never duplicated.
 *
 * Apache is the only server type where we auto-write; nginx sites need operator
 * action (the rules are shown in the dashboard as a snippet). The writer is
 * completely idempotent: re-running with the same config is a no-op.
 *
 * @package WPMgr\Agent\Security
 */

declare(strict_types=1);

namespace WPMgr\Agent\Security;

/**
 * Manages the "# BEGIN WPMgr Security" / "# END WPMgr Security" block in
 * the site-root .htaccess.
 */
final class ServerConfigWriter
{
    /** Opening marker — byte-exact for idempotency. */
    public const BEGIN = '# BEGIN WPMgr Security';

    /** Closing marker — byte-exact for idempotency. */
    public const END = '# END WPMgr Security';

    /**
     * Maximum number of IP/range ban rules written to server config.
     *
     * Overflow entries are still enforced at the PHP (mu-plugin) layer; we cap
     * here to keep .htaccess from growing unbounded on sites with large ban lists.
     */
    private const MAX_SERVER_BAN_RULES = 200;

    /**
     * Install / refresh the managed block. Returns false when the site is on
     * nginx (no .htaccess auto-write), when the path is unresolvable, or when
     * the file is not writable. Returns true when the block is in place (even
     * if we skipped the write because content is already current).
     *
     * @param HardeningConfig $config Validated hardening config.
     * @return bool Whether the block is (or was already) in place.
     */
    public function install(HardeningConfig $config): bool
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

        $updated = $this->renderInto($existing, $config);
        if ($updated === $existing) {
            return true; // already current
        }

        if (!@is_writable($path) && !@is_writable(dirname($path))) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- headless agent; WP_Filesystem never initialized; direct writability probe is the only option
            return false;
        }

        $result = @file_put_contents($path, $updated, LOCK_EX);
        return $result !== false;
    }

    /**
     * Remove the managed security block. Idempotent (absent => no-op true).
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
            return true; // not present
        }

        if (!@is_writable($path) && !@is_writable(dirname($path))) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- headless agent; WP_Filesystem never initialized; direct writability probe is the only option
            return false;
        }

        $result = @file_put_contents($path, $stripped, LOCK_EX);
        return $result !== false;
    }

    /**
     * Whether the site is using nginx (no .htaccess auto-write possible).
     *
     * @return bool
     */
    public function isNginx(): bool
    {
        if (!isset($_SERVER['SERVER_SOFTWARE']) || !is_string($_SERVER['SERVER_SOFTWARE'])) {
            return false;
        }
        return str_contains(
            strtolower(sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE']))), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via sanitize_text_field(wp_unslash())
            'nginx'
        );
    }

    /**
     * Pure transform: render the block into $existing. Used by tests without
     * disk access.
     *
     * @param string          $existing Current .htaccess content.
     * @param HardeningConfig $config   Validated config.
     * @return string
     */
    public function renderInto(string $existing, HardeningConfig $config): string
    {
        $body = $this->blockBody($config);

        if ($body === '') {
            // Nothing to write — strip any existing block and return.
            return $this->stripBlock($existing);
        }

        $block = self::BEGIN . "\n" . $body . "\n" . self::END;
        return $this->spliceBlock($existing, $block);
    }

    // -------------------------------------------------------------------------
    // Private block-body builder
    // -------------------------------------------------------------------------

    /**
     * Build the directive body (everything between the markers). Returns '' when
     * no toggles are active and there are no bans, so the block is cleanly
     * removed when all features are disabled.
     *
     * @param HardeningConfig $config
     * @return string
     */
    private function blockBody(HardeningConfig $config): string
    {
        $lines = [];

        // SSL redirect (Apache) — redirect http:// -> https:// + HSTS header.
        if ($config->forceSsl) {
            $lines[] = '<IfModule mod_rewrite.c>';
            $lines[] = '    RewriteEngine On';
            $lines[] = '    RewriteCond %{HTTPS} off';
            $lines[] = '    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]';
            $lines[] = '</IfModule>';
            $lines[] = '<IfModule mod_headers.c>';
            $lines[] = '    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"';
            $lines[] = '</IfModule>';
        }

        // Directory browsing off.
        if ($config->disableDirectoryBrowsing) {
            $lines[] = 'Options -Indexes';
        }

        // Block PHP execution in uploads directory.
        if ($config->disablePhpInUploads) {
            $lines[] = '<IfModule mod_rewrite.c>';
            $lines[] = '    RewriteEngine On';
            $lines[] = '    RewriteCond %{REQUEST_URI} /wp-content/uploads/ [NC]';
            $lines[] = '    RewriteRule \.php$ - [F,L]';
            $lines[] = '</IfModule>';
        }

        // Protect system files.
        if ($config->protectSystemFiles) {
            $lines[] = '<FilesMatch "^(wp-config\.php|\.htaccess|\.htpasswd|readme\.html|readme\.txt|license\.txt|debug\.log)$">';
            $lines[] = '    Require all denied';
            $lines[] = '    Order deny,allow';
            $lines[] = '    Deny from all';
            $lines[] = '</FilesMatch>';
        }

        // XML-RPC off at server level.
        if ($config->xmlrpcMode === HardeningConfig::XMLRPC_OFF) {
            $lines[] = '<Files "xmlrpc.php">';
            $lines[] = '    Require all denied';
            $lines[] = '    Order deny,allow';
            $lines[] = '    Deny from all';
            $lines[] = '</Files>';
        }

        // IP/range bans (capped to avoid unbounded .htaccess growth).
        $ipBans = array_slice($config->ipRangeBans(), 0, self::MAX_SERVER_BAN_RULES);
        if ($ipBans !== []) {
            $lines[] = '<IfModule mod_authz_core.c>';
            $lines[] = '    <RequireAll>';
            $lines[] = '        Require all granted';
            foreach ($ipBans as $cidr) {
                $cidr = trim($cidr);
                if ($cidr !== '') {
                    $lines[] = '        Require not ip ' . $cidr;
                }
            }
            $lines[] = '    </RequireAll>';
            $lines[] = '</IfModule>';
            // Legacy Apache 2.2 fallback.
            $lines[] = '<IfModule !mod_authz_core.c>';
            $lines[] = '    Order allow,deny';
            $lines[] = '    Allow from all';
            foreach ($ipBans as $cidr) {
                $cidr = trim($cidr);
                if ($cidr !== '') {
                    $lines[] = '    Deny from ' . $cidr;
                }
            }
            $lines[] = '</IfModule>';
        }

        // User-agent bans.
        $uaBans = $config->userAgentBans();
        if ($uaBans !== []) {
            $lines[] = '<IfModule mod_rewrite.c>';
            $lines[] = '    RewriteEngine On';
            foreach ($uaBans as $ua) {
                $ua = trim($ua);
                if ($ua !== '') {
                    // Escape special regex characters in the UA string.
                    $escaped = preg_quote($ua, '!');
                    $lines[] = '    RewriteCond %{HTTP_USER_AGENT} !' . $escaped . ' [NC]';
                }
            }
            $lines[] = '    RewriteRule ^ - [F,L]';
            $lines[] = '</IfModule>';
        }

        if ($lines === []) {
            return '';
        }

        return implode("\n", $lines);
    }

    // -------------------------------------------------------------------------
    // Block splice helpers (mirror HtaccessManager pattern)
    // -------------------------------------------------------------------------

    /**
     * Strip the managed security block (BEGIN..END inclusive).
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
     * Splice the fresh block BEFORE the WordPress block (if present) or prepend
     * it to the existing content. Strips any prior copy first.
     *
     * @param string $content Existing .htaccess content.
     * @param string $block   Fully-formed BEGIN...END block.
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
     * Resolve the site-root .htaccess path.
     *
     * @return string Absolute path, or '' when unresolvable.
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
}
