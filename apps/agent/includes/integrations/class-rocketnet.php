<?php
/**
 * RocketNet — purge Rocket.net's edge CDN cache.
 *
 * Rocket.net runs an edge cache cleared by its `CDN_Clear_Cache_Hooks` plugin,
 * whose static purge_cache() flushes the zone and purge_cache_url($url) clears a
 * single page. We call its OWN class so the purge runs through Rocket.net's
 * supported path.
 *
 * Detection: the `CDN_Clear_Cache_Hooks` class (Rocket.net's own signal).
 * No-op otherwise.
 *
 * Original implementation.
 *
 * @package WPMgr\Agent\Integrations
 */

declare(strict_types=1);

namespace WPMgr\Agent\Integrations;

/**
 * Rocket.net edge CDN purger.
 */
final class RocketNet extends Integration
{
    /**
     * @return bool
     */
    protected function detect(): bool
    {
        return class_exists('\CDN_Clear_Cache_Hooks');
    }

    /**
     * @return void
     */
    protected function purgeAll(): void
    {
        $class = '\CDN_Clear_Cache_Hooks';
        if (class_exists($class) && method_exists($class, 'purge_cache')) {
            $class::purge_cache();
        }
    }

    /**
     * @param list<string> $urls Validated absolute URLs.
     * @return void
     */
    protected function purgeUrls(array $urls): void
    {
        $class = '\CDN_Clear_Cache_Hooks';
        if (class_exists($class) && method_exists($class, 'purge_cache_url')) {
            foreach ($urls as $url) {
                $class::purge_cache_url($url);
            }
            return;
        }
        $this->purgeAll();
    }
}
