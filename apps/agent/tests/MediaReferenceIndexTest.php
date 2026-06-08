<?php
/**
 * MediaReferenceIndexTest — proves the exhaustive conservative reference scanner.
 *
 * Key invariant: FALSE POSITIVE (flagging an in-use image as unused) = data loss.
 * Any matched attachment MUST return isReferenced() = true.
 *
 * Tests:
 *   - build() is idempotent (second call is a no-op).
 *   - build() returns false when uploads base URL is empty (abort signal).
 *   - isReferenced() returns true for _thumbnail_id (featured image).
 *   - isReferenced() returns true for wp-image-{id} class in post_content.
 *   - isReferenced() returns true for [gallery ids=...] shortcode.
 *   - isReferenced() returns true for URL found in wp_options.
 *   - isReferenced() returns false for attachment with no references on any surface.
 *   - isReferenced() returns true when a sub-size URL (-WxH) is referenced.
 *   - isReferenced() returns true for Elementor _elementor_data JSON (by ID).
 *   - isReferenced() returns true for ACF serialized array of IDs.
 *   - isReferenced() returns true for theme_mods (custom_logo ID).
 *   - Basename-only match: referenced sub-size filename marks parent as used.
 *   - Protocol-agnostic match (http:// vs https://).
 *   - original_image (WP 5.3 scaled original) path match.
 *   - Elementor image_id key (numeric string in image-bearing key).
 *   - Revision content: image referenced only in a revision is NOT flagged unused.
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
final class MediaReferenceIndexTest extends TestCase
{
    /** Uploads base URL host+path portion (no scheme). */
    private const BASE_HOST = 'example.com/wp-content/uploads';
    /** Uploads base URL (https). */
    private const BASE_URL  = 'https://' . self::BASE_HOST;
    /** Uploads base dir. */
    private const BASE_DIR  = '/var/www/html/wp-content/uploads';

    /** @var RefIndexFakeWpdb */
    private RefIndexFakeWpdb $db;

    protected function set_up(): void
    {
        parent::set_up();
        Monkey\setUp();

        $this->db = new RefIndexFakeWpdb(self::BASE_HOST, self::BASE_DIR);

        global $wpdb;
        $wpdb = $this->db; // @phpstan-ignore-line

        // wp_upload_dir — read once inside build().
        Functions\when('wp_upload_dir')->justReturn([
            'baseurl' => self::BASE_URL,
            'basedir' => self::BASE_DIR,
        ]);

        // get_option stubs — index reads several named options.
        Functions\when('get_option')->justReturn(false);

        // is_serialized — used when extracting theme_mods.
        Functions\when('is_serialized')->alias(static function ($data): bool {
            if (!is_string($data)) {
                return false;
            }
            // Recognise PHP serialized strings by the first byte token.
            return (bool)preg_match('/^[sabiOdACNR]:|^b:0;/', $data);
        });

        // esc_sql — used in the raw LIKE fragments.
        Functions\when('esc_sql')->alias(static fn ($s) => addslashes((string)$s));
        // admin_url is called by buildPostEditUrl() for attribution; return a safe stub.
        Functions\when('admin_url')->alias(static fn (string $path = '') => 'https://example.com/wp-admin/' . ltrim($path, '/'));
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

    private function makeMeta(string $relFile = '2024/01/hero.jpg'): array
    {
        $base = pathinfo($relFile, PATHINFO_FILENAME);
        return [
            'file'   => $relFile,
            'width'  => 1200,
            'height' => 800,
            'sizes'  => [
                'medium'    => ['file' => $base . '-300x200.jpg', 'width' => 300, 'height' => 200],
                'thumbnail' => ['file' => $base . '-150x150.jpg', 'width' => 150, 'height' => 150],
            ],
        ];
    }

    // =========================================================================
    // build() idempotency
    // =========================================================================

    public function testBuildIsIdempotent(): void
    {
        $idx = $this->makeIndex();
        $idx->build();
        $buildCount = $this->db->getBuildCalls();
        $idx->build(); // no-op
        $this->assertSame($buildCount, $this->db->getBuildCalls());
        $this->assertFalse($idx->isReferenced(99, '2024/01/img.jpg', []));
    }

    // =========================================================================
    // Surface 2 — _thumbnail_id
    // =========================================================================

    public function testIsReferencedReturnsTrueForThumbnailId(): void
    {
        $this->db->addThumbnailId(42);

        $idx = $this->makeIndex();
        $idx->build();

        $this->assertTrue(
            $idx->isReferenced(42, '2024/01/featured.jpg', []),
            '_thumbnail_id attachment must be marked as used.'
        );
    }

    public function testIsReferencedReturnsFalseWhenNotFeaturedImage(): void
    {
        $this->db->addThumbnailId(42);

        $idx = $this->makeIndex();
        $idx->build();

        $this->assertFalse(
            $idx->isReferenced(99, '2024/01/orphan.jpg', []),
            'Unrelated attachment must not be marked used via _thumbnail_id.'
        );
    }

    // =========================================================================
    // Surface 1 — wp-image-{id} class in post_content
    // =========================================================================

    public function testIsReferencedReturnsTrueForWpImageClass(): void
    {
        $this->db->addPostContent(
            '<figure class="wp-block-image wp-image-77"><img src="hero.jpg"/></figure>'
        );

        $idx = $this->makeIndex();
        $idx->build();

        $this->assertTrue(
            $idx->isReferenced(77, '2024/01/hero.jpg', []),
            'wp-image-{id} class must mark attachment as used.'
        );
    }

    // =========================================================================
    // Surface 1 — [gallery ids=...] shortcode
    // =========================================================================

    public function testIsReferencedReturnsTrueForGalleryShortcode(): void
    {
        $this->db->addPostContent('[gallery ids="10,20,30"]');

        $idx = $this->makeIndex();
        $idx->build();

        $this->assertTrue($idx->isReferenced(10, '2024/01/a.jpg', []));
        $this->assertTrue($idx->isReferenced(20, '2024/01/b.jpg', []));
        $this->assertTrue($idx->isReferenced(30, '2024/01/c.jpg', []));
    }

    // =========================================================================
    // Surface 13 — theme_mods (custom_logo attachment ID)
    // =========================================================================

    public function testIsReferencedReturnsTrueForThemeModsCustomLogo(): void
    {
        // custom_logo stored as int attachment ID inside serialized theme_mods.
        $raw = serialize([
            'custom_logo'      => 55,
            'header_image'     => self::BASE_URL . '/2024/01/header.jpg',
            'background_image' => '',
        ]);
        $this->db->addThemeModsRow($raw);

        $idx = $this->makeIndex();
        $idx->build();

        // custom_logo ID 55 must be picked up from the deserialized array.
        $this->assertTrue(
            $idx->isReferenced(55, '2024/01/logo.jpg', []),
            'custom_logo ID in theme_mods must mark attachment as used.'
        );

        // header_image URL must be indexed too.
        $this->assertTrue(
            $idx->isReferenced(56, '2024/01/header.jpg', $this->makeMeta('2024/01/header.jpg')),
            'header_image URL in theme_mods must mark the attachment as used.'
        );
    }

    // =========================================================================
    // Surface 4 — Elementor _elementor_data JSON by attachment ID
    // =========================================================================

    public function testIsReferencedReturnsTrueForElementorDataById(): void
    {
        $json = json_encode([[
            'elType'   => 'section',
            'elements' => [[
                'elType'    => 'widget',
                'widgetType' => 'image',
                'settings'  => [
                    'image' => [
                        'id'  => 88,
                        'url' => self::BASE_URL . '/2024/01/hero.jpg',
                    ],
                ],
            ]],
        ]]);

        $this->db->addPageBuilderMeta('_elementor_data', (string)$json);

        $idx = $this->makeIndex();
        $idx->build();

        $this->assertTrue(
            $idx->isReferenced(88, '2024/01/hero.jpg', []),
            'Attachment ID in _elementor_data must be marked used.'
        );
    }

    // =========================================================================
    // Surface 11 — ACF serialized array of IDs
    // =========================================================================

    public function testIsReferencedReturnsTrueForAcfSerializedGalleryIds(): void
    {
        $this->db->addSerializedMeta(serialize([101, 102, 103]));

        $idx = $this->makeIndex();
        $idx->build();

        $this->assertTrue(
            $idx->isReferenced(101, '2024/01/img101.jpg', []),
            'ACF serialized ID 101 must mark attachment as used.'
        );
        $this->assertTrue(
            $idx->isReferenced(102, '2024/01/img102.jpg', []),
            'ACF serialized ID 102 must mark attachment as used.'
        );
        $this->assertTrue(
            $idx->isReferenced(103, '2024/01/img103.jpg', []),
            'ACF serialized ID 103 must mark attachment as used.'
        );
    }

    // =========================================================================
    // Surface 21 — Sub-size URL (-WxH) marks parent attachment as used
    // =========================================================================

    public function testIsReferencedReturnsTrueWhenOnlySubSizeIsReferenced(): void
    {
        // Content references only the 300x200 sub-size, not the full image.
        $this->db->addPostContent(
            '<img src="' . self::BASE_URL . '/2024/01/hero-300x200.jpg" />'
        );

        $meta = $this->makeMeta('2024/01/hero.jpg');
        // makeMeta() sets sizes.medium.file = 'hero-300x200.jpg'

        $idx = $this->makeIndex();
        $idx->build();

        $this->assertTrue(
            $idx->isReferenced(42, '2024/01/hero.jpg', $meta),
            'Referencing only a -WxH sub-size URL must mark the parent attachment as used.'
        );
    }

    // =========================================================================
    // Surface 17 — Generic options URL scan
    // =========================================================================

    public function testIsReferencedReturnsTrueForUrlInOptions(): void
    {
        $url = self::BASE_URL . '/2024/01/settings-img.jpg';
        $this->db->addOptionsRow($url);

        $idx = $this->makeIndex();
        $idx->build();

        $this->assertTrue(
            $idx->isReferenced(200, '2024/01/settings-img.jpg', $this->makeMeta('2024/01/settings-img.jpg')),
            'Uploads URL in wp_options must mark the attachment as used.'
        );
    }

    // =========================================================================
    // Protocol-agnostic (http vs https)
    // =========================================================================

    public function testProtocolAgnosticMatchHttp(): void
    {
        // http:// URL in content; uploads base URL is https://.
        $this->db->addPostContent(
            '<img src="http://' . self::BASE_HOST . '/2024/01/proto.jpg" />'
        );

        $idx = $this->makeIndex();
        $idx->build();

        $this->assertTrue(
            $idx->isReferenced(300, '2024/01/proto.jpg', $this->makeMeta('2024/01/proto.jpg')),
            'http:// URL must match when uploads base is https:// (protocol-agnostic).'
        );
    }

    // =========================================================================
    // Basename-only match (sub-size filename)
    // =========================================================================

    public function testBasenameMatchViaSubSizeFilename(): void
    {
        // Content references the sub-size URL; basename 'hero-300x200.jpg' is indexed.
        $this->db->addPostContent(
            '<img src="' . self::BASE_URL . '/2024/01/hero-300x200.jpg" />'
        );

        $meta = [
            'file'  => '2024/01/hero.jpg',
            'sizes' => [
                'medium' => ['file' => 'hero-300x200.jpg'],
            ],
        ];

        $idx = $this->makeIndex();
        $idx->build();

        $this->assertTrue(
            $idx->isReferenced(42, '2024/01/hero.jpg', $meta),
            'Sub-size basename indexed via URL must mark parent attachment as used.'
        );
    }

    // =========================================================================
    // Regression: basename-collision false-positive (#190 off-by-one)
    //
    // If attachment A (referenced) and attachment B (unused) share the same
    // filename under different upload subdirectories (e.g. 2023/01/logo.jpg vs
    // 2024/06/logo.jpg), addPath() must NOT index the bare filename as a key.
    // If it did, isReferenced() would find basename 'logo.jpg' in $paths and
    // incorrectly mark attachment B as referenced, causing the scan to report
    // one fewer unused image than actually exists (the 9→8 / 38→37 undercount).
    // =========================================================================

    public function testBasenameCollisionDoesNotMarkUnusedAttachmentAsReferenced(): void
    {
        // Attachment A (ID=10) lives at 2023/01/logo.jpg and IS referenced.
        $this->db->addPostContent(
            '<img src="' . self::BASE_URL . '/2023/01/logo.jpg" class="wp-image-10" />'
        );

        $idx = $this->makeIndex();
        $idx->build();

        // A is correctly referenced (direct ID match).
        $this->assertTrue(
            $idx->isReferenced(10, '2023/01/logo.jpg', []),
            'Attachment A (referenced by content) must be marked used.'
        );

        // Attachment B (ID=99) has the SAME filename 'logo.jpg' but in a different
        // subdirectory (2024/06/). It is NOT referenced anywhere. The bare-basename
        // match must NOT fire for B just because A shares the same filename.
        $this->assertFalse(
            $idx->isReferenced(99, '2024/06/logo.jpg', []),
            'Attachment B with same filename but different subdirectory must NOT be ' .
            'marked used (basename-collision false-positive regression, #190).'
        );
    }

    // =========================================================================
    // Negative: truly unreferenced attachment
    // =========================================================================

    public function testIsReferencedReturnsFalseForOrphanedAttachment(): void
    {
        $this->db->addThumbnailId(1);
        $this->db->addPostContent('<p class="wp-image-1">text</p>');

        $idx = $this->makeIndex();
        $idx->build();

        $this->assertFalse(
            $idx->isReferenced(999, '2024/01/orphan.jpg', $this->makeMeta('2024/01/orphan.jpg')),
            'Attachment with no reference must not be marked used.'
        );
    }

    // =========================================================================
    // original_image path (WP 5.3+ big-image scale-down)
    // =========================================================================

    public function testIsReferencedReturnsTrueForOriginalImageScaledRef(): void
    {
        // Content references hero-scaled.jpg (the scaled-down display version).
        $this->db->addPostContent(
            '<img src="' . self::BASE_URL . '/2024/01/hero-scaled.jpg" />'
        );

        $meta = [
            'file'           => '2024/01/hero-scaled.jpg',
            'original_image' => 'hero.jpg',
            'sizes'          => [],
        ];

        $idx = $this->makeIndex();
        $idx->build();

        $this->assertTrue(
            $idx->isReferenced(42, '2024/01/hero-scaled.jpg', $meta),
            'Scaled main file URL must mark attachment as used.'
        );
    }

    // =========================================================================
    // Elementor image_id numeric string
    // =========================================================================

    public function testIsReferencedReturnsTrueForElementorImageIdKey(): void
    {
        $json = json_encode([[
            'settings' => [
                'image_id'  => '95',
                'some_text' => 'Hello',
            ],
        ]]);

        $this->db->addPageBuilderMeta('_elementor_data', (string)$json);

        $idx = $this->makeIndex();
        $idx->build();

        $this->assertTrue(
            $idx->isReferenced(95, '2024/01/img95.jpg', []),
            'image_id numeric string in Elementor JSON must mark attachment as used.'
        );
    }

    // =========================================================================
    // build() returns false when uploads base is empty (abort signal)
    // =========================================================================

    public function testBuildReturnsFalseWhenUploadsBaseIsEmpty(): void
    {
        // Override wp_upload_dir to return an empty baseurl.
        Functions\when('wp_upload_dir')->justReturn([
            'baseurl' => '',
            'basedir' => '',
        ]);

        $idx    = new MediaReferenceIndex();
        $result = $idx->build();

        $this->assertFalse(
            $result,
            'build() must return false when uploads baseurl is empty so callers can abort.'
        );
    }

    // =========================================================================
    // Revision content: image referenced only in a revision is NOT flagged unused
    // =========================================================================

    public function testImageReferencedOnlyInRevisionIsNotFlaggedUnused(): void
    {
        // The regular post_content scan returns nothing for this image.
        // Only the revision content references it.
        $this->db->addRevisionContent(
            '<figure class="wp-block-image wp-image-404"><img src="' .
            self::BASE_URL . '/2024/01/revision-only.jpg"/></figure>'
        );

        $idx = $this->makeIndex();
        $idx->build();

        $this->assertTrue(
            $idx->isReferenced(404, '2024/01/revision-only.jpg', $this->makeMeta('2024/01/revision-only.jpg')),
            'Image referenced only in a revision must be marked as used (conservative).'
        );
    }
}

// =============================================================================
// RefIndexFakeWpdb — minimal $wpdb double for MediaReferenceIndex tests.
//
// The index calls: prepare(), get_results(), get_col(), esc_like(), and reads
// $wpdb->posts, $wpdb->postmeta, $wpdb->options, $wpdb->termmeta, $wpdb->usermeta.
//
// Pagination guard: each "bucket" (post_content, etc.) is returned once then
// replaced with [] to break the while(true) loops in the index.
// =============================================================================

final class RefIndexFakeWpdb
{
    // Table name properties the index references in SQL strings.
    public string $posts    = 'wp_posts';
    public string $postmeta = 'wp_postmeta';
    public string $options  = 'wp_options';
    public string $termmeta = 'wp_termmeta';
    public string $usermeta = 'wp_usermeta';
    public string $prefix   = 'wp_';

    private string $uploadsBase;
    private string $uploadsDir;

    // ---- seeded data --------------------------------------------------------

    /** @var list<string> */
    private array $postContentData = [];

    /** @var list<string> */
    private array $revisionContentData = [];

    /** @var list<int> */
    private array $thumbnailIds = [];

    /** @var array<string,list<string>> meta_key => [values] */
    private array $pageBuilderMeta = [];

    /** @var list<string> */
    private array $serializedMeta = [];

    /** @var list<string> */
    private array $themeModsRows = [];

    /** @var list<string> */
    private array $optionsRows = [];

    // ---- pagination state ---------------------------------------------------

    /** Tracks whether each paged bucket has already been consumed. */
    private array $consumed = [
        'post_content'     => false,
        'revision_content' => false,
        'postmeta_generic' => false,
    ];

    /** Total wpdb method calls (used for idempotency assertion). */
    private int $buildCallCount = 0;

    public function __construct(string $uploadsBase, string $uploadsDir)
    {
        $this->uploadsBase = $uploadsBase;
        $this->uploadsDir  = $uploadsDir;
    }

    // ---- seeding API --------------------------------------------------------

    public function addPostContent(string $content): void
    {
        $this->postContentData[] = $content;
    }

    public function addRevisionContent(string $content): void
    {
        $this->revisionContentData[] = $content;
    }

    public function addThumbnailId(int $id): void
    {
        $this->thumbnailIds[] = $id;
    }

    public function addPageBuilderMeta(string $key, string $json): void
    {
        $this->pageBuilderMeta[$key][] = $json;
    }

    public function addSerializedMeta(string $serialized): void
    {
        $this->serializedMeta[] = $serialized;
    }

    public function addThemeModsRow(string $serialized): void
    {
        $this->themeModsRows[] = $serialized;
    }

    public function addOptionsRow(string $value): void
    {
        $this->optionsRows[] = $value;
    }

    /** Reset pagination flags between tests (called by makeIndex()). */
    public function reset(): void
    {
        $this->consumed    = [
            'post_content'     => false,
            'revision_content' => false,
            'postmeta_generic' => false,
        ];
        $this->buildCallCount = 0;
    }

    public function getBuildCalls(): int
    {
        return $this->buildCallCount;
    }

    // ---- wpdb API -----------------------------------------------------------

    public function prepare(string $query, ...$args): string
    {
        $this->buildCallCount++;
        return json_encode(['sql' => $query, 'args' => $args]) ?: '';
    }

    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    /** get_var — only used by the scan handler (not the reference index). */
    public function get_var(string $prepared): ?string
    {
        return null;
    }

    /**
     * get_results — returns structured rows.
     *
     * @param string|array $prepared prepare() output or raw SQL.
     * @param string $output
     * @return list<array<string,string>>
     */
    public function get_results($prepared, string $output = ARRAY_A): array
    {
        $sql     = $this->extractSql($prepared);
        $args    = $this->extractArgs($prepared);

        // --- post_content paginated scan (excludes revision, has post_status filter) ---
        if ($this->matchesSql($sql, ['post_content', 'post_status', 'LIMIT'])) {
            // Return data on first call (offset=0), empty on subsequent (offset>0).
            if (!$this->consumed['post_content']) {
                $this->consumed['post_content'] = true;
                $rows = [];
                foreach ($this->postContentData as $c) {
                    $rows[] = ['post_content' => $c, 'post_excerpt' => ''];
                }
                return $rows;
            }
            return [];
        }

        // --- revision content paginated scan (post_type = 'revision', no post_status filter) ---
        if ($this->matchesSql($sql, ['post_content', "post_type = 'revision'", 'LIMIT'])) {
            if (!$this->consumed['revision_content']) {
                $this->consumed['revision_content'] = true;
                $rows = [];
                foreach ($this->revisionContentData as $c) {
                    $rows[] = ['post_content' => $c, 'post_excerpt' => ''];
                }
                return $rows;
            }
            return [];
        }

        // --- _thumbnail_id (get_results with LEFT JOIN, post_title attribution) ---
        if ($this->matchesSql($sql, ['_thumbnail_id', 'REGEXP'])) {
            $rows = [];
            foreach ($this->thumbnailIds as $id) {
                $rows[] = ['meta_value' => (string)$id, 'post_id' => '0', 'post_title' => ''];
            }
            return $rows;
        }

        // --- _product_image_gallery (get_results with LEFT JOIN) ---
        if ($this->matchesSql($sql, ['_product_image_gallery'])) {
            return [];
        }

        // --- page-builder meta (prepare() encodes meta_key as args[0]) ---
        if ($this->matchesSql($sql, ['meta_key', 'FROM', 'postmeta']) && count($args) >= 1) {
            $metaKey = (string)($args[0] ?? '');
            $rows    = [];
            foreach ($this->pageBuilderMeta[$metaKey] ?? [] as $val) {
                $rows[] = ['meta_value' => $val, 'post_id' => '0', 'post_title' => ''];
            }
            return $rows;
        }

        // --- theme_mods (raw SQL, no prepare) ---
        if ($this->matchesSql($sql, ["theme\\_mods\\_%", 'option_name', 'LIKE'])) {
            $rows = [];
            foreach ($this->themeModsRows as $val) {
                $rows[] = ['option_name' => 'theme_mods_test', 'option_value' => $val];
            }
            return $rows;
        }

        // --- Generic options URL scan (get_results via prepare(), SELECT option_name, option_value) ---
        if ($this->matchesSql($sql, ['wp_options', 'option_value', 'LIKE'])) {
            $rows = [];
            foreach ($this->optionsRows as $v) {
                if (strpos($v, $this->uploadsBase) !== false) {
                    $rows[] = ['option_name' => 'test_option', 'option_value' => $v];
                }
            }
            return $rows;
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
     * @param string|array $prepared
     * @return list<string>
     */
    public function get_col($prepared): array
    {
        $sql  = $this->extractSql($prepared);
        $args = $this->extractArgs($prepared);

        // --- _thumbnail_id ---
        if ($this->matchesSql($sql, ['_thumbnail_id'])) {
            return array_map('strval', $this->thumbnailIds);
        }

        // --- _product_image_gallery ---
        if ($this->matchesSql($sql, ['_product_image_gallery'])) {
            return [];
        }

        // --- ACF key-name-filtered numeric scan (0.25.6: INNER JOIN + meta_key REGEXP) ---
        if ($this->matchesSql($sql, ['INNER JOIN', 'meta_key REGEXP'])) {
            return [];
        }

        // --- Serialized meta scan (LIKE 'a:%') ---
        if ($this->matchesSql($sql, ["LIKE 'a:%'"])) {
            return $this->serializedMeta;
        }

        // --- Generic postmeta URL scan (LIKE %uploads% LIMIT OFFSET) ---
        if ($this->matchesSql($sql, ['postmeta', 'LIKE', 'LIMIT', 'OFFSET'])) {
            // Consume and return empty to break the while loop.
            if (!$this->consumed['postmeta_generic']) {
                $this->consumed['postmeta_generic'] = true;
                return [];
            }
            return [];
        }

        // --- WPBakery meta keys ---
        if ($this->matchesSql($sql, ['_wpb_shortcodes_custom_css', '_vc_post_settings'])) {
            return [];
        }

        // --- Generic options URL scan ---
        if ($this->matchesSql($sql, ['wp_options', 'LIKE'])) {
            $out = [];
            foreach ($this->optionsRows as $v) {
                if (strpos($v, $this->uploadsBase) !== false) {
                    $out[] = $v;
                }
            }
            return $out;
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
     * Return true if $sql contains ALL of the given needles.
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
