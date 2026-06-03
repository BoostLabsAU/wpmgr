<?php
/**
 * Bloat tests: each enabled toggle removes its target WP core hook (asserted via
 * a tracked remove_action/remove_filter registry) and adds the right replacement
 * filter; a disabled toggle touches nothing.
 *
 * WordPress's real has_action() reflects the live $wp_filter registry, which is
 * absent under unit tests. We instead seed the core hooks as "present" in a
 * fake registry, run Bloat::register(), and assert via has_action() (aliased to
 * read the registry) that the targeted hooks were removed.
 *
 * @package WPMgr\Agent\Tests
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WPMgr\Agent\Optimizer\Bloat;
use WPMgr\Agent\Optimizer\PerfConfig;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Optimizer\Bloat
 */
final class OptimizerBloatTest extends TestCase
{
    /**
     * Fake hook registry: "hook|callback" => true when present.
     *
     * @var array<string,bool>
     */
    private array $hooks = [];

    /**
     * Records add_filter('hook', cb) calls so we can assert replacements.
     *
     * @var array<string,bool>
     */
    private array $added = [];

    protected function set_up(): void
    {
        parent::set_up();
        Monkey\setUp();

        $this->hooks = [];
        $this->added = [];

        // Seed the WP core hooks Bloat targets as "present".
        foreach ([
            'wp_head|print_emoji_detection_script',
            'wp_print_styles|print_emoji_styles',
            'wp_head|wp_oembed_add_discovery_links',
            'wp_head|wp_oembed_add_host_js',
            'wp_head|feed_links',
            'wp_head|feed_links_extra',
        ] as $key) {
            $this->hooks[$key] = true;
        }

        Functions\when('remove_action')->alias(function ($hook, $cb) {
            unset($this->hooks[$hook . '|' . (is_string($cb) ? $cb : 'closure')]);
            return true;
        });
        Functions\when('remove_filter')->alias(function ($hook, $cb) {
            unset($this->hooks[$hook . '|' . (is_string($cb) ? $cb : 'closure')]);
            return true;
        });
        Functions\when('add_action')->alias(function ($hook, $cb) {
            $this->added[$hook] = true;
            return true;
        });
        Functions\when('add_filter')->alias(function ($hook, $cb) {
            $this->added[$hook] = true;
            return true;
        });
        Functions\when('has_action')->alias(function ($hook, $cb = false) {
            if ($cb === false) {
                foreach (array_keys($this->hooks) as $k) {
                    if (strpos($k, $hook . '|') === 0) {
                        return true;
                    }
                }
                return false;
            }
            return isset($this->hooks[$hook . '|' . (is_string($cb) ? $cb : 'closure')]);
        });
        Functions\when('get_option')->justReturn([]);
    }

    protected function tear_down(): void
    {
        Monkey\tearDown();
        parent::tear_down();
    }

    private function bloat(array $cfg): Bloat
    {
        return new Bloat(new PerfConfig($cfg));
    }

    public function test_disabled_removes_nothing(): void
    {
        $this->bloat([])->register();
        $this->assertTrue(has_action('wp_head', 'print_emoji_detection_script'));
        $this->assertTrue(has_action('wp_head', 'wp_oembed_add_discovery_links'));
    }

    public function test_emojis_toggle_removes_emoji_hooks(): void
    {
        $this->bloat(['bloat_disable_emojis' => true])->register();
        $this->assertFalse(has_action('wp_head', 'print_emoji_detection_script'));
        $this->assertFalse(has_action('wp_print_styles', 'print_emoji_styles'));
    }

    public function test_oembeds_toggle_removes_oembed_hooks(): void
    {
        $this->bloat(['bloat_disable_oembeds' => true])->register();
        $this->assertFalse(has_action('wp_head', 'wp_oembed_add_discovery_links'));
        $this->assertFalse(has_action('wp_head', 'wp_oembed_add_host_js'));
    }

    public function test_rss_toggle_removes_feed_link_hooks(): void
    {
        $this->bloat(['bloat_disable_rss_feed' => true])->register();
        $this->assertFalse(has_action('wp_head', 'feed_links'));
        $this->assertFalse(has_action('wp_head', 'feed_links_extra'));
    }

    public function test_xmlrpc_toggle_adds_disable_filter(): void
    {
        $this->bloat(['bloat_disable_xml_rpc' => true])->register();
        $this->assertArrayHasKey('xmlrpc_enabled', $this->added);
    }

    public function test_block_css_toggle_binds_dequeue_hook(): void
    {
        $this->bloat(['bloat_disable_block_css' => true])->register();
        $this->assertArrayHasKey('wp_enqueue_scripts', $this->added);
    }

    public function test_heartbeat_toggle_binds_settings_filter(): void
    {
        $this->bloat(['bloat_heartbeat_control' => true])->register();
        $this->assertArrayHasKey('heartbeat_settings', $this->added);
    }

    public function test_jquery_migrate_toggle_binds_default_scripts_filter(): void
    {
        $this->bloat(['bloat_disable_jquery_migrate' => true])->register();
        $this->assertArrayHasKey('wp_default_scripts', $this->added);
    }

    public function test_emoji_toggle_does_not_remove_unrelated_hooks(): void
    {
        $this->bloat(['bloat_disable_emojis' => true])->register();
        // Feed + oembed hooks must survive when only emojis is enabled.
        $this->assertTrue(has_action('wp_head', 'feed_links'));
        $this->assertTrue(has_action('wp_head', 'wp_oembed_add_discovery_links'));
    }

    public function test_heartbeat_callback_sets_60s_interval(): void
    {
        $bloat = $this->bloat(['bloat_heartbeat_control' => true]);
        $this->assertSame(60, $bloat->throttleHeartbeat([])['interval']);
    }

    public function test_revisions_cap_callback_clamps(): void
    {
        $bloat = $this->bloat(['bloat_post_revisions_control' => true]);
        $this->assertSame(5, $bloat->capRevisions(99));
        $this->assertSame(3, $bloat->capRevisions(3));
    }
}
