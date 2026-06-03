<?php
/**
 * Gravatar — download + self-host gravatar avatar images.
 *
 * Comment/author avatars served from gravatar.com are a third-party request per
 * avatar (and a privacy leak). This transform downloads each referenced gravatar
 * once into the wpmgr asset cache and rewrites the <img> src (and any srcset
 * descriptor) to the local copy. Downloads are best-effort: a failed fetch
 * leaves the original gravatar.com URL untouched.
 *
 * Original implementation. NOT copied from a third-party plugin.
 *
 * @package WPMgr\Agent\Optimizer
 */

declare(strict_types=1);

namespace WPMgr\Agent\Optimizer;

/**
 * Self-hosts gravatar avatars.
 */
final class Gravatar
{
    private PerfConfig $config;

    private AssetCache $cache;

    /** @var callable(string):?string Downloader: url -> bytes|null. */
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
     * Self-host gravatars referenced by <img> tags.
     *
     * @param string $html Full page HTML.
     * @return string
     */
    public function process(string $html): string
    {
        if (!$this->config->selfHostGravatars || !$this->cache->isUsable()) {
            return $html;
        }
        if (!preg_match_all('/<img\b[^>]*>/i', $html, $tags)) {
            return $html;
        }
        $localized = [];
        foreach ($tags[0] as $tag) {
            if (stripos($tag, 'gravatar.com/avatar/') === false) {
                continue;
            }
            $newTag = $tag;

            $src = TagHelper::attr($tag, 'src');
            if ($src !== null && stripos($src, 'gravatar.com/avatar/') !== false) {
                $local = $this->cachedFor($src, $localized);
                if ($local !== null) {
                    $newTag = TagHelper::setAttr($newTag, 'src', $local);
                }
            }

            $srcset = TagHelper::attr($tag, 'srcset');
            if ($srcset !== null && $srcset !== '') {
                $newSrcset = $this->rewriteSrcset($srcset, $localized);
                if ($newSrcset !== $srcset) {
                    $newTag = TagHelper::setAttr($newTag, 'srcset', $newSrcset);
                }
            }

            if ($newTag !== $tag) {
                $html = str_replace($tag, $newTag, $html);
            }
        }
        return $html;
    }

    /**
     * Rewrite each URL in a srcset to its self-hosted copy.
     *
     * @param string                $srcset    Original srcset.
     * @param array<string,string>  $localized Per-request URL->local memo (by-ref).
     * @return string
     */
    private function rewriteSrcset(string $srcset, array &$localized): string
    {
        $parts = explode(',', $srcset);
        foreach ($parts as &$part) {
            $trimmed = trim($part);
            $url = strstr($trimmed, ' ', true);
            if ($url === false) {
                $url = $trimmed;
            }
            if (stripos($url, 'gravatar.com/avatar/') === false) {
                continue;
            }
            $local = $this->cachedFor($url, $localized);
            if ($local !== null) {
                $part = str_replace($url, $local, $part);
            }
        }
        unset($part);
        return implode(',', $parts);
    }

    /**
     * Download + cache one gravatar URL, memoised per request.
     *
     * @param string                $url       Gravatar URL.
     * @param array<string,string>  $localized URL->local memo (by-ref).
     * @return string|null
     */
    private function cachedFor(string $url, array &$localized): ?string
    {
        if (isset($localized[$url])) {
            return $localized[$url];
        }
        $name = $this->cache->name($url, 'gravatar', 'png');
        if (!$this->cache->exists($name)) {
            $bytes = ($this->downloader)($url);
            if (!is_string($bytes) || $bytes === '') {
                return null;
            }
            if (!$this->cache->write($name, $bytes)) {
                return null;
            }
        }
        $local = $this->cache->url($name);
        $localized[$url] = $local;
        return $local;
    }
}
