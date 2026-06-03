<?php
/**
 * CSS/JS minify tests: minification reduces bytes, rewrites the tag URL to the
 * cached copy, and already-minified `*.min.css` / `*.min.js` sources are left
 * untouched.
 *
 * @package WPMgr\Agent\Tests
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests;

use WPMgr\Agent\Optimizer\AssetCache;
use WPMgr\Agent\Optimizer\CssMinify;
use WPMgr\Agent\Optimizer\JsMinify;
use WPMgr\Agent\Optimizer\UrlHelper;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Optimizer\CssMinify
 * @covers \WPMgr\Agent\Optimizer\JsMinify
 * @covers \WPMgr\Agent\Optimizer\AssetCache
 */
final class OptimizerMinifyTest extends TestCase
{
    private string $base = '';

    private string $docRoot = '';

    private string $cacheDir = '';

    protected function set_up(): void
    {
        parent::set_up();
        $this->base     = sys_get_temp_dir() . '/wpmgr-min-' . uniqid('', true);
        $this->docRoot  = $this->base . '/site';
        $this->cacheDir = $this->base . '/cache/assets';
        @mkdir($this->docRoot . '/wp-content', 0o777, true);
        @mkdir($this->cacheDir, 0o777, true);
    }

    protected function tear_down(): void
    {
        $this->rrmdir($this->base);
        parent::tear_down();
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

    private function cache(): AssetCache
    {
        return new AssetCache($this->cacheDir, 'https://example.com/wp-content/cache/assets');
    }

    private function urls(): UrlHelper
    {
        return new UrlHelper('https://example.com', $this->docRoot);
    }

    public function test_css_minify_reduces_bytes_and_rewrites_href(): void
    {
        $css = "/* comment */\nbody {\n    color:   red;\n    margin: 0   0   0   0;\n}\n\n\n.foo { padding: 10px; }";
        file_put_contents($this->docRoot . '/wp-content/style.css', $css);

        $html = '<link rel="stylesheet" href="https://example.com/wp-content/style.css">';
        $out  = (new CssMinify($this->cache(), $this->urls()))->process($html);

        // The href was rewritten to a cached asset.
        $this->assertMatchesRegularExpression('#href="https://example\.com/wp-content/cache/assets/[0-9a-f]{12}\.style\.css"#', $out);

        // The cached file is smaller than the source.
        $files = glob($this->cacheDir . '/*.css');
        $this->assertNotEmpty($files);
        $this->assertLessThan(strlen($css), filesize($files[0]), 'minified CSS must be smaller');
    }

    public function test_already_minified_css_is_skipped(): void
    {
        file_put_contents($this->docRoot . '/wp-content/app.min.css', 'body{color:red}');
        $html = '<link rel="stylesheet" href="https://example.com/wp-content/app.min.css">';
        $out  = (new CssMinify($this->cache(), $this->urls()))->process($html);

        $this->assertSame($html, $out, 'already-min CSS must be left untouched');
        $this->assertEmpty(glob($this->cacheDir . '/*.css'));
    }

    public function test_js_minify_reduces_bytes_and_rewrites_src(): void
    {
        $js = "function hello(name) {\n    // greet\n    var msg = 'hi ' + name;\n    console.log(msg);\n    return msg;\n}\n";
        file_put_contents($this->docRoot . '/wp-content/app.js', $js);

        $html = '<script src="https://example.com/wp-content/app.js"></script>';
        $out  = (new JsMinify($this->cache(), $this->urls()))->process($html);

        $this->assertMatchesRegularExpression('#src="https://example\.com/wp-content/cache/assets/[0-9a-f]{12}\.app\.js"#', $out);

        $files = glob($this->cacheDir . '/*.js');
        $this->assertNotEmpty($files);
        $this->assertLessThan(strlen($js), filesize($files[0]), 'minified JS must be smaller');
    }

    public function test_already_minified_js_is_skipped(): void
    {
        file_put_contents($this->docRoot . '/wp-content/app.min.js', 'var a=1;');
        $html = '<script src="https://example.com/wp-content/app.min.js"></script>';
        $out  = (new JsMinify($this->cache(), $this->urls()))->process($html);

        $this->assertSame($html, $out);
        $this->assertEmpty(glob($this->cacheDir . '/*.js'));
    }

    public function test_external_css_is_not_minified(): void
    {
        $html = '<link rel="stylesheet" href="https://cdn.other.com/x.css">';
        $out  = (new CssMinify($this->cache(), $this->urls()))->process($html);
        $this->assertSame($html, $out, 'external CSS is not a minify target');
    }
}
