<?php
/**
 * Host/edge-cache integration tests.
 *
 * Three guarantees:
 *   1. The loader registers every integration on the purge actions on boot.
 *   2. Each integration NO-OPS cleanly when its host is NOT detected — no PHP
 *      error and, critically, no outbound HTTP / host call.
 *   3. For two representative hosts (loopback Varnish + WP Engine) the right
 *      purge mechanism IS invoked with the expected URLs/methods when the host
 *      is stubbed present.
 *
 * Integrations detect their host via the host's own class/function/global, so
 * each test stubs (or withholds) that signal and inspects the recorded calls.
 *
 * @package WPMgr\Agent\Tests
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WPMgr\Agent\Integrations\Cloudflare;
use WPMgr\Agent\Integrations\Cloudways;
use WPMgr\Agent\Integrations\GridPane;
use WPMgr\Agent\Integrations\Integrations;
use WPMgr\Agent\Integrations\Kinsta;
use WPMgr\Agent\Integrations\RocketNet;
use WPMgr\Agent\Integrations\RunCloud;
use WPMgr\Agent\Integrations\SiteGround;
use WPMgr\Agent\Integrations\SpinupWP;
use WPMgr\Agent\Integrations\Varnish;
use WPMgr\Agent\Integrations\WPCloud;
use WPMgr\Agent\Integrations\WPEngine;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Integrations\Integrations
 * @covers \WPMgr\Agent\Integrations\Integration
 * @covers \WPMgr\Agent\Integrations\Varnish
 * @covers \WPMgr\Agent\Integrations\WPEngine
 */
final class IntegrationsPurgeTest extends TestCase
{
    /** @var array<string,list<callable>> Captured add_action(hook => callbacks). */
    private array $hooks = [];

    /** @var list<array{string,string,array<string,mixed>}> wp_remote_request calls (url, method, args). */
    private array $http = [];

    protected function set_up(): void
    {
        parent::set_up();
        Monkey\setUp();

        $this->hooks = [];
        $this->http  = [];

        // Capture hook registrations so we can both assert them and dispatch.
        Functions\when('add_action')->alias(function (string $hook, $cb, $prio = 10, $args = 1) {
            $this->hooks[$hook][] = $cb;
            return true;
        });

        Functions\when('wp_remote_request')->alias(function ($url, $args = []) {
            $this->http[] = [
                (string) $url,
                (string) ($args['method'] ?? 'GET'),
                is_array($args) ? $args : [],
            ];
            return [];
        });
        Functions\when('wp_remote_post')->alias(function ($url, $args = []) {
            $this->http[] = [(string) $url, 'POST', is_array($args) ? $args : []];
            return [];
        });

        unset($GLOBALS['kinsta_cache'], $GLOBALS['cloudflareHooks']);
        unset(
            $_SERVER['HTTP_X_VARNISH'],
            $_SERVER['HTTP_X_APPLICATION'],
            $_SERVER['HTTP_VIA'],
            $_SERVER['HTTP_HOST'],
            $_SERVER['SERVER_NAME']
        );
    }

    protected function tear_down(): void
    {
        unset($GLOBALS['kinsta_cache'], $GLOBALS['cloudflareHooks']);
        unset(
            $_SERVER['HTTP_X_VARNISH'],
            $_SERVER['HTTP_X_APPLICATION'],
            $_SERVER['HTTP_VIA'],
            $_SERVER['HTTP_HOST'],
            $_SERVER['SERVER_NAME']
        );
        Monkey\tearDown();
        parent::tear_down();
    }

    /** Dispatch every callback registered on a hook with the given args. */
    private function dispatch(string $hook, mixed ...$args): void
    {
        foreach ($this->hooks[$hook] ?? [] as $cb) {
            $cb(...$args);
        }
    }

    // ------------------------------------------------------------------ loader

    public function test_loader_registers_all_integrations_on_purge_actions(): void
    {
        (new Integrations())->boot();

        // 11 integrations each register the three purge:before hooks.
        $this->assertCount(11, $this->hooks['wpmgr_purge_urls:before'] ?? []);
        $this->assertCount(11, $this->hooks['wpmgr_purge_pages:before'] ?? []);
        $this->assertCount(11, $this->hooks['wpmgr_purge_everything:before'] ?? []);
    }

    public function test_loader_boot_is_idempotent(): void
    {
        $loader = new Integrations();
        $loader->boot();
        $loader->boot();

        // Still one registration per integration despite the double boot.
        $this->assertCount(11, $this->hooks['wpmgr_purge_everything:before'] ?? []);
    }

    // -------------------------------------------------- no-op when host absent

    /**
     * With NO host signals present, booting + firing every purge action must be
     * completely silent: no host call, no outbound HTTP.
     *
     * @dataProvider purgeActions
     */
    public function test_every_integration_noops_when_host_absent(string $hook, array $args): void
    {
        (new Integrations())->boot();
        $this->dispatch($hook, ...$args);

        $this->assertSame([], $this->http, 'no integration may make an outbound call when its host is absent');
    }

    /** @return array<string,array{0:string,1:array<mixed>}> */
    public static function purgeActions(): array
    {
        return [
            'purge urls'       => ['wpmgr_purge_urls:before', [['https://example.com/a/', 'https://example.com/b/']]],
            'purge pages'      => ['wpmgr_purge_pages:before', [['https://example.com/']]],
            'purge everything' => ['wpmgr_purge_everything:before', []],
        ];
    }

    /**
     * Each integration instantiated directly also no-ops on both handlers when
     * its host is absent (defence regardless of how it is dispatched).
     *
     * @dataProvider allIntegrations
     */
    public function test_integration_class_noops_when_host_absent(string $class): void
    {
        if ($class === WPEngine::class && class_exists('\WpeCommon')) {
            $this->markTestSkipped('WpeCommon double already defined this process — host not absent.');
        }

        /** @var \WPMgr\Agent\Integrations\Integration $integration */
        $integration = new $class();
        $integration->onPurgeUrls(['https://example.com/x/']);
        $integration->onPurgeEverything();

        $this->assertSame([], $this->http, $class . ' must not call out when its host is absent');
    }

    /**
     * Define a recording WpeCommon double in the global namespace (once per
     * process). WP Engine ships this class; we mimic its static purge surface.
     */
    private function defineWpeCommon(): void
    {
        if (class_exists('\WpeCommon')) {
            return;
        }
        eval(
            'class WpeCommon {'
            . ' public static array $calls = [];'
            . ' public static function purge_varnish_cache() { self::$calls[] = "purge_varnish_cache"; }'
            . ' public static function purge_memcached() { self::$calls[] = "purge_memcached"; }'
            . ' public static function clear_maxcdn_cache() { self::$calls[] = "clear_maxcdn_cache"; }'
            . '}'
        );
    }

    /** @return array<string,array{0:class-string}> */
    public static function allIntegrations(): array
    {
        return [
            'Varnish'    => [Varnish::class],
            'Cloudflare' => [Cloudflare::class],
            'Kinsta'     => [Kinsta::class],
            'SiteGround' => [SiteGround::class],
            'WPEngine'   => [WPEngine::class],
            'Cloudways'  => [Cloudways::class],
            'RunCloud'   => [RunCloud::class],
            'GridPane'   => [GridPane::class],
            'SpinupWP'   => [SpinupWP::class],
            'RocketNet'  => [RocketNet::class],
            'WPCloud'    => [WPCloud::class],
        ];
    }

    // ----------------------------------------------------------- Varnish (host)

    public function test_varnish_detected_purges_each_url_over_loopback(): void
    {
        // Varnish marks the request with X-Varnish; Host header keys the object.
        $_SERVER['HTTP_X_VARNISH'] = '12345';
        $_SERVER['HTTP_HOST']      = 'shop.example';

        (new Varnish())->onPurgeUrls(['https://shop.example/cart/', 'https://shop.example/checkout/']);

        $this->assertCount(2, $this->http);

        [$url1, $method1, $args1] = $this->http[0];
        $this->assertSame('PURGE', $method1);
        $this->assertSame('http://127.0.0.1/cart/', $url1);
        $this->assertSame('shop.example', $args1['headers']['Host']);
        // Loopback target: reject_unsafe_urls is intentionally disabled.
        $this->assertFalse($args1['reject_unsafe_urls']);

        [$url2, $method2] = $this->http[1];
        $this->assertSame('PURGE', $method2);
        $this->assertSame('http://127.0.0.1/checkout/', $url2);
    }

    public function test_varnish_detected_bans_everything(): void
    {
        $_SERVER['HTTP_X_VARNISH'] = '1';
        $_SERVER['HTTP_HOST']      = 'shop.example';

        (new Varnish())->onPurgeEverything();

        $this->assertCount(1, $this->http);
        [$url, $method, $args] = $this->http[0];
        $this->assertSame('BAN', $method);
        $this->assertSame('http://127.0.0.1/.*', $url);
        $this->assertSame('shop.example', $args['headers']['X-Ban-Host']);
    }

    public function test_varnish_noops_on_varnishpass_application(): void
    {
        // X-Varnish present but the app is bypassing Varnish → nothing cached.
        $_SERVER['HTTP_X_VARNISH']     = '1';
        $_SERVER['HTTP_X_APPLICATION'] = 'varnishpass';
        $_SERVER['HTTP_HOST']          = 'shop.example';

        (new Varnish())->onPurgeEverything();
        $this->assertSame([], $this->http);
    }

    // --------------------------------------------------------- WP Engine (host)

    public function test_wpengine_detected_flushes_each_cache_layer(): void
    {
        $this->defineWpeCommon();
        \WpeCommon::$calls = [];
        (new WPEngine())->onPurgeEverything();

        // Every available WpeCommon purge method was invoked exactly once.
        $this->assertSame(
            ['purge_varnish_cache', 'purge_memcached', 'clear_maxcdn_cache'],
            \WpeCommon::$calls
        );
    }

    public function test_wpengine_url_purge_falls_back_to_full_flush(): void
    {
        $this->defineWpeCommon();
        \WpeCommon::$calls = [];
        (new WPEngine())->onPurgeUrls(['https://example.com/page/']);

        // No per-URL WPE purge exists → full flush.
        $this->assertContains('purge_varnish_cache', \WpeCommon::$calls);
    }
}
