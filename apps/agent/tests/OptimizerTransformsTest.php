<?php
/**
 * Transform tests for the request-path optimizer stages that operate purely on
 * the HTML string (no WP/network): JS delay, font display-swap, image lazy +
 * sizing, YouTube facade, speculation rules, CDN rewrite.
 *
 * @package WPMgr\Agent\Tests
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WPMgr\Agent\Optimizer\CdnRewrite;
use WPMgr\Agent\Optimizer\Font;
use WPMgr\Agent\Optimizer\IFrame;
use WPMgr\Agent\Optimizer\ImagesHtml;
use WPMgr\Agent\Optimizer\JsDelay;
use WPMgr\Agent\Optimizer\PerfConfig;
use WPMgr\Agent\Optimizer\SpeculationRules;
use WPMgr\Agent\Optimizer\UrlHelper;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Optimizer\JsDelay
 * @covers \WPMgr\Agent\Optimizer\Font
 * @covers \WPMgr\Agent\Optimizer\ImagesHtml
 * @covers \WPMgr\Agent\Optimizer\IFrame
 * @covers \WPMgr\Agent\Optimizer\SpeculationRules
 * @covers \WPMgr\Agent\Optimizer\CdnRewrite
 */
final class OptimizerTransformsTest extends TestCase
{
    protected function set_up(): void
    {
        parent::set_up();
        Monkey\setUp();
        // The Font/SelfHost/Gravatar transforms lazily build a UrlHelper that
        // reads site_url(); stub it so those paths resolve under unit tests.
        Functions\when('site_url')->justReturn('https://example.com');
        Functions\when('home_url')->justReturn('https://example.com');
        Functions\when('get_option')->justReturn([]);
    }

    protected function tear_down(): void
    {
        Monkey\tearDown();
        parent::tear_down();
    }

    // ---- JS delay -----------------------------------------------------------

    public function test_js_delay_interaction_rewrites_src_to_data_attr(): void
    {
        $delay = new JsDelay('interaction', []);
        $html  = '<html><body><script src="https://example.com/app.js"></script></body></html>';
        $out   = $delay->process($html);

        $this->assertStringContainsString('data-wpmgr-src="https://example.com/app.js"', $out);
        $this->assertStringNotContainsString(' src="https://example.com/app.js"', $out);
        $this->assertStringContainsString('data-wpmgr-method="interaction"', $out);
    }

    public function test_js_delay_leaves_excluded_scripts_alone(): void
    {
        $delay = new JsDelay('interaction', ['app.js']);
        $html  = '<html><body><script src="https://example.com/app.js"></script>'
            . '<script src="https://example.com/other.js"></script></body></html>';
        $out   = $delay->process($html);

        // Excluded script keeps its real src.
        $this->assertStringContainsString('<script src="https://example.com/app.js"></script>', $out);
        // Non-excluded script is delayed.
        $this->assertStringContainsString('data-wpmgr-src="https://example.com/other.js"', $out);
    }

    public function test_js_delay_defer_method_adds_defer(): void
    {
        $delay = new JsDelay('defer', []);
        $html  = '<html><body><script src="https://example.com/app.js"></script></body></html>';
        $out   = $delay->process($html);

        $this->assertMatchesRegularExpression('/<script[^>]*\sdefer[^>]*>/', $out);
        // defer mode does NOT inject the runtime.
        $this->assertStringNotContainsString('data-wpmgr-delay-runtime', $out);
    }

    public function test_js_delay_skips_ld_json(): void
    {
        $delay = new JsDelay('interaction', []);
        $html  = '<html><body><script type="application/ld+json">{"a":1}</script></body></html>';
        $out   = $delay->process($html);
        $this->assertStringContainsString('type="application/ld+json"', $out);
        $this->assertStringNotContainsString('data-wpmgr-method', $out);
    }

    public function test_js_delay_disabled_when_nothing_to_do(): void
    {
        $delay = new JsDelay('interaction', []);
        $html  = '<html><body><p>no scripts</p></body></html>';
        $this->assertSame($html, $delay->process($html));
    }

    // ---- Font ---------------------------------------------------------------

    public function test_font_display_swap_injected_into_font_face(): void
    {
        $cfg  = new PerfConfig(['fonts_display_swap' => true]);
        $font = new Font($cfg);
        $html = '<html><head><style>@font-face{font-family:X;src:url(x.woff2)}</style></head></html>';
        $out  = $font->process($html);
        $this->assertStringContainsString('@font-face{font-display:swap;', $out);
    }

    public function test_font_display_swap_normalises_existing_directive(): void
    {
        $css = '@font-face{font-display:block;font-family:X}';
        $out = Font::injectDisplaySwap($css);
        $this->assertStringContainsString('font-display:swap;', $out);
        $this->assertStringNotContainsString('font-display:block', $out);
    }

    public function test_font_display_swap_added_to_google_link(): void
    {
        $cfg  = new PerfConfig(['fonts_display_swap' => true]);
        $font = new Font($cfg);
        $html = '<html><head><link href="https://fonts.googleapis.com/css?family=Roboto" rel="stylesheet"></head></html>';
        $out  = $font->process($html);
        $this->assertStringContainsString('display=swap', $out);
    }

    public function test_font_disabled_is_noop(): void
    {
        $cfg  = new PerfConfig([]);
        $font = new Font($cfg);
        $html = '<html><head><style>@font-face{font-family:X}</style></head></html>';
        $this->assertSame($html, $font->process($html));
    }

    // ---- Images -------------------------------------------------------------

    public function test_images_get_lazy_and_dimensions_except_above_fold(): void
    {
        $cfg  = new PerfConfig(['lazy_load' => true, 'properly_size_images' => true]);
        $urls = new UrlHelper('https://example.com', '/tmp');
        $img  = new ImagesHtml($cfg, $urls);
        // 3 images: first two above-fold (eager), third lazy. WxH filename gives dims.
        $html = '<!DOCTYPE html><html><body>'
            . '<img src="/a-100x80.jpg">'
            . '<img src="/b-200x100.jpg">'
            . '<img src="/c-300x150.jpg">'
            . '</body></html>';
        $out  = $img->process($html);

        // Dimensions filled from the WxH suffix for the third image.
        $this->assertStringContainsString('<img src="/c-300x150.jpg" width="300" height="150" loading="lazy"', $out);
        // First image is above-fold => eager.
        $this->assertMatchesRegularExpression('#<img src="/a-100x80\.jpg"[^>]*loading="eager"#', $out);
    }

    public function test_images_excluded_keyword_stays_eager(): void
    {
        $cfg  = new PerfConfig(['lazy_load' => true, 'lazy_load_exclusions' => ['hero']]);
        $urls = new UrlHelper('https://example.com', '/tmp');
        $img  = new ImagesHtml($cfg, $urls);
        // Push the hero past the above-fold window so only the exclusion matters.
        $html = '<!DOCTYPE html><html><body>'
            . '<img src="/x1.jpg"><img src="/x2.jpg">'
            . '<img class="hero" src="/hero.jpg">'
            . '</body></html>';
        $out  = $img->process($html);
        $this->assertMatchesRegularExpression('#<img class="hero" src="/hero\.jpg"[^>]*loading="eager"#', $out);
    }

    public function test_images_respect_author_loading_attr(): void
    {
        $cfg  = new PerfConfig(['lazy_load' => true]);
        $urls = new UrlHelper('https://example.com', '/tmp');
        $img  = new ImagesHtml($cfg, $urls);
        $html = '<!DOCTYPE html><html><body><img src="/a.jpg"><img src="/b.jpg"><img loading="lazy" src="/c.jpg"></body></html>';
        $out  = $img->process($html);
        // The author-set loading attribute is preserved (single occurrence).
        $this->assertSame(1, substr_count($out, 'loading="lazy"') + substr_count($out, "loading='lazy'"));
    }

    public function test_images_disabled_is_noop(): void
    {
        $cfg  = new PerfConfig([]);
        $img  = new ImagesHtml($cfg, new UrlHelper('https://example.com', '/tmp'));
        $html = '<!DOCTYPE html><html><body><img src="/a.jpg"></body></html>';
        $this->assertSame($html, $img->process($html));
    }

    // ---- IFrame -------------------------------------------------------------

    public function test_youtube_iframe_replaced_with_facade(): void
    {
        $cfg = new PerfConfig(['youtube_placeholder' => true]);
        $html = '<!DOCTYPE html><html><head></head><body>'
            . '<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ" title="vid"></iframe>'
            . '</body></html>';
        $out = (new IFrame($cfg))->process($html);

        $this->assertStringContainsString('wpmgr-yt', $out);
        $this->assertStringContainsString('i.ytimg.com/vi/dQw4w9WgXcQ', $out);
        $this->assertStringNotContainsString('<iframe', $out);
        $this->assertStringContainsString('data-wpmgr-yt-assets', $out);
    }

    public function test_youtube_disabled_is_noop(): void
    {
        $cfg = new PerfConfig([]);
        $html = '<html><body><iframe src="https://www.youtube.com/embed/x"></iframe></body></html>';
        $this->assertSame($html, (new IFrame($cfg))->process($html));
    }

    // ---- Speculation rules --------------------------------------------------

    public function test_speculation_rules_injected(): void
    {
        $cfg = new PerfConfig(['cache_link_prefetch' => true]);
        $out = (new SpeculationRules($cfg))->process('<html><head></head><body></body></html>');
        $this->assertStringContainsString('type="speculationrules"', $out);
        $this->assertStringContainsString('"prefetch"', $out);
    }

    public function test_speculation_rules_not_duplicated(): void
    {
        $cfg = new PerfConfig(['cache_link_prefetch' => true]);
        $html = '<html><head><script type="speculationrules">{}</script></head></html>';
        $this->assertSame($html, (new SpeculationRules($cfg))->process($html));
    }

    // ---- CDN ----------------------------------------------------------------

    public function test_cdn_rewrites_asset_urls(): void
    {
        $cfg  = new PerfConfig(['cdn' => true, 'cdn_url' => 'cdn.example.net', 'cdn_file_types' => 'all']);
        $urls = new UrlHelper('https://example.com', '/tmp');
        $html = '<html><head></head><body><img src="https://example.com/x.png"><script src="//example.com/y.js"></script></body></html>';
        $out  = (new CdnRewrite($cfg, $urls))->process($html);

        $this->assertStringContainsString('//cdn.example.net/x.png', $out);
        $this->assertStringContainsString('//cdn.example.net/y.js', $out);
        $this->assertStringContainsString('rel="preconnect" href="//cdn.example.net"', $out);
    }

    public function test_cdn_image_only_skips_css(): void
    {
        $cfg  = new PerfConfig(['cdn' => true, 'cdn_url' => 'cdn.example.net', 'cdn_file_types' => 'image']);
        $urls = new UrlHelper('https://example.com', '/tmp');
        $html = '<html><head><link rel="stylesheet" href="https://example.com/a.css"></head><body><img src="https://example.com/b.png"></body></html>';
        $out  = (new CdnRewrite($cfg, $urls))->process($html);

        $this->assertStringContainsString('//cdn.example.net/b.png', $out);
        $this->assertStringContainsString('https://example.com/a.css', $out, 'css not rewritten in image-only mode');
    }

    public function test_cdn_css_js_font_only_skips_images(): void
    {
        $cfg  = new PerfConfig(['cdn' => true, 'cdn_url' => 'cdn.example.net', 'cdn_file_types' => 'css_js_font']);
        $urls = new UrlHelper('https://example.com', '/tmp');
        $html = '<html><head><link rel="stylesheet" href="https://example.com/a.css"><script src="https://example.com/b.js"></script></head>'
            . '<body><img src="https://example.com/c.png"><img src="https://example.com/d.woff2"></body></html>';
        $out  = (new CdnRewrite($cfg, $urls))->process($html);

        $this->assertStringContainsString('//cdn.example.net/a.css', $out, 'css rewritten in css_js_font mode');
        $this->assertStringContainsString('//cdn.example.net/b.js', $out, 'js rewritten in css_js_font mode');
        $this->assertStringContainsString('//cdn.example.net/d.woff2', $out, 'font rewritten in css_js_font mode');
        $this->assertStringContainsString('https://example.com/c.png', $out, 'image not rewritten in css_js_font mode');
    }

    public function test_cdn_disabled_is_noop(): void
    {
        $cfg  = new PerfConfig(['cdn' => false]);
        $urls = new UrlHelper('https://example.com', '/tmp');
        $html = '<html><body><img src="https://example.com/x.png"></body></html>';
        $this->assertSame($html, (new CdnRewrite($cfg, $urls))->process($html));
    }
}
