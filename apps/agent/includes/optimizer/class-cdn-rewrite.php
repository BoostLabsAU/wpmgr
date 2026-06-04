<?php
/**
 * CdnRewrite — rewrite same-site static-asset URLs to the configured CDN host.
 *
 * When a custom CDN is configured (PerfConfig::$cdn + $cdnUrl), every same-site
 * URL whose path ends in a CDN-eligible extension (per $cdnFileTypes) is
 * rewritten host-only to the CDN host. The site scheme/host is matched
 * protocol-relative so http/https/`//` references are all caught. A preconnect
 * hint to the CDN is added once.
 *
 * Runs LAST in the pipeline so it also picks up the local minified/self-hosted
 * asset URLs the earlier stages produced.
 *
 * Standard CDN URL-rewrite technique.
 *
 * @package WPMgr\Agent\Optimizer
 */

declare(strict_types=1);

namespace WPMgr\Agent\Optimizer;

/**
 * Rewrites static-asset URLs to the CDN host.
 */
final class CdnRewrite
{
    /** Extension groups per cdn_file_types. */
    private const GROUPS = [
        'all'         => 'css|js|eot|otf|ttf|woff|woff2|gif|jpe?g|png|svg|webp|avif|ico|mp4|webm|ogg',
        'css_js_font' => 'css|js|eot|otf|ttf|woff|woff2',
        'image'       => 'gif|jpe?g|png|svg|webp|avif|ico',
    ];

    private PerfConfig $config;

    private UrlHelper $urls;

    /**
     * @param PerfConfig|null $config Optimization config.
     * @param UrlHelper|null  $urls   URL helper (provides the site host).
     */
    public function __construct(?PerfConfig $config = null, ?UrlHelper $urls = null)
    {
        $this->config = $config ?? PerfConfig::load();
        $this->urls   = $urls ?? new UrlHelper();
    }

    /**
     * Rewrite eligible asset URLs to the CDN host.
     *
     * @param string $html Full page HTML.
     * @return string
     */
    public function process(string $html): string
    {
        if (!$this->config->cdn || $this->config->cdnUrl === '') {
            return $html;
        }
        $siteHost = $this->urls->host();
        if ($siteHost === '') {
            return $html;
        }
        $cdnHost = $this->cdnHost();
        if ($cdnHost === '' || strtolower($cdnHost) === $siteHost) {
            return $html;
        }

        $exts = self::GROUPS[$this->config->cdnFileTypes] ?? self::GROUPS['all'];

        // Match //host/path.ext (with optional scheme) up to a delimiter.
        $hostQuoted = preg_quote($siteHost, '/');
        $pattern = '/(https?:)?\/\/' . $hostQuoted . '(\/[^"\'\s)>]*?\.(?:' . $exts . '))(\?[^"\'\s)>]*)?/i';

        $html = (string) preg_replace_callback(
            $pattern,
            static function (array $m) use ($cdnHost): string {
                $path  = $m[2];
                $query = $m[3] ?? '';
                return '//' . $cdnHost . $path . $query;
            },
            $html
        );

        return $this->addPreconnect($html, $cdnHost);
    }

    /**
     * Extract the host portion of the configured CDN URL.
     *
     * @return string
     */
    private function cdnHost(): string
    {
        $url = $this->config->cdnUrl;
        if (!preg_match('#^https?://#i', $url) && !str_starts_with($url, '//')) {
            $url = '//' . $url;
        }
        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }
        $host = parse_url($url, PHP_URL_HOST);
        return is_string($host) ? $host : '';
    }

    /**
     * Add a single preconnect hint to the CDN host.
     *
     * @param string $html    Page HTML.
     * @param string $cdnHost CDN host.
     * @return string
     */
    private function addPreconnect(string $html, string $cdnHost): string
    {
        if (stripos($html, 'rel="preconnect" href="//' . $cdnHost) !== false) {
            return $html;
        }
        $hint = '<link rel="preconnect" href="//' . htmlspecialchars($cdnHost, ENT_QUOTES) . '" crossorigin>';
        if (stripos($html, '</head>') !== false) {
            return (string) preg_replace('/<\/head>/i', $hint . '</head>', $html, 1);
        }
        return $hint . $html;
    }
}
