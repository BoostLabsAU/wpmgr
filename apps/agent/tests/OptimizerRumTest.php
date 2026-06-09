<?php
/**
 * RUM injector + PerfConfig coercion tests.
 *
 * Covered invariants:
 *   1. rumEnabled defaults to false.
 *   2. rumSampleRate defaults to 1.0 and clamps to [0,1].
 *   3. rumBeaconKey round-trips through constructor and toArray().
 *   4. rumIngestUrl is derived from the CP URL option when not pushed.
 *   5. rumIngestUrl is used as-is when pushed by the CP.
 *   6. anyHtmlTransformEnabled() returns true when ONLY rumEnabled is on.
 *   7. anyHtmlTransformEnabled() returns false when rumEnabled is off and all others off.
 *   8. PerfConfig round-trips RUM fields through toArray() -> constructor.
 *   9. RumInjector: flag OFF => HTML unchanged.
 *  10. RumInjector: flag ON, empty key => HTML unchanged.
 *  11. RumInjector: flag ON, empty url => HTML unchanged.
 *  12. RumInjector: valid config => injects inline config + async external script into <head>.
 *  13. RumInjector: valid config => snippet not injected twice on second call.
 *  14. RumInjector: sample_rate is clamped to [0,1] in the JSON config.
 *  15. Optimizer pipeline: rum stage runs when ONLY rumEnabled is on.
 *  16. Optimizer pipeline: rum stage is no-op when rumEnabled is off.
 *  17. RumInjector: CSP with nonce and without unsafe-inline => skip injection.
 *  18. RumInjector: CSP with unsafe-inline => allow injection.
 *  19. RumInjector: no </head> in document => falls back to before </body>.
 *
 * @package WPMgr\Agent\Tests
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WPMgr\Agent\Optimizer\Optimizer;
use WPMgr\Agent\Optimizer\PerfConfig;
use WPMgr\Agent\Optimizer\RumInjector;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Optimizer\PerfConfig
 * @covers \WPMgr\Agent\Optimizer\RumInjector
 * @covers \WPMgr\Agent\Optimizer\Optimizer
 */
final class OptimizerRumTest extends TestCase
{
    private const BASIC_DOC = '<!DOCTYPE html><html><head></head><body><p>Hello</p></body></html>';

    /** @var array<string,mixed> */
    private array $options = [];

    protected function set_up(): void
    {
        parent::set_up();
        Monkey\setUp();

        $this->options = [];
        Functions\when('get_option')->alias(fn ($k, $d = false) => $this->options[$k] ?? $d);
        Functions\when('update_option')->alias(function ($k, $v) {
            $this->options[$k] = $v;
            return true;
        });
        Functions\when('plugins_url')->alias(function (string $path, string $file): string {
            return 'https://example.com/wp-content/plugins/wpmgr-agent/' . ltrim($path, '/');
        });
        Functions\when('wp_json_encode')->alias(fn ($v) => (string) json_encode($v));
        Functions\when('esc_url')->alias(fn ($u) => $u);
        Functions\when('headers_list')->justReturn([]);
        Functions\when('is_user_logged_in')->justReturn(false);
        Functions\when('is_singular')->justReturn(false);
        Functions\when('site_url')->justReturn('https://example.com');
        Functions\when('home_url')->justReturn('https://example.com');
        Functions\when('get_site_option')->alias(fn ($k, $d = false) => $d);
    }

    protected function tear_down(): void
    {
        Monkey\tearDown();
        parent::tear_down();
    }

    // ---- PerfConfig coercion tests ----

    public function test_rum_enabled_defaults_false(): void
    {
        $c = new PerfConfig([]);
        $this->assertFalse($c->rumEnabled);
    }

    public function test_rum_sample_rate_defaults_to_one(): void
    {
        $c = new PerfConfig([]);
        $this->assertSame(1.0, $c->rumSampleRate);
    }

    public function test_rum_sample_rate_clamped_below_zero(): void
    {
        $c = new PerfConfig(['rum_sample_rate' => -0.5]);
        $this->assertSame(0.0, $c->rumSampleRate);
    }

    public function test_rum_sample_rate_clamped_above_one(): void
    {
        $c = new PerfConfig(['rum_sample_rate' => 2.5]);
        $this->assertSame(1.0, $c->rumSampleRate);
    }

    public function test_rum_beacon_key_round_trips(): void
    {
        $c = new PerfConfig(['rum_beacon_key' => 'TESTKEY123456']);
        $this->assertSame('TESTKEY123456', $c->rumBeaconKey);
    }

    public function test_rum_beacon_key_defaults_empty(): void
    {
        $c = new PerfConfig([]);
        $this->assertSame('', $c->rumBeaconKey);
    }

    public function test_rum_ingest_url_uses_pushed_value(): void
    {
        $c = new PerfConfig(['rum_ingest_url' => 'https://cp.example.com/rum/ingest']);
        $this->assertSame('https://cp.example.com/rum/ingest', $c->rumIngestUrl);
    }

    public function test_rum_ingest_url_derived_from_cp_option(): void
    {
        $this->options['wpmgr_agent_cp_url'] = 'https://manage.wpmgr.app';
        $c = new PerfConfig([]);
        $this->assertSame('https://manage.wpmgr.app/rum/ingest', $c->rumIngestUrl);
    }

    public function test_rum_ingest_url_empty_when_no_cp_url(): void
    {
        $c = new PerfConfig([]);
        $this->assertSame('', $c->rumIngestUrl);
    }

    public function test_any_html_transform_enabled_when_only_rum_on(): void
    {
        $c = new PerfConfig(['rum_enabled' => true, 'rum_beacon_key' => 'key']);
        $this->assertTrue($c->anyHtmlTransformEnabled());
    }

    public function test_any_html_transform_disabled_when_rum_off_and_all_others_off(): void
    {
        $c = new PerfConfig([]);
        $this->assertFalse($c->anyHtmlTransformEnabled());
    }

    public function test_perf_config_rum_round_trips_via_to_array(): void
    {
        $data = [
            'rum_enabled'     => true,
            'rum_sample_rate' => 0.5,
            'rum_beacon_key'  => 'MYROUNDTRIPKEY',
            'rum_ingest_url'  => 'https://cp.example.com/rum/ingest',
        ];
        $c1   = new PerfConfig($data);
        $c2   = new PerfConfig($c1->toArray());

        $this->assertTrue($c2->rumEnabled);
        $this->assertSame(0.5, $c2->rumSampleRate);
        $this->assertSame('MYROUNDTRIPKEY', $c2->rumBeaconKey);
        $this->assertSame('https://cp.example.com/rum/ingest', $c2->rumIngestUrl);
    }

    // ---- RumInjector tests ----

    private function makeConfig(array $overrides = []): PerfConfig
    {
        return new PerfConfig(array_merge([
            'rum_enabled'     => true,
            'rum_sample_rate' => 1.0,
            'rum_beacon_key'  => 'TESTBEACONKEY',
            'rum_ingest_url'  => 'https://cp.example.com/rum/ingest',
        ], $overrides));
    }

    public function test_injector_noop_when_rum_disabled(): void
    {
        $c = new PerfConfig(['rum_enabled' => false]);
        $out = (new RumInjector($c))->process(self::BASIC_DOC);
        $this->assertSame(self::BASIC_DOC, $out);
    }

    public function test_injector_noop_when_key_empty(): void
    {
        $c = $this->makeConfig(['rum_beacon_key' => '']);
        $out = (new RumInjector($c))->process(self::BASIC_DOC);
        $this->assertSame(self::BASIC_DOC, $out);
    }

    public function test_injector_noop_when_url_empty(): void
    {
        $c = $this->makeConfig(['rum_ingest_url' => '']);
        $out = (new RumInjector($c))->process(self::BASIC_DOC);
        $this->assertSame(self::BASIC_DOC, $out);
    }

    public function test_injector_injects_inline_config_and_external_script(): void
    {
        // Ensure WPMGR_AGENT_FILE is defined so plugins_url fallback path works.
        if (!defined('WPMGR_AGENT_FILE')) {
            define('WPMGR_AGENT_FILE', '/path/to/wpmgr-agent.php');
        }

        $c   = $this->makeConfig();
        $out = (new RumInjector($c))->process(self::BASIC_DOC);

        // Inline config block must be present.
        $this->assertStringContainsString('data-wpmgr-rum-config', $out);
        $this->assertStringContainsString('window.__WPMGR_RUM__', $out);
        $this->assertStringContainsString('"TESTBEACONKEY"', $out);
        // wp_json_encode encodes '/' as '\/' in its default output; check for the key.
        $this->assertStringContainsString('cp.example.com', $out);

        // External script tag must be present and async (not defer).
        $this->assertStringContainsString('wpmgr-rum.min.js', $out);
        $this->assertStringContainsString('async', $out);
        $this->assertStringNotContainsString('defer', $out);

        // Snippet must appear inside <head> (before </head>), not just before </body>.
        // Early injection is the fix for the CLS FCP-gate race on view-then-leave pages.
        $snippetPos = strpos($out, 'data-wpmgr-rum-config');
        $headPos    = stripos($out, '</head>');
        $this->assertNotFalse($snippetPos, 'Snippet must be present');
        $this->assertNotFalse($headPos, 'Document must have </head>');
        $this->assertLessThan($headPos, $snippetPos, 'Snippet must appear before </head>');
    }

    public function test_injector_falls_back_to_before_body_when_no_head(): void
    {
        if (!defined('WPMGR_AGENT_FILE')) {
            define('WPMGR_AGENT_FILE', '/path/to/wpmgr-agent.php');
        }

        // A document with no </head> tag (unconventional but must not break).
        $noHeadDoc = '<!DOCTYPE html><html><body><p>Hello</p></body></html>';
        $c         = $this->makeConfig();
        $out       = (new RumInjector($c))->process($noHeadDoc);

        // Snippet must still be injected.
        $this->assertStringContainsString('data-wpmgr-rum-config', $out);
        $this->assertStringContainsString('wpmgr-rum.min.js', $out);

        // Must appear before </body> as the fallback.
        $snippetPos = strpos($out, 'data-wpmgr-rum-config');
        $bodyPos    = stripos($out, '</body>');
        $this->assertNotFalse($snippetPos, 'Snippet must be present in fallback');
        $this->assertNotFalse($bodyPos, 'Document must have </body>');
        $this->assertLessThan($bodyPos, $snippetPos, 'Snippet must appear before </body> in fallback');
    }

    public function test_injector_does_not_inject_twice(): void
    {
        if (!defined('WPMGR_AGENT_FILE')) {
            define('WPMGR_AGENT_FILE', '/path/to/wpmgr-agent.php');
        }

        $c    = $this->makeConfig();
        $inj  = new RumInjector($c);
        $out1 = $inj->process(self::BASIC_DOC);
        $out2 = $inj->process($out1);

        // Only one occurrence of the config marker.
        $this->assertSame(1, substr_count($out2, 'data-wpmgr-rum-config'));
    }

    public function test_injector_sample_rate_clamped_in_json(): void
    {
        if (!defined('WPMGR_AGENT_FILE')) {
            define('WPMGR_AGENT_FILE', '/path/to/wpmgr-agent.php');
        }

        // rate > 1 should be clamped to 1.0 in the snippet.
        $c   = $this->makeConfig(['rum_sample_rate' => 2.0]);
        $out = (new RumInjector($c))->process(self::BASIC_DOC);
        $this->assertStringContainsString('"rate":1', $out);
    }

    // ---- Optimizer pipeline integration tests ----

    public function test_optimizer_runs_rum_stage_when_only_rum_enabled(): void
    {
        if (!defined('WPMGR_AGENT_FILE')) {
            define('WPMGR_AGENT_FILE', '/path/to/wpmgr-agent.php');
        }

        $config = new PerfConfig([
            'rum_enabled'     => true,
            'rum_sample_rate' => 1.0,
            'rum_beacon_key'  => 'PIPELINEKEY',
            'rum_ingest_url'  => 'https://cp.example.com/rum/ingest',
        ]);

        $opt = new Optimizer($config);
        $this->assertTrue($opt->isActive(), 'Optimizer must be active when only rum_enabled is on');

        $out = $opt->run(self::BASIC_DOC);
        $this->assertStringContainsString('data-wpmgr-rum-config', $out);
        $this->assertStringContainsString('wpmgr-rum.min.js', $out);
    }

    public function test_optimizer_noop_rum_when_disabled(): void
    {
        $config = new PerfConfig([]);
        $opt    = new Optimizer($config);
        $this->assertFalse($opt->isActive());
        $out = $opt->run(self::BASIC_DOC);
        $this->assertStringNotContainsString('data-wpmgr-rum-config', $out);
    }

    // ---- CSP detection tests ----

    public function test_injector_skips_when_strict_nonce_csp_present(): void
    {
        if (!defined('WPMGR_AGENT_FILE')) {
            define('WPMGR_AGENT_FILE', '/path/to/wpmgr-agent.php');
        }

        // Override headers_list stub to return a strict nonce CSP.
        Functions\when('headers_list')->justReturn([
            "Content-Security-Policy: default-src 'self'; script-src 'nonce-abc123'",
        ]);

        $c   = $this->makeConfig();
        $out = (new RumInjector($c))->process(self::BASIC_DOC);
        $this->assertSame(self::BASIC_DOC, $out, 'Should skip injection on strict nonce CSP');
    }

    public function test_injector_allows_when_csp_has_unsafe_inline(): void
    {
        if (!defined('WPMGR_AGENT_FILE')) {
            define('WPMGR_AGENT_FILE', '/path/to/wpmgr-agent.php');
        }

        // A nonce + unsafe-inline CSP: unsafe-inline wins, injection is safe.
        Functions\when('headers_list')->justReturn([
            "Content-Security-Policy: script-src 'nonce-abc123' 'unsafe-inline'",
        ]);

        $c   = $this->makeConfig();
        $out = (new RumInjector($c))->process(self::BASIC_DOC);
        $this->assertStringContainsString('data-wpmgr-rum-config', $out);
    }
}
