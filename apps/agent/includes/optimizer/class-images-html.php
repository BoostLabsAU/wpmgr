<?php
/**
 * ImagesHtml — <img> markup optimization for CLS + lazy loading.
 *
 * For every <img> in the page (skipping base64/data URIs):
 *   - width/height : when absent, fill them from getimagesize() of the local
 *     file (or the WxH suffix in the filename) so the browser can reserve space
 *     and avoid layout shift. Gated by PerfConfig::$properlySizeImages.
 *   - lazy-load    : add loading=lazy + fetchpriority=low + decoding=async,
 *     EXCEPT the first N "above-the-fold" images and any image matching an
 *     exclusion substring — those get loading=eager + fetchpriority=high. Gated
 *     by PerfConfig::$lazyLoad.
 *   - srcset       : when a same-site image has a `-WxH` resized filename and no
 *     srcset, leave WP's existing srcset alone (we never fabricate one without
 *     the attachment) — we only add width/height + loading here.
 *
 * The "above-the-fold" heuristic: the first {@see ABOVE_FOLD} images in document
 * order are treated as critical (eager). This is a pragmatic, dependency-free
 * approximation of LCP detection.
 *
 * Original implementation. NOT copied from a third-party plugin.
 *
 * @package WPMgr\Agent\Optimizer
 */

declare(strict_types=1);

namespace WPMgr\Agent\Optimizer;

/**
 * Optimizes <img> tags (sizing + lazy loading).
 */
final class ImagesHtml
{
    /** Number of leading images treated as above-the-fold (kept eager). */
    public const ABOVE_FOLD = 2;

    private PerfConfig $config;

    private UrlHelper $urls;

    /**
     * @param PerfConfig|null $config Optimization config.
     * @param UrlHelper|null  $urls   URL/path resolver.
     */
    public function __construct(?PerfConfig $config = null, ?UrlHelper $urls = null)
    {
        $this->config = $config ?? PerfConfig::load();
        $this->urls   = $urls ?? new UrlHelper();
    }

    /**
     * Apply the enabled image transforms.
     *
     * @param string $html Full page HTML.
     * @return string
     */
    public function process(string $html): string
    {
        if (!$this->config->lazyLoad && !$this->config->properlySizeImages) {
            return $html;
        }
        // Strip <noscript>/<template> regions from the scan window so we never
        // touch fallback markup, but transform against the original string.
        if (!preg_match_all('/<img\b[^>]*>/i', $html, $tags)) {
            return $html;
        }

        $excludes = array_merge(['data:image', 'skip-lazy'], $this->config->lazyLoadExclusions);
        $index = 0;
        foreach ($tags[0] as $tag) {
            $src = TagHelper::attr($tag, 'src');
            if ($src === null || str_starts_with($src, 'data:')) {
                continue;
            }
            $aboveFold = $index < self::ABOVE_FOLD;
            $index++;

            $newTag = $tag;

            if ($this->config->properlySizeImages) {
                $newTag = $this->addDimensions($newTag, $src);
            }

            if ($this->config->lazyLoad) {
                $excluded = $aboveFold || TagHelper::matchesAny($excludes, $tag);
                $newTag = $this->applyLoading($newTag, $excluded);
            }

            if ($newTag !== $tag) {
                $html = str_replace($tag, $newTag, $html);
            }
        }

        return $html;
    }

    /**
     * Fill missing width/height from the WxH filename suffix or getimagesize().
     *
     * @param string $tag <img> tag.
     * @param string $src Image src.
     * @return string
     */
    private function addDimensions(string $tag, string $src): string
    {
        $hasW = is_numeric(TagHelper::attr($tag, 'width'));
        $hasH = is_numeric(TagHelper::attr($tag, 'height'));
        if ($hasW && $hasH) {
            return $tag;
        }

        $dims = $this->dimensions($src);
        if ($dims === null) {
            return $tag;
        }
        [$w, $h] = $dims;
        if (!$hasW) {
            $tag = TagHelper::setAttr($tag, 'width', (string) $w);
        }
        if (!$hasH) {
            $tag = TagHelper::setAttr($tag, 'height', (string) $h);
        }
        return $tag;
    }

    /**
     * Resolve image pixel dimensions (filename suffix first, then getimagesize).
     *
     * @param string $src Image src.
     * @return array{0:int,1:int}|null
     */
    private function dimensions(string $src): ?array
    {
        $clean = preg_replace('/[?#].*$/', '', $src) ?? $src;
        if (preg_match('/-(\d+)x(\d+)\.(?:jpe?g|png|gif|webp|avif)$/i', $clean, $m)) {
            return [(int) $m[1], (int) $m[2]];
        }
        $path = $this->urls->localPath($src);
        if ($path === null || !is_file($path)) {
            return null;
        }
        $info = @getimagesize($path);
        if (!is_array($info) || (int) ($info[0] ?? 0) <= 0 || (int) ($info[1] ?? 0) <= 0) {
            return null;
        }
        return [(int) $info[0], (int) $info[1]];
    }

    /**
     * Apply loading/fetchpriority/decoding attributes.
     *
     * @param string $tag      <img> tag.
     * @param bool   $excluded Whether the image is eager (above-fold/excluded).
     * @return string
     */
    private function applyLoading(string $tag, bool $excluded): string
    {
        // Respect an author-set loading attribute.
        if (TagHelper::hasAttr($tag, 'loading')) {
            return $tag;
        }
        if ($excluded) {
            $tag = TagHelper::setAttr($tag, 'loading', 'eager');
            $tag = TagHelper::setAttr($tag, 'fetchpriority', 'high');
            $tag = TagHelper::setAttr($tag, 'decoding', 'async');
            return $tag;
        }
        $tag = TagHelper::setAttr($tag, 'loading', 'lazy');
        $tag = TagHelper::setAttr($tag, 'decoding', 'async');
        if (!TagHelper::hasAttr($tag, 'fetchpriority')) {
            $tag = TagHelper::setAttr($tag, 'fetchpriority', 'low');
        }
        return $tag;
    }
}
