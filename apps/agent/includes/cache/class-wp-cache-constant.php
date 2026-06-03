<?php
/**
 * WpCacheConstant — manages the `WP_CACHE` define in wp-config.php.
 *
 * WordPress only loads `wp-content/advanced-cache.php` when the `WP_CACHE`
 * constant is true. This thin wrapper over {@see WpConfigEditor} sets it on
 * cache enable and removes it on cache disable, idempotently and atomically.
 *
 * @package WPMgr\Agent\Cache
 */

declare(strict_types=1);

namespace WPMgr\Agent\Cache;

/**
 * Set / remove the WP_CACHE constant.
 */
final class WpCacheConstant
{
    /** The wp-config.php constant that gates advanced-cache.php loading. */
    public const NAME = 'WP_CACHE';

    private WpConfigEditor $editor;

    /**
     * @param WpConfigEditor|null $editor Injected for tests; defaults to a real one.
     */
    public function __construct(?WpConfigEditor $editor = null)
    {
        $this->editor = $editor ?? new WpConfigEditor();
    }

    /**
     * Ensure `define('WP_CACHE', true);` is present. Idempotent.
     *
     * @return bool True when wp-config.php ends with WP_CACHE === true.
     */
    public function enable(): bool
    {
        return $this->editor->setConstant(self::NAME, true);
    }

    /**
     * Remove the `WP_CACHE` define. Idempotent.
     *
     * @return bool
     */
    public function disable(): bool
    {
        return $this->editor->removeConstant(self::NAME);
    }

    /**
     * Whether wp-config.php can be edited (writability pre-check). Lets a command
     * surface a clear error instead of silently failing.
     *
     * @return bool
     */
    public function isWritable(): bool
    {
        return $this->editor->isWritable();
    }
}
