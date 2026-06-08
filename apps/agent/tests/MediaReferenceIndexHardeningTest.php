<?php
/**
 * MediaReferenceIndexHardeningTest — one test per gap closed in #196.
 *
 * Gap 1: sub-size-only URL with EMPTY _wp_attachment_metadata sizes → parent still
 *         isReferenced=true; and the basename-collision test still passes.
 * Gap 2: wp:image {"id":N} / "mediaId":N / "ids":[...] in post content with NO
 *         wp-image-N and NO url → referenced.
 * Gap 3: Yoast/RankMath *-image-id meta = attachment id → referenced.
 * Gap 4: options_* wp_options row = bare attachment id → referenced.
 * Gap 5: attachment id >= 10,000,000 referenced via an image-suggestive meta key →
 *         referenced (was missed before).
 * Gap 6: builder/ACF flat key from the newly-added keyword list (e.g. "media":123) →
 *         referenced.
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
final class MediaReferenceIndexHardeningTest extends TestCase
{
    private const BASE_HOST = 'example.com/wp-content/uploads';
    private const BASE_URL  = 'https://' . self::BASE_HOST;
    private const BASE_DIR  = '/var/www/html/wp-content/uploads';

    /** @var HardeningFakeWpdb */
    private HardeningFakeWpdb $db;

    protected function set_up(): void
    {
        parent::set_up();
        Monkey\setUp();

        $this->db = new HardeningFakeWpdb(self::BASE_HOST, self::BASE_DIR);

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
            return (bool)preg_match('/^[sabiOdACNR]:|^b:0;/', $data);
        });

        Functions\when('esc_sql')->alias(static fn ($s) => addslashes((string)$s));
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

    // =========================================================================
    // Gap 1a: sub-size-only URL with EMPTY _wp_attachment_metadata sizes
    // =========================================================================

    /**
     * The indexed content only references the 300x200 sub-size URL. The
     * attachment metadata has an EMPTY 'sizes' array (as happens when metadata
     * is missing, plugin-generated, or external crop plugins skip writing back
     * to core). The parent attachment MUST still be classified as referenced
     * via the sub-size → parent derivation introduced in #196.
     */
    public function testSubSizeUrlWithEmptySizesMetaStillReferencesParent(): void
    {
        // Content references only the sub-size, not the full-resolution file.
        $this->db->addPostContent(
            '<img src="' . self::BASE_URL . '/2024/01/hero-300x200.jpg" />'
        );

        // Metadata has an empty sizes array — the sub-size is NOT registered.
        $meta = [
            'file'   => '2024/01/hero.jpg',
            'width'  => 1200,
            'height' => 800,
            'sizes'  => [],
        ];

        $idx = $this->makeIndex();
        $idx->build();

        $this->assertTrue(
            $idx->isReferenced(42, '2024/01/hero.jpg', $meta),
            'Sub-size URL with EMPTY sizes metadata must still mark parent as referenced ' .
            '(Gap 1: sub-size → parent derivation).'
        );
    }

    /**
     * Gap 1b: basename-collision guard.
     *
     * Attachment A (2023/01/logo.jpg, ID=10) is referenced. Attachment B
     * (2024/06/logo.jpg, ID=99) shares the same filename but is in a different
     * subdirectory and is NOT referenced. The sub-size derivation must NOT fire
     * for B just because A's sub-size "2023/01/logo-300x200.jpg" would strip to
     * "2023/01/logo.jpg" — a different path from B's "2024/06/logo.jpg".
     *
     * This mirrors the existing testBasenameCollisionDoesNotMarkUnusedAttachmentAsReferenced
     * and adds the sub-size derivation angle.
     */
    public function testSubSizeDerivedParentDoesNotCrossDirectoryBoundary(): void
    {
        // A's sub-size URL in 2023/01/ is referenced in content.
        $this->db->addPostContent(
            '<img src="' . self::BASE_URL . '/2023/01/logo-300x200.jpg" />'
        );

        $idx = $this->makeIndex();
        $idx->build();

        // A (2023/01/logo.jpg) — sub-size derivation strips "2023/01/logo-300x200.jpg"
        // to "2023/01/logo.jpg" → match → referenced.
        $this->assertTrue(
            $idx->isReferenced(10, '2023/01/logo.jpg', ['file' => '2023/01/logo.jpg', 'sizes' => []]),
            'Attachment A: sub-size in 2023/01/ must mark parent 2023/01/logo.jpg as referenced.'
        );

        // B (2024/06/logo.jpg) — derivation would produce "2023/01/logo.jpg", NOT "2024/06/logo.jpg"
        // → no match → must remain unreferenced.
        $this->assertFalse(
            $idx->isReferenced(99, '2024/06/logo.jpg', ['file' => '2024/06/logo.jpg', 'sizes' => []]),
            'Attachment B in 2024/06/ must NOT be referenced by a sub-size from a different ' .
            'subdirectory (Gap 1: cross-directory collision guard).'
        );
    }

    // =========================================================================
    // Gap 2: Block-editor comment IDs
    // =========================================================================

    /**
     * wp:image {"id":N} in post_content with NO wp-image-N class and NO URL.
     * The block comment JSON must be scanned for the "id" key.
     */
    public function testBlockCommentImageIdIsExtracted(): void
    {
        // Pure Gutenberg serialised block — no wp-image-N class, no full URL.
        $this->db->addPostContent(
            '<!-- wp:image {"id":501,"sizeSlug":"large"} -->'
            . '<figure class="wp-block-image size-large">'
            . '<img src="/placeholder.jpg" />'
            . '</figure>'
            . '<!-- /wp:image -->'
        );

        $idx = $this->makeIndex();
        $idx->build();

        $this->assertTrue(
            $idx->isReferenced(501, '2024/03/shot.jpg', []),
            'wp:image {"id":N} block comment must mark attachment as referenced (Gap 2).'
        );
    }

    /**
     * wp:media-text {"mediaId":N} — mediaId key in block comment JSON.
     */
    public function testBlockCommentMediaIdIsExtracted(): void
    {
        $this->db->addPostContent(
            '<!-- wp:media-text {"mediaId":612,"mediaType":"image"} -->'
            . '<div class="wp-block-media-text"></div>'
            . '<!-- /wp:media-text -->'
        );

        $idx = $this->makeIndex();
        $idx->build();

        $this->assertTrue(
            $idx->isReferenced(612, '2024/04/feature.jpg', []),
            'wp:media-text {"mediaId":N} block comment must mark attachment as referenced (Gap 2).'
        );
    }

    /**
     * Gallery block "ids":[N,N,N] — comma-separated IDs list in block comment JSON.
     */
    public function testBlockCommentGalleryIdsAreExtracted(): void
    {
        $this->db->addPostContent(
            '<!-- wp:gallery {"ids":[201,202,203],"columns":3} -->'
            . '<figure class="wp-block-gallery"></figure>'
            . '<!-- /wp:gallery -->'
        );

        $idx = $this->makeIndex();
        $idx->build();

        $this->assertTrue(
            $idx->isReferenced(201, '2024/05/a.jpg', []),
            'Gallery block "ids":[201,...] must mark attachment 201 as referenced (Gap 2).'
        );
        $this->assertTrue(
            $idx->isReferenced(202, '2024/05/b.jpg', []),
            'Gallery block "ids":[...,202,...] must mark attachment 202 as referenced (Gap 2).'
        );
        $this->assertTrue(
            $idx->isReferenced(203, '2024/05/c.jpg', []),
            'Gallery block "ids":[...,203] must mark attachment 203 as referenced (Gap 2).'
        );
    }

    // =========================================================================
    // Gap 3: SEO-plugin image-ID meta
    // =========================================================================

    /**
     * _yoast_wpseo_opengraph-image-id and rank_math_facebook_image_id stored as
     * a bare attachment ID under an underscore-prefixed meta key. Previously the
     * ACF numeric scan excluded `_`-prefixed keys, causing these to be missed.
     * indexSeoMeta() now explicitly covers this allowlist.
     */
    public function testYoastOpenGraphImageIdIsReferenced(): void
    {
        $this->db->addSeoMetaRow('_yoast_wpseo_opengraph-image-id', 750);

        $idx = $this->makeIndex();
        $idx->build();

        $this->assertTrue(
            $idx->isReferenced(750, '2024/06/og-image.jpg', []),
            '_yoast_wpseo_opengraph-image-id attachment must be marked referenced (Gap 3).'
        );
    }

    public function testRankMathFacebookImageIdIsReferenced(): void
    {
        $this->db->addSeoMetaRow('rank_math_facebook_image_id', 888);

        $idx = $this->makeIndex();
        $idx->build();

        $this->assertTrue(
            $idx->isReferenced(888, '2024/06/fb-image.jpg', []),
            'rank_math_facebook_image_id attachment must be marked referenced (Gap 3).'
        );
    }

    // =========================================================================
    // Gap 4: Bare attachment IDs in wp_options (ACF options pages)
    // =========================================================================

    /**
     * An ACF options page stores a bare attachment ID in wp_options under
     * the option_name "options_hero_image". Previously wp_options was only
     * URL-scanned. indexOptionsNumericIds() now covers this case.
     */
    public function testAcfOptionsPageBareIdIsReferenced(): void
    {
        $this->db->addOptionsNumericIdRow('options_hero_image', 910);

        $idx = $this->makeIndex();
        $idx->build();

        $this->assertTrue(
            $idx->isReferenced(910, '2024/07/hero-bg.jpg', []),
            'ACF options_hero_image bare ID must mark attachment as referenced (Gap 4).'
        );
    }

    // =========================================================================
    // Gap 5: Attachment ID >= 10,000,000
    // =========================================================================

    /**
     * A mature site with > 10 million media uploads will have attachment IDs
     * exceeding the old 7-digit / < 10_000_000 ceiling. This was a silent false
     * negative: the numeric scan passed IDs silently. Now the ceiling is
     * 2_147_483_647 (PHP signed 32-bit max), so high IDs are included.
     */
    public function testHighAttachmentIdAboveTenMillionIsReferenced(): void
    {
        // Seed a postmeta row with an image-suggestive key and a high numeric ID.
        $this->db->addAcfNumericRow(10_500_042, 'hero_image');

        $idx = $this->makeIndex();
        $idx->build();

        $this->assertTrue(
            $idx->isReferenced(10_500_042, '2024/08/big-site-hero.jpg', []),
            'Attachment ID >= 10,000,000 must be referenced when stored in an image-suggestive ' .
            'meta key (Gap 5: raised ID ceiling from 10M to 2,147,483,647).'
        );
    }

    // =========================================================================
    // Gap 6: Broadened keyword list (media, img, src, pic, file, banner, hero, poster)
    // =========================================================================

    /**
     * A page-builder JSON settings blob stores {"media": 123} as a flat key.
     * "media" was not in the original keyword list. After Gap 6, it is, so this
     * ID must be collected.
     */
    public function testNewKeywordMediaIsIndexed(): void
    {
        $json = json_encode([['settings' => ['media' => '123']]]);
        $this->db->addPageBuilderMeta('_elementor_data', (string)$json);

        $idx = $this->makeIndex();
        $idx->build();

        $this->assertTrue(
            $idx->isReferenced(123, '2024/09/widget-media.jpg', []),
            '"media" key in page-builder JSON must mark attachment as referenced (Gap 6).'
        );
    }

    /**
     * A theme_mods-style array stores {"hero": 456} as a named key.
     * "hero" was not in the original keyword list.
     */
    public function testNewKeywordHeroIsIndexed(): void
    {
        $raw = serialize(['hero' => 456, 'other' => 'noop']);
        $this->db->addThemeModsRow($raw);

        $idx = $this->makeIndex();
        $idx->build();

        $this->assertTrue(
            $idx->isReferenced(456, '2024/09/hero-shot.jpg', []),
            '"hero" key in theme_mods array must mark attachment as referenced (Gap 6).'
        );
    }

    /**
     * A settings array stores {"banner": 789}. "banner" was not in the
     * original keyword list.
     */
    public function testNewKeywordBannerIsIndexed(): void
    {
        $json = json_encode([['settings' => ['banner' => '789']]]);
        $this->db->addPageBuilderMeta('_elementor_data', (string)$json);

        $idx = $this->makeIndex();
        $idx->build();

        $this->assertTrue(
            $idx->isReferenced(789, '2024/09/banner-img.jpg', []),
            '"banner" key in page-builder JSON must mark attachment as referenced (Gap 6).'
        );
    }
}

// =============================================================================
// HardeningFakeWpdb — wpdb double for the #196 hardening tests.
//
// Extends the pattern of RefIndexFakeWpdb with additional seeding methods for:
//   - SEO meta rows (Gap 3)
//   - Options numeric ID rows (Gap 4)
//   - ACF numeric postmeta rows (Gap 5)
// =============================================================================

final class HardeningFakeWpdb
{
    public string $posts    = 'wp_posts';
    public string $postmeta = 'wp_postmeta';
    public string $options  = 'wp_options';
    public string $termmeta = 'wp_termmeta';
    public string $usermeta = 'wp_usermeta';
    public string $prefix   = 'wp_';

    private string $uploadsBase;
    private string $uploadsDir;

    /** @var list<string> */
    private array $postContentData = [];

    /** @var list<string> */
    private array $themeModsRows = [];

    /** @var array<string, list<string>> */
    private array $pageBuilderMeta = [];

    /** @var list<string> */
    private array $serializedMeta = [];

    /** @var list<string> */
    private array $optionsRows = [];

    /**
     * SEO meta rows: list of [meta_key, attachment_id, post_id].
     * @var list<array{0:string,1:int,2:int}>
     */
    private array $seoMetaRows = [];

    /**
     * Options numeric-ID rows: list of [option_name, attachment_id].
     * @var list<array{0:string,1:int}>
     */
    private array $optionsNumericIdRows = [];

    /**
     * ACF/postmeta numeric rows: list of [attach_id, meta_key, post_id].
     * Used for Gap 5 (high attachment IDs via ACF postmeta path).
     * @var list<array{0:int,1:string,2:int}>
     */
    private array $acfNumericRows = [];

    private array $consumed = [
        'post_content'     => false,
        'revision_content' => false,
        'postmeta_generic' => false,
        'seo_meta'         => false,
        'options_numeric'  => false,
    ];

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

    public function addThemeModsRow(string $serialized): void
    {
        $this->themeModsRows[] = $serialized;
    }

    public function addPageBuilderMeta(string $key, string $json): void
    {
        $this->pageBuilderMeta[$key][] = $json;
    }

    public function addSerializedMeta(string $serialized): void
    {
        $this->serializedMeta[] = $serialized;
    }

    public function addOptionsRow(string $value): void
    {
        $this->optionsRows[] = $value;
    }

    /** Seed a SEO meta row that stores a bare attachment ID under the given key. */
    public function addSeoMetaRow(string $metaKey, int $attachmentId, int $postId = 1): void
    {
        $this->seoMetaRows[] = [$metaKey, $attachmentId, $postId];
    }

    /** Seed a wp_options row with an image-suggestive name holding a bare attachment ID. */
    public function addOptionsNumericIdRow(string $optionName, int $attachmentId): void
    {
        $this->optionsNumericIdRows[] = [$optionName, $attachmentId];
    }

    /** Seed a postmeta row simulating a high-ID ACF numeric reference. */
    public function addAcfNumericRow(int $attachId, string $metaKey, int $postId = 1): void
    {
        $this->acfNumericRows[] = [$attachId, $metaKey, $postId];
    }

    public function reset(): void
    {
        $this->consumed = [
            'post_content'     => false,
            'revision_content' => false,
            'postmeta_generic' => false,
            'seo_meta'         => false,
            'options_numeric'  => false,
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
     * get_results dispatcher.
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
                $rows = [];
                foreach ($this->postContentData as $c) {
                    $rows[] = ['post_content' => $c, 'post_excerpt' => '', 'ID' => '1', 'post_title' => ''];
                }
                return $rows;
            }
            return [];
        }

        // --- revision content paginated scan ---
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

        // --- SEO meta scan: detected by INNER JOIN + meta_key IN + meta_value REGEXP
        // + first arg is a _yoast or rank_math key (the args array holds the IN-list keys).
        // indexSeoMeta() passes $seoIdKeys as variadic args to prepare(), so
        // $args[0] will be '_yoast_wpseo_opengraph-image-id'.
        if (
            $this->matchesSql($sql, ['INNER JOIN', 'meta_key IN', 'meta_value REGEXP'])
            && count($args) >= 1
            && (
                str_contains((string)($args[0] ?? ''), '_yoast')
                || str_contains((string)($args[0] ?? ''), 'rank_math')
                || str_contains((string)($args[0] ?? ''), '_seopress')
            )
        ) {
            if (!$this->consumed['seo_meta']) {
                $this->consumed['seo_meta'] = true;
                $rows = [];
                foreach ($this->seoMetaRows as [$metaKey, $attachId, $postId]) {
                    $rows[] = [
                        'post_id'    => (string)$postId,
                        'meta_key'   => $metaKey,
                        'meta_value' => (string)$attachId,
                        'post_title' => '',
                    ];
                }
                return $rows;
            }
            return [];
        }

        // --- Options numeric-ID scan: detected by options table + INNER JOIN + REGEXP ---
        if ($this->matchesSql($sql, ['wp_options', 'INNER JOIN', 'REGEXP'])) {
            if (!$this->consumed['options_numeric']) {
                $this->consumed['options_numeric'] = true;
                $rows = [];
                foreach ($this->optionsNumericIdRows as [$optName, $attachId]) {
                    $rows[] = [
                        'option_name' => $optName,
                        'attach_id'   => (string)$attachId,
                    ];
                }
                return $rows;
            }
            return [];
        }

        // --- page-builder meta (specific meta_key = %s queries) ---
        if ($this->matchesSql($sql, ['meta_key', 'FROM', 'postmeta']) && count($args) >= 1) {
            $metaKey = (string)($args[0] ?? '');
            $rows    = [];
            foreach ($this->pageBuilderMeta[$metaKey] ?? [] as $val) {
                $rows[] = ['meta_value' => $val, 'post_id' => '0', 'post_title' => ''];
            }
            return $rows;
        }

        // --- ACF numeric scan (postmeta INNER JOIN + meta_value REGEXP) ---
        // This handles the Gap 5 acfNumericRows seeded data.
        if ($this->matchesSql($sql, ['INNER JOIN', 'meta_value REGEXP'])) {
            $rows = [];
            foreach ($this->acfNumericRows as [$attachId, $metaKey, $postId]) {
                $rows[] = [
                    'post_id'    => (string)$postId,
                    'attach_id'  => (string)$attachId,
                    'meta_key'   => $metaKey,
                    'post_title' => '',
                ];
            }
            // Serve once then clear to prevent re-serving.
            $this->acfNumericRows = [];
            return $rows;
        }

        // --- theme_mods ---
        if ($this->matchesSql($sql, ["theme\\_mods\\_%", 'option_name', 'LIKE'])) {
            $rows = [];
            foreach ($this->themeModsRows as $val) {
                $rows[] = ['option_name' => 'theme_mods_test', 'option_value' => $val];
            }
            return $rows;
        }

        // --- Generic options URL scan ---
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
     * get_col dispatcher.
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

        // --- Serialized meta scan (LIKE 'a:%') ---
        if ($this->matchesSql($sql, ["LIKE 'a:%'"])) {
            return $this->serializedMeta;
        }

        // --- Generic postmeta URL scan ---
        if ($this->matchesSql($sql, ['postmeta', 'LIKE', 'LIMIT', 'OFFSET'])) {
            if (!$this->consumed['postmeta_generic']) {
                $this->consumed['postmeta_generic'] = true;
            }
            return [];
        }

        // --- WPBakery meta keys ---
        if ($this->matchesSql($sql, ['_wpb_shortcodes_custom_css', '_vc_post_settings'])) {
            return [];
        }

        // --- Generic options URL scan (get_col path) ---
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
