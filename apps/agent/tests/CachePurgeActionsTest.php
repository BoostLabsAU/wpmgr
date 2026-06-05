<?php
/**
 * Purge-action firing tests.
 *
 * Every Purge granularity must emit a paired before/after WordPress action so
 * host/edge-cache integrations can clear the upstream cache in lock step:
 *   - purgeUrl()        → wpmgr_purge_urls:before / :after   (with the URL list)
 *   - purgeSite()       → wpmgr_purge_pages:before / :after  (with the host root)
 *   - purgeEverything() → wpmgr_purge_everything:before / :after
 *
 * The underlying file-deletion behaviour is covered by CachePurgeTest; here we
 * only assert the actions fire (with the right payload) around the deletion,
 * including on the early-return paths.
 *
 * @package WPMgr\Agent\Tests
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WPMgr\Agent\Cache\Purge;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Cache\Purge
 */
final class CachePurgeActionsTest extends TestCase
{
    private string $root = '';

    /** @var list<array{string,array<mixed>}> Recorded do_action(hook, args) calls. */
    private array $fired = [];

    protected function set_up(): void
    {
        parent::set_up();
        Monkey\setUp();

        $this->fired = [];
        Functions\when('do_action')->alias(function (string $hook, ...$args): void {
            $this->fired[] = [$hook, $args];
        });
        // PerfReporter::persistLastPurge() calls update_option() under a
        // function_exists guard; Brain Monkey makes that guard pass, so stub it.
        Functions\when('update_option')->justReturn(true);

        $this->root = sys_get_temp_dir() . '/wpmgr-pa-' . uniqid('', true) . '/cache/wpmgr';
        $this->seed('example.com', ['index.html.gz']);
        $this->seed('example.com/blog', ['index.html.gz']);
    }

    protected function tear_down(): void
    {
        $this->rrmdir(dirname(dirname($this->root)));
        Monkey\tearDown();
        parent::tear_down();
    }

    private function seed(string $hostPath, array $files): void
    {
        $dir = $this->root . '/' . $hostPath;
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }
        foreach ($files as $f) {
            file_put_contents($dir . '/' . $f, 'x');
        }
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $p = $dir . '/' . $e;
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    /** @return list<string> Hooks fired, in order. */
    private function hooks(): array
    {
        return array_map(static fn ($e) => $e[0], $this->fired);
    }

    /** @return array<mixed>|null Args of the first occurrence of $hook. */
    private function argsOf(string $hook): ?array
    {
        foreach ($this->fired as [$h, $args]) {
            if ($h === $hook) {
                return $args;
            }
        }
        return null;
    }

    public function test_purge_url_fires_before_and_after_with_url_list(): void
    {
        $purge = new Purge($this->root);
        $purge->purgeUrl('https://example.com/');

        $hooks = $this->hooks();
        $this->assertContains('wpmgr_purge_urls:before', $hooks);
        $this->assertContains('wpmgr_purge_urls:after', $hooks);

        // before precedes after.
        $this->assertLessThan(
            array_search('wpmgr_purge_urls:after', $hooks, true),
            array_search('wpmgr_purge_urls:before', $hooks, true)
        );

        // The payload is the URL list.
        $this->assertSame([['https://example.com/']], $this->argsOf('wpmgr_purge_urls:before'));
    }

    public function test_purge_url_fires_actions_even_on_uncontained_early_return(): void
    {
        // A host that resolves outside the cache root → 0 removed, but actions
        // must still bracket the no-op so integrations always run.
        $purge = new Purge($this->root);
        $removed = $purge->purgeUrl('https://nonexistent.example/no/such/path/');

        $this->assertSame(0, $removed);
        $this->assertContains('wpmgr_purge_urls:before', $this->hooks());
        $this->assertContains('wpmgr_purge_urls:after', $this->hooks());
    }

    public function test_purge_site_fires_pages_actions_with_host_root(): void
    {
        $purge = new Purge($this->root);
        $purge->purgeSite('example.com');

        $this->assertContains('wpmgr_purge_pages:before', $this->hooks());
        $this->assertContains('wpmgr_purge_pages:after', $this->hooks());
        $this->assertSame([['https://example.com/']], $this->argsOf('wpmgr_purge_pages:before'));
    }

    public function test_purge_everything_fires_before_and_after(): void
    {
        $purge = new Purge($this->root);
        $this->assertTrue($purge->purgeEverything());

        $hooks = $this->hooks();
        $this->assertContains('wpmgr_purge_everything:before', $hooks);
        $this->assertContains('wpmgr_purge_everything:after', $hooks);
        $this->assertLessThan(
            array_search('wpmgr_purge_everything:after', $hooks, true),
            array_search('wpmgr_purge_everything:before', $hooks, true)
        );
        // No args on the everything actions.
        $this->assertSame([], $this->argsOf('wpmgr_purge_everything:before'));
    }

    public function test_purge_everything_fires_actions_even_when_refused(): void
    {
        $bogus = sys_get_temp_dir() . '/wpmgr-not-cache-' . uniqid('', true);
        mkdir($bogus, 0o777, true);

        $purge = new Purge($bogus);
        $this->assertFalse($purge->purgeEverything());
        $this->assertContains('wpmgr_purge_everything:before', $this->hooks());
        $this->assertContains('wpmgr_purge_everything:after', $this->hooks());

        @rmdir($bogus);
    }
}
