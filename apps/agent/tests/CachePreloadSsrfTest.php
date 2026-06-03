<?php
/**
 * Preload SSRF guard tests (confused-deputy).
 *
 * A `cache_preload` command is signed by the control plane but carries an
 * operator-supplied `urls[]`. The warmer MUST refuse to fetch any URL whose host
 * is not this site's own host — otherwise a captured/abused command could coerce
 * the agent into requesting cloud-metadata (169.254.169.254), loopback
 * (127.0.0.1:9000) or any internal service. These tests assert that:
 *
 *   - off-host URLs are dropped at queue() time (never persisted), and
 *   - even if an off-host URL reaches fetch(), wp_remote_get is NEVER called for
 *     it, while on-host URLs ARE fetched (with reject_unsafe_urls engaged).
 *
 * @package WPMgr\Agent\Tests
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WPMgr\Agent\Cache\Preload;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Cache\Preload
 */
final class CachePreloadSsrfTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $options = [];

    /** @var list<string> URLs that actually reached wp_remote_get. */
    private array $fetched = [];

    /** @var list<array<string,mixed>> wp_remote_get arg arrays. */
    private array $fetchArgs = [];

    protected function set_up(): void
    {
        parent::set_up();
        Monkey\setUp();

        $this->options   = [];
        $this->fetched   = [];
        $this->fetchArgs = [];

        Functions\when('home_url')->justReturn('https://example.com/');
        Functions\when('get_option')->alias(fn ($k, $d = false) => $this->options[$k] ?? $d);
        Functions\when('update_option')->alias(function ($k, $v) {
            $this->options[$k] = $v;
            return true;
        });
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\when('wp_schedule_single_event')->justReturn(true);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);

        // Record every fetch + its args so we can assert what was/wasn't hit.
        Functions\when('wp_remote_get')->alias(function ($url, $args = []) {
            $this->fetched[]   = (string) $url;
            $this->fetchArgs[] = is_array($args) ? $args : [];
            return [];
        });
    }

    protected function tear_down(): void
    {
        Monkey\tearDown();
        parent::tear_down();
    }

    public function test_off_host_urls_are_dropped_at_queue_time(): void
    {
        $preload = new Preload(false);

        $queued = $preload->queue([
            'http://169.254.169.254/',                 // cloud metadata
            'http://127.0.0.1:9000/',                  // loopback service
            'https://attacker.example.net/internal',   // arbitrary off-host
            'https://example.com/keep/',               // on-host — survives
        ]);

        $this->assertSame(1, $queued, 'only the on-host URL should be queued');
        $this->assertSame(['https://example.com/keep/'], $preload->pending());
        $this->assertNotContains('http://169.254.169.254/', $preload->pending());
    }

    public function test_off_host_urls_are_never_fetched_even_if_persisted(): void
    {
        // Simulate a poisoned persisted queue (bypassing queue()'s filter).
        $this->options['wpmgr_cache_preload_queue'] = [
            'http://169.254.169.254/latest/meta-data/',
            'http://127.0.0.1:9000/',
            'https://example.com/page/',
        ];

        (new Preload(false))->run();

        // Only the on-host URL hit the network.
        $this->assertSame(['https://example.com/page/'], $this->fetched);
        $this->assertNotContains('http://169.254.169.254/latest/meta-data/', $this->fetched);
        $this->assertNotContains('http://127.0.0.1:9000/', $this->fetched);
    }

    public function test_on_host_fetch_engages_reject_unsafe_urls(): void
    {
        $this->options['wpmgr_cache_preload_queue'] = ['https://example.com/page/'];

        (new Preload(false))->run();

        $this->assertCount(1, $this->fetchArgs);
        $this->assertArrayHasKey('reject_unsafe_urls', $this->fetchArgs[0]);
        $this->assertTrue($this->fetchArgs[0]['reject_unsafe_urls']);
    }

    public function test_host_match_is_case_insensitive(): void
    {
        $preload = new Preload(false);
        $queued  = $preload->queue(['https://EXAMPLE.com/Path/']);
        $this->assertSame(1, $queued);
        $this->assertSame(['https://EXAMPLE.com/Path/'], $preload->pending());
    }

    public function test_non_http_schemes_are_rejected(): void
    {
        $preload = new Preload(false);
        $queued  = $preload->queue([
            'file:///etc/passwd',
            'gopher://example.com/',
            'ftp://example.com/x',
        ]);
        $this->assertSame(0, $queued);
        $this->assertSame([], $preload->pending());
    }
}
