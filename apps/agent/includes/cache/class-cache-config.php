<?php
/**
 * CacheConfig — the typed page-cache configuration value object.
 *
 * The CP pushes the page-cache settings via the `perf.config.update` command;
 * this object is the in-memory shape the manager, writer, drop-in renderer, and
 * htaccess manager all consume. It is also the exact array that gets var_export-
 * inlined into the serving drop-in (so the drop-in needs zero DB load).
 *
 * @package WPMgr\Agent\Cache
 */

declare(strict_types=1);

namespace WPMgr\Agent\Cache;

/**
 * Immutable page-cache configuration.
 */
final class CacheConfig
{
    /** Master enable flag for page caching. */
    public bool $enabled;

    /** Whether logged-in users get cached (role-segmented) pages. */
    public bool $cacheLoggedIn;

    /** Whether a separate mobile cache bucket is maintained. */
    public bool $cacheMobile;

    /** Auto-purge + preload changed URLs on content updates. */
    public bool $autoPurge;

    /** Scheduled full-cache refresh interval in seconds (0 = disabled). */
    public int $refreshInterval;

    /**
     * Extra cache-varying query params (operator additions to the include list).
     *
     * @var list<string>
     */
    public array $includeQueries;

    /**
     * Cookie names whose values fragment the cache, in order.
     *
     * @var list<string>
     */
    public array $includeCookies;

    /**
     * URL substrings that bypass the cache.
     *
     * @var list<string>
     */
    public array $bypassUrls;

    /**
     * Cookie-name keywords that bypass the cache.
     *
     * @var list<string>
     */
    public array $bypassCookies;

    /**
     * Operator-configured include-queries, BEFORE i18n/currency presets are folded
     * in. Persisted as-is (so round-trips don't bake auto-detected presets into the
     * stored config) while {@see $includeQueries} carries the effective set.
     *
     * @var list<string>
     */
    public array $operatorIncludeQueries;

    /**
     * Operator-configured include-cookies, BEFORE i18n/currency presets are folded
     * in. See {@see $operatorIncludeQueries}.
     *
     * @var list<string>
     */
    public array $operatorIncludeCookies;

    /**
     * @param array<string,mixed> $data Raw config map (from storage or CP).
     */
    public function __construct(array $data = [])
    {
        $this->enabled         = (bool) ($data['enabled'] ?? false);
        $this->cacheLoggedIn   = (bool) ($data['cache_logged_in'] ?? false);
        $this->cacheMobile     = (bool) ($data['cache_mobile'] ?? false);
        $this->autoPurge       = (bool) ($data['auto_purge'] ?? true);
        $this->refreshInterval = max(0, (int) ($data['refresh_interval'] ?? 0));

        $this->operatorIncludeQueries = self::stringList($data['include_queries'] ?? []);
        $this->operatorIncludeCookies = self::stringList($data['include_cookies'] ?? []);

        // Fold in the auto-detected i18n/multi-currency presets so language and
        // currency variants fragment the cache key WITHOUT the operator having to
        // hand-configure each plugin's cookie/param. Operator config still wins
        // (it is merged first); presets are appended as defaults. The unmerged
        // operator lists are preserved above so {@see toArray()} round-trips the
        // operator's intent, not the auto-detected set.
        $this->includeQueries  = EcosystemPresets::effectiveIncludeQueries($this->operatorIncludeQueries);
        $this->includeCookies  = EcosystemPresets::effectiveIncludeCookies($this->operatorIncludeCookies);

        $this->bypassUrls      = self::stringList($data['bypass_urls'] ?? []);
        $this->bypassCookies   = self::stringList($data['bypass_cookies'] ?? []);
    }

    /**
     * The array form inlined into the drop-in (lean: only the keys the serving
     * fast path reads). Marketing-ignore params are added here so the drop-in
     * does not need the MarketingParams class.
     *
     * @return array<string,mixed>
     */
    public function toDropinArray(): array
    {
        return [
            'cache_logged_in'  => $this->cacheLoggedIn,
            'cache_mobile'     => $this->cacheMobile,
            'include_cookies'  => $this->includeCookies,
            // The baked-in default "always-bypass" cookies (WooCommerce/EDD cart,
            // session, logged-in, password, comment-author) are merged with the
            // operator list via the SAME helper the PHP cacheability path uses, so
            // the pre-WP drop-in and the PHP write layer bypass an identical set.
            // This is what prevents a logged-out cart page from ever being served
            // from (or written to) the shared disk cache.
            'bypass_cookies'   => Cacheability::effectiveBypassCookies($this->bypassCookies),
            'ignore_queries'   => MarketingParams::ignoreList(),
        ];
    }

    /**
     * Full serialisable form (storage + CP round-trips).
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'enabled'          => $this->enabled,
            'cache_logged_in'  => $this->cacheLoggedIn,
            'cache_mobile'     => $this->cacheMobile,
            'auto_purge'       => $this->autoPurge,
            'refresh_interval' => $this->refreshInterval,
            // Persist the OPERATOR-configured lists, not the preset-folded effective
            // ones, so a save→load round-trip never bakes auto-detected i18n/currency
            // presets into stored config (they are re-derived live on each load).
            'include_queries'  => $this->operatorIncludeQueries,
            'include_cookies'  => $this->operatorIncludeCookies,
            'bypass_urls'      => $this->bypassUrls,
            'bypass_cookies'   => $this->bypassCookies,
        ];
    }

    /**
     * Coerce a mixed value into a clean list of non-empty strings.
     *
     * @param mixed $value Candidate list.
     * @return list<string>
     */
    private static function stringList($value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_scalar($item)) {
                $s = trim((string) $item);
                if ($s !== '') {
                    $out[] = $s;
                }
            }
        }
        return array_values(array_unique($out));
    }
}
