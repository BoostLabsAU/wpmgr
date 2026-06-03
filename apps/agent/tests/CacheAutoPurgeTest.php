<?php
/**
 * AutoPurge URL-list tests: for a post with categories + tags (and a parent
 * category) the affected-URL set includes the permalink, home, posts page,
 * post-type archive, author archive, every term archive, and ancestor terms.
 *
 * WP getter functions are stubbed via Brain Monkey.
 *
 * @package WPMgr\Agent\Tests
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WPMgr\Agent\Cache\AutoPurge;
use WPMgr\Agent\Cache\Preload;
use WPMgr\Agent\Cache\Purge;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Cache\AutoPurge
 */
final class CacheAutoPurgeTest extends TestCase
{
    protected function set_up(): void
    {
        parent::set_up();
        Monkey\setUp();
    }

    protected function tear_down(): void
    {
        Monkey\tearDown();
        parent::tear_down();
    }

    private function autoPurge(): AutoPurge
    {
        $root = sys_get_temp_dir() . '/wpmgr-ap-' . uniqid('', true) . '/cache/wpmgr';
        return new AutoPurge(new Purge($root), new Preload(false));
    }

    public function test_urls_for_post_includes_full_set(): void
    {
        $postId = 42;

        Functions\when('get_permalink')->alias(static function ($id) {
            if ((int) $id === 42) {
                return 'https://example.com/blog/my-post/';
            }
            if ((int) $id === 7) {
                return 'https://example.com/blog/'; // page_for_posts
            }
            return '';
        });
        Functions\when('home_url')->justReturn('https://example.com/');
        Functions\when('get_option')->alias(static fn ($k) => $k === 'page_for_posts' ? 7 : false);
        Functions\when('get_post_type')->justReturn('post');
        Functions\when('get_post_type_archive_link')->justReturn(''); // 'post' has no archive
        Functions\when('get_post_field')->alias(static fn ($f, $id) => $f === 'post_author' ? 3 : null);
        Functions\when('get_author_posts_url')->justReturn('https://example.com/author/jane/');

        Functions\when('get_object_taxonomies')->justReturn(['category', 'post_tag']);
        Functions\when('is_taxonomy_viewable')->justReturn(true);
        Functions\when('wp_get_post_terms')->alias(static function ($id, $tax) {
            if ($tax === 'category') {
                return [(object) ['term_id' => 10]]; // child category
            }
            if ($tax === 'post_tag') {
                return [(object) ['term_id' => 20]];
            }
            return [];
        });
        Functions\when('get_term_link')->alias(static function ($termId, $tax) {
            return [
                10 => 'https://example.com/category/news/',
                11 => 'https://example.com/category/parent/',
                20 => 'https://example.com/tag/featured/',
            ][(int) $termId] ?? '';
        });
        Functions\when('get_ancestors')->alias(static function ($termId, $tax) {
            // The "news" category (10) has a parent (11).
            return (int) $termId === 10 ? [11] : [];
        });
        Functions\when('apply_filters')->returnArg(2);

        $urls = $this->autoPurge()->urlsForPost($postId);

        $this->assertContains('https://example.com/blog/my-post/', $urls, 'post permalink');
        $this->assertContains('https://example.com/', $urls, 'home');
        $this->assertContains('https://example.com/blog/', $urls, 'posts page');
        $this->assertContains('https://example.com/author/jane/', $urls, 'author archive');
        $this->assertContains('https://example.com/category/news/', $urls, 'category term');
        $this->assertContains('https://example.com/category/parent/', $urls, 'ancestor category term');
        $this->assertContains('https://example.com/tag/featured/', $urls, 'tag term');

        // The set is de-duplicated.
        $this->assertSame(count($urls), count(array_unique($urls)));
    }

    public function test_urls_are_filterable(): void
    {
        Functions\when('get_permalink')->justReturn('https://example.com/x/');
        Functions\when('home_url')->justReturn('https://example.com/');
        Functions\when('get_option')->justReturn(false);
        Functions\when('get_post_type')->justReturn('post');
        Functions\when('get_post_type_archive_link')->justReturn('');
        Functions\when('get_post_field')->justReturn(0);
        Functions\when('get_object_taxonomies')->justReturn([]);
        // The filter appends an extra URL.
        Functions\when('apply_filters')->alias(static function ($hook, $urls) {
            $urls[] = 'https://example.com/extra/';
            return $urls;
        });

        $urls = $this->autoPurge()->urlsForPost(1);
        $this->assertContains('https://example.com/extra/', $urls);
    }
}
