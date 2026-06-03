<?php
/**
 * Font — font-display optimization.
 *
 * Three independent transforms, each gated by its PerfConfig flag:
 *   - display-swap : add `&display=swap` to Google Fonts <link> hrefs and inject
 *     `font-display:swap` into every @font-face block in inline <style> tags, so
 *     text renders immediately with a fallback while the web font loads (kills
 *     the FOIT / invisible-text flash).
 *   - optimize-google : self-host the Google Fonts stylesheet (download the CSS,
 *     rewrite its font URLs to local copies) and rewrite the <link> href to the
 *     local file, removing the render-blocking cross-origin request.
 *   - preload : heuristically emit `<link rel=preload as=font>` for the first
 *     few woff2 files referenced by self-hosted/inline font CSS.
 *
 * Original implementation. NOT copied from a third-party plugin.
 *
 * @package WPMgr\Agent\Optimizer
 */

declare(strict_types=1);

namespace WPMgr\Agent\Optimizer;

/**
 * Applies font-display + Google-Fonts self-host + font preload.
 */
final class Font
{
    private PerfConfig $config;

    private AssetCache $cache;

    /** @var callable(string):?string Downloader for the Google Fonts CSS. */
    private $downloader;

    /**
     * @param PerfConfig|null $config     Optimization config.
     * @param AssetCache|null $cache      Asset store.
     * @param callable|null   $downloader Override fetcher (tests). url -> bytes|null.
     */
    public function __construct(?PerfConfig $config = null, ?AssetCache $cache = null, ?callable $downloader = null)
    {
        $this->config     = $config ?? PerfConfig::load();
        $this->cache      = $cache ?? new AssetCache();
        $this->downloader = $downloader ?? [new SelfHost($this->cache), 'fetch'];
    }

    /**
     * Run the enabled font transforms over the page.
     *
     * @param string $html Full page HTML.
     * @return string
     */
    public function process(string $html): string
    {
        if ($this->config->fontsDisplaySwap) {
            $html = $this->addDisplaySwapToGoogleLinks($html);
            $html = $this->addDisplaySwapToInlineStyles($html);
        }
        if ($this->config->fontsOptimizeGoogle && $this->cache->isUsable()) {
            $html = $this->selfHostGoogleFonts($html);
        }
        if ($this->config->fontsPreload) {
            $html = $this->preloadFonts($html);
        }
        return $html;
    }

    /**
     * Add `display=swap` to every Google Fonts <link>.
     *
     * @param string $html Page HTML.
     * @return string
     */
    private function addDisplaySwapToGoogleLinks(string $html): string
    {
        if (!preg_match_all('/<link\b[^>]*href=["\'][^"\']*fonts\.googleapis\.com\/css[^"\']*["\'][^>]*>/i', $html, $tags)) {
            return $html;
        }
        foreach ($tags[0] as $tag) {
            $href = TagHelper::attr($tag, 'href');
            if ($href === null) {
                continue;
            }
            $new = preg_match('/[?&]display=/', $href)
                ? (string) preg_replace('/display=[a-z]+/i', 'display=swap', $href)
                : $href . (strpos($href, '?') !== false ? '&' : '?') . 'display=swap';
            $html = str_replace($tag, TagHelper::setAttr($tag, 'href', $new), $html);
        }
        return $html;
    }

    /**
     * Inject `font-display:swap` into @font-face blocks inside inline <style>.
     *
     * @param string $html Page HTML.
     * @return string
     */
    private function addDisplaySwapToInlineStyles(string $html): string
    {
        return (string) preg_replace_callback(
            '/<style\b[^>]*>(.*?)<\/style>/is',
            static function (array $m): string {
                return str_replace($m[1], self::injectDisplaySwap($m[1]), $m[0]);
            },
            $html
        );
    }

    /**
     * Inject `font-display:swap` into a CSS string's @font-face blocks (also
     * normalises any existing font-display to swap).
     *
     * @param string $css CSS.
     * @return string
     */
    public static function injectDisplaySwap(string $css): string
    {
        if (stripos($css, '@font-face') === false) {
            return $css;
        }
        // Drop existing font-display declarations, then add swap right after
        // each @font-face {.
        $css = (string) preg_replace('/font-display\s*:\s*(swap|block|fallback|optional|auto)\s*;?/i', '', $css);
        return (string) preg_replace('/@font-face\s*\{/i', '@font-face{font-display:swap;', $css);
    }

    /**
     * Self-host the Google Fonts stylesheet(s) referenced by <link> tags.
     *
     * @param string $html Page HTML.
     * @return string
     */
    private function selfHostGoogleFonts(string $html): string
    {
        if (!preg_match_all('/<link\b[^>]*href=["\']([^"\']*fonts\.googleapis\.com\/css[^"\']*)["\'][^>]*>/i', $html, $tags, PREG_SET_ORDER)) {
            return $html;
        }
        foreach ($tags as $set) {
            $tag  = $set[0];
            $href = html_entity_decode($set[1]);
            $localUrl = $this->cachedGoogleCssUrl($href);
            if ($localUrl === null) {
                continue;
            }
            $html = str_replace($tag, TagHelper::setAttr($tag, 'href', $localUrl), $html);
        }
        return $html;
    }

    /**
     * Download + cache a Google Fonts stylesheet, rewriting its font URLs to
     * local copies; return the local CSS URL (or null on any failure).
     *
     * @param string $href Google Fonts CSS URL.
     * @return string|null
     */
    private function cachedGoogleCssUrl(string $href): ?string
    {
        if (str_starts_with($href, '//')) {
            $href = 'https:' . $href;
        }
        $name = $this->cache->name($href, 'google-font', 'css');
        if ($this->cache->exists($name)) {
            return $this->cache->url($name);
        }
        $css = ($this->downloader)($href);
        if (!is_string($css) || $css === '') {
            return null;
        }
        // Download the woff2/woff files and rewrite their URLs to local copies.
        foreach ($this->extractFontUrls($css) as $fontUrl) {
            $fontName = $this->cache->name($fontUrl, basename((string) (parse_url($fontUrl, PHP_URL_PATH) ?? '')), pathinfo($fontUrl, PATHINFO_EXTENSION) ?: 'woff2');
            if (!$this->cache->exists($fontName)) {
                $fontBytes = ($this->downloader)($fontUrl);
                if (!is_string($fontBytes) || strlen($fontBytes) < 100) {
                    continue;
                }
                $this->cache->write($fontName, $fontBytes);
            }
            $css = str_replace($fontUrl, $this->cache->url($fontName), $css);
        }
        if (!$this->cache->write($name, $css)) {
            return null;
        }
        return $this->cache->url($name);
    }

    /**
     * Emit preload tags for the first few woff2 fonts referenced by inline /
     * self-hosted font CSS (heuristic: critical above-the-fold fonts).
     *
     * @param string $html Page HTML.
     * @return string
     */
    private function preloadFonts(string $html): string
    {
        $fonts = [];
        if (preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $html, $styles)) {
            foreach ($styles[1] as $css) {
                foreach ($this->extractFontUrls($css) as $url) {
                    if (preg_match('/\.woff2(\?|$)/i', $url)) {
                        $fonts[$url] = true;
                    }
                }
            }
        }
        if ($fonts === []) {
            return $html;
        }
        // Heuristic cap — preload at most 2 fonts so we do not flood the head.
        $fonts = array_slice(array_keys($fonts), 0, 2);
        $links = '';
        foreach ($fonts as $url) {
            $links .= '<link rel="preload" href="' . htmlspecialchars($url, ENT_QUOTES) . '" as="font" type="font/woff2" crossorigin>';
        }
        if (stripos($html, '</head>') !== false) {
            return (string) preg_replace('/<\/head>/i', $links . '</head>', $html, 1);
        }
        return $links . $html;
    }

    /**
     * Extract font file URLs (woff2/woff/ttf/otf) from CSS url() declarations.
     *
     * @param string $css CSS.
     * @return list<string>
     */
    private function extractFontUrls(string $css): array
    {
        if (!preg_match_all('/url\(\s*["\']?(https?:\/\/[^"\')]+\.(?:woff2|woff|ttf|otf))[^"\')]*["\']?\s*\)/i', $css, $m)) {
            return [];
        }
        return array_values(array_unique($m[1]));
    }
}
