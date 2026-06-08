<?php
/**
 * Font — font-display optimization.
 *
 * Four independent transforms, each gated by its PerfConfig flag:
 *   - display-swap        : add `&display=swap` to Google Fonts <link> hrefs and
 *     inject `font-display:swap` into every @font-face block in inline <style>
 *     tags, so text renders immediately with a fallback while the web font loads
 *     (kills the FOIT / invisible-text flash).
 *   - optimize-google     : self-host the Google Fonts stylesheet (download the
 *     CSS, rewrite its font URLs to local copies) and rewrite the <link> href to
 *     the local file, removing the render-blocking cross-origin request.
 *   - preload             : heuristically emit `<link rel=preload as=font>` for
 *     the first few woff2 files referenced by self-hosted/inline font CSS.
 *   - transcode-woff2     : for each self-hosted TTF/OTF/WOFF font, request a
 *     WOFF2 transcode from the media-encoder via the CP, cache the result, and
 *     rewrite the @font-face src to serve WOFF2-first with the original as a
 *     format() fallback. Always serves the original on any miss/failure. Never
 *     blocks the page on a pending transcode.
 *
 * Standard font-display optimization technique.
 *
 * @package WPMgr\Agent\Optimizer
 */

declare(strict_types=1);

namespace WPMgr\Agent\Optimizer;

/**
 * Applies font-display + Google-Fonts self-host + font preload + woff2 transcode.
 */
final class Font
{
    /**
     * Source extensions that are candidates for WOFF2 transcoding.
     * WOFF2/SVG/EOT are intentionally excluded: woff2 is already optimal,
     * svg/eot are legacy-only and not worth transcoding.
     */
    private const TRANSCODE_EXTS = ['ttf', 'otf', 'woff'];

    /** CSS format() hint per extension. */
    private const FORMAT_HINTS = [
        'woff'  => 'woff',
        'ttf'   => 'truetype',
        'otf'   => 'opentype',
    ];

    private PerfConfig $config;

    private AssetCache $cache;

    /** @var callable(string):?string Downloader for the Google Fonts CSS. */
    private $downloader;

    /** @var FontTranscodeClientInterface|null Transcode client; null disables the feature. */
    private ?FontTranscodeClientInterface $transcodeClient;

    /**
     * @param PerfConfig|null                    $config          Optimization config.
     * @param AssetCache|null                    $cache           Asset store.
     * @param callable|null                      $downloader      Override fetcher (tests). url -> bytes|null.
     * @param FontTranscodeClientInterface|null  $transcodeClient Woff2 transcode client; null = feature off.
     */
    public function __construct(
        ?PerfConfig $config = null,
        ?AssetCache $cache = null,
        ?callable $downloader = null,
        ?FontTranscodeClientInterface $transcodeClient = null
    ) {
        $this->config          = $config ?? PerfConfig::load();
        $this->cache           = $cache ?? new AssetCache();
        $this->downloader      = $downloader ?? [new SelfHost($this->cache), 'fetch'];
        $this->transcodeClient = $transcodeClient;
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
        if ($this->config->fontsTranscodeWoff2 && $this->transcodeClient !== null) {
            $html = $this->transcodeWoff2InlineStyles($html);
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
     * Attempt WOFF2 transcoding for TTF/OTF/WOFF fonts in inline <style> blocks.
     *
     * For each @font-face block that contains a src URL whose extension is in
     * TRANSCODE_EXTS and whose bytes are locally accessible (via the same
     * downloader used for self-hosting), request a WOFF2 from the CP transcode
     * endpoint. On state=="ready", rewrite the src to:
     *
     *   src: url('<local-woff2-url>') format('woff2'),
     *        url('<original-url>') format('<orig-format>');
     *
     * On "pending" or "negative" (or any error): leave the original src intact.
     * On any failure mid-rewrite: abandon the individual rewrite; never corrupt HTML.
     *
     * @param string $html Page HTML.
     * @return string
     */
    private function transcodeWoff2InlineStyles(string $html): string
    {
        if ($this->transcodeClient === null) {
            return $html; // @phpstan-ignore-line — guard re-checked for clarity
        }
        return (string) preg_replace_callback(
            '/<style\b[^>]*>(.*?)<\/style>/is',
            function (array $m) use (&$html): string {
                // Process the CSS inside the <style> block.
                $newCss = $this->rewriteFontFaceForWoff2($m[1]);
                return str_replace($m[1], $newCss, $m[0]);
            },
            $html
        );
    }

    /**
     * Rewrite @font-face src declarations in a CSS string for WOFF2-first delivery.
     *
     * Each @font-face block is scanned for a src that contains a URL pointing at
     * a TTF/OTF/WOFF font. When transcoding succeeds (state=="ready"), the src is
     * rewritten. Any failure leaves the src unchanged.
     *
     * @param string $css CSS containing zero or more @font-face blocks.
     * @return string
     */
    private function rewriteFontFaceForWoff2(string $css): string
    {
        if (stripos($css, '@font-face') === false) {
            return $css;
        }

        // Match each @font-face { ... } block (non-greedy, handles nested braces
        // defensively by relying on the fact that @font-face blocks contain no
        // nested rule-sets in valid CSS).
        $result = (string) preg_replace_callback(
            '/@font-face\s*\{([^}]*)\}/is',
            function (array $m): string {
                $block    = $m[0]; // the full @font-face { ... }
                $interior = $m[1]; // the interior declarations

                try {
                    $rewritten = $this->tryRewriteFontFaceBlock($block, $interior);
                    return $rewritten ?? $block;
                } catch (\Throwable $e) {
                    return $block; // safety: never corrupt the CSS
                }
            },
            $css
        );

        return $result === null ? $css : $result;
    }

    /**
     * Try to rewrite a single @font-face block for WOFF2-first delivery.
     *
     * Returns the rewritten block on success, or null to signal "keep original".
     *
     * @param string $block    Full @font-face { ... } string.
     * @param string $interior Interior declarations.
     * @return string|null Rewritten block, or null = keep original.
     */
    private function tryRewriteFontFaceBlock(string $block, string $interior): ?string
    {
        if ($this->transcodeClient === null) {
            return null;
        }

        // Find the src declaration. We look for `src:` followed by any number
        // of url() tokens (possibly multi-line). The full src value ends at the
        // next declaration (identified by a property name + colon) or the closing
        // brace. This handles both single- and multi-url src values.
        if (!preg_match('/\bsrc\s*:\s*((?:(?:url\s*\([^)]*\)|format\s*\([^)]*\)|local\s*\([^)]*\)|[^;{}]))+)/is', $interior, $srcMatch)) {
            return null;
        }

        $srcDecl    = $srcMatch[0]; // e.g. "src: url('x.ttf') format('truetype')"
        $srcValue   = $srcMatch[1]; // everything after "src:"

        // Extract the first URL in the src value.
        if (!preg_match('/url\(\s*["\']?([^"\')\s]+)["\']?\s*\)/i', $srcValue, $urlMatch)) {
            return null;
        }
        $originalUrl = $urlMatch[1];

        // Determine the extension.
        $ext = strtolower((string) pathinfo(strtok($originalUrl, '?#') ?: '', PATHINFO_EXTENSION));
        if (!in_array($ext, self::TRANSCODE_EXTS, true)) {
            return null; // skip woff2/svg/eot/unknown
        }

        $formatHint = self::FORMAT_HINTS[$ext] ?? null;
        if ($formatHint === null) {
            return null; // unknown extension — serve original
        }

        // Fetch the source font bytes for hashing + upload.
        $sourceBytes = ($this->downloader)($originalUrl);
        if (!is_string($sourceBytes) || strlen($sourceBytes) < 12) {
            return null; // font not accessible — serve original
        }

        // Request transcode from the CP (or serve from local cache if ready).
        $result = $this->transcodeClient->resolve($sourceBytes, $ext);
        if ($result === null || $result['state'] !== 'ready' || $result['woff2_url'] === '') {
            return null; // pending, negative, or any error — serve original
        }

        $woff2Url = $result['woff2_url'];

        // Build the new src value: woff2 first, original as fallback.
        $escapedWoff2    = $this->cssSafeUrl($woff2Url);
        $escapedOriginal = $this->cssSafeUrl($originalUrl);
        $newSrcValue = "url('{$escapedWoff2}') format('woff2'), url('{$escapedOriginal}') format('{$formatHint}')";

        // Rewrite the src declaration in the block, preserving all other descriptors.
        $newSrcDecl = str_replace($srcValue, $newSrcValue, $srcDecl);
        return str_replace($srcDecl, $newSrcDecl, $block);
    }

    /**
     * Escape a URL for use inside a CSS url('…') token.
     * Replaces single quotes and backslashes — the only characters that must be
     * escaped inside a single-quoted CSS string.
     *
     * @param string $url Raw URL.
     * @return string
     */
    private function cssSafeUrl(string $url): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $url);
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
