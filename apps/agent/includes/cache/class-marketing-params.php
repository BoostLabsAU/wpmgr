<?php
/**
 * MarketingParams — the canonical query-parameter classification lists used by
 * the page-cache key builder and the cacheability checks.
 *
 * Two factual lists drive cache fragmentation:
 *
 *   - IGNORE list (~70 marketing / analytics tracking params): these are
 *     STRIPPED before the query is hashed and are ALLOWED without breaking
 *     cacheability. Two visitors arriving with different `utm_*` / `gclid` /
 *     `fbclid` values see the SAME cached page (no fragmentation, no cache-bust).
 *
 *   - INCLUDE list (the small set of params that legitimately vary the rendered
 *     output — language, currency, WooCommerce sort/filter): these are KEPT in
 *     the query hash so each distinct value gets its own cache entry, and are
 *     ALLOWED through cacheability.
 *
 * Any query param that appears in NEITHER list makes a URL un-cacheable (an
 * unknown param is treated as a personalised / dynamic request — the same
 * conservative stance taken by Cache Enabler and WP Super Cache). Operators can
 * extend the INCLUDE list per-site via the stored config (handled by the
 * Cacheability class, not here).
 *
 * The marketing-param data is uncopyrightable factual information (the public
 * registry of advertising/analytics URL parameters published by Google, Meta,
 * Microsoft, Matomo/Piwik, Mailchimp, TikTok, etc.). This implementation is
 * original.
 *
 * @package WPMgr\Agent\Cache
 */

declare(strict_types=1);

namespace WPMgr\Agent\Cache;

/**
 * Static lookup tables for query-parameter cache classification.
 */
final class MarketingParams
{
    /**
     * Marketing / analytics tracking parameters that are stripped before hashing
     * and never fragment the cache. Roughly 70 entries spanning the major ad and
     * analytics ecosystems. Kept lowercase; lookups must lowercase the key first.
     *
     * @var list<string>
     */
    private const IGNORE = [
        // Google Ads / Analytics
        'gclid', 'gclsrc', 'gad_source', 'gad_campaignid', 'gbraid', 'wbraid',
        '_ga', '_gl', 'gdfms', 'gdftrk', 'gdffi', 'srsltid',
        // Generic UTM (Google Analytics campaign tagging)
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'utm_id', 'utm_expid',
        // Matomo / Piwik (mtm_ and pk_ variants)
        'mtm_source', 'mtm_medium', 'mtm_campaign', 'mtm_keyword', 'mtm_content',
        'mtm_cid', 'mtm_group', 'mtm_placement',
        'pk_source', 'pk_medium', 'pk_campaign', 'pk_keyword', 'pk_content',
        'pk_cid',
        // Microsoft / Bing Ads
        'msclkid',
        // Meta / Facebook
        'fbclid', 'fb_action_ids', 'fb_action_types', 'fb_source',
        // TikTok
        'ttclid',
        // Mailchimp
        'mc_cid', 'mc_eid',
        // HubSpot / generic
        '_hsenc', '_hsmi', 'hsa_cam', 'hsa_grp', 'hsa_mt', 'hsa_src', 'hsa_ad',
        'hsa_acc', 'hsa_net', 'hsa_kw', 'hsa_tgt', 'hsa_ver',
        // Generic ad-network click / campaign IDs
        'adgroupid', 'adid', 'campaignid', 'pcrid', 'mkwid', 'pmt', 'pp',
        // Affiliate / referral tracking
        'aff', 'ref', 'sscid', 's_kwcid', 'epik', 'dm_i', 'ef_id', 'kboard_id',
        // Email / CRM tracking
        'trk_contact', 'trk_msg', 'trk_module', 'trk_sid',
        // Redirect-log internal params (carried by some logging plugins)
        'redirect_log_mongo_id', 'redirect_mongo_id', 'sb_referer_host',
        // Optimisation bypass / state flags that must not fragment the disk cache
        'ao_noptimize', 'cn-reloaded', 'age-verified',
    ];

    /**
     * Parameters that DO vary the rendered page and therefore stay in the cache
     * key (each value gets its own cache file). These cover localisation and the
     * common WooCommerce catalog sort/filter params. Operators may extend this
     * per-site; the default set lives here.
     *
     * @var list<string>
     */
    private const INCLUDE = [
        'lang',
        'currency',
        'orderby',
        'max_price',
        'min_price',
        'rating_filter',
        'product_orderby',
        'product_count',
    ];

    /**
     * Cache-varying query-param NAME PREFIXES. WooCommerce's layered-nav /
     * faceted-catalog filters are emitted as `filter_<attribute>` (e.g.
     * `filter_color`, `filter_size`) where the attribute slug is store-specific
     * and therefore cannot be a fixed list. Any param whose lowercased name starts
     * with one of these prefixes legitimately varies the rendered catalog and so
     * is KEPT in the cache key (each filter combination gets its own entry) and is
     * ALLOWED through cacheability.
     *
     * @var list<string>
     */
    private const INCLUDE_PREFIXES = [
        'filter_',
        'query_type_',
    ];

    /**
     * The default ignore (marketing) list.
     *
     * @return list<string>
     */
    public static function ignoreList(): array
    {
        return self::IGNORE;
    }

    /**
     * The default include (cache-varying) list.
     *
     * @return list<string>
     */
    public static function includeList(): array
    {
        return self::INCLUDE;
    }

    /**
     * Whether a query parameter name is a known marketing / tracking param that
     * should be stripped before hashing. Case-insensitive.
     *
     * @param string $name Query parameter name.
     * @return bool
     */
    public static function isIgnored(string $name): bool
    {
        return in_array(strtolower($name), self::IGNORE, true);
    }

    /**
     * Whether a query parameter name is in the default cache-varying include
     * set. Case-insensitive.
     *
     * @param string $name Query parameter name.
     * @return bool
     */
    public static function isIncluded(string $name): bool
    {
        $lower = strtolower($name);
        if (in_array($lower, self::INCLUDE, true)) {
            return true;
        }
        foreach (self::INCLUDE_PREFIXES as $prefix) {
            if ($lower !== $prefix && str_starts_with($lower, $prefix)) {
                return true;
            }
        }
        return false;
    }
}
