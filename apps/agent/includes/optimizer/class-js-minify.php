<?php
/**
 * JsMinify — minify local <script src> assets.
 *
 * For each same-site external script whose file resolves on disk, minify it with
 * matthiasmullie/minify, content-address it into the wpmgr asset cache, and
 * rewrite the tag's src to the minified URL. Files already named `*.min.js` are
 * skipped (assumed pre-minified). Inline scripts, external sources, and already-
 * cached files are left untouched.
 *
 * Standard minify-and-rewrite technique; original implementation. NOT copied
 * from a third-party plugin.
 *
 * @package WPMgr\Agent\Optimizer
 */

declare(strict_types=1);

namespace WPMgr\Agent\Optimizer;

use MatthiasMullie\Minify;

/**
 * Minifies local JS assets and rewrites their srcs.
 */
final class JsMinify
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
     * Rewrite all eligible script srcs to minified, cached copies.
     *
     * @param string $html Full page HTML.
     * @return string Transformed HTML (unchanged when the cache is unusable).
     */
    public function process(string $html): string
    {
        if (!$this->cache->isUsable()) {
            return $html;
        }

        if (!preg_match_all('/<script\b[^>]*\bsrc=["\'][^"\']+["\'][^>]*>(?:<\/script>)?/i', $html, $tags)) {
            return $html;
        }

        foreach ($tags[0] as $tag) {
            $src = TagHelper::attr($tag, 'src');
            if ($src === null || $src === '') {
                continue;
            }
            if (preg_match('/\.min\.js(\?|$)/i', $src)) {
                continue;
            }
            $path = $this->urls->localPath($src);
            if ($path === null || !is_file($path)) {
                continue;
            }
            $minifiedUrl = $this->minifiedUrlFor($path);
            if ($minifiedUrl === null) {
                continue;
            }
            $newTag = TagHelper::setAttr($tag, 'src', $minifiedUrl);
            $html = str_replace($tag, $newTag, $html);
        }

        return $html;
    }

    /**
     * Minify (or reuse a cached minify of) a JS file; return its cached URL.
     *
     * @param string $path Absolute path to the source JS.
     * @return string|null Cached URL, or null on failure.
     */
    public function minifiedUrlFor(string $path): ?string
    {
        $source = @file_get_contents($path);
        if ($source === false || $source === '') {
            return null;
        }

        $name = $this->cache->name($source, basename($path), 'js');
        if ($this->cache->exists($name)) {
            return $this->cache->url($name);
        }

        try {
            $minifier = new Minify\JS();
            $minifier->add($source);
            $minified = $minifier->minify();
        } catch (\Throwable $e) {
            return null;
        }

        if ($minified === '' || strlen($minified) >= strlen($source)) {
            $minified = $source;
        }
        if (!$this->cache->write($name, $minified)) {
            return null;
        }
        return $this->cache->url($name);
    }
}
