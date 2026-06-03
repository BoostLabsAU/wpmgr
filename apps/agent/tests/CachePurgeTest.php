<?php
/**
 * Purge tests on a temp-dir cache fixture: per-URL purge deletes only that
 * directory's .html.gz variants (not child paths, not minified assets), and
 * purge-everything recursively wipes the tree.
 *
 * @package WPMgr\Agent\Tests
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests;

use WPMgr\Agent\Cache\Purge;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Cache\Purge
 */
final class CachePurgeTest extends TestCase
{
    private string $root = '';

    protected function set_up(): void
    {
        parent::set_up();
        $this->root = sys_get_temp_dir() . '/wpmgr-cache-' . uniqid('', true) . '/cache/wpmgr';
        $this->seed('example.com', '', ['index.html.gz', 'index-mobile.html.gz', 'index-logged-in-editor.html.gz']);
        $this->seed('example.com/blog', '', ['index.html.gz']);
        $this->seed('example.com/blog/post-a', '', ['index.html.gz', 'index-mobile.html.gz']);
        // A non-page artefact that per-URL purge must keep.
        $this->seed('example.com/blog/post-a', '', ['style.min.css']);
        $this->seed('other.com', '', ['index.html.gz']);
    }

    protected function tear_down(): void
    {
        $this->rrmdir(dirname(dirname($this->root)));
        parent::tear_down();
    }

    private function seed(string $hostPath, string $sub, array $files): void
    {
        $dir = $this->root . '/' . $hostPath . ($sub !== '' ? '/' . $sub : '');
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

    private function exists(string $rel): bool
    {
        return is_file($this->root . '/' . $rel);
    }

    public function test_purge_url_deletes_only_that_dirs_html_variants(): void
    {
        $purge = new Purge($this->root);
        $removed = $purge->purgeUrl('https://example.com/blog/post-a/');

        // All .html.gz variants for that exact path are gone.
        $this->assertSame(2, $removed);
        $this->assertFalse($this->exists('example.com/blog/post-a/index.html.gz'));
        $this->assertFalse($this->exists('example.com/blog/post-a/index-mobile.html.gz'));

        // The non-page artefact in the same dir is preserved.
        $this->assertTrue($this->exists('example.com/blog/post-a/style.min.css'));

        // Sibling and parent paths are untouched.
        $this->assertTrue($this->exists('example.com/blog/index.html.gz'));
        $this->assertTrue($this->exists('example.com/index.html.gz'));
        $this->assertTrue($this->exists('example.com/index-mobile.html.gz'));
    }

    public function test_purge_url_root(): void
    {
        $purge = new Purge($this->root);
        $removed = $purge->purgeUrl('https://example.com/');

        $this->assertSame(3, $removed, 'all three root variants removed');
        $this->assertFalse($this->exists('example.com/index.html.gz'));
        $this->assertFalse($this->exists('example.com/index-mobile.html.gz'));
        $this->assertFalse($this->exists('example.com/index-logged-in-editor.html.gz'));
        // Child path survives a root-only purge.
        $this->assertTrue($this->exists('example.com/blog/index.html.gz'));
    }

    public function test_purge_site_is_recursive_for_one_host_only(): void
    {
        $purge = new Purge($this->root);
        $removed = $purge->purgeSite('example.com');

        // Every .html.gz under example.com is gone (3 root + 1 blog + 2 post-a).
        $this->assertSame(6, $removed);
        $this->assertFalse($this->exists('example.com/index.html.gz'));
        $this->assertFalse($this->exists('example.com/blog/index.html.gz'));
        $this->assertFalse($this->exists('example.com/blog/post-a/index.html.gz'));

        // The other host is untouched.
        $this->assertTrue($this->exists('other.com/index.html.gz'));

        // The non-page asset is preserved by a recursive HTML-only purge.
        $this->assertTrue($this->exists('example.com/blog/post-a/style.min.css'));
    }

    public function test_purge_everything_wipes_the_tree(): void
    {
        $purge = new Purge($this->root);
        $this->assertTrue($purge->purgeEverything());

        $this->assertFalse($this->exists('example.com/index.html.gz'));
        $this->assertFalse($this->exists('other.com/index.html.gz'));
        // The root itself is recreated empty.
        $this->assertTrue(is_dir($this->root));
        $this->assertSame(['.', '..'], scandir($this->root));
    }

    public function test_purge_everything_refuses_non_cache_root(): void
    {
        $bogus = sys_get_temp_dir() . '/wpmgr-not-a-cache-' . uniqid('', true);
        mkdir($bogus, 0o777, true);
        file_put_contents($bogus . '/keep.txt', 'x');

        $purge = new Purge($bogus);
        $this->assertFalse($purge->purgeEverything(), 'must refuse to nuke a non-cache directory');
        $this->assertTrue(is_file($bogus . '/keep.txt'));

        @unlink($bogus . '/keep.txt');
        @rmdir($bogus);
    }
}
