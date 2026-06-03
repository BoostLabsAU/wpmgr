<?php
/**
 * Cacheability tests: isUrlCacheable rejects admin/auth/API/unknown-query/bypass;
 * isRequestCacheable rejects AJAX/admin/password-protected/bypass-cookie/non-200/
 * non-HTML; marketing params are allowed and don't break cacheability.
 *
 * @package WPMgr\Agent\Tests
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests;

use WPMgr\Agent\Cache\Cacheability;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Cache\Cacheability
 */
final class CacheabilityTest extends TestCase
{
    private const HTML = '<!DOCTYPE html><html><head></head><body>hi</body></html>';

    private function baseCtx(array $over = []): array
    {
        return array_merge([
            'url'               => 'https://example.com/about/',
            'method'            => 'GET',
            'cookies'           => [],
            'is_admin'          => false,
            'is_ajax'           => false,
            'status'            => 200,
            'logged_in'         => false,
            'cache_logged_in'   => false,
            'password_required' => false,
            'body'              => self::HTML,
        ], $over);
    }

    public function test_plain_anonymous_html_get_is_cacheable(): void
    {
        $c = new Cacheability();
        $this->assertTrue($c->isRequestCacheable($this->baseCtx()));
    }

    public function test_url_rejects_admin_and_api_paths(): void
    {
        $c = new Cacheability();
        $this->assertFalse($c->isUrlCacheable('https://example.com/wp-admin/edit.php'));
        $this->assertFalse($c->isUrlCacheable('https://example.com/wp-login.php'));
        $this->assertFalse($c->isUrlCacheable('https://example.com/wp-json/wp/v2/posts'));
        $this->assertFalse($c->isUrlCacheable('https://example.com/sitemap.xml'));
        $this->assertTrue($c->isUrlCacheable('https://example.com/normal-page/'));
    }

    public function test_url_rejects_unknown_query_param(): void
    {
        $c = new Cacheability();
        // Unknown param → uncacheable.
        $this->assertFalse($c->isUrlCacheable('https://example.com/?add-to-cart=99'));
        // Known cache-varying param → cacheable.
        $this->assertTrue($c->isUrlCacheable('https://example.com/?lang=fr'));
        // Marketing param → cacheable (stripped before hashing, allowed through).
        $this->assertTrue($c->isUrlCacheable('https://example.com/?utm_source=news&gclid=abc'));
    }

    public function test_url_extra_include_query_is_allowed(): void
    {
        // The operator added 'sort_dir' as an extra cache-varying param. A param
        // that is NOT a known marketing/include param AND NOT operator-configured
        // is still uncacheable.
        $c = new Cacheability(['sort_dir']);
        $this->assertTrue($c->isUrlCacheable('https://example.com/?sort_dir=asc'));
        $this->assertFalse($c->isUrlCacheable('https://example.com/?totally_unknown=xl'));
    }

    public function test_url_woocommerce_layered_nav_filter_is_cacheable(): void
    {
        // WooCommerce faceted-catalog filters (filter_<attribute>) vary the page
        // and are recognised by the built-in `filter_` include prefix, so the URL
        // is cacheable (each filter combination gets its own cache entry).
        $c = new Cacheability();
        $this->assertTrue($c->isUrlCacheable('https://example.com/shop/?filter_color=red'));
        $this->assertTrue($c->isUrlCacheable('https://example.com/shop/?filter_size=xl&orderby=price'));
        $this->assertTrue($c->isUrlCacheable('https://example.com/shop/?min_price=10&max_price=50'));
    }

    public function test_url_bypass_keyword(): void
    {
        $c = new Cacheability([], ['/cart', '/checkout']);
        $this->assertFalse($c->isUrlCacheable('https://example.com/cart/'));
        $this->assertFalse($c->isUrlCacheable('https://example.com/checkout/'));
        $this->assertTrue($c->isUrlCacheable('https://example.com/shop/'));
    }

    public function test_request_rejects_ajax(): void
    {
        $c = new Cacheability();
        $this->assertFalse($c->isRequestCacheable($this->baseCtx(['is_ajax' => true])));
    }

    public function test_request_rejects_admin(): void
    {
        $c = new Cacheability();
        $this->assertFalse($c->isRequestCacheable($this->baseCtx(['is_admin' => true])));
    }

    public function test_request_rejects_non_get(): void
    {
        $c = new Cacheability();
        $this->assertFalse($c->isRequestCacheable($this->baseCtx(['method' => 'POST'])));
    }

    public function test_request_rejects_non_200(): void
    {
        $c = new Cacheability();
        $this->assertFalse($c->isRequestCacheable($this->baseCtx(['status' => 404])));
        $this->assertFalse($c->isRequestCacheable($this->baseCtx(['status' => 302])));
    }

    public function test_request_rejects_password_protected(): void
    {
        $c = new Cacheability();
        $this->assertFalse($c->isRequestCacheable($this->baseCtx(['password_required' => true])));
    }

    public function test_request_rejects_logged_in_when_not_allowed(): void
    {
        $c = new Cacheability();
        $this->assertFalse($c->isRequestCacheable($this->baseCtx(['logged_in' => true, 'cache_logged_in' => false])));
        $this->assertTrue($c->isRequestCacheable($this->baseCtx(['logged_in' => true, 'cache_logged_in' => true])));
    }

    public function test_request_rejects_bypass_cookie(): void
    {
        $c = new Cacheability([], [], ['woocommerce_items_in_cart', 'wp-postpass']);
        $ctx = $this->baseCtx(['cookies' => ['woocommerce_items_in_cart_xyz' => '1']]);
        $this->assertFalse($c->isRequestCacheable($ctx));
    }

    /**
     * A2 (privacy): a logged-out request carrying a WooCommerce cart cookie must
     * be NON-cacheable even with ZERO operator-configured bypass cookies — the
     * baked-in DEFAULT_BYPASS_COOKIES set guarantees it. This is the core
     * cart-leakage fix.
     */
    public function test_default_bypass_cookies_block_woocommerce_cart_with_no_operator_config(): void
    {
        $c = new Cacheability(); // no operator bypass cookies at all
        $this->assertFalse(
            $c->isRequestCacheable($this->baseCtx(['cookies' => ['woocommerce_items_in_cart' => '1']])),
            'logged-out cart request must not be cacheable by default'
        );
        $this->assertFalse(
            $c->isRequestCacheable($this->baseCtx(['cookies' => ['woocommerce_cart_hash' => 'abc']])),
            'cart-hash cookie must bypass by default'
        );
        $this->assertFalse(
            $c->isRequestCacheable($this->baseCtx(['cookies' => ['wp_woocommerce_session_9f8' => 'x']])),
            'wc session cookie (hashed suffix) must bypass by default'
        );
        $this->assertFalse(
            $c->isRequestCacheable($this->baseCtx(['cookies' => ['edd_items_in_cart' => '1']])),
            'EDD cart cookie must bypass by default'
        );
        $this->assertFalse(
            $c->isRequestCacheable($this->baseCtx(['cookies' => ['wp-postpass_abc' => 'x']])),
            'password-protected cookie must bypass by default'
        );
    }

    public function test_default_bypass_cookies_const_is_complete(): void
    {
        foreach ([
            'woocommerce_items_in_cart',
            'woocommerce_cart_hash',
            'wp_woocommerce_session_',
            'edd_items_in_cart',
            'wp-postpass_',
            'comment_author_',
            'wordpress_logged_in_',
        ] as $expected) {
            $this->assertContains($expected, Cacheability::DEFAULT_BYPASS_COOKIES);
        }
    }

    public function test_effective_bypass_cookies_merges_defaults_and_operator_dedup(): void
    {
        $eff = Cacheability::effectiveBypassCookies(['my_custom_cookie', 'WOOCOMMERCE_ITEMS_IN_CART']);
        $this->assertContains('woocommerce_items_in_cart', $eff, 'defaults present');
        $this->assertContains('my_custom_cookie', $eff, 'operator cookie present');
        // Case-insensitive de-dup: the operator duplicate of a default is dropped.
        $lower = array_map('strtolower', $eff);
        $this->assertSame(count($lower), count(array_unique($lower)), 'no case-insensitive duplicates');
    }

    /**
     * A2 safety net: a WooCommerce cart/checkout/account page is never cacheable,
     * injected via the `wc_excluded` context flag (the live path probes is_cart()
     * etc. directly).
     */
    public function test_request_rejects_woocommerce_excluded_page_context(): void
    {
        $c = new Cacheability();
        $this->assertFalse(
            $c->isRequestCacheable($this->baseCtx(['url' => 'https://example.com/shop/', 'wc_excluded' => true])),
            'cart/checkout/account page must not be cached'
        );
        // The same URL without the WC exclusion flag IS cacheable.
        $this->assertTrue(
            $c->isRequestCacheable($this->baseCtx(['url' => 'https://example.com/shop/']))
        );
    }

    public function test_request_rejects_non_html_body(): void
    {
        $c = new Cacheability();
        $this->assertFalse($c->isRequestCacheable($this->baseCtx(['body' => '{"json":true}'])));
    }
}
