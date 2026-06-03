<?php
/**
 * CacheConfig + LoggedInRoles + DropinInstaller render coverage:
 *   - CacheConfig coerces/round-trips and exposes a lean drop-in array carrying
 *     the marketing ignore list.
 *   - LoggedInRoles::encodeRoles slugifies + joins roles deterministically.
 *   - DropinInstaller::render inlines the config over the CONFIG_TO_REPLACE token
 *     and the result is valid PHP carrying our signature.
 *
 * @package WPMgr\Agent\Tests
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests;

use WPMgr\Agent\Cache\Cacheability;
use WPMgr\Agent\Cache\CacheConfig;
use WPMgr\Agent\Cache\DropinInstaller;
use WPMgr\Agent\Cache\LoggedInRoles;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Cache\CacheConfig
 * @covers \WPMgr\Agent\Cache\LoggedInRoles
 * @covers \WPMgr\Agent\Cache\DropinInstaller
 */
final class CacheConfigAndRolesTest extends TestCase
{
    public function test_config_defaults_are_conservative(): void
    {
        $c = new CacheConfig();
        $this->assertFalse($c->enabled);
        $this->assertFalse($c->cacheLoggedIn);
        $this->assertFalse($c->cacheMobile);
        $this->assertTrue($c->autoPurge, 'auto-purge defaults on');
        $this->assertSame(0, $c->refreshInterval);
    }

    public function test_config_round_trips(): void
    {
        $data = [
            'enabled'          => true,
            'cache_logged_in'  => true,
            'cache_mobile'     => true,
            'auto_purge'       => false,
            'refresh_interval' => 3600,
            'include_queries'  => ['filter_color', 'filter_color', ''],
            'include_cookies'  => ['geo'],
            'bypass_urls'      => ['/cart'],
            'bypass_cookies'   => ['woocommerce'],
        ];
        $c = new CacheConfig($data);
        $out = $c->toArray();

        $this->assertTrue($out['enabled']);
        $this->assertSame(3600, $out['refresh_interval']);
        // De-duped + empties dropped.
        $this->assertSame(['filter_color'], $out['include_queries']);
        $this->assertSame(['/cart'], $out['bypass_urls']);
    }

    public function test_dropin_array_carries_ignore_list(): void
    {
        $c = new CacheConfig(['cache_mobile' => true, 'include_cookies' => ['geo']]);
        $arr = $c->toDropinArray();

        $this->assertTrue($arr['cache_mobile']);
        $this->assertSame(['geo'], $arr['include_cookies']);
        $this->assertContains('utm_source', $arr['ignore_queries']);
        $this->assertContains('gclid', $arr['ignore_queries']);
        // The lean drop-in array must NOT carry server-only keys.
        $this->assertArrayNotHasKey('enabled', $arr);
        $this->assertArrayNotHasKey('bypass_urls', $arr);
    }

    /**
     * A2 (privacy): the drop-in's inlined default config must bypass the
     * WooCommerce/EDD cart, session, logged-in, and password cookies EVEN when the
     * operator configured ZERO bypass cookies — so a logged-out cart page is never
     * served from the pre-WP fast path.
     */
    public function test_dropin_default_config_bypasses_cart_cookies_with_no_operator_config(): void
    {
        // A bare config: operator set NO bypass cookies.
        $c   = new CacheConfig();
        $arr = $c->toDropinArray();

        $this->assertArrayHasKey('bypass_cookies', $arr);
        $this->assertContains('woocommerce_items_in_cart', $arr['bypass_cookies'],
            'drop-in default config must contain the WooCommerce cart cookie');
        $this->assertContains('woocommerce_cart_hash', $arr['bypass_cookies']);
        $this->assertContains('wp_woocommerce_session_', $arr['bypass_cookies']);
        $this->assertContains('edd_items_in_cart', $arr['bypass_cookies']);
        $this->assertContains('wp-postpass_', $arr['bypass_cookies']);
        $this->assertContains('wordpress_logged_in_', $arr['bypass_cookies']);
    }

    public function test_dropin_default_bypass_cookies_match_php_cacheability_path(): void
    {
        // The drop-in's inlined bypass set MUST equal the PHP cacheability path's
        // effective set for the same (empty) operator config — behavioral parity.
        $c = new CacheConfig();
        $this->assertSame(
            Cacheability::effectiveBypassCookies([]),
            $c->toDropinArray()['bypass_cookies'],
            'drop-in and PHP builder bypass-cookie sets must be identical'
        );
    }

    public function test_dropin_merges_operator_bypass_cookies_with_defaults(): void
    {
        $c   = new CacheConfig(['bypass_cookies' => ['my_session_cookie']]);
        $arr = $c->toDropinArray();
        $this->assertContains('my_session_cookie', $arr['bypass_cookies'], 'operator cookie kept');
        $this->assertContains('woocommerce_items_in_cart', $arr['bypass_cookies'], 'default still merged');
    }

    public function test_operator_include_lists_round_trip_without_baking_presets(): void
    {
        // With no i18n/currency plugin active, the effective include lists equal the
        // operator lists and toArray() persists the operator intent verbatim.
        $c   = new CacheConfig(['include_queries' => ['sort_dir'], 'include_cookies' => ['geo']]);
        $out = $c->toArray();
        $this->assertSame(['sort_dir'], $out['include_queries']);
        $this->assertSame(['geo'], $out['include_cookies']);
    }

    public function test_negative_refresh_interval_clamped_to_zero(): void
    {
        $c = new CacheConfig(['refresh_interval' => -50]);
        $this->assertSame(0, $c->refreshInterval);
    }

    public function test_encode_roles_slugifies_and_joins(): void
    {
        $this->assertSame('editor', LoggedInRoles::encodeRoles(['editor']));
        $this->assertSame('administrator', LoggedInRoles::encodeRoles(['Administrator']));
        $this->assertSame('shop_manager-editor', LoggedInRoles::encodeRoles(['shop_manager', 'editor']));
        // Hostile characters are stripped.
        $this->assertSame('editor', LoggedInRoles::encodeRoles(['../editor!!']));
        $this->assertSame('', LoggedInRoles::encodeRoles([]));
    }

    public function test_dropin_render_inlines_config(): void
    {
        $template = "<?php\n// WPMgr Page Cache drop-in\n\$config = CONFIG_TO_REPLACE;\nreturn \$config['cache_mobile'];\n";
        $dir = sys_get_temp_dir() . '/wpmgr-dropin-' . uniqid('', true);
        mkdir($dir, 0o777, true);
        $tplPath = $dir . '/tpl.php';
        file_put_contents($tplPath, $template);

        $installer = new DropinInstaller($dir, $tplPath);
        $rendered  = $installer->render(['cache_mobile' => true, 'ignore_queries' => ['utm_source']]);

        $this->assertStringNotContainsString('CONFIG_TO_REPLACE', $rendered);
        $this->assertStringContainsString("'cache_mobile' => true", $rendered);
        $this->assertStringContainsString('WPMgr Page Cache drop-in', $rendered);

        // The rendered drop-in is valid PHP (eval-free check via php -l on a tmp file).
        $out = $dir . '/rendered.php';
        file_put_contents($out, $rendered);
        $lint = shell_exec('php -l ' . escapeshellarg($out) . ' 2>&1');
        $this->assertStringContainsString('No syntax errors', (string) $lint);

        @unlink($tplPath);
        @unlink($out);
        @rmdir($dir);
    }
}
