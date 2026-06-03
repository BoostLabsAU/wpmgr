<?php
/**
 * AssetCache — the on-disk store for optimizer-derived assets (minified CSS/JS,
 * self-hosted third-party files, self-hosted Google Fonts, gravatars).
 *
 * Lives under the SAME cache root as the page cache (…/wp-content/cache/wpmgr)
 * in a dedicated `assets/` subdir so a page-cache purge that only removes
 * `*.html.gz` leaves the minified assets intact (matching the Phase-3 Purge
 * semantics). Files are content-addressed (12-hex prefix of an md5 over the
 * source bytes + a salt), so identical input never re-minifies and a CDN/host
 * change produces a fresh name.
 *
 * Writes are atomic (temp + rename). All operations degrade to inert no-ops
 * when the cache root cannot be resolved (e.g. unit tests without WP_CONTENT_DIR).
 *
 * @package WPMgr\Agent\Optimizer
 */

declare(strict_types=1);

namespace WPMgr\Agent\Optimizer;

/**
 * Content-addressed disk store for optimizer assets.
 */
final class AssetCache
{
    /** Absolute assets dir (…/cache/wpmgr/assets). '' when unresolved. */
    private string $dir;

    /** Public URL base for the assets dir. '' when unresolved. */
    private string $url;

    /**
     * @param string|null $dir Override dir (tests). Default resolves from WP.
     * @param string|null $url Override URL base (tests). Default resolves from WP.
     */
    public function __construct(?string $dir = null, ?string $url = null)
    {
        $this->dir = $dir !== null ? rtrim($dir, '/\\') : self::resolveDir();
        $this->url = $url !== null ? rtrim($url, '/') : self::resolveUrl();
    }

    /**
     * Whether the cache is usable (root resolved + creatable).
     *
     * @return bool
     */
    public function isUsable(): bool
    {
        return $this->dir !== '' && $this->url !== '';
    }

    /**
     * Content-addressed file name: {12-hex}.{label}.{ext}.
     *
     * @param string $content Source bytes.
     * @param string $label   Stable label (e.g. original basename).
     * @param string $ext     Extension without dot.
     * @param string $salt    Extra cache-busting salt (CDN url, etc).
     * @return string
     */
    public function name(string $content, string $label, string $ext, string $salt = ''): string
    {
        $hash  = substr(md5($content . "\0" . $salt), 0, 12);
        $label = self::sanitizeLabel($label);
        $ext   = preg_replace('/[^a-z0-9]/i', '', $ext) ?? '';
        return $label === ''
            ? $hash . '.' . $ext
            : $hash . '.' . $label . '.' . $ext;
    }

    /**
     * Absolute path for a name.
     *
     * @param string $name File name.
     * @return string '' when the cache is unusable.
     */
    public function path(string $name): string
    {
        return $this->dir === '' ? '' : $this->dir . '/' . $name;
    }

    /**
     * Public URL for a name.
     *
     * @param string $name File name.
     * @return string '' when the cache is unusable.
     */
    public function url(string $name): string
    {
        return $this->url === '' ? '' : $this->url . '/' . $name;
    }

    /**
     * Does a cached file already exist?
     *
     * @param string $name File name.
     * @return bool
     */
    public function exists(string $name): bool
    {
        $path = $this->path($name);
        return $path !== '' && is_file($path);
    }

    /**
     * Atomically write bytes to a cached file.
     *
     * @param string $name  File name.
     * @param string $bytes Content.
     * @return bool True on success.
     */
    public function write(string $name, string $bytes): bool
    {
        if (!$this->isUsable()) {
            return false;
        }
        if (!@is_dir($this->dir) && !@mkdir($this->dir, 0o755, true) && !@is_dir($this->dir)) {
            return false;
        }
        $path = $this->dir . '/' . $name;
        $tmp  = $path . '.tmp-' . getmypid() . '-' . mt_rand();
        if (@file_put_contents($tmp, $bytes, LOCK_EX) === false) {
            @unlink($tmp);
            return false;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /**
     * Read a cached file's bytes (null when absent).
     *
     * @param string $name File name.
     * @return string|null
     */
    public function read(string $name): ?string
    {
        $path = $this->path($name);
        if ($path === '' || !is_file($path)) {
            return null;
        }
        $bytes = @file_get_contents($path);
        return $bytes === false ? null : $bytes;
    }

    /**
     * Resolve the assets dir from WP_CONTENT_DIR.
     *
     * @return string
     */
    private static function resolveDir(): string
    {
        $content = defined('WP_CONTENT_DIR') ? rtrim((string) constant('WP_CONTENT_DIR'), '/\\') : '';
        return $content === '' ? '' : $content . '/cache/wpmgr/assets';
    }

    /**
     * Resolve the public URL base from WP_CONTENT_URL (or content_url()).
     *
     * @return string
     */
    private static function resolveUrl(): string
    {
        $base = '';
        if (defined('WP_CONTENT_URL')) {
            $base = rtrim((string) constant('WP_CONTENT_URL'), '/');
        } elseif (function_exists('content_url')) {
            $base = rtrim((string) content_url(), '/');
        }
        return $base === '' ? '' : $base . '/cache/wpmgr/assets';
    }

    /**
     * Make a label safe for a file name.
     *
     * @param string $label Raw label.
     * @return string
     */
    private static function sanitizeLabel(string $label): string
    {
        $label = preg_replace('/\.(min\.)?(css|js)$/i', '', $label) ?? $label;
        $label = preg_replace('/[^a-z0-9._-]/i', '-', $label) ?? '';
        return trim($label, '-.');
    }
}
