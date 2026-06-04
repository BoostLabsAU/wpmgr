<?php
/**
 * Preload SSRF guard tests (confused-deputy).
 *
 * A `cache_preload` command is signed by the control plane but carries an
 * operator-supplied `urls[]`. The warmer MUST refuse to fetch any URL whose host
 * is not this site's own host — otherwise a captured/abused command could coerce
 * the agent into requesting cloud-metadata (169.254.169.254), loopback
 * (127.0.0.1:9000) or any internal service.
 *
 * Since Task #171 the pending queue lives in a custom MySQL table (PreloadQueue),
 * not a wp-option, so these tests exercise the per-URL warm seam (warmOne) — the
 * single point every claimed row flows through — and assert that:
 *
 *   - an on-host URL IS fetched, with reject_unsafe_urls engaged, and
 *   - off-host URLs (cloud-metadata, loopback, arbitrary host, non-http schemes)
 *     are NEVER fetched (the same-host guard re-runs at WARM time).
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
    /** @var list<string> URLs that actually reached wp_remote_get. */
    private array $fetched = [];

    /** @var list<array<string,mixed>> wp_remote_get arg arrays. */
    private array $fetchArgs = [];

    protected function set_up(): void
    {
        parent::set_up();
        Monkey\setUp();

        $this->fetched   = [];
        $this->fetchArgs = [];

        Functions\when('home_url')->justReturn('https://example.com/');
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

    public function test_on_host_url_is_fetched_with_reject_unsafe_urls(): void
    {
        (new Preload(false))->warmOne('https://example.com/page/', 'desktop');

        $this->assertSame(['https://example.com/page/'], $this->fetched);
        $this->assertCount(1, $this->fetchArgs);
        $this->assertArrayHasKey('reject_unsafe_urls', $this->fetchArgs[0]);
        $this->assertTrue($this->fetchArgs[0]['reject_unsafe_urls']);
    }

    public function test_cloud_metadata_url_is_never_fetched(): void
    {
        (new Preload(false))->warmOne('http://169.254.169.254/latest/meta-data/', 'desktop');
        $this->assertSame([], $this->fetched);
    }

    public function test_loopback_service_url_is_never_fetched(): void
    {
        (new Preload(false))->warmOne('http://127.0.0.1:9000/', 'desktop');
        $this->assertSame([], $this->fetched);
    }

    public function test_arbitrary_off_host_url_is_never_fetched(): void
    {
        (new Preload(false))->warmOne('https://attacker.example.net/internal', 'desktop');
        $this->assertSame([], $this->fetched);
    }

    public function test_host_match_is_case_insensitive(): void
    {
        (new Preload(false))->warmOne('https://EXAMPLE.com/Path/', 'mobile');
        $this->assertSame(['https://EXAMPLE.com/Path/'], $this->fetched);
    }

    public function test_non_http_schemes_are_rejected(): void
    {
        $preload = new Preload(false);
        $preload->warmOne('file:///etc/passwd', 'desktop');
        $preload->warmOne('gopher://example.com/', 'desktop');
        $preload->warmOne('ftp://example.com/x', 'desktop');
        $this->assertSame([], $this->fetched);
    }
}
