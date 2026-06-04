<?php
/**
 * EcosystemPresets — auto-detects i18n (multi-language) and multi-currency
 * plugins and derives the cache-varying cookies / query params each one needs so
 * that language and currency variants of a page cache SEPARATELY instead of one
 * variant being served to everyone.
 *
 * The problem this solves: a multi-currency or multi-language site renders DIFFERENT
 * HTML for the same URL depending on a cookie (e.g. WPML's
 * `wp-wpml_current_language`, WOOCS's `woocommerce_current_currency`) or a query
 * param (e.g. `?lang=fr`). Unless those cookies/params fragment the cache key,
 * the first visitor's currency/language is frozen into the shared cache file and
 * served to everyone — a correctness bug, not just a perf one.
 *
 * Detection is by the canonical class / function / constant / global each plugin
 * defines (the same identifiers their own docs publish). When a plugin is
 * detected its cookies/queries are contributed as DEFAULTS, merged UNDER the
 * operator's configured `include_cookies` / `include_queries` (operator config
 * always wins, we never silently override it). Only the
 * public identifier names are borrowed (uncopyrightable facts).
 *
 * @package WPMgr\Agent\Cache
 */

declare(strict_types=1);

namespace WPMgr\Agent\Cache;

/**
 * Detects i18n / multi-currency plugins and the cache key fragments they require.
 */
final class EcosystemPresets
{
    /**
     * Optional detection override (slug-label => bool) used by tests to simulate
     * a plugin being active WITHOUT defining a real, process-wide constant/class
     * that would leak into other test cases. Keyed by the entry label. Null in
     * production — the live constant/class/function probes run unchanged. This is
     * the ONLY way detection is influenced for tests; it has no production effect.
     *
     * @var array<string,bool>|null
     */
    private static ?array $detectOverride = null;

    /**
     * Test hook: force the detection result for a set of plugin labels. Pass null
     * to clear and return to live detection. Production code never calls this.
     *
     * @param array<string,bool>|null $map Label => active flag, or null to reset.
     * @return void
     */
    public static function overrideDetectionForTests(?array $map): void
    {
        self::$detectOverride = $map;
    }

    /**
     * Resolve whether a table entry is active, honouring the test override when
     * one is set for that entry's label, else running its live detector.
     *
     * @param array{label:string,detect:callable():bool} $entry Table entry.
     * @return bool
     */
    private static function entryActive(array $entry): bool
    {
        if (self::$detectOverride !== null) {
            return (bool) (self::$detectOverride[$entry['label']] ?? false);
        }
        try {
            return ($entry['detect'])();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Each entry: a human label, a detection predicate, the cache-varying COOKIE
     * names it introduces, and the cache-varying QUERY params it introduces.
     *
     * Detection identifiers (classes/functions/constants/globals) are the public
     * ones each plugin ships; this table is data, the merge logic is below.
     *
     * @return list<array{label:string,kind:string,detect:callable():bool,cookies:list<string>,queries:list<string>}>
     */
    private static function table(): array
    {
        return [
            // -------- Multi-currency --------
            [
                'label'   => 'WPML Multi-Currency (WCML)',
                'kind'    => 'currency',
                'detect'  => static fn (): bool => class_exists('woocommerce_wpml') || defined('WCML_VERSION'),
                'cookies' => ['wcml_client_currency', 'woocommerce_current_currency'],
                'queries' => ['currency'],
            ],
            [
                'label'   => 'WOOCS / FOX Currency Switcher',
                'kind'    => 'currency',
                // WOOCS exposes the global $WOOCS and the WOOCS class.
                'detect'  => static fn (): bool => class_exists('WOOCS') || isset($GLOBALS['WOOCS']),
                'cookies' => ['woocommerce_current_currency', 'aelia_cs_selected_currency'],
                'queries' => ['currency'],
            ],
            [
                'label'   => 'Aelia Currency Switcher',
                'kind'    => 'currency',
                'detect'  => static fn (): bool =>
                    class_exists('\\Aelia\\WC\\CurrencySwitcher\\WC_Aelia_CurrencySwitcher')
                    || class_exists('WC_Aelia_CurrencySwitcher'),
                'cookies' => ['aelia_cs_selected_currency', 'aelia_customer_country', 'aelia_customer_state'],
                'queries' => ['aelia_cs_currency', 'currency'],
            ],
            [
                'label'   => 'YITH Multi Currency',
                'kind'    => 'currency',
                'detect'  => static fn (): bool =>
                    defined('YITH_WCMCS_VERSION') || class_exists('YITH_WCMCS'),
                'cookies' => ['yith_wcmcs_currency'],
                'queries' => ['currency'],
            ],
            [
                'label'   => 'CURCY / WooCommerce Multi-Currency',
                'kind'    => 'currency',
                'detect'  => static fn (): bool =>
                    defined('WOOMULTI_CURRENCY_F_VERSION') || class_exists('WOOMULTI_CURRENCY_Data'),
                'cookies' => ['wmc_current_currency', 'woocommerce_current_currency'],
                'queries' => ['wmc-currency', 'currency'],
            ],

            // -------- Multi-language --------
            [
                'label'   => 'WPML',
                'kind'    => 'language',
                'detect'  => static fn (): bool =>
                    defined('ICL_SITEPRESS_VERSION') || isset($GLOBALS['sitepress']),
                'cookies' => ['wp-wpml_current_language', '_icl_current_language'],
                'queries' => ['lang'],
            ],
            [
                'label'   => 'Polylang',
                'kind'    => 'language',
                'detect'  => static fn (): bool =>
                    defined('POLYLANG_VERSION') || function_exists('pll_current_language'),
                'cookies' => ['pll_language'],
                'queries' => ['lang'],
            ],
            [
                'label'   => 'TranslatePress',
                'kind'    => 'language',
                'detect'  => static fn (): bool =>
                    class_exists('TRP_Translate_Press') || defined('TRP_PLUGIN_VERSION'),
                'cookies' => ['trp_language'],
                'queries' => ['trp-edit-translation', 'lang'],
            ],
            [
                'label'   => 'Weglot',
                'kind'    => 'language',
                'detect'  => static fn (): bool =>
                    defined('WEGLOT_VERSION') || function_exists('weglot_get_current_language'),
                'cookies' => [],
                'queries' => ['wg-choose-original'],
            ],
        ];
    }

    /**
     * The labels of every currency/language plugin detected as active, with its
     * kind. Surfaced (not silently applied) so operators/dashboards can see what
     * drove the auto-preset.
     *
     * @return list<array{label:string,kind:string}>
     */
    public static function detected(): array
    {
        $out = [];
        foreach (self::table() as $entry) {
            if (self::entryActive($entry)) {
                $out[] = ['label' => $entry['label'], 'kind' => $entry['kind']];
            }
        }
        return $out;
    }

    /**
     * The cookie names contributed by every detected plugin (de-duplicated).
     *
     * @return list<string>
     */
    public static function presetCookies(): array
    {
        return self::collect('cookies');
    }

    /**
     * The query params contributed by every detected plugin (de-duplicated).
     *
     * @return list<string>
     */
    public static function presetQueries(): array
    {
        return self::collect('queries');
    }

    /**
     * Merge the detected presets UNDER an operator-configured list: operator
     * entries come first (and win on de-dup), detected presets are appended as
     * defaults. Case-insensitive de-dup, order-stable.
     *
     * @param 'cookies'|'queries' $kind     Which preset dimension to merge.
     * @param list<string>        $operator Operator-configured values.
     * @return list<string> Effective list (operator first, then presets).
     */
    public static function mergeInto(string $kind, array $operator): array
    {
        $presets = $kind === 'cookies' ? self::presetCookies() : self::presetQueries();
        return self::dedupe(array_merge($operator, $presets));
    }

    /**
     * Effective include-cookies for the cache key: operator config + detected
     * i18n/currency cookies.
     *
     * @param list<string> $operatorCookies Operator-configured include cookies.
     * @return list<string>
     */
    public static function effectiveIncludeCookies(array $operatorCookies): array
    {
        return self::mergeInto('cookies', $operatorCookies);
    }

    /**
     * Effective include-queries for the cache key: operator config + detected
     * i18n/currency query params.
     *
     * @param list<string> $operatorQueries Operator-configured include queries.
     * @return list<string>
     */
    public static function effectiveIncludeQueries(array $operatorQueries): array
    {
        return self::mergeInto('queries', $operatorQueries);
    }

    /**
     * Collect a single field across every detected entry.
     *
     * @param 'cookies'|'queries' $field Field key.
     * @return list<string>
     */
    private static function collect(string $field): array
    {
        $out = [];
        foreach (self::table() as $entry) {
            if (!self::entryActive($entry)) {
                continue;
            }
            foreach ($entry[$field] as $value) {
                $out[] = (string) $value;
            }
        }
        return self::dedupe($out);
    }

    /**
     * De-duplicate a string list case-insensitively, dropping empties, stable
     * order.
     *
     * @param list<string> $values Input values.
     * @return list<string>
     */
    private static function dedupe(array $values): array
    {
        $out  = [];
        $seen = [];
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            $lower = strtolower($value);
            if (isset($seen[$lower])) {
                continue;
            }
            $seen[$lower] = true;
            $out[]        = $value;
        }
        return $out;
    }
}
