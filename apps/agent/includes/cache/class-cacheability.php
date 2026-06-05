<?php
/**
 * Cacheability — decides whether a request/response may be served from, or
 * written to, the page cache.
 *
 * Two entry points:
 *
 *   isUrlCacheable($url)            — cheap, URL-only. Used by the preloader and
 *                                     auto-purge to decide which URLs to warm.
 *   isRequestCacheable(...)         — full request+response gate. Used at the
 *                                     output-buffer flush before writing a page.
 *
 * The checks follow the standard WordPress disk-cache exclusion set.
 *
 * @package WPMgr\Agent\Cache
 */

declare(strict_types=1);

namespace WPMgr\Agent\Cache;

/**
 * Request/URL cacheability predicates.
 */
final class Cacheability
{
    /**
     * Path fragments that are never cacheable (admin, auth, API, system).
     * Matched case-insensitively against the URL path.
     */
    private const UNCACHEABLE_PATH_PATTERN =
        '#/wp-(admin|login|register|comments-post|cron|json)|/xmlrpc\.php|\.(txt|xml|rss|atom)(/|$)|/sitemap#i';

    /**
     * Cookie name-prefixes that ALWAYS bypass the cache, baked in (NOT operator-
     * configured). These mark a request as personalised — a WooCommerce/EDD cart,
     * a logged-in session, a password-protected page, or a comment author — and
     * serving such a request from (or writing it to) a shared cache leaks one
     * visitor's cart/session to the next. They are merged with the operator
     * `bypass_cookies` everywhere a bypass decision is made (here AND in the
     * inlined drop-in config), so the two paths stay behaviorally identical.
     *
     * Matched as a case-insensitive SUBSTRING of the cookie name, so a prefix
     * such as `wp_woocommerce_session_` covers every per-store-hash variant.
     *
     * @var list<string>
     */
    public const DEFAULT_BYPASS_COOKIES = [
        'woocommerce_items_in_cart',
        'woocommerce_cart_hash',
        'wp_woocommerce_session_',
        'edd_items_in_cart',
        'wp-postpass_',
        'comment_author_',
        'wordpress_logged_in_',
    ];

    /**
     * Merge the baked-in default bypass cookies with an operator-supplied list,
     * de-duplicated (case-insensitively) and order-stable (defaults first). This
     * is the single source of truth used by BOTH the PHP cacheability path and
     * what {@see CacheConfig::toDropinArray()} inlines into the serving drop-in,
     * guaranteeing the pre-WP fast path and the PHP write layer bypass the exact
     * same cookie set.
     *
     * @param list<string> $operator Operator-configured bypass cookies.
     * @return list<string> Effective bypass-cookie list.
     */
    public static function effectiveBypassCookies(array $operator): array
    {
        $out  = [];
        $seen = [];
        foreach (array_merge(self::DEFAULT_BYPASS_COOKIES, $operator) as $cookie) {
            $cookie = trim((string) $cookie);
            if ($cookie === '') {
                continue;
            }
            $lower = strtolower($cookie);
            if (isset($seen[$lower])) {
                continue;
            }
            $seen[$lower] = true;
            $out[]        = $cookie;
        }
        return $out;
    }

    /**
     * Extra cache-varying query params configured per-site (operator).
     *
     * @var list<string>
     */
    private array $extraIncludeQueries;

    /**
     * Substring keywords that, if present in a URL, bypass the cache.
     *
     * @var list<string>
     */
    private array $bypassUrls;

    /**
     * Keywords matched against cookie NAMES to bypass the cache.
     *
     * @var list<string>
     */
    private array $bypassCookies;

    /**
     * @param list<string> $extraIncludeQueries Operator-added cache-varying query params.
     * @param list<string> $bypassUrls          Substrings that disable caching for a URL.
     * @param list<string> $bypassCookies       Cookie-name keywords that disable caching.
     */
    public function __construct(
        array $extraIncludeQueries = [],
        array $bypassUrls = [],
        array $bypassCookies = []
    ) {
        $this->extraIncludeQueries = array_values(array_filter(
            array_map('strval', $extraIncludeQueries),
            static fn (string $s): bool => $s !== ''
        ));
        $this->bypassUrls = array_values(array_filter(
            array_map('strval', $bypassUrls),
            static fn (string $s): bool => $s !== ''
        ));
        // The baked-in default "always-bypass" cookies (cart/session/logged-in/
        // password/comment-author) are ALWAYS merged in, so a logged-out cart
        // request is non-cacheable even when the operator configured nothing.
        $operatorBypass = array_values(array_filter(
            array_map('strval', $bypassCookies),
            static fn (string $s): bool => $s !== ''
        ));
        $this->bypassCookies = self::effectiveBypassCookies($operatorBypass);
    }

    /**
     * Cheap URL-only cacheability (preload / purge planning).
     *
     * Rejects when: an explicit no-cache flag is present; the path is an
     * admin/auth/API/system path; the URL matches a bypass keyword; or the query
     * contains any param that is neither a known marketing param nor a known
     * cache-varying param (an unknown param ⇒ dynamic, uncacheable).
     *
     * @param string $url Absolute or path-relative URL.
     * @return bool
     */
    public function isUrlCacheable(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        // Explicit per-request opt-out.
        if (stripos($url, 'no_optimize') !== false || stripos($url, 'nocache') !== false) {
            return false;
        }

        $path  = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        if ($path !== '' && preg_match(self::UNCACHEABLE_PATH_PATTERN, $path) === 1) {
            return false;
        }

        if ($this->matchesBypassUrl($url)) {
            return false;
        }

        // Unknown query params make the URL uncacheable.
        $queryStr = (string) (parse_url($url, PHP_URL_QUERY) ?? '');
        if ($queryStr !== '') {
            parse_str($queryStr, $params);
            foreach (array_keys($params) as $key) {
                if (!$this->isKnownQueryParam((string) $key)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Full request+response cacheability gate (called at output-buffer flush).
     *
     * Recognised $ctx keys (all read defensively with safe defaults): url,
     * method, cookies, is_admin, is_ajax, status, logged_in, cache_logged_in,
     * password_required, body. No superglobal access here.
     *
     * @param array<string,mixed> $ctx Resolved request/response context.
     * @return bool
     */
    public function isRequestCacheable(array $ctx): bool
    {
        $url    = (string) ($ctx['url'] ?? '');
        $method = strtoupper((string) ($ctx['method'] ?? 'GET'));

        if (!$this->isUrlCacheable($url)) {
            return false;
        }
        if ($method !== 'GET') {
            return false;
        }
        if (!empty($ctx['is_ajax'])) {
            return false;
        }
        if (!empty($ctx['is_admin'])) {
            return false;
        }
        if ((int) ($ctx['status'] ?? 200) !== 200) {
            return false;
        }
        // Logged-in requests are only cacheable when explicitly enabled.
        if (!empty($ctx['logged_in']) && empty($ctx['cache_logged_in'])) {
            return false;
        }
        // Password-protected singular content is never cached.
        if (!empty($ctx['password_required'])) {
            return false;
        }
        // Per-page no-cache meta: never store excluded singular pages.
        if (function_exists('is_singular') && is_singular()
            && function_exists('get_queried_object_id') && function_exists('get_post_meta')
        ) {
            if (get_post_meta((int) get_queried_object_id(), '_wpmgr_no_cache', true) === '1') {
                return false;
            }
        }
        // Any bypass cookie disables caching for this request.
        if ($this->matchesBypassCookie((array) ($ctx['cookies'] ?? []))) {
            return false;
        }
        // WooCommerce cart/checkout/account pages must NEVER be cached — they are
        // per-visitor by definition and the cart-hash cookie may not yet be set on
        // the very first add-to-cart. This is the safety net behind the cookie
        // bypass. Resolved by the writer's context probe (see resolveContext) and
        // re-checked here so the gate is unit-testable.
        if (!empty($ctx['wc_excluded']) || $this->isWooCommerceExcludedPage()) {
            return false;
        }
        // The response body must be a full HTML document.
        $body = (string) ($ctx['body'] ?? '');
        if (preg_match('/<!DOCTYPE\s*html\b[^>]*>/i', $body) !== 1) {
            return false;
        }

        return true;
    }

    /**
     * Whether a query param name is recognised (marketing ignore OR cache-varying
     * include OR an operator-configured extra include).
     *
     * @param string $name Query parameter name.
     * @return bool
     */
    public function isKnownQueryParam(string $name): bool
    {
        if (MarketingParams::isIgnored($name) || MarketingParams::isIncluded($name)) {
            return true;
        }
        $lower = strtolower($name);
        foreach ($this->extraIncludeQueries as $extra) {
            if (strtolower($extra) === $lower) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether the current request resolves to a WooCommerce cart, checkout,
     * "my account", or any WC endpoint (order-received, add-payment-method, etc.)
     * page. Only ever true inside a full WooCommerce request — every predicate is
     * `function_exists`-guarded so this is a cheap `false` on non-WC sites and in
     * unit tests. Returns false unless WooCommerce is actually active.
     *
     * @return bool
     */
    public function isWooCommerceExcludedPage(): bool
    {
        if (!class_exists('WooCommerce')) {
            return false;
        }
        if (function_exists('is_cart') && is_cart()) {
            return true;
        }
        if (function_exists('is_checkout') && is_checkout()) {
            return true;
        }
        if (function_exists('is_account_page') && is_account_page()) {
            return true;
        }
        if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url()) {
            return true;
        }
        return false;
    }

    /**
     * Whether a URL contains any configured bypass keyword (case-insensitive
     * substring).
     *
     * @param string $url URL to test.
     * @return bool
     */
    public function matchesBypassUrl(string $url): bool
    {
        foreach ($this->bypassUrls as $keyword) {
            if (stripos($url, $keyword) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether any cookie NAME matches a configured bypass keyword (case-
     * insensitive substring). WooCommerce/EDD cart and session cookies are the
     * usual targets.
     *
     * @param array<string,mixed> $cookies Request cookie map.
     * @return bool
     */
    public function matchesBypassCookie(array $cookies): bool
    {
        foreach (array_keys($cookies) as $name) {
            $name = (string) $name;
            foreach ($this->bypassCookies as $keyword) {
                if (stripos($name, $keyword) !== false) {
                    return true;
                }
            }
        }
        return false;
    }
}
