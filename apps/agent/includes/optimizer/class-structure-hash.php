<?php
/**
 * StructureHash — a dedup key for the RUCSS service.
 *
 * Two pages with the SAME structure (same tag set, same de-numbered class/id
 * tokens, same stylesheet/script srcs) yield the same used-CSS, so the control
 * plane can cache the RUCSS result against this hash instead of recomputing per
 * URL. The hash deliberately IGNORES text content and numeric suffixes (post
 * ids, pagination numbers) so a whole template family collapses to one key.
 *
 * Recipe (md5 of the sorted-unique join of):
 *   - distinct lower-cased tag names,
 *   - distinct class/id tokens with trailing digits stripped,
 *   - distinct stylesheet hrefs + script srcs,
 *   - the configured RUCSS include-selectors.
 *
 * The CONCEPT (structure-hash dedup) is standard; the exact recipe here is ours.
 *
 * @package WPMgr\Agent\Optimizer
 */

declare(strict_types=1);

namespace WPMgr\Agent\Optimizer;

/**
 * Computes the RUCSS structure-hash dedup key.
 */
final class StructureHash
{
    /**
     * Compute the structure hash for a page.
     *
     * @param string       $html             Page HTML.
     * @param list<string> $includeSelectors RUCSS safelist (varies the hash).
     * @return string 32-hex md5.
     */
    public static function compute(string $html, array $includeSelectors = []): string
    {
        $tokens = [];

        // 1. Tag names.
        if (preg_match_all('/<([a-z][a-z0-9-]*)\b/i', $html, $m)) {
            foreach ($m[1] as $tag) {
                $tokens['t:' . strtolower($tag)] = true;
            }
        }

        // 2. class tokens (per-render noise collapsed so the same template hashes
        //    identically across renders — see normalizeToken).
        if (preg_match_all('/\sclass=["\']([^"\']*)["\']/i', $html, $m)) {
            foreach ($m[1] as $classAttr) {
                foreach (preg_split('/\s+/', trim($classAttr)) ?: [] as $cls) {
                    if ($cls === '') {
                        continue;
                    }
                    $tokens['c:' . self::normalizeToken($cls)] = true;
                }
            }
        }

        // 3. id tokens (per-render noise collapsed — see normalizeToken).
        if (preg_match_all('/\sid=["\']([^"\']*)["\']/i', $html, $m)) {
            foreach ($m[1] as $id) {
                $id = trim($id);
                if ($id !== '') {
                    $tokens['i:' . self::normalizeToken($id)] = true;
                }
            }
        }

        // 4. stylesheet hrefs.
        if (preg_match_all('/<link\b[^>]*\brel=["\']stylesheet["\'][^>]*>/i', $html, $links)) {
            foreach ($links[0] as $link) {
                $href = TagHelper::attr($link, 'href');
                if ($href !== null && $href !== '') {
                    $tokens['s:' . self::stripVersion($href)] = true;
                }
            }
        }

        // 5. script srcs.
        if (preg_match_all('/<script\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*>/i', $html, $scripts)) {
            foreach ($scripts[1] as $src) {
                $tokens['j:' . self::stripVersion($src)] = true;
            }
        }

        // 6. include selectors (a safelist change must invalidate the cache).
        foreach ($includeSelectors as $sel) {
            $tokens['x:' . $sel] = true;
        }

        $keys = array_keys($tokens);
        sort($keys);
        return md5(implode('|', $keys));
    }

    /**
     * Collapse per-render NOISE in a class/id token so the same template hashes
     * identically across renders. Order matters: strip uniqid-style suffixes
     * FIRST (while their digits are still present), then strip remaining digit runs.
     *
     *  - "_<8+ hex>" suffixes are render-random, NOT structural — e.g. WooCommerce
     *    emits id="quantity_<uniqid()>" on EVERY product page (uniqid() is a hex
     *    string), which otherwise mints a brand-new structure_hash on every render
     *    and defeats RUCSS caching entirely (the page caches but never gets the
     *    used-CSS applied). Collapsing them is safe: real structural tokens almost
     *    never carry an 8+ char hex run after an underscore.
     *  - Trailing digits collapse paginated/post-id variants (post-123 == post-456).
     *
     * @param string $token Raw class or id token.
     * @return string Normalized token.
     */
    private static function normalizeToken(string $token): string
    {
        // 1. Drop uniqid-like hex suffixes (WooCommerce quantity_<uniqid>, etc.).
        $token = (string) preg_replace('/_[0-9a-f]{8,}/i', '_', $token);
        // 2. Drop remaining digit runs (post ids, pagination).
        return (string) preg_replace('/\d+/', '', $token);
    }

    /**
     * Strip a `?ver=...` query so an asset's version bump does not change the
     * structure (the bytes are still cache-busted by the CP-side content hash).
     *
     * @param string $url Asset URL.
     * @return string
     */
    private static function stripVersion(string $url): string
    {
        return (string) preg_replace('/[?&]ver=[^&]*/', '', $url);
    }
}
