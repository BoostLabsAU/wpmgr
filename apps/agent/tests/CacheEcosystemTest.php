<?php
/**
 * Ecosystem correctness coverage for the page cache:
 *   - A2b  WooCommerce stock/product change purges product + shop + cat/tag terms.
 *   - A3   page-builder template / ACF options saves purge EVERYTHING (and only
 *          for those post types — an ordinary post save does not purge-all).
 *   - A4   EcosystemPresets detects i18n / multi-currency plugins and contributes
 *          cache-varying cookies/queries (operator config still wins).
 *   - A5   ConflictDetect reports active conflicting cache/optimization plugins.
 *
 * WP getters are stubbed via Brain Monkey. WooCommerce / ACF / conflicting-plugin
 * presence is simulated by declaring the canonical identifiers the detectors look
 * for. Because constants/classes cannot be un-declared within a process, the
 * detection-by-class/constant cases are asserted on the public identifiers each
 * plugin ships and verified end-to-end through a stub class where feasible.
 *
 * @package WPMgr\Agent\Tests
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WPMgr\Agent\Cache\AutoPurge;
use WPMgr\Agent\Cache\CacheKey;
use WPMgr\Agent\Cache\ConflictDetect;
use WPMgr\Agent\Cache\EcosystemPresets;
use WPMgr\Agent\Cache\Preload;
use WPMgr\Agent\Cache\Purge;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Cache\AutoPurge
 * @covers \WPMgr\Agent\Cache\EcosystemPresets
 * @covers \WPMgr\Agent\Cache\ConflictDetect
 */
final class CacheEcosystemTest extends TestCase
{
    /** Absolute temp cache root for the current test (…/cache/wpmgr). */
    private string $root = '';

    protected function set_up(): void
    {
        parent::set_up();
        Monkey\setUp();
        $this->root = sys_get_temp_dir() . '/wpmgr-eco-' . uniqid('', true) . '/cache/wpmgr';
        @mkdir($this->root, 0o777, true);
    }

    protected function tear_down(): void
    {
        // Clear any per-test detection simulation so it can't leak between tests.
        EcosystemPresets::overrideDetectionForTests(null);
        ConflictDetect::overrideDetectionForTests(null);
        $this->rrmdir(dirname(dirname($this->root)));
        Monkey\tearDown();
        parent::tear_down();
    }

    /** Real Purge against the per-test temp root (Purge is final — can't spy). */
    private function purge(): Purge
    {
        return new Purge($this->root);
    }

    /** Real Preload (queue() just schedules cron, which is stubbed below). */
    private function preload(): Preload
    {
        // Preload::queue() resolves the site host from home_url() for its SSRF
        // on-host filter, then de-dups the pending batch via the cron-args option.
        Functions\when('home_url')->justReturn('https://shop.test/');
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\when('wp_schedule_single_event')->justReturn(true);
        Functions\when('get_option')->justReturn([]);
        Functions\when('update_option')->justReturn(true);
        return new Preload(false);
    }

    /** Seed a cache file for a host+path so we can assert purgeUrl removed it. */
    private function seedUrl(string $host, string $path): string
    {
        $dir  = $this->root . '/' . $host . CacheKey::normalizePath($path);
        @mkdir($dir, 0o777, true);
        $file = $dir . '/index' . CacheKey::EXTENSION;
        file_put_contents($file, 'x');
        return $file;
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
            $full = $dir . '/' . $e;
            is_dir($full) ? $this->rrmdir($full) : @unlink($full);
        }
        @rmdir($dir);
    }

    // ----- A2b: WooCommerce stock/product change purge -----------------------

    public function test_woo_stock_change_purges_product_shop_and_terms(): void
    {
        if (!class_exists('WooCommerce', false)) {
            // Declare the canonical WooCommerce class the guard checks for.
            eval('class WooCommerce {}');
        }

        Functions\when('get_permalink')->alias(static function ($id) {
            return [
                55 => 'https://shop.test/product/widget/',
                90 => 'https://shop.test/shop/',
            ][(int) $id] ?? '';
        });
        Functions\when('wc_get_page_id')->alias(static fn ($p) => $p === 'shop' ? 90 : 0);
        Functions\when('wp_get_post_terms')->alias(static function ($id, $tax) {
            if ($tax === 'product_cat') {
                return [(object) ['term_id' => 100]];
            }
            if ($tax === 'product_tag') {
                return [(object) ['term_id' => 200]];
            }
            return [];
        });
        Functions\when('get_term_link')->alias(static function ($termId, $tax) {
            return [
                100 => 'https://shop.test/product-category/tools/',
                200 => 'https://shop.test/product-tag/sale/',
            ][(int) $termId] ?? '';
        });

        // Seed a cache file for each surface; assert each is removed by the purge.
        $product = $this->seedUrl('shop.test', '/product/widget/');
        $shop    = $this->seedUrl('shop.test', '/shop/');
        $cat     = $this->seedUrl('shop.test', '/product-category/tools/');
        $tag     = $this->seedUrl('shop.test', '/product-tag/sale/');

        // WooCommerce passes a WC_Product-like object exposing get_id().
        $wcProduct = new class {
            public function get_id(): int
            {
                return 55;
            }
        };
        (new AutoPurge($this->purge(), $this->preload()))->onWooStockChange($wcProduct);

        $this->assertFileDoesNotExist($product, 'product URL purged');
        $this->assertFileDoesNotExist($shop, 'shop page purged');
        $this->assertFileDoesNotExist($cat, 'product_cat term purged');
        $this->assertFileDoesNotExist($tag, 'product_tag term purged');
    }

    public function test_woo_stock_change_accepts_scalar_id(): void
    {
        if (!class_exists('WooCommerce', false)) {
            eval('class WooCommerce {}');
        }
        Functions\when('get_permalink')->justReturn('https://shop.test/product/x/');
        Functions\when('wc_get_page_id')->justReturn(0);
        Functions\when('wp_get_post_terms')->justReturn([]);
        Functions\when('get_term_link')->justReturn('');

        $file = $this->seedUrl('shop.test', '/product/x/');
        (new AutoPurge($this->purge(), $this->preload()))->onWooStockChange(55);

        $this->assertFileDoesNotExist($file);
    }

    // ----- A3: page-builder template + ACF options purge-all -----------------

    /** Seed a marker file at the cache root; purgeEverything() wipes the root. */
    private function seedMarker(): string
    {
        $marker = $this->root . '/marker.html.gz';
        file_put_contents($marker, 'x');
        return $marker;
    }

    public function test_page_builder_template_save_purges_everything(): void
    {
        Functions\when('wp_is_post_autosave')->justReturn(false);
        Functions\when('wp_is_post_revision')->justReturn(false);

        $marker = $this->seedMarker();
        (new AutoPurge($this->purge(), $this->preload()))
            ->onSavePostMaybePurgeAll(7, (object) ['post_type' => 'wp_template_part']);

        $this->assertFileDoesNotExist($marker, 'FSE template part save purges everything');
    }

    public function test_elementor_global_save_purges_everything(): void
    {
        Functions\when('wp_is_post_autosave')->justReturn(false);
        Functions\when('wp_is_post_revision')->justReturn(false);

        $marker = $this->seedMarker();
        (new AutoPurge($this->purge(), $this->preload()))
            ->onSavePostMaybePurgeAll(9, (object) ['post_type' => 'elementor_library']);

        $this->assertFileDoesNotExist($marker);
    }

    public function test_ordinary_post_save_does_not_purge_everything(): void
    {
        Functions\when('wp_is_post_autosave')->justReturn(false);
        Functions\when('wp_is_post_revision')->justReturn(false);

        $marker = $this->seedMarker();
        (new AutoPurge($this->purge(), $this->preload()))
            ->onSavePostMaybePurgeAll(3, (object) ['post_type' => 'post']);

        $this->assertFileExists($marker, 'a normal post save must NOT purge-all');
    }

    public function test_template_autosave_does_not_purge_everything(): void
    {
        Functions\when('wp_is_post_autosave')->justReturn(true);
        Functions\when('wp_is_post_revision')->justReturn(false);

        $marker = $this->seedMarker();
        (new AutoPurge($this->purge(), $this->preload()))
            ->onSavePostMaybePurgeAll(11, (object) ['post_type' => 'wp_template']);

        $this->assertFileExists($marker, 'autosave of a template must NOT purge-all');
    }

    public function test_acf_options_save_purges_everything(): void
    {
        if (!class_exists('ACF', false)) {
            eval('class ACF {}');
        }
        $marker = $this->seedMarker();
        (new AutoPurge($this->purge(), $this->preload()))->onAcfSavePost('options');

        $this->assertFileDoesNotExist($marker, 'ACF options-page save purges everything');
    }

    public function test_acf_post_id_save_does_not_purge_everything(): void
    {
        if (!class_exists('ACF', false)) {
            eval('class ACF {}');
        }
        $marker = $this->seedMarker();
        // A numeric ACF target is a real post (handled by per-post purge) — no purge-all.
        (new AutoPurge($this->purge(), $this->preload()))->onAcfSavePost(123);

        $this->assertFileExists($marker);
    }

    // ----- A4: EcosystemPresets ----------------------------------------------

    public function test_ecosystem_presets_merge_keeps_operator_first_and_dedupes(): void
    {
        // Operator config always wins (comes first) and is never overridden.
        $cookies = EcosystemPresets::effectiveIncludeCookies(['geo']);
        $this->assertSame('geo', $cookies[0] ?? null, 'operator cookie stays first');

        $queries = EcosystemPresets::effectiveIncludeQueries(['custom_q']);
        $this->assertContains('custom_q', $queries);
    }

    public function test_ecosystem_presets_detect_returns_list(): void
    {
        // detected() returns a list of {label,kind} entries. (Other tests in this
        // process may have defined a plugin constant, so we assert shape, not
        // strict emptiness.)
        foreach (EcosystemPresets::detected() as $entry) {
            $this->assertArrayHasKey('label', $entry);
            $this->assertArrayHasKey('kind', $entry);
            $this->assertContains($entry['kind'], ['currency', 'language']);
        }
        // The operator's own 'lang' is always preserved (deduped against any
        // preset that also contributes it).
        $this->assertContains('lang', EcosystemPresets::effectiveIncludeQueries(['lang']));
    }

    public function test_ecosystem_presets_pick_up_wpml_when_active(): void
    {
        // Simulate WPML active via the test override (no process-wide constant
        // that would leak into other test cases).
        EcosystemPresets::overrideDetectionForTests(['WPML' => true]);

        $cookies = EcosystemPresets::presetCookies();
        $this->assertContains('wp-wpml_current_language', $cookies, 'WPML language cookie auto-detected');
        $this->assertContains('lang', EcosystemPresets::presetQueries(), 'WPML lang query auto-detected');

        $detected = array_column(EcosystemPresets::detected(), 'label');
        $this->assertContains('WPML', $detected);

        // Operator config still wins: it is merged FIRST, presets appended.
        $eff = EcosystemPresets::effectiveIncludeCookies(['geo']);
        $this->assertSame('geo', $eff[0], 'operator cookie first');
        $this->assertContains('wp-wpml_current_language', $eff, 'preset appended under operator');
    }

    public function test_ecosystem_presets_woocs_currency_cookie(): void
    {
        EcosystemPresets::overrideDetectionForTests(['WOOCS / FOX Currency Switcher' => true]);
        $cookies = EcosystemPresets::presetCookies();
        $this->assertContains('woocommerce_current_currency', $cookies);
        $this->assertContains('aelia_cs_selected_currency', $cookies);
    }

    public function test_cache_config_folds_in_detected_presets_for_dropin(): void
    {
        // With WPML detected, the drop-in's include_cookies must carry the WPML
        // language cookie so language variants fragment the pre-WP cache key.
        EcosystemPresets::overrideDetectionForTests(['WPML' => true]);
        $cfg = new \WPMgr\Agent\Cache\CacheConfig(['include_cookies' => ['geo']]);
        $dropin = $cfg->toDropinArray();
        $this->assertContains('geo', $dropin['include_cookies']);
        $this->assertContains('wp-wpml_current_language', $dropin['include_cookies']);
        // But the persisted form keeps ONLY the operator's intent (no baked preset).
        $this->assertSame(['geo'], $cfg->toArray()['include_cookies']);
    }

    // ----- A5: ConflictDetect ------------------------------------------------

    public function test_conflict_detect_empty_when_none_active(): void
    {
        ConflictDetect::overrideDetectionForTests([]); // simulate: nothing active
        $cd = new ConflictDetect();
        $this->assertFalse($cd->hasConflict());
        $this->assertSame([], $cd->conflicts());
        $this->assertSame([], $cd->conflictSlugs());
    }

    public function test_conflict_detect_flags_wp_rocket(): void
    {
        ConflictDetect::overrideDetectionForTests(['wp-rocket' => true]);
        $cd = new ConflictDetect();
        $this->assertTrue($cd->hasConflict());
        $this->assertContains('wp-rocket', $cd->conflictSlugs());

        $labels = array_column($cd->conflicts(), 'label');
        $this->assertContains('WP Rocket', $labels);
        // Slugs and conflicts() agree.
        $this->assertSame($cd->conflictSlugs(), array_column($cd->conflicts(), 'slug'));
    }

    public function test_conflict_detect_flags_multiple(): void
    {
        ConflictDetect::overrideDetectionForTests([
            'litespeed-cache' => true,
            'autoptimize'     => true,
            'perfmatters'     => false,
        ]);
        $slugs = (new ConflictDetect())->conflictSlugs();
        $this->assertContains('litespeed-cache', $slugs);
        $this->assertContains('autoptimize', $slugs);
        $this->assertNotContains('perfmatters', $slugs);
    }
}
