<?php
/**
 * CssMinify — minify local <link rel=stylesheet> assets.
 *
 * For each same-site stylesheet whose file resolves on disk, minify it with
 * matthiasmullie/minify, content-address it into the wpmgr asset cache, and
 * rewrite the tag's href to the minified URL. Files already named `*.min.css`
 * are skipped (assumed pre-minified). External, excluded, or already-cached
 * sources are left untouched.
 *
 * Standard minify-and-rewrite technique (same pattern WP Rocket / Autoptimize
 * use, GPLv2); original implementation. NOT copied from a third-party plugin.
 *
 * @package WPMgr\Agent\Optimizer
 */

declare(strict_types=1);

namespace WPMgr\Agent\Optimizer;

use MatthiasMullie\Minify;

/**
 * Minifies local CSS assets and rewrites their hrefs.
 */
final class CssMinify
{
    private AssetCache $cache;

    private UrlHelper $urls;

    /**
     * @param AssetCache|null $cache Asset store.
     * @param UrlHelper|null  $urls  URL/path resolver.
     */
    public function __construct(?AssetCache $cache = null, ?UrlHelper $urls = null)
    {
        $this->cache = $cache ?? new AssetCache();
        $this->urls  = $urls ?? new UrlHelper();
    }

    /**
     * Rewrite all eligible stylesheet links to minified, cached copies.
     *
     * @param string $html Full page HTML.
     * @return string Transformed HTML (unchanged when the cache is unusable).
     */
    public function process(string $html): string
    {
        if (!$this->cache->isUsable()) {
            return $html;
        }

        if (!preg_match_all('/<link\b[^>]*\brel=["\']stylesheet["\'][^>]*>/i', $html, $tags)) {
            return $html;
        }

        foreach ($tags[0] as $tag) {
            $href = TagHelper::attr($tag, 'href');
            if ($href === null || $href === '') {
                continue;
            }
            // Skip already-minified sources (just leave the original).
            if (preg_match('/\.min\.css(\?|$)/i', $href)) {
                continue;
            }
            $path = $this->urls->localPath($href);
            if ($path === null || !is_file($path)) {
                continue;
            }
            $minifiedUrl = $this->minifiedUrlFor($path);
            if ($minifiedUrl === null) {
                continue;
            }
            $newTag = TagHelper::setAttr($tag, 'href', $minifiedUrl);
            $html = str_replace($tag, $newTag, $html);
        }

        return $html;
    }

    /**
     * Minify (or reuse a cached minify of) a CSS file; return its cached URL.
     *
     * @param string $path Absolute path to the source CSS.
     * @return string|null Cached URL, or null on failure.
     */
    public function minifiedUrlFor(string $path): ?string
    {
        $source = @file_get_contents($path);
        if ($source === false || $source === '') {
            return null;
        }

        $name = $this->cache->name($source, basename($path), 'css');
        if ($this->cache->exists($name)) {
            return $this->cache->url($name);
        }

        try {
            $minifier = new Minify\CSS();
            $minifier->add($source);
            $minified = $minifier->minify();
        } catch (\Throwable $e) {
            return null;
        }

        // Only adopt the minified copy when it is actually smaller.
        if (strlen($minified) >= strlen($source)) {
            $minified = $source;
        }
        if (!$this->cache->write($name, $minified)) {
            return null;
        }
        return $this->cache->url($name);
    }

    /**
     * Minify raw CSS bytes (used by font/RUCSS helpers). Returns the input
     * unchanged on any minifier failure.
     *
     * @param string $css Raw CSS.
     * @return string
     */
    public static function minifyString(string $css): string
    {
        if ($css === '') {
            return $css;
        }
        try {
            $minifier = new Minify\CSS();
            $minifier->add($css);
            $out = $minifier->minify();
            return $out !== '' ? $out : $css;
        } catch (\Throwable $e) {
            return $css;
        }
    }
}
