<?php
/**
 * SelfHost — download external (third-party) CSS/JS to the local asset cache and
 * rewrite the tag URLs to the local copy.
 *
 * For each <link rel=stylesheet> / <script src> pointing at a DIFFERENT origin
 * than the site, download the file once (via wp_remote_get), store it content-
 * addressed in the wpmgr asset cache, and rewrite the tag to the local URL.
 * Downloads are best-effort: any failure leaves the original external URL in
 * place. Integrity/crossorigin attributes are stripped on a successful rewrite
 * (the bytes are now same-origin). Resource hints (preconnect/dns-prefetch) to
 * the rewritten host are removed so the browser does not warm a now-unused
 * connection.
 *
 * Original implementation. NOT copied from a third-party plugin.
 *
 * @package WPMgr\Agent\Optimizer
 */

declare(strict_types=1);

namespace WPMgr\Agent\Optimizer;

/**
 * Self-hosts third-party CSS/JS assets.
 */
final class SelfHost
{
    private AssetCache $cache;

    private UrlHelper $urls;

    /** @var callable(string):?string Downloader: url -> bytes|null. */
    private $downloader;

    /**
     * @param AssetCache|null $cache      Asset store.
     * @param UrlHelper|null  $urls       URL classifier.
     * @param callable|null   $downloader Override fetcher (tests). url -> bytes|null.
     */
    public function __construct(?AssetCache $cache = null, ?UrlHelper $urls = null, ?callable $downloader = null)
    {
        $this->cache      = $cache ?? new AssetCache();
        $this->urls       = $urls ?? new UrlHelper();
        $this->downloader = $downloader ?? [$this, 'fetch'];
    }

    /**
     * Self-host external CSS + JS in the HTML.
     *
     * @param string $html Full page HTML.
     * @return string Transformed HTML.
     */
    public function process(string $html): string
    {
        if (!$this->cache->isUsable()) {
            return $html;
        }
        $html = $this->rewrite($html, '/<link\b[^>]*\brel=["\']stylesheet["\'][^>]*>/i', 'href', 'css');
        $html = $this->rewrite($html, '/<script\b[^>]*\bsrc=["\'][^"\']+["\'][^>]*>(?:<\/script>)?/i', 'src', 'js');
        return $html;
    }

    /**
     * Rewrite one tag family (stylesheets or scripts).
     *
     * @param string $html    Page HTML.
     * @param string $pattern preg pattern matching the tags.
     * @param string $attr    URL-bearing attribute (href|src).
     * @param string $ext     File extension for the cache.
     * @return string
     */
    private function rewrite(string $html, string $pattern, string $attr, string $ext): string
    {
        if (!preg_match_all($pattern, $html, $tags)) {
            return $html;
        }
        foreach ($tags[0] as $tag) {
            $url = TagHelper::attr($tag, $attr);
            if ($url === null || $url === '' || !$this->urls->isExternal($url)) {
                continue;
            }
            $localUrl = $this->localize($url, $ext);
            if ($localUrl === null) {
                continue;
            }
            $newTag = TagHelper::setAttr($tag, $attr, $localUrl);
            $newTag = TagHelper::setAttr($newTag, 'data-wpmgr-origin', $url);
            $newTag = TagHelper::removeAttr($newTag, 'integrity');
            $newTag = TagHelper::removeAttr($newTag, 'crossorigin');
            $html = str_replace($tag, $newTag, $html);
            $html = $this->stripResourceHints($html, $url);
        }
        return $html;
    }

    /**
     * Download + cache an external asset; return its local URL (or null).
     *
     * @param string $url External URL.
     * @param string $ext Extension.
     * @return string|null
     */
    private function localize(string $url, string $ext): ?string
    {
        $name = $this->cache->name($url, basename((string) (parse_url($url, PHP_URL_PATH) ?? '')), $ext);
        if ($this->cache->exists($name)) {
            return $this->cache->url($name);
        }
        $bytes = ($this->downloader)($url);
        if (!is_string($bytes) || $bytes === '') {
            return null;
        }
        if (!$this->cache->write($name, $bytes)) {
            return null;
        }
        return $this->cache->url($name);
    }

    /**
     * Remove preconnect/dns-prefetch hints that point at a now-self-hosted host.
     *
     * @param string $html Page HTML.
     * @param string $url  The original external URL (host extracted from it).
     * @return string
     */
    private function stripResourceHints(string $html, string $url): string
    {
        $host = parse_url(str_starts_with($url, '//') ? 'https:' . $url : $url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return $html;
        }
        $quoted = preg_quote($host, '/');
        $pattern = '/<link\b[^>]*\brel=["\'](?:dns-prefetch|preconnect)["\'][^>]*' . $quoted . '[^>]*>/i';
        return (string) preg_replace($pattern, '', $html);
    }

    /**
     * Default downloader using wp_remote_get; returns the body or null.
     *
     * @param string $url URL to fetch.
     * @return string|null
     */
    public function fetch(string $url): ?string
    {
        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }
        if (!function_exists('wp_remote_get')) {
            return null;
        }
        $response = wp_remote_get($url, ['timeout' => 10, 'redirection' => 3]);
        if (function_exists('is_wp_error') && is_wp_error($response)) {
            return null;
        }
        $code = function_exists('wp_remote_retrieve_response_code')
            ? (int) wp_remote_retrieve_response_code($response)
            : 0;
        if ($code < 200 || $code >= 300) {
            return null;
        }
        $body = function_exists('wp_remote_retrieve_body')
            ? (string) wp_remote_retrieve_body($response)
            : '';
        return $body === '' ? null : $body;
    }
}
