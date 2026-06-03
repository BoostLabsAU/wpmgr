<?php
/**
 * DropinInstaller — installs and removes the page-cache serving drop-in
 * (advanced-cache.php) in wp-content.
 *
 * The drop-in template lives at assets/wpmgr-advanced-cache.php with a
 * `CONFIG_TO_REPLACE` placeholder. On install we render the live config into
 * the placeholder (so the drop-in is self-contained and needs zero plugin/DB
 * load on the serving fast path) and write it to wp-content.
 *
 * WP Engine quirk: on Atomic / EverCache hosts (detected by the presence of the
 * `Atomic_Persistent_Data` class) WP Engine ships its OWN advanced-cache.php, so
 * we MUST NOT overwrite it. There we install under the alternate filename
 * `wpmgr-advanced-cache.php` and chain to it; on every other host we install the
 * canonical `advanced-cache.php`.
 *
 * Standard disk-cache drop-in technique (Cache Enabler / WP Super Cache, GPLv2).
 * Original implementation.
 *
 * @package WPMgr\Agent\Cache
 */

declare(strict_types=1);

namespace WPMgr\Agent\Cache;

/**
 * Installs / removes the advanced-cache.php serving drop-in.
 */
final class DropinInstaller
{
    /** Canonical WordPress drop-in filename. */
    public const CANONICAL = 'advanced-cache.php';

    /** Alternate filename used on WP Engine Atomic hosts. */
    public const WPENGINE = 'wpmgr-advanced-cache.php';

    /** Placeholder in the template replaced with the live config var_export. */
    public const CONFIG_PLACEHOLDER = 'CONFIG_TO_REPLACE';

    /** Signature line proving a drop-in on disk is ours (safe to overwrite/remove). */
    public const SIGNATURE = 'WPMgr Page Cache drop-in';

    /** Absolute path to the wp-content directory. */
    private string $contentDir;

    /** Absolute path to the template source file. */
    private string $templatePath;

    /**
     * @param string|null $contentDir   wp-content path (defaults to WP_CONTENT_DIR).
     * @param string|null $templatePath Drop-in template path (defaults to the plugin asset).
     */
    public function __construct(?string $contentDir = null, ?string $templatePath = null)
    {
        if ($contentDir !== null) {
            $this->contentDir = rtrim($contentDir, '/\\');
        } elseif (defined('WP_CONTENT_DIR')) {
            $this->contentDir = rtrim((string) constant('WP_CONTENT_DIR'), '/\\');
        } else {
            $this->contentDir = '';
        }

        if ($templatePath !== null) {
            $this->templatePath = $templatePath;
        } elseif (defined('WPMGR_AGENT_DIR')) {
            $this->templatePath = rtrim((string) constant('WPMGR_AGENT_DIR'), '/\\')
                . '/assets/wpmgr-advanced-cache.php';
        } else {
            $this->templatePath = '';
        }
    }

    /**
     * Whether this host ships its own advanced-cache.php (WP Engine Atomic), in
     * which case we install under the alternate filename to avoid clobbering it.
     *
     * @return bool
     */
    public function isWpEngine(): bool
    {
        return class_exists('Atomic_Persistent_Data');
    }

    /**
     * The drop-in filename appropriate for this host.
     *
     * @return string
     */
    public function dropinFilename(): string
    {
        return $this->isWpEngine() ? self::WPENGINE : self::CANONICAL;
    }

    /**
     * Absolute path the drop-in is installed at on this host.
     *
     * @return string
     */
    public function dropinPath(): string
    {
        if ($this->contentDir === '') {
            return '';
        }
        return $this->contentDir . '/' . $this->dropinFilename();
    }

    /**
     * Render the drop-in source with the live config inlined.
     *
     * @param array<string,mixed> $config Serialisable cache config.
     * @return string The rendered PHP source (empty string when the template is unreadable).
     */
    public function render(array $config): string
    {
        if ($this->templatePath === '' || !@is_file($this->templatePath)) {
            return '';
        }
        $template = @file_get_contents($this->templatePath);
        if ($template === false) {
            return '';
        }

        $export = var_export($config, true);

        return str_replace(self::CONFIG_PLACEHOLDER, $export, $template);
    }

    /**
     * Install (or refresh) the drop-in with the given config. Idempotent: a
     * byte-identical existing drop-in is left untouched.
     *
     * Refuses to overwrite a foreign advanced-cache.php (one that is NOT ours and
     * NOT on a WP Engine host) — that signals another cache plugin owns it.
     *
     * @param array<string,mixed> $config Cache config to inline.
     * @return bool True on success or when already current.
     */
    public function install(array $config): bool
    {
        $path = $this->dropinPath();
        if ($path === '') {
            return false;
        }

        $rendered = $this->render($config);
        if ($rendered === '') {
            return false;
        }

        // On non-WP-Engine hosts, never clobber a foreign canonical drop-in.
        if (!$this->isWpEngine() && @is_file($path)) {
            $existing = @file_get_contents($path);
            if ($existing !== false
                && strpos($existing, self::SIGNATURE) === false
                && trim($existing) !== ''
            ) {
                return false; // another cache plugin owns advanced-cache.php
            }
            if ($existing === $rendered) {
                return true; // already current
            }
        }

        if (!@is_writable($this->contentDir) && !(@is_file($path) && @is_writable($path))) {
            return false;
        }

        $result = @file_put_contents($path, $rendered, LOCK_EX);
        return $result !== false;
    }

    /**
     * Remove the drop-in if (and only if) it is ours. Idempotent.
     *
     * @return bool True when the drop-in is absent or successfully removed.
     */
    public function uninstall(): bool
    {
        $path = $this->dropinPath();
        if ($path === '' || !@is_file($path)) {
            return true;
        }

        $existing = @file_get_contents($path);
        if ($existing === false) {
            return true;
        }
        // Only delete a drop-in we recognise as ours.
        if (strpos($existing, self::SIGNATURE) === false) {
            return true;
        }

        return @unlink($path);
    }
}
