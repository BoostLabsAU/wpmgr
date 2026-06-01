<?php
/**
 * URL rewriter for WordPress database restore operations.
 *
 * Algorithm: "serialization-safe search-replace" — the length-fix technique
 * documented in the WP-CLI Handbook (search-replace command) and the
 * interconnectIT Search-Replace-DB tool (MIT-licensed, publicly documented).
 *
 * Core insight: PHP's serialize() format encodes strings as s:NN:"<bytes>";
 * where NN is the exact byte length. A naive str_replace on a serialized blob
 * corrupts NN without updating it, causing unserialize() to return false on
 * the next read. The correct approach is:
 *   1. unserialize() the blob (with allowed_classes => false per PHP RFC).
 *   2. Walk the data structure recursively.
 *   3. Apply str_replace only to string leaves.
 *   4. Re-serialize with serialize(), which recomputes all s:NN: prefixes.
 *
 * References:
 *   - PHP manual: serialize(), unserialize(), allowed_classes option (PHP 7.0+).
 *   - WP-CLI Handbook: https://make.wordpress.org/cli/handbook/guides/search-replace/
 *   - interconnectIT Search-Replace-DB: https://github.com/interconnectit/Search-Replace-DB
 *
 * @package WPMgr\Agent\Backup
 */

declare(strict_types=1);

namespace WPMgr\Agent\Backup;

/**
 * Pure-static URL rewriting utility for WordPress database restore passes.
 *
 * Handles plain strings, JSON-escaped strings, URL-encoded strings, and
 * PHP-serialized blobs — the four primary forms in which WordPress stores
 * URLs in the database.
 */
final class UrlRewriter
{
    /**
     * Tables that must never be URL-rewritten.
     *
     * This denylist covers analytics, firewall log, session, stats, and cache
     * tables whose content is binary, hit-count, or cross-table-reference data
     * rather than human-readable URLs. Rewriting these tables risks corrupting
     * binary blobs or invalidating foreign-key-style references.
     *
     * The list follows the industry-standard community denylist used by WP
     * backup and migration tooling.
     *
     * @var list<string>
     */
    public const DENYLIST_TABLES = [
        'adrotate_stats', 'login_security_solution_fail', 'icl_strings',
        'icl_string_positions', 'icl_string_translations',
        'icl_languages_translations', 'slim_stats', 'slim_stats_archive',
        'es_online', 'ahm_download_stats', 'woocommerce_order_items',
        'woocommerce_sessions', 'redirection_404', 'redirection_logs',
        'wbz404_logs', 'wbz404_redirects', 'Counterize',
        'Counterize_UserAgents', 'Counterize_Referers', 'et_bloom_stats',
        'term_relationships', 'lbakut_activity_log', 'simple_feed_stats',
        'svisitor_stat', 'itsec_log', 'relevanssi_log',
        'wysija_email_user_stat', 'wponlinebackup_generations', 'blc_instances',
        'wp_rp_tags', 'statpress', 'wfHits', 'wp_wfFileMods',
        'tts_trafficstats', 'tts_referrer_stats', 'dmsguestbook',
        'relevanssi', 'wfFileMods', 'learnpress_sessions', 'icl_string_pages',
        'webarx_event_log', 'duplicator_packages', 'wsal_metadata',
        'wsal_occurrences',
    ];

    /**
     * SQL column types that are unsafe to run URL search-replace over.
     *
     * Binary blob types cannot be safely treated as text. A type string
     * matches if it equals one of these entries exactly, or starts with the
     * entry immediately followed by "(" (e.g. "blob(100)").
     *
     * @var list<string>
     */
    private const SKIP_COLUMN_TYPES = [
        'blob', 'mediumblob', 'longblob', 'tinyblob', 'binary', 'varbinary',
    ];

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Build the parallel search/replace arrays for a full four-URL-pair
     * WordPress migration (siteUrl, homeUrl, contentUrl, uploadUrl).
     *
     * Each surviving pair emits exactly 7 variant entries (raw, JSON-escaped,
     * URL-encoded, scheme-relative, scheme-relative JSON-escaped, cross-scheme
     * raw, cross-scheme JSON-escaped) in that order, covering the full range
     * of URL encodings WordPress and common plugins write to the database.
     *
     * @param string $oldSiteUrl
     * @param string $newSiteUrl
     * @param string $oldHomeUrl
     * @param string $newHomeUrl
     * @param string $oldContentUrl
     * @param string $newContentUrl
     * @param string $oldUploadUrl
     * @param string $newUploadUrl
     * @return array{0: list<string>, 1: list<string>}
     */
    public static function build_replacements(
        string $oldSiteUrl,
        string $newSiteUrl,
        string $oldHomeUrl,
        string $newHomeUrl,
        string $oldContentUrl,
        string $newContentUrl,
        string $oldUploadUrl,
        string $newUploadUrl
    ): array {
        /** @var list<string> $from */
        $from = [];
        /** @var list<string> $to */
        $to = [];

        // Track seen old\0new pairs to avoid emitting duplicate variant blocks.
        // siteUrl and homeUrl are frequently identical on single-site installs.
        $seen = [];

        $pairs = [
            [$oldSiteUrl,    $newSiteUrl],
            [$oldHomeUrl,    $newHomeUrl],
            [$oldContentUrl, $newContentUrl],
            [$oldUploadUrl,  $newUploadUrl],
        ];

        foreach ($pairs as [$old, $new]) {
            // Strip trailing slashes — mirrors WordPress untrailingslashit().
            $old = self::untrailingslashit($old);
            $new = self::untrailingslashit($new);

            // Skip empty or identical pairs — no replacement needed.
            if ($old === '' || $new === '' || $old === $new) {
                continue;
            }

            // Deduplicate by logical old→new pair.
            $key = $old . "\0" . $new;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            self::appendVariants($from, $to, $old, $new);
        }

        return [$from, $to];
    }

    /**
     * Rewrite a raw database column value string using the supplied replacement
     * arrays.
     *
     * Handles plain strings and PHP-serialized blobs. For serialized blobs the
     * length-fix technique is applied: unserialize → walk → str_replace on
     * string leaves → re-serialize (which recomputes s:NN: prefixes).
     *
     * @param string                                   $oldData
     * @param array{0: list<string>, 1: list<string>}  $replacements
     * @return string
     */
    public static function rewrite_row_data(string $oldData, array $replacements): string
    {
        [$from, $to] = $replacements;

        // Fast-exit: no replacement rules defined.
        if (empty($from)) {
            return $oldData;
        }

        // Fast-exit: no needle appears in the raw data (dominant production path).
        $hasMatch = false;
        foreach ($from as $needle) {
            if (strpos($oldData, $needle) !== false) {
                $hasMatch = true;
                break;
            }
        }
        if (!$hasMatch) {
            return $oldData;
        }

        // Attempt to treat the value as a PHP-serialized blob.
        try {
            $unserialized = @unserialize($oldData, ['allowed_classes' => false]);

            // unserialize() returns false both on failure and for the valid
            // serialized boolean false ("b:0;"). The b:0; guard distinguishes them.
            if ($unserialized === false && $oldData !== 'b:0;') {
                // Not serialized — plain string replacement.
                return str_replace($from, $to, $oldData);
            }

            // Serialized: walk the structure, then re-serialize so all s:NN:
            // length prefixes are recomputed from the actual (post-rewrite) lengths.
            $rewritten = self::rewrite_serialize_data($unserialized, $replacements);
            return serialize($rewritten);
        } catch (\Throwable $e) {
            // Some PHP builds throw Error on severely malformed serialized input.
            // Fall back to flat string replacement rather than dropping the value.
            return str_replace($from, $to, $oldData);
        }
    }

    /**
     * Recursively walk an already-unserialized PHP data structure and rewrite
     * any URL strings found within it.
     *
     * This is the recursive visitor that implements the WP-CLI length-fix
     * algorithm. It handles the "double-encoded" pattern (a serialized array
     * stored as a plain string field inside another serialized array) by
     * attempting an inner unserialize on string values before falling back to
     * plain str_replace.
     *
     * @param mixed                                    $data
     * @param array{0: list<string>, 1: list<string>}  $replacements
     * @return mixed
     */
    public static function rewrite_serialize_data(mixed $data, array $replacements): mixed
    {
        if (is_string($data)) {
            // Check for double-encoded serialization: a serialized value stored
            // as a plain string inside an outer serialized structure.
            $inner = @unserialize($data, ['allowed_classes' => false]);
            if ($inner !== false || $data === 'b:0;') {
                // Double-encoded: recurse into the inner structure, then re-serialize.
                return serialize(self::rewrite_serialize_data($inner, $replacements));
            }
            // Ordinary string leaf.
            return self::rewriteScalarString($data, $replacements);
        }

        if (is_array($data)) {
            $result = [];
            foreach ($data as $key => $value) {
                if (is_string($value)) {
                    $result[$key] = self::rewriteScalarString($value, $replacements);
                } elseif (is_array($value) || is_object($value)) {
                    $result[$key] = self::rewrite_serialize_data($value, $replacements);
                } else {
                    // Non-string scalars (int, float, bool, null) are left unchanged.
                    $result[$key] = $value;
                }
            }
            return $result;
        }

        if (is_object($data)) {
            // __PHP_Incomplete_Class objects are produced when unserialize()
            // encounters a class that is not loaded in the current process.
            // There is no safe way to mutate their internal property bag without
            // the class definition, so we return them unchanged.
            if ($data instanceof \__PHP_Incomplete_Class) {
                return $data;
            }

            foreach (get_object_vars($data) as $prop => $value) {
                // Skip PHP-internal private/protected property encodings.
                // PHP encodes protected properties as "\0*\0name" and private
                // ones as "\0ClassName\0name". Mutating these via the public
                // property accessor produces a corrupt re-serialized blob.
                if ($prop !== '' && $prop[0] === "\x00") {
                    continue;
                }

                if (is_string($value)) {
                    $data->$prop = self::rewriteScalarString($value, $replacements);
                } elseif (is_array($value) || is_object($value)) {
                    $data->$prop = self::rewrite_serialize_data($value, $replacements);
                }
                // Non-string scalars left unchanged.
            }
            return $data;
        }

        // int, float, bool, null — return as-is.
        return $data;
    }

    /**
     * Determine whether a table should be excluded from URL rewriting.
     *
     * The table prefix is stripped before checking against DENYLIST_TABLES,
     * so callers can pass the full prefixed table name (e.g. "wp_wfHits") and
     * still match the bare denylist entry ("wfHits").
     *
     * @param string $table  Full table name (may include prefix).
     * @param string $prefix Active WordPress table prefix (e.g. "wp_").
     * @return bool
     */
    public static function should_skip_table(string $table, string $prefix): bool
    {
        $bare = self::stripTablePrefix($table, $prefix);
        return in_array($bare, self::DENYLIST_TABLES, true);
    }

    /**
     * Determine whether a column should be excluded from URL rewriting.
     *
     * Two skip conditions apply:
     *   1. posts.guid is an immutable WordPress post identifier; the WP Codex
     *      explicitly warns against rewriting it during migration.
     *   2. Binary blob column types cannot be safely treated as text.
     *
     * @param string $table      Full table name (may include prefix).
     * @param string $column     Column name.
     * @param string $prefix     Active WordPress table prefix.
     * @param string $columnType SQL type as returned by SHOW COLUMNS
     *                           (e.g. "varchar(255)", "longblob").
     * @return bool
     */
    public static function should_skip_column(
        string $table,
        string $column,
        string $prefix,
        string $columnType
    ): bool {
        $bare = self::stripTablePrefix($table, $prefix);

        // Rule 1: posts.guid is an immutable WP post identifier (WP Codex).
        if ($bare === 'posts' && $column === 'guid') {
            return true;
        }

        // Rule 2: binary blob types are unsafe for text search-replace.
        $lowerType = strtolower($columnType);
        foreach (self::SKIP_COLUMN_TYPES as $skipType) {
            // Exact match (e.g. "longblob").
            if ($lowerType === $skipType) {
                return true;
            }
            // Match with size suffix immediately following type name (e.g. "blob(100)").
            // strpos === 0 ensures the type name is a prefix, not just a substring.
            if (strpos($lowerType, $skipType . '(') === 0) {
                return true;
            }
        }

        return false;
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Append the 7 URL variant entries for a single old→new pair into the
     * accumulator arrays (passed by reference).
     *
     * The 7 variants cover the full range of URL encodings WordPress and common
     * plugins write to the database:
     *
     *   1. Raw: straight string replacement.
     *   2. JSON-escaped: "/" → "\/" — for plugins that store JSON with escaped
     *      slashes (contrary to wp_json_encode's JSON_UNESCAPED_SLASHES default).
     *   3. URL-encoded: ":" → "%3A", "/" → "%2F" — for URLs stored as query params.
     *   4. Scheme-relative raw: strip scheme, prefix "//".
     *   5. Scheme-relative JSON-escaped: strip scheme, prefix "\/\/".
     *   6. Cross-scheme raw: swap http↔https (for rows hard-coded at opposite scheme).
     *   7. Cross-scheme JSON-escaped: same cross-scheme logic, JSON-escaped form.
     *
     * str_replace applies variants left-to-right; more-specific (longer) needles
     * are emitted first so they match before a shorter one would partially overlap.
     *
     * @param list<string> $from (by reference)
     * @param list<string> $to   (by reference)
     * @param string       $old
     * @param string       $new
     */
    private static function appendVariants(array &$from, array &$to, string $old, string $new): void
    {
        // Variant 1: raw.
        $from[] = $old;
        $to[]   = $new;

        // Variant 2: JSON-escaped ("/" → "\/").
        $from[] = self::jsonEscape($old);
        $to[]   = self::jsonEscape($new);

        // Variant 3: URL-encoded (":" → "%3A", "/" → "%2F").
        $from[] = self::urlEncodeSchemeAndSlashes($old);
        $to[]   = self::urlEncodeSchemeAndSlashes($new);

        // Variants 4 & 5: scheme-relative forms — only when both URLs have a
        // recognized scheme (so stripScheme returns non-null for both).
        $oldHost = self::stripScheme($old);
        $newHost = self::stripScheme($new);

        if ($oldHost !== null && $newHost !== null) {
            // Variant 4: scheme-relative raw ("//host/path").
            $from[] = '//' . $oldHost;
            $to[]   = '//' . $newHost;

            // Variant 5: scheme-relative JSON-escaped ("\\/\\/host\\/path").
            $from[] = self::jsonEscape('//' . $oldHost);
            $to[]   = self::jsonEscape('//' . $newHost);
        }

        // Variants 6 & 7: cross-scheme — only when both URLs have a recognized
        // scheme. The old URL's scheme is swapped so we find rows that stored
        // the URL under the opposite scheme.
        $oldScheme = self::detectScheme($old);
        $newScheme = self::detectScheme($new);

        if ($oldScheme !== null && $newScheme !== null && $oldHost !== null && $newHost !== null) {
            $crossOldScheme = ($oldScheme === 'https') ? 'http' : 'https';

            // Variant 6: cross-scheme raw.
            $from[] = $crossOldScheme . '://' . $oldHost;
            $to[]   = $newScheme . '://' . $newHost;

            // Variant 7: cross-scheme JSON-escaped.
            $from[] = self::jsonEscape($crossOldScheme . '://' . $oldHost);
            $to[]   = self::jsonEscape($newScheme . '://' . $newHost);
        }
    }

    /**
     * Rewrite a single scalar string leaf, applying replacements only when a
     * needle is actually present in the value (fast-path guard).
     *
     * Also handles the double-encoded serialization pattern: if the string
     * value itself is a serialized blob (common with certain WP option patterns),
     * it is recursively unserialized, rewritten, and re-serialized.
     *
     * @param string                                   $value
     * @param array{0: list<string>, 1: list<string>}  $replacements
     * @return string
     */
    private static function rewriteScalarString(string $value, array $replacements): string
    {
        [$from, $to] = $replacements;

        // Fast-path: no needle present in the value.
        $hasMatch = false;
        foreach ($from as $needle) {
            if (strpos($value, $needle) !== false) {
                $hasMatch = true;
                break;
            }
        }
        if (!$hasMatch) {
            return $value;
        }

        // Check for double-encoded serialization (a serialized blob stored as
        // a plain string field inside an outer serialized structure).
        $inner = @unserialize($value, ['allowed_classes' => false]);
        if ($inner !== false || $value === 'b:0;') {
            return serialize(self::rewrite_serialize_data($inner, $replacements));
        }

        return str_replace($from, $to, $value);
    }

    /**
     * JSON-escape a URL by replacing every "/" with "\/".
     *
     * WordPress's wp_json_encode() does not escape slashes (PHP 5.4+
     * JSON_UNESCAPED_SLASHES behavior). Many third-party plugins use plain
     * json_encode() without that flag, producing escaped slashes in stored
     * option values and REST payloads — this variant handles those rows.
     *
     * @param string $url
     * @return string
     */
    private static function jsonEscape(string $url): string
    {
        return str_replace('/', '\\/', $url);
    }

    /**
     * URL-encode the scheme and slashes of a URL.
     *
     * Converts ":" to "%3A" and "/" to "%2F", producing the encoded form used
     * when a URL is stored as the value of a query-string parameter or inside
     * another URL.
     *
     * @param string $url
     * @return string
     */
    private static function urlEncodeSchemeAndSlashes(string $url): string
    {
        $encoded = str_replace(':', '%3A', $url);
        return str_replace('/', '%2F', $encoded);
    }

    /**
     * Strip the http:// or https:// scheme prefix from a URL and return the
     * host+path portion, or null if neither scheme is recognized.
     *
     * Case-insensitive per RFC 3986 §3.1.
     *
     * @param string $url
     * @return string|null
     */
    private static function stripScheme(string $url): ?string
    {
        if (stripos($url, 'https://') === 0) {
            return substr($url, strlen('https://'));
        }
        if (stripos($url, 'http://') === 0) {
            return substr($url, strlen('http://'));
        }
        return null;
    }

    /**
     * Detect the URL scheme as "https", "http", or null if neither is present.
     *
     * @param string $url
     * @return string|null
     */
    private static function detectScheme(string $url): ?string
    {
        if (stripos($url, 'https://') === 0) {
            return 'https';
        }
        if (stripos($url, 'http://') === 0) {
            return 'http';
        }
        return null;
    }

    /**
     * Strip trailing slashes from a URL — mirrors WordPress's untrailingslashit().
     *
     * @param string $url
     * @return string
     */
    private static function untrailingslashit(string $url): string
    {
        return rtrim($url, '/');
    }

    /**
     * Strip the WordPress table prefix from a table name to obtain the bare
     * name used in DENYLIST_TABLES and the skip-column rules.
     *
     * @param string $table  Full table name (e.g. "wp_posts").
     * @param string $prefix Active prefix (e.g. "wp_").
     * @return string        Bare name (e.g. "posts"), or the full table name
     *                       when prefix is empty or not found at position 0.
     */
    private static function stripTablePrefix(string $table, string $prefix): string
    {
        if ($prefix !== '' && strpos($table, $prefix) === 0) {
            return substr($table, strlen($prefix));
        }
        return $table;
    }
}
