<?php
/**
 * HtaccessManager tests: the BEGIN/END WPMgr Cache block is idempotent (no
 * duplicate markers), preserves the existing WordPress block, flips the
 * MOBILE_CACHING_FLAG, and emits a Cache-Tag for the host.
 *
 * @package WPMgr\Agent\Tests
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests;

use WPMgr\Agent\Cache\HtaccessManager;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Cache\HtaccessManager
 */
final class CacheHtaccessManagerTest extends TestCase
{
    protected function tear_down(): void
    {
        unset($_SERVER['LSWS_EDITION'], $_SERVER['SERVER_SOFTWARE']);
        parent::tear_down();
    }

    public function test_block_is_idempotent(): void
    {
        $mgr      = new HtaccessManager();
        $existing = "# BEGIN WordPress\nRewriteRule . /index.php [L]\n# END WordPress\n";

        $once  = $mgr->renderInto($existing, 'example.com', false);
        $twice = $mgr->renderInto($once, 'example.com', false);

        $this->assertSame(1, substr_count($twice, HtaccessManager::BEGIN));
        $this->assertSame(1, substr_count($twice, HtaccessManager::END));
        $this->assertSame($once, $twice, 'second render must equal the first (idempotent)');

        // The original WordPress block survives.
        $this->assertStringContainsString('# BEGIN WordPress', $twice);
    }

    public function test_empty_input_produces_single_block(): void
    {
        $mgr = new HtaccessManager();
        $out = $mgr->renderInto('', 'example.com', false);
        $this->assertSame(1, substr_count($out, HtaccessManager::BEGIN));
        $this->assertSame(1, substr_count($out, HtaccessManager::END));
    }

    public function test_mobile_flag_flips(): void
    {
        $mgr = new HtaccessManager();

        $off = $mgr->renderInto('', 'example.com', false);
        $this->assertStringContainsString('MOBILE_CACHING_FLAG:0', $off);
        $this->assertStringNotContainsString('MOBILE_CACHING_FLAG:1', $off);

        $on = $mgr->renderInto('', 'example.com', true);
        $this->assertStringContainsString('MOBILE_CACHING_FLAG:1', $on);
        $this->assertStringNotContainsString('MOBILE_CACHING_FLAG:0', $on);
    }

    public function test_hostname_is_substituted_into_cache_tag(): void
    {
        $mgr = new HtaccessManager();
        $out = $mgr->renderInto('', 'shop.example.com', false);
        $this->assertStringContainsString('Cache-Tag "shop.example.com"', $out);
        $this->assertStringNotContainsString('HOSTNAME', $out);
    }

    public function test_block_serves_from_wpmgr_cache_dir(): void
    {
        $mgr = new HtaccessManager();
        $out = $mgr->renderInto('', 'example.com', false);
        $this->assertStringContainsString('cache/wpmgr/%{HTTP_HOST}%{REQUEST_URI}/index.html.gz', $out);
        $this->assertStringContainsString('no-gzip', $out, 'must avoid double-gzip of .gz files');
    }

    public function test_openlitespeed_strips_gzip_section(): void
    {
        $_SERVER['LSWS_EDITION'] = 'Openlitespeed 1.7';
        $mgr = new HtaccessManager();
        $out = $mgr->renderInto('', 'example.com', false);

        // The gzip/deflate section is removed on OLS.
        $this->assertStringNotContainsString('mod_deflate', $out);
        $this->assertStringNotContainsString('AddOutputFilterByType', $out);
        // But the serve rules remain.
        $this->assertStringContainsString('RewriteRule', $out);
    }

    public function test_nginx_is_detected(): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'nginx/1.24.0';
        $this->assertTrue((new HtaccessManager())->isNginx());

        $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.57';
        $this->assertFalse((new HtaccessManager())->isNginx());
    }
}
