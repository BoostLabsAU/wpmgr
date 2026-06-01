<?php
/**
 * DbRewriter — rewrites image URLs stored in wp_posts.post_content and
 * wp_postmeta.meta_value, handling PHP-serialized data and JSON blobs
 * without corrupting s:<len>: length prefixes.
 *
 * Algorithm: the standard WP-CLI search-replace serialized-string technique
 * (https://developer.wordpress.org/cli/commands/search-replace/).
 * Serialized blobs are: deserialized → recursively string-walked → re-serialized,
 * so PHP recomputes all s:<len>: prefixes correctly.  Plain strings and JSON arrays
 * are handled via preg_replace with a boundary lookahead to avoid false-positive
 * prefix matches on longer filenames (e.g. banner.jpg inside banner.jpg2).
 *
 * @package WPMgr\Agent\Media
 */

declare(strict_types=1);

namespace WPMgr\Agent\Media;

final class DbRewriter
{
    // ---------------------------------------------------------------------------
    // Load-bearing private constants (behavior, not public interface).
    // ---------------------------------------------------------------------------

    /**
     * Postmeta keys that are NEVER string-rewritten.
     * wpmgr_image_optimization (= MediaKeystore::KEY) is the restore-anchor blob;
     * altering it during a URL rewrite pass would break reverse restores.
     */
    private const SKIP_META_KEYS = [
        '_wp_attached_file',
        '_wp_attachment_metadata',
        'wpmgr_image_optimization',
        '_wp_attachment_backup_sizes',
    ];

    /** Maximum post_content rows fetched per pass. */
    private const POST_LIMIT = 100;

    /** Maximum postmeta rows fetched per pass. */
    private const META_LIMIT = 200;

    // ---------------------------------------------------------------------------
    // Constructor
    // ---------------------------------------------------------------------------

    public function __construct() {}

    // ---------------------------------------------------------------------------
    // Public interface
    // ---------------------------------------------------------------------------

    /**
     * Rewrite image URLs forward (old -> new) in post_content and postmeta.
     *
     * @param array<string,string> $map  old_url => new_url
     * @return array{post_content_rows:int, postmeta_rows:int, needs_more:bool}
     */
    public function replaceImages(array $map): array
    {
        $clean = $this->cleanMap($map);
        if ($clean === []) {
            return ['post_content_rows' => 0, 'postmeta_rows' => 0, 'needs_more' => false];
        }

        // Suppress object-cache churn during bulk updates.
        $canSuspend = function_exists('wp_suspend_cache_addition');
        if ($canSuspend) {
            wp_suspend_cache_addition(true);
        }

        try {
            $content = $this->replaceInPostContent($clean);
            $meta    = $this->replaceInPostmeta($clean);
        } finally {
            if ($canSuspend) {
                wp_suspend_cache_addition(false);
            }
        }

        return [
            'post_content_rows' => $content['rows'],
            'postmeta_rows'     => $meta['rows'],
            'needs_more'        => ($content['full'] || $meta['full']),
        ];
    }

    /**
     * Rewrite image URLs in the reverse direction (new -> old), for restore.
     * The caller stores old_url => new_url; this method flips the map before
     * delegating to replaceImages so the DB is written back to original URLs.
     *
     * @param array<string,string> $map  old_url => new_url  (the FORWARD map)
     * @return array{post_content_rows:int, postmeta_rows:int, needs_more:bool}
     */
    public function reverseImages(array $map): array
    {
        $clean   = $this->cleanMap($map);
        $flipped = array_flip($clean);
        return $this->replaceImages($flipped);
    }

    /**
     * Recursively walk any PHP value and rewrite string leaves.
     * Used internally by rewriteValue; exposed publicly for testability.
     *
     * The $patterns array is parallel to array_values($map): each pattern
     * corresponds to the same-indexed replacement in the map.
     *
     * @param mixed                $data     Any PHP value.
     * @param array<string,string> $map      old_url => new_url.
     * @param list<string>         $patterns Pre-built PCRE patterns (from buildPatterns).
     * @return mixed  Same shape/type with string leaves rewritten.
     */
    public function recursiveReplace($data, array $map, array $patterns)
    {
        if (is_string($data)) {
            // Apply boundary-guarded patterns; fall back on regex error.
            $result = preg_replace($patterns, array_values($map), $data);
            return is_string($result) ? $result : $data;
        }

        if (is_array($data)) {
            foreach ($data as $k => $v) {
                $data[$k] = $this->recursiveReplace($v, $map, $patterns);
            }
            return $data;
        }

        if (is_object($data)) {
            // Defensive guard: incomplete class objects cannot be safely walked.
            if ($data instanceof \__PHP_Incomplete_Class) {
                return $data;
            }

            foreach (get_object_vars($data) as $prop => $val) {
                // Skip protected/private properties serialized with NUL-prefixed names;
                // assigning through them would corrupt the re-serialized blob.
                if (is_string($prop) && isset($prop[0]) && $prop[0] === "\0") {
                    continue;
                }
                $data->$prop = $this->recursiveReplace($val, $map, $patterns);
            }
            return $data;
        }

        // int, float, bool, null — unchanged.
        return $data;
    }

    /**
     * Rewrite URLs in a single meta_value string.
     * Handles PHP-serialized data, JSON arrays, and plain strings.
     * Pure: no DB access; works without a WP runtime.
     *
     * @param string               $value  Raw meta_value string.
     * @param array<string,string> $map    old_url => new_url.
     * @return string  Rewritten value (unchanged when nothing matched).
     */
    public function rewriteValue(string $value, array $map): string
    {
        $clean = $this->cleanMap($map);
        if ($clean === [] || $value === '') {
            return $value;
        }

        $patterns     = $this->buildPatterns(array_keys($clean));
        $replacements = array_values($clean);

        // ------------------------------------------------------------------
        // 1. PHP-serialized data — detect, deserialize, recurse, re-serialize.
        //    Standard WP-CLI technique: re-serializing recomputes s:<len>: prefixes.
        // ------------------------------------------------------------------
        if ($this->isSerialized($value)) {
            $unserialized = @unserialize($value, ['allowed_classes' => false]);

            if ($unserialized === false && $value !== 'b:0;') {
                // Genuine unserialize failure: fall back to flat pattern replace
                // on the raw blob (best-effort; may produce length-prefix skew, but
                // avoids silently dropping the value).
                $result = preg_replace($patterns, $replacements, $value);
                return is_string($result) ? $result : $value;
            }

            // Walk the deserialized structure and re-serialize so PHP rewrites
            // every s:<len>: prefix for strings whose byte length changed.
            $rewritten = $this->recursiveReplace($unserialized, $clean, $patterns);
            return serialize($rewritten);
        }

        // ------------------------------------------------------------------
        // 2. JSON array — Elementor/Gutenberg block attributes stored as JSON
        //    in postmeta.  Decode, recurse, re-encode.
        // ------------------------------------------------------------------
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $rewritten = $this->recursiveReplace($decoded, $clean, $patterns);
            $encoded   = function_exists('wp_json_encode')
                ? wp_json_encode($rewritten)
                : json_encode($rewritten);
            return is_string($encoded) && $encoded !== '' ? $encoded : $value;
        }

        // ------------------------------------------------------------------
        // 3. Plain string fallback — boundary-guarded preg_replace.
        // ------------------------------------------------------------------
        $result = preg_replace($patterns, $replacements, $value);
        return is_string($result) ? $result : $value;
    }

    // ---------------------------------------------------------------------------
    // Private helpers
    // ---------------------------------------------------------------------------

    /**
     * Sanitize the input map: keep only entries where both key and value are
     * non-empty strings and key !== value (self-mappings are no-ops).
     *
     * @param  array<string,string> $map
     * @return array<string,string>
     */
    private function cleanMap(array $map): array
    {
        $clean = [];
        foreach ($map as $old => $new) {
            if (!is_string($old) || !is_string($new)) {
                continue;
            }
            if ($old === '' || $new === '' || $old === $new) {
                continue;
            }
            $clean[$old] = $new;
        }
        return $clean;
    }

    /**
     * Build the boundary-guarded PCRE patterns for a list of URL strings.
     *
     * The trailing positive lookahead `(?=([^0-9A-Za-z]|$))` is the standard
     * guard used in WP search-replace tools: it matches the URL only when
     * followed by a non-alphanumeric character or end-of-string, preventing
     * false positives such as matching banner.jpg inside banner.jpg2.
     * The lookahead is zero-width, so the boundary character is not consumed.
     *
     * Reference: https://developer.wordpress.org/cli/commands/search-replace/
     *
     * @param  list<string> $urls
     * @return list<string>  PCRE patterns, one per URL.
     */
    private function buildPatterns(array $urls): array
    {
        $patterns = [];
        foreach ($urls as $url) {
            $patterns[] = '/' . preg_quote($url, '/') . '(?=([^0-9A-Za-z]|$))/';
        }
        return $patterns;
    }

    /**
     * Detect whether a string is PHP-serialized data.
     * Delegates to WordPress's is_serialized() when available; otherwise uses
     * the well-known prefix heuristic so pure-value methods work in unit tests.
     *
     * @param  string $value
     * @return bool
     */
    private function isSerialized(string $value): bool
    {
        if (function_exists('is_serialized')) {
            return (bool) is_serialized($value);
        }

        // Fallback heuristic matching the WP implementation.
        $v = trim($value);
        if ($v === 'N;' || $v === 'b:0;' || $v === 'b:1;') {
            return true;
        }
        return (bool) preg_match('/^(a|O|s|i|d|b):[0-9]/', $v);
    }

    /**
     * Retrieve the active $wpdb global, or null when not in a WP runtime.
     * Allows pure-value methods to work in unit tests with no WP bootstrap.
     *
     * @return \wpdb|null
     */
    private function wpdb(): ?\wpdb
    {
        if (isset($GLOBALS['wpdb']) && $GLOBALS['wpdb'] instanceof \wpdb) {
            return $GLOBALS['wpdb'];
        }
        return null;
    }

    /**
     * Resolve the set of public post types, falling back to a safe default
     * when get_post_types() is not available (test environment).
     *
     * @return list<string>
     */
    private function publicPostTypes(): array
    {
        if (function_exists('get_post_types')) {
            $types = get_post_types(['public' => true]);
            if (is_array($types) && $types !== []) {
                return array_values($types);
            }
        }
        return ['post', 'page'];
    }

    /**
     * Replace URLs in wp_posts.post_content rows (up to POST_LIMIT per pass).
     *
     * Uses LIKE conditions biased toward attribute/JSON appearances (quoted URL).
     * Applies boundary-guarded preg_replace on each matched row and updates only
     * rows where the content actually changed.
     *
     * @param  array<string,string> $map  Sanitized old => new map.
     * @return array{rows:int, full:bool}
     */
    private function replaceInPostContent(array $map): array
    {
        $wpdb = $this->wpdb();
        if ($wpdb === null) {
            return ['rows' => 0, 'full' => false];
        }

        $postTypes = $this->publicPostTypes();
        $patterns  = $this->buildPatterns(array_keys($map));

        // Build OR'd LIKE conditions for the WHERE clause.
        // Each URL is surrounded by double-quote characters to match attribute/JSON
        // appearances (e.g. src="<url>" or {"url":"<url>"}).
        $likeClauses = [];
        $likeArgs    = [];
        foreach (array_keys($map) as $url) {
            $likeClauses[] = 'post_content LIKE %s';
            $likeArgs[]    = '%"' . $wpdb->esc_like($url) . '"%';
        }

        // Build the IN placeholder list for post types.
        $typePlaceholders = implode(', ', array_fill(0, count($postTypes), '%s'));

        $sql  = "SELECT ID, post_content
                 FROM {$wpdb->posts}
                 WHERE (" . implode(' OR ', $likeClauses) . ")
                   AND post_type IN ({$typePlaceholders})
                   AND post_status = 'publish'
                 ORDER BY post_date DESC
                 LIMIT " . self::POST_LIMIT;

        $args = array_merge($likeArgs, $postTypes);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($wpdb->prepare($sql, $args));

        if (!is_array($rows)) {
            return ['rows' => 0, 'full' => false];
        }

        $affected = 0;
        foreach ($rows as $row) {
            $original = (string) $row->post_content;
            $updated  = preg_replace($patterns, array_values($map), $original);
            if (!is_string($updated) || $updated === $original) {
                continue;
            }
            $wpdb->update(
                $wpdb->posts,
                ['post_content' => $updated],
                ['ID'           => $row->ID]
            );
            $affected++;
        }

        return ['rows' => $affected, 'full' => (count($rows) >= self::POST_LIMIT)];
    }

    /**
     * Replace URLs in wp_postmeta.meta_value rows (up to META_LIMIT per pass).
     *
     * Uses LIKE (quoted) and REGEXP conditions joined with OR for each URL.
     * Excludes protected meta keys (SKIP_META_KEYS denylist) and delegates
     * per-row rewriting to rewriteValue() which handles serialized/JSON/plain.
     *
     * @param  array<string,string> $map  Sanitized old => new map.
     * @return array{rows:int, full:bool}
     */
    private function replaceInPostmeta(array $map): array
    {
        $wpdb = $this->wpdb();
        if ($wpdb === null) {
            return ['rows' => 0, 'full' => false];
        }

        $postTypes = $this->publicPostTypes();

        // Build the OR'd WHERE conditions: for each URL, a LIKE and a REGEXP.
        $urlClauses = [];
        $urlArgs    = [];
        foreach (array_keys($map) as $url) {
            // Quoted LIKE: matches JSON/attribute appearances.
            $urlClauses[] = 'pm.meta_value LIKE %s';
            $urlArgs[]    = '%"' . $wpdb->esc_like($url) . '"%';

            // REGEXP: matches URL at any boundary (non-alphanumeric or end-of-string).
            // Forward slashes in the URL are escaped for MySQL REGEXP.
            $regexpUrl    = str_replace('/', '\\/', $url);
            $urlClauses[] = 'pm.meta_value REGEXP %s';
            $urlArgs[]    = $regexpUrl . '([^0-9A-Za-z]|$)';
        }

        // Build the denylist exclusion using single-quote doubling (no escaping function).
        $skipList = [];
        foreach (self::SKIP_META_KEYS as $key) {
            $skipList[] = "'" . str_replace("'", "''", $key) . "'";
        }
        $skipIn = implode(', ', $skipList);

        // Build the IN placeholder list for post types.
        $typePlaceholders = implode(', ', array_fill(0, count($postTypes), '%s'));

        $sql = "SELECT pm.meta_id, pm.meta_value
                FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                WHERE (" . implode(' OR ', $urlClauses) . ")
                  AND pm.meta_key NOT IN ({$skipIn})
                  AND p.post_status = 'publish'
                  AND p.post_type IN ({$typePlaceholders})
                ORDER BY pm.meta_id DESC
                LIMIT " . self::META_LIMIT;

        $args = array_merge($urlArgs, $postTypes);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);

        if (!is_array($rows)) {
            return ['rows' => 0, 'full' => false];
        }

        $affected = 0;
        foreach ($rows as $row) {
            $original = (string) $row['meta_value'];
            $updated  = $this->rewriteValue($original, $map);
            if ($updated === $original) {
                continue;
            }
            $wpdb->update(
                $wpdb->postmeta,
                ['meta_value' => $updated],
                ['meta_id'    => $row['meta_id']]
            );
            $affected++;
        }

        return ['rows' => $affected, 'full' => (count($rows) >= self::META_LIMIT)];
    }
}
