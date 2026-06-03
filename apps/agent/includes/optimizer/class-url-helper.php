<?php
/**
 * UrlHelper — small URL utilities shared by the optimizer transforms.
 *
 * Maps a (possibly protocol-relative or root-relative) asset URL to its local
 * filesystem path when it lives under this site, and classifies external vs
 * internal URLs. Pure string/path logic so it is fully unit-testable; the WP
 * bindings (site_url / ABSPATH) are read once and injectable for tests.
 *
 * @package WPMgr\Agent\Optimizer
 */

declare(strict_types=1);

namespace WPMgr\Agent\Optimizer;

/**
 * URL/path resolution helpers for the optimizer.
 */
final class UrlHelper
{
    /** Site base URL (scheme + host[:port]), no trailing slash. */
    private string $siteUrl;

    /** Document root for the site (ABSPATH), no trailing slash. */
    private string $docRoot;

    /**
     * @param string|null $siteUrl Override (tests). Default site_url().
     * @param string|null $docRoot Override (tests). Default ABSPATH.
     */
    public function __construct(?string $siteUrl = null, ?string $docRoot = null)
    {
        $this->siteUrl = rtrim($siteUrl ?? self::resolveSiteUrl(), '/');
        $this->docRoot = rtrim($docRoot ?? self::resolveDocRoot(), '/\\');
    }

    /**
     * The site host (no scheme), or '' when unknown.
     *
     * @return string
     */
    public function host(): string
    {
        $host = (string) (parse_url($this->siteUrl, PHP_URL_HOST) ?? '');
        return strtolower($host);
    }

    /**
     * Is this URL hosted on a DIFFERENT origin than the site (i.e. third-party)?
     * Protocol-relative and root-relative URLs are treated as internal.
     *
     * @param string $url Candidate URL.
     * @return bool
     */
    public function isExternal(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, 'data:')) {
            return false;
        }
        // Root-relative or fragment/anchor — internal.
        if ($url[0] === '/' && (!isset($url[1]) || $url[1] !== '/')) {
            return false;
        }
        $host = parse_url(self::normalizeScheme($url), PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }
        $site = $this->host();
        return $site !== '' && strtolower($host) !== $site;
    }

    /**
     * Map a local (same-site or root-relative) asset URL to its filesystem path.
     * Returns null for external URLs, data URIs, or when the doc root is unknown.
     *
     * @param string $url Asset URL.
     * @return string|null Absolute path, or null when not locally resolvable.
     */
    public function localPath(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, 'data:')) {
            return null;
        }
        // Strip query/fragment for the path lookup.
        $url = preg_replace('/[?#].*$/', '', $url) ?? $url;

        $path = null;
        if ($url[0] === '/' && (!isset($url[1]) || $url[1] !== '/')) {
            $path = $url; // root-relative
        } else {
            $normalized = self::normalizeScheme($url);
            $host = parse_url($normalized, PHP_URL_HOST);
            if (!is_string($host) || strtolower($host) !== $this->host() || $this->host() === '') {
                return null; // external
            }
            $path = parse_url($normalized, PHP_URL_PATH);
        }
        if (!is_string($path) || $path === '') {
            return null;
        }

        if ($this->docRoot === '') {
            return null;
        }
        $abs = $this->docRoot . '/' . ltrim(rawurldecode($path), '/');
        // Containment: the resolved path must stay under the doc root.
        $realRoot = realpath($this->docRoot);
        $realAbs  = realpath($abs);
        if ($realRoot === false || $realAbs === false) {
            return null;
        }
        $realRoot = rtrim($realRoot, '/\\') . DIRECTORY_SEPARATOR;
        if (!str_starts_with($realAbs . DIRECTORY_SEPARATOR, $realRoot)
            && $realAbs . DIRECTORY_SEPARATOR !== $realRoot
        ) {
            return null;
        }
        return $realAbs;
    }

    /**
     * Add an explicit https scheme to a protocol-relative URL for parsing.
     *
     * @param string $url URL.
     * @return string
     */
    private static function normalizeScheme(string $url): string
    {
        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }
        return $url;
    }

    /**
     * Resolve the site URL from WP.
     *
     * @return string
     */
    private static function resolveSiteUrl(): string
    {
        if (function_exists('site_url')) {
            return (string) site_url();
        }
        if (function_exists('home_url')) {
            return (string) home_url();
        }
        return '';
    }

    /**
     * Resolve the document root (ABSPATH).
     *
     * @return string
     */
    private static function resolveDocRoot(): string
    {
        return defined('ABSPATH') ? (string) constant('ABSPATH') : '';
    }
}
