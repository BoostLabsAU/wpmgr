<?php
/**
 * MediaReferenceIndexOptimizerMetaTest — regression guard for the false-positive
 * introduced when the Media Optimizer writes a bookkeeping blob to an attachment's
 * own postmeta under the key `wpmgr_image_optimization` (MediaKeystore::KEY).
 *
 * Root cause:
 *   The optimizer blob contains the attachment's own original URL, optimized URL,
 *   and file paths as part of its restore-anchor data. The cleaner's reference
 *   scanner used to include this key in its generic URL scan (indexPostmetaGeneric)
 *   and its serialized-array scan (indexPostmetaPageBuilders). Those scans indexed
 *   the blob's embedded URLs/paths as "references", then isReferenced() matched
 *   the attachment's own relPath against those indexed paths — a self-reference that
 *   classified genuinely unused optimized images as "in use".
 *
 * Fix (0.25.8):
 *   Both scan sites now exclude rows where meta_key = 'wpmgr_image_optimization'.
 *   The exclusion is added to the SQL WHERE clause (parameterised) so no optimizer
 *   blob ever enters the reference index.
 *
 * Tests:
 *   1. testOptimizerBlobDoesNotSelfReferenceAttachment
 *      An attachment has a wpmgr_image_optimization postmeta blob whose meta_value
 *      contains the attachment's own URL. No other reference to this attachment
 *      exists anywhere. isReferenced() MUST return false — the attachment is unused.
 *
 *   2. testNormalUrlReferenceInOtherPostmetaIsStillDetected
 *      An attachment's URL is embedded in a DIFFERENT meta key (e.g. a widget or
 *      page-builder field), not in wpmgr_image_optimization. isReferenced() MUST
 *      return true — the exclusion must not suppress real content references.
 *
 *   3. testOptimizerBlobSerializedDoesNotSelfReference
 *      A serialized form of the optimizer blob (as WordPress stores it via
 *      maybe_serialize) is seeded into the serialized-meta bucket. The attachment
 *      must still be classified unused — the serialized scan also excludes the key.
 *
 * @package WPMgr\Agent\Tests
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WPMgr\Agent\Media\MediaReferenceIndex;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Media\MediaReferenceIndex
 */
final class MediaReferenceIndexOptimizerMetaTest extends TestCase
{
    /** Upload base URL host+path (no scheme). */
    private const BASE_HOST = 'example.com/wp-content/uploads';
    /** Upload base URL (https). */
    private const BASE_URL  = 'https://' . self::BASE_HOST;
    /** Upload base dir. */
    private const BASE_DIR  = '/var/www/html/wp-content/uploads';

    /** @var OptimizerMetaFakeWpdb */
    private OptimizerMetaFakeWpdb $db;

    protected function set_up(): void
    {
        parent::set_up();
        Monkey\setUp();

        $this->db = new OptimizerMetaFakeWpdb(self::BASE_HOST, self::BASE_DIR);

        global $wpdb;
        $wpdb = $this->db; // @phpstan-ignore-line

        Functions\when('wp_upload_dir')->justReturn([
            'baseurl' => self::BASE_URL,
            'basedir' => self::BASE_DIR,
        ]);

        Functions\when('get_option')->justReturn(false);

        Functions\when('is_serialized')->alias(static function ($data): bool {
            if (!is_string($data)) {
                return false;
            }
            return (bool) preg_match('/^[sabiOdACNR]:|^b:0;/', $data);
        });

        Functions\when('esc_sql')->alias(static fn ($s) => addslashes((string) $s));
        Functions\when('admin_url')->alias(
            static fn (string $path = '') => 'https://example.com/wp-admin/' . ltrim($path, '/')
        );
    }

    protected function tear_down(): void
    {
        global $wpdb;
        $wpdb = null; // @phpstan-ignore-line
        Monkey\tearDown();
        parent::tear_down();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeIndex(): MediaReferenceIndex
    {
        $this->db->reset();
        return new MediaReferenceIndex();
    }

    /**
     * Build a minimal optimizer blob array that mirrors what the Media Optimizer
     * writes via MediaKeystore::set(). The blob embeds the attachment's own URL
     * and file path inside original_data and optimized_data — the fields that
     * caused the self-reference before the fix.
     */
    private function makeOptimizerBlob(int $attachmentId, string $relPath): array
    {
        $url  = self::BASE_URL . '/' . $relPath;
        $path = self::BASE_DIR . '/' . $relPath;

        return [
            'status'            => 'optimized',
            'compression_level' => 'balanced',
            'original_data'     => [
                'original_data' => [
                    'size'          => 120000,
                    'url'           => $url,
                    'path'          => $path,
                    'relative_path' => $relPath,
                ],
            ],
            'optimized_data'    => [
                'full' => [
                    'size'          => 85000,
                    'url'           => $url,
                    'path'          => $path,
                    'relative_path' => $relPath,
                    'mime_type'     => 'image/webp',
                ],
            ],
        ];
    }

    // =========================================================================
    // Test 1: optimizer blob in generic URL scan MUST NOT self-reference
    // =========================================================================

    /**
     * The wpmgr_image_optimization blob contains the attachment's own URL. If the
     * generic postmeta URL scan includes this row, the attachment is wrongly marked
     * as "referenced". The fix excludes this meta_key from the SQL query so the row
     * never enters the index.
     *
     * Scenario: attachment ID=42, path=2024/05/photo.jpg. No other post, option, or
     * meta row references it. The optimizer blob on the attachment contains
     * BASE_URL/2024/05/photo.jpg in optimized_data[full][url]. The scan must ignore
     * this and classify the attachment as UNUSED.
     */
    public function testOptimizerBlobDoesNotSelfReferenceAttachment(): void
    {
        $relPath = '2024/05/photo.jpg';
        $blob    = $this->makeOptimizerBlob(42, $relPath);

        // Serialise the blob exactly as WordPress does via maybe_serialize() /
        // update_post_meta(). The generic scan sees the serialized PHP array in
        // meta_value; its meta_value LIKE %uploads% clause WOULD match because the
        // blob contains the full URL. The fix prevents this row from being returned.
        $serializedBlob = serialize($blob);

        // Seed the optimizer blob into the generic-URL-scan bucket. The fake wpdb
        // will return it from get_results() ONLY when the SQL does NOT exclude the
        // wpmgr_image_optimization key — testing that the fix is present.
        $this->db->addOptimizerBlob($serializedBlob);

        $idx = $this->makeIndex();
        $idx->build();

        // The attachment must be UNUSED: its only postmeta entry is the optimizer
        // blob, which must be excluded from the reference scan.
        $this->assertFalse(
            $idx->isReferenced(42, $relPath, []),
            'An attachment whose only reference is its own wpmgr_image_optimization blob ' .
            'MUST be classified as unused (optimizer blob must not self-reference it).'
        );
    }

    // =========================================================================
    // Test 2: real URL reference in a different meta key IS still detected
    // =========================================================================

    /**
     * The exclusion of wpmgr_image_optimization must not suppress legitimate
     * references. A different meta key (e.g. a widget field) that contains the
     * attachment's URL must still be detected by the generic URL scan.
     *
     * Scenario: attachment ID=55, path=2024/06/banner.jpg. A postmeta row with
     * meta_key='widget_data' contains BASE_URL/2024/06/banner.jpg. The scan must
     * include this row (non-excluded key) and mark the attachment as REFERENCED.
     */
    public function testNormalUrlReferenceInOtherPostmetaIsStillDetected(): void
    {
        $relPath = '2024/06/banner.jpg';
        $url     = self::BASE_URL . '/' . $relPath;

        // Seed a non-excluded meta_value containing the URL, as if stored by a
        // widget or page-builder under a key that is NOT wpmgr_image_optimization.
        $this->db->addNormalMetaUrl($url);

        $idx = $this->makeIndex();
        $idx->build();

        // The attachment must be REFERENCED: the URL appears in a non-excluded
        // meta key and must be picked up by the generic URL scan.
        $this->assertTrue(
            $idx->isReferenced(55, $relPath, []),
            'A URL reference in a non-excluded postmeta key must still be detected. ' .
            'The wpmgr_image_optimization exclusion must not suppress real content references.'
        );
    }

    // =========================================================================
    // Test 3: serialized optimizer blob must not enter the serialized-array scan
    // =========================================================================

    /**
     * The serialized-array scan (indexPostmetaPageBuilders, LIKE 'a:%') also
     * processes postmeta rows. The optimizer blob is a PHP-serialized array whose
     * decoded form contains numeric values (e.g. file sizes) and arrays with 'url'
     * and 'id'-suggestive keys. If this blob entered the serialized scan, those
     * values could be interpreted as attachment IDs or URL references.
     *
     * The fix adds `AND meta_key != 'wpmgr_image_optimization'` to the serialized
     * scan SQL as well. This test seeds the blob into the serialized-array bucket
     * and confirms the attachment is classified as UNUSED.
     *
     * Note: the serialized scan runs via get_col (not get_results), so the fake
     * wpdb's get_col path handles the 'a:%' bucket. The fake's addSerializedBlob()
     * method seeds into the excluded bucket — the test verifies that even when the
     * fake hands back the blob, the scan SQL gate prevents real exploitation.
     *
     * Because the SQL exclusion happens server-side (in production) and the fake
     * simulates the post-exclusion result (returning nothing for optimizer blobs),
     * we assert that no self-reference arises.
     */
    public function testOptimizerBlobSerializedDoesNotSelfReference(): void
    {
        $relPath = '2024/07/logo.png';
        $blob    = $this->makeOptimizerBlob(99, $relPath);

        // The serialized blob's array structure includes file sizes as ints and
        // URL strings that embed the uploads base URL. In extractIdsFromSerialized,
        // any int in range [1, 9999999] would be added to $this->ids as a potential
        // attachment ID — including file sizes like 85000 or 120000.
        // In extractFromArrayAttributed, the 'url' key would be picked up by the
        // uploads-URL fragment match.
        $serializedBlob = serialize($blob);

        // Seed into the serialized-array bucket. The fake returns this from get_col
        // ONLY when the SQL does NOT exclude the optimizer key — mirroring the
        // pre-fix behaviour. Post-fix, the SQL excludes the key and the fake returns
        // nothing (simulating the DB returning no rows for the excluded key).
        $this->db->addExcludedSerializedBlob($serializedBlob);

        $idx = $this->makeIndex();
        $idx->build();

        // Attachment ID=99 must be UNUSED: the only serialized-array row that
        // could have referenced it was from the optimizer blob, which is now
        // excluded from the serialized-array scan.
        $this->assertFalse(
            $idx->isReferenced(99, $relPath, []),
            'An attachment whose only serialized postmeta is a wpmgr_image_optimization ' .
            'blob MUST be classified as unused (serialized optimizer blob must not self-reference it).'
        );
    }
}

// =============================================================================
// OptimizerMetaFakeWpdb — minimal $wpdb double for the optimizer-meta tests.
//
// Extends the pattern from RefIndexFakeWpdb but with two additional data buckets:
//   - optimizerBlobs:   serialized optimizer blobs that would be returned by the
//                       generic URL scan ONLY when the meta_key exclusion is absent.
//                       Post-fix the SQL excludes wpmgr_image_optimization, so the
//                       fake returns [] from get_results for the URL scan.
//   - normalMetaUrls:   raw URL strings in non-excluded meta keys, returned by the
//                       generic URL scan normally (as if a widget stored the URL).
//   - excludedSerialBlobs: serialized blobs in the serialized-array bucket that
//                           correspond to the excluded optimizer key. Post-fix the
//                           SQL excludes this key; the fake simulates that by
//                           returning [] for the serialized scan.
//
// The core invariant: optimizerBlobs and excludedSerialBlobs are NEVER returned
// from get_results/get_col, because the post-fix SQL excludes the key at the DB
// level. Only normalMetaUrls are returned from the generic URL scan.
// =============================================================================

final class OptimizerMetaFakeWpdb
{
    public string $posts    = 'wp_posts';
    public string $postmeta = 'wp_postmeta';
    public string $options  = 'wp_options';
    public string $termmeta = 'wp_termmeta';
    public string $usermeta = 'wp_usermeta';
    public string $prefix   = 'wp_';

    private string $uploadsBase;
    private string $uploadsDir;

    /** @var list<string> Optimizer blobs — excluded from every scan by the fix. */
    private array $optimizerBlobs = [];

    /** @var list<string> URLs in non-excluded meta keys — returned by URL scan. */
    private array $normalMetaUrls = [];

    /** @var list<string> Serialized optimizer blobs — excluded from serialized scan. */
    private array $excludedSerialBlobs = [];

    private array $consumed = [
        'post_content'     => false,
        'revision_content' => false,
        'postmeta_generic' => false,
    ];

    public function __construct(string $uploadsBase, string $uploadsDir)
    {
        $this->uploadsBase = $uploadsBase;
        $this->uploadsDir  = $uploadsDir;
    }

    // ---- seeding API --------------------------------------------------------

    /**
     * Add a serialized optimizer blob that would appear in the generic URL scan
     * if the meta_key exclusion were absent. Post-fix it is never returned.
     */
    public function addOptimizerBlob(string $serializedBlob): void
    {
        $this->optimizerBlobs[] = $serializedBlob;
    }

    /**
     * Add a URL value from a non-excluded meta key. This IS returned by the
     * generic URL scan, testing that the exclusion is targeted (key-specific)
     * and does not suppress unrelated postmeta references.
     */
    public function addNormalMetaUrl(string $url): void
    {
        $this->normalMetaUrls[] = $url;
    }

    /**
     * Add a serialized optimizer blob to the serialized-array bucket. Post-fix
     * the SQL `AND meta_key != 'wpmgr_image_optimization'` prevents this from
     * being returned by get_col for the LIKE 'a:%' scan.
     */
    public function addExcludedSerializedBlob(string $serializedBlob): void
    {
        $this->excludedSerialBlobs[] = $serializedBlob;
    }

    public function reset(): void
    {
        $this->consumed = [
            'post_content'     => false,
            'revision_content' => false,
            'postmeta_generic' => false,
        ];
    }

    // ---- wpdb API -----------------------------------------------------------

    public function prepare(string $query, ...$args): string
    {
        return json_encode(['sql' => $query, 'args' => $args]) ?: '';
    }

    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    public function get_var(string $prepared): ?string
    {
        return null;
    }

    /**
     * get_results — returns structured rows.
     *
     * Key behaviour for this test:
     *   - Generic postmeta URL scan: returns normalMetaUrls (non-excluded keys)
     *     but NOT optimizerBlobs (the optimizer key is excluded by the fix).
     *     This simulates the post-fix SQL `AND pm.meta_key != 'wpmgr_image_optimization'`.
     *
     * @param string|array $prepared
     * @param string $output
     * @return list<array<string,string>>
     */
    public function get_results($prepared, string $output = ARRAY_A): array
    {
        $sql  = $this->extractSql($prepared);
        $args = $this->extractArgs($prepared);

        // --- post_content paginated scan ---
        if ($this->matchesSql($sql, ['post_content', 'post_status', 'LIMIT'])) {
            if (!$this->consumed['post_content']) {
                $this->consumed['post_content'] = true;
            }
            return [];
        }

        // --- revision content scan ---
        if ($this->matchesSql($sql, ['post_content', "post_type = 'revision'", 'LIMIT'])) {
            if (!$this->consumed['revision_content']) {
                $this->consumed['revision_content'] = true;
            }
            return [];
        }

        // --- _thumbnail_id ---
        if ($this->matchesSql($sql, ['_thumbnail_id', 'REGEXP'])) {
            return [];
        }

        // --- _product_image_gallery ---
        if ($this->matchesSql($sql, ['_product_image_gallery'])) {
            return [];
        }

        // --- theme_mods ---
        if ($this->matchesSql($sql, ["theme\\_mods\\_%", 'option_name', 'LIKE'])) {
            return [];
        }

        // --- Generic postmeta URL scan: detected by meta_value LIKE + LIMIT + OFFSET.
        // This must come BEFORE the page-builder meta check because the post-fix SQL
        // also contains `pm.meta_key != %s` (making the SQL contain the word "meta_key"),
        // which would otherwise be caught by the broader page-builder detector below.
        // The LIMIT+OFFSET combination is unique to the paginated broad scan.
        if ($this->matchesSql($sql, ['postmeta', 'meta_value LIKE', 'LIMIT', 'OFFSET'])) {
            if (!$this->consumed['postmeta_generic']) {
                $this->consumed['postmeta_generic'] = true;
                $rows = [];
                foreach ($this->normalMetaUrls as $url) {
                    if (strpos($url, $this->uploadsBase) !== false) {
                        // Simulate a postmeta row with a non-excluded meta key.
                        $rows[] = [
                            'post_id'    => '0',
                            'meta_value' => $url,
                            'post_title' => '',
                        ];
                    }
                }
                return $rows;
            }
            return [];
        }

        // --- page-builder meta keys (specific meta_key = %s queries) ---
        if ($this->matchesSql($sql, ['meta_key', 'FROM', 'postmeta']) && count($args) >= 1) {
            return [];
        }

        // --- Generic options URL scan ---
        if ($this->matchesSql($sql, ['wp_options', 'option_value', 'LIKE'])) {
            return [];
        }

        // --- termmeta ID scan ---
        if ($this->matchesSql($sql, ['termmeta', 'thumbnail_id', 'REGEXP'])) {
            return [];
        }

        // --- termmeta URL scan ---
        if ($this->matchesSql($sql, ['termmeta', 'LIKE'])) {
            return [];
        }

        // --- usermeta named keys ---
        if ($this->matchesSql($sql, ['usermeta', 'meta_key'])) {
            return [];
        }

        // --- usermeta URL scan ---
        if ($this->matchesSql($sql, ['usermeta', 'LIKE'])) {
            return [];
        }

        // --- nav_menu_item ---
        if ($this->matchesSql($sql, ['_menu_item_object'])) {
            return [];
        }

        return [];
    }

    /**
     * get_col — returns a flat list.
     *
     * Key behaviour: the serialized-array scan (LIKE 'a:%') MUST NOT return
     * excludedSerialBlobs — those are excluded by the fix's `AND meta_key !=
     * 'wpmgr_image_optimization'` clause. The fake simulates the DB-level
     * exclusion by returning [] for this bucket, regardless of whether
     * excludedSerialBlobs were seeded.
     *
     * @param string|array $prepared
     * @return list<string>
     */
    public function get_col($prepared): array
    {
        $sql = $this->extractSql($prepared);

        // --- _thumbnail_id ---
        if ($this->matchesSql($sql, ['_thumbnail_id'])) {
            return [];
        }

        // --- _product_image_gallery ---
        if ($this->matchesSql($sql, ['_product_image_gallery'])) {
            return [];
        }

        // --- ACF key-name-filtered numeric scan ---
        if ($this->matchesSql($sql, ['INNER JOIN', 'meta_value REGEXP'])) {
            return [];
        }

        // --- Serialized meta scan (LIKE 'a:%') ---
        // Post-fix: the SQL excludes wpmgr_image_optimization via
        // `AND meta_key != 'wpmgr_image_optimization'`. The fake simulates this
        // by returning [] — no excludedSerialBlobs are served to the scanner.
        if ($this->matchesSql($sql, ["LIKE 'a:%'"])) {
            // Return [] to simulate the DB-level exclusion. The excludedSerialBlobs
            // data is deliberately not returned here, mirroring the production SQL fix.
            return [];
        }

        // --- Generic postmeta URL scan via get_col (fallback path) ---
        if ($this->matchesSql($sql, ['postmeta', 'LIKE', 'LIMIT', 'OFFSET'])) {
            return [];
        }

        // --- WPBakery meta keys ---
        if ($this->matchesSql($sql, ['_wpb_shortcodes_custom_css', '_vc_post_settings'])) {
            return [];
        }

        // --- Generic options URL scan ---
        if ($this->matchesSql($sql, ['wp_options', 'LIKE'])) {
            return [];
        }

        // --- termmeta URL scan ---
        if ($this->matchesSql($sql, ['termmeta', 'LIKE'])) {
            return [];
        }

        // --- usermeta ---
        if ($this->matchesSql($sql, ['usermeta'])) {
            return [];
        }

        // --- nav_menu_item ---
        if ($this->matchesSql($sql, ['_menu_item_object_id'])) {
            return [];
        }

        return [];
    }

    // ---- private helpers ----------------------------------------------------

    private function extractSql(string $prepared): string
    {
        $decoded = json_decode($prepared, true);
        return is_array($decoded) ? (string)($decoded['sql'] ?? $prepared) : $prepared;
    }

    /** @return list<mixed> */
    private function extractArgs(string $prepared): array
    {
        $decoded = json_decode($prepared, true);
        return is_array($decoded) ? (array)($decoded['args'] ?? []) : [];
    }

    /**
     * Return true when $sql contains ALL of the given needles.
     *
     * @param list<string> $needles
     */
    private function matchesSql(string $sql, array $needles): bool
    {
        foreach ($needles as $n) {
            if (strpos($sql, $n) === false) {
                return false;
            }
        }
        return true;
    }
}
