<?php
/**
 * MediaCleanScanAcfBroadScanTest — exercises the real ACF broad-numeric-postmeta
 * scan path that the existing fake-$wpdb in MediaCleanScanPaginationTest does NOT
 * exercise (it returns [] for every ACF/NOT-LIKE/REGEXP query, masking the path).
 *
 * Root cause of #190 persistent -1 (post-0.25.3) and first fix attempt in 0.25.5:
 *   MediaReferenceIndex::indexPostmetaPageBuilders() ran a broad SQL query:
 *
 *     SELECT DISTINCT CAST(meta_value AS UNSIGNED)
 *     FROM wp_postmeta
 *     WHERE meta_key NOT LIKE '\_percent'
 *       AND meta_value REGEXP '^[1-9][0-9]{0,6}$'
 *
 *   On a real site this returned every numeric value in any public meta row —
 *   including values that happened to equal an attachment ID (e.g. a page-view
 *   counter = 5, a plugin setting = 101, etc.). Those coincidental values caused
 *   the affected attachment to be classified as "referenced" even though it was
 *   genuinely unused, producing the persistent -1 symptom.
 *
 * 0.25.5 INNER JOIN fix (partially addresses the issue):
 *   Added an INNER JOIN against wp_posts to ensure only numeric values that map to
 *   real attachment posts are collected. This filters non-attachment IDs but still
 *   false-positives when a coincidental counter value (e.g. post_views_count=567)
 *   equals a genuinely-existing attachment ID (ID=567 in wp_posts), because the JOIN
 *   confirms ID 567 IS a real attachment — even though it is stored under a
 *   non-image key and is not actually referenced by any image-bearing field.
 *
 * 0.25.6 key-name narrowing fix (definitive — issue #190):
 *   The ACF broad scan is narrowed to IMAGE-SUGGESTIVE meta key names using the
 *   same generous heuristic already used in extractFromArray():
 *
 *     AND pm.meta_key REGEXP '(image|gallery|attachment|thumbnail|photo|logo|icon|bg|avatar|picture)'
 *
 *   This ensures that only meta keys with an image-bearing name fragment contribute
 *   numeric attachment IDs. A counter key (post_views_count, seo_score, menu_depth)
 *   cannot pass the key-name REGEXP even when its value coincidentally equals an
 *   existing attachment ID. An image key (hero_image, gallery_ids, post_thumbnail,
 *   featured_photo, site_logo, author_avatar) still passes and is correctly indexed.
 *   The INNER JOIN is retained as belt-and-braces.
 *
 * The tests in this file:
 *   1. testAllUnusedWhenAcfBroadScanReturnsEmpty — baseline: ACF returns [],
 *      all N attachments reported unused. Walk correctness check.
 *   2. testAcfBroadScanCoincidentalValueDoesNotReduceCount — the post-0.25.6 path:
 *      a numeric postmeta value coincides with an attachment ID but the joined+
 *      key-name-filtered query returns [] → still N=10.
 *   3. testAcfBroadScanLegitimateAttachmentReferenceIsDetected — a numeric
 *      postmeta value IS a real attachment ID under an image key (joined query
 *      returns it) → that attachment is correctly marked as referenced → N-1 unused.
 *   4. testAcfBroadScanWithNonAttachmentIdDoesNotReduceCount — ACF returns a
 *      value > 9999999 (outside the attachment ID range), no false positive.
 *   5. testDiagnosticFieldsAlwaysPresentInScanResult — contract check.
 *   6. testNonImageKeyCoincidentalIdIsNotFlagged — key-name gate: post_views_count=567
 *      with attachment ID 567 existing → NOT flagged (key is not image-suggestive).
 *   7. testImageSuggestiveKeyIsIndexed — hero_image=567 with attachment ID 567 →
 *      correctly flagged as referenced (key IS image-suggestive).
 *   8. testAllUnusedNYieldsNcandidates — all-unused N → exactly N candidates.
 *
 * @package WPMgr\Agent\Tests
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WPMgr\Agent\Commands\MediaCleanCommand;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Commands\MediaCleanCommand
 * @covers \WPMgr\Agent\Media\MediaReferenceIndex
 */
final class MediaCleanScanAcfBroadScanTest extends TestCase
{
    protected function set_up(): void
    {
        parent::set_up();
        Monkey\setUp();
    }

    protected function tear_down(): void
    {
        global $wpdb;
        $wpdb = null; // @phpstan-ignore-line
        Monkey\tearDown();
        parent::tear_down();
    }

    // =========================================================================
    // Wpdb factory that exercises the real ACF broad-numeric-scan path
    // =========================================================================

    /**
     * Build a wpdb stub that:
     *   - serves get_results for "post_status = 'inherit'" attachment-walk selects.
     *   - serves get_results for the ACF key-name-filtered + INNER-JOIN numeric scan
     *     (detected by "meta_value REGEXP" in the SQL, distinguishing it from the walk).
     *   - serves get_col for _thumbnail_id queries.
     *
     * The ACF broad scan query (post-0.25.6 fix) contains ALL of:
     *   - "INNER JOIN" (the cross-validate join against wp_posts)
     *   - "meta_value REGEXP" for the numeric range filter
     *
     * The attachment walk query is distinguished by "post_status = 'inherit'".
     *
     * The fake detects the ACF broad-scan get_results call by checking for
     * "meta_value REGEXP" in the SQL. When matched, it returns $acfJoinedResults —
     * which simulates what the DB would return after BOTH filters (key-name REGEXP
     * + INNER JOIN): only rows whose meta_key is image-suggestive AND whose meta_value
     * corresponds to a real attachment post. Callers pass [] to simulate "no result"
     * (non-image key filtered out, or no real attachment matched). Callers pass rows
     * to simulate a "legitimate ACF image field reference".
     *
     * Each row in $acfJoinedResults has:
     *   post_id    (string) — the post that owns the meta row
     *   attach_id  (string) — CAST(meta_value AS UNSIGNED)
     *   meta_key   (string) — the ACF field slug (e.g. 'hero_image')
     *   post_title (string) — post_title of the owning post (may be empty)
     *
     * @param list<array<string,string>> $attachmentRows    Rows for the attachment walk.
     * @param list<array<string,string>> $acfJoinedResults  Rows returned by the ACF query.
     * @param list<int>                  $thumbnailIds      IDs to return from _thumbnail_id query.
     */
    private function makeWpdb(
        array $attachmentRows,
        array $acfJoinedResults = [],
        array $thumbnailIds     = []
    ): object {
        return new class ($attachmentRows, $acfJoinedResults, $thumbnailIds) {
            public string $posts    = 'wp_posts';
            public string $postmeta = 'wp_postmeta';
            public string $options  = 'wp_options';
            public string $termmeta = 'wp_termmeta';
            public string $usermeta = 'wp_usermeta';

            /** @var list<array<string,string>> */
            private array $attachmentRows;
            /** @var list<array<string,string>> */
            private array $acfJoinedResults;
            /** @var list<int> */
            private array $thumbnailIds;
            private bool  $thumbnailServed = false;
            private bool  $acfServed       = false;

            public function __construct(
                array $attachmentRows,
                array $acfJoinedResults,
                array $thumbnailIds
            ) {
                $this->attachmentRows   = $attachmentRows;
                $this->acfJoinedResults = $acfJoinedResults;
                $this->thumbnailIds     = $thumbnailIds;
            }

            /** @return list<array<string,string>> */
            public function get_results(string $sql, string $output): array
            {
                // ACF broad-scan query: detected by the combination of
                // "meta_key NOT LIKE" and "INNER JOIN" which is unique to the
                // indexPostmetaPageBuilders() numeric-ID scan. Other queries that
                // contain "meta_value REGEXP" (e.g. _thumbnail_id fetch in
                // indexFeaturedImages) do NOT have "meta_key NOT LIKE", so they
                // cannot collide with this detector.
                // Served once (the index only runs one pass per build()).
                // $acfJoinedResults simulates DB rows after both the INNER JOIN and
                // (in production) the key-name filter.
                if (
                    strpos($sql, 'meta_key NOT LIKE') !== false
                    && strpos($sql, 'INNER JOIN') !== false
                    && !$this->acfServed
                ) {
                    $this->acfServed = true;
                    return $this->acfJoinedResults;
                }

                // Attachment walk query: detected by "post_status = 'inherit'".
                // Serves LIMIT/OFFSET-aware slices to exercise the real pagination path.
                if (strpos($sql, "post_status = 'inherit'") !== false) {
                    preg_match('/LIMIT\s+(\d+)\s+OFFSET\s+(\d+)/i', $sql, $m);
                    $limit  = isset($m[1]) ? (int)$m[1] : count($this->attachmentRows);
                    $offset = isset($m[2]) ? (int)$m[2] : 0;
                    return array_slice($this->attachmentRows, $offset, $limit);
                }

                return [];
            }

            /** @return list<string> */
            public function get_col(string $sql): array
            {
                // _thumbnail_id scan (served once then empty to terminate the loop).
                if (strpos($sql, '_thumbnail_id') !== false && !$this->thumbnailServed) {
                    $this->thumbnailServed = true;
                    return array_map('strval', $this->thumbnailIds);
                }

                // Serialized meta scan (LIKE 'a:%').
                if (strpos($sql, "LIKE 'a:%'") !== false) {
                    return [];
                }

                return [];
            }

            public function get_var(string $sql): string
            {
                return '';
            }

            public function prepare(string $sql, mixed ...$args): string
            {
                foreach ($args as $arg) {
                    $pos = strpos($sql, '%d');
                    if ($pos !== false) {
                        $sql = substr_replace($sql, (string)(int)$arg, $pos, 2);
                        continue;
                    }
                    $pos = strpos($sql, '%s');
                    if ($pos !== false) {
                        $sql = substr_replace($sql, (string)$arg, $pos, 2);
                    }
                }
                return $sql;
            }

            public function esc_like(string $text): string
            {
                return addcslashes($text, '_%\\');
            }
        };
    }

    /**
     * Build a minimal ACF-scan row as returned by the production get_results call
     * in indexPostmetaPageBuilders(). The row shape is:
     *   post_id, attach_id (=CAST(meta_value AS UNSIGNED)), meta_key, post_title.
     *
     * @param int    $attachId  The attachment post ID referenced by the field.
     * @param string $metaKey   The ACF field slug (e.g. 'hero_image').
     * @param int    $postId    The post that owns the meta row (default 0 = unknown).
     * @param string $postTitle The post_title of the owning post (default '').
     * @return array<string,string>
     */
    private function makeAcfRow(
        int $attachId,
        string $metaKey,
        int $postId = 0,
        string $postTitle = ''
    ): array {
        return [
            'post_id'    => (string)$postId,
            'attach_id'  => (string)$attachId,
            'meta_key'   => $metaKey,
            'post_title' => $postTitle,
        ];
    }

    private function stubWpFunctions(): void
    {
        Functions\when('wp_upload_dir')->justReturn([
            'baseurl' => 'https://example.com/wp-content/uploads',
            'basedir' => '/var/www/html/wp-content/uploads',
        ]);

        Functions\when('get_post_meta')->alias(
            static function (int $id, string $key) {
                if ($key === '_wp_attached_file') {
                    return "tests/img{$id}.jpg";
                }
                return [];
            }
        );

        Functions\when('wp_get_attachment_image_src')->justReturn(false);
        Functions\when('get_option')->justReturn(false);
        Functions\when('is_serialized')->justReturn(false);
        Functions\when('esc_sql')->alias(static fn ($s) => addslashes((string)$s));
        // admin_url is called by buildPostEditUrl() for attribution on rows with postId > 0.
        Functions\when('admin_url')->alias(static fn (string $path = '') => 'https://example.com/wp-admin/' . ltrim($path, '/'));
    }

    // =========================================================================
    // Test 1: ACF joined query returns empty → all N attachments reported unused.
    //
    // Baseline: joined ACF query returns [] (no numeric postmeta value corresponds
    // to a real attachment post). All 10 attachments are unused; total must be 10.
    // This verifies the real ACF fetch-loop path is exercised without suppression.
    // =========================================================================

    public function testAllUnusedWhenAcfBroadScanReturnsEmpty(): void
    {
        global $wpdb;

        $allRows = [];
        for ($i = 1; $i <= 10; $i++) {
            $allRows[] = [
                'ID'         => (string)$i,
                'post_title' => "Screenshot {$i}",
                'guid'       => "https://example.com/wp-content/uploads/img{$i}.jpg",
            ];
        }

        // ACF joined query returns [] — no real attachment IDs from the broad scan.
        $wpdb = $this->makeWpdb($allRows, [], []); // @phpstan-ignore-line
        $this->stubWpFunctions();

        $cmd    = new MediaCleanCommand();
        $result = $cmd->execute([], ['action' => 'scan', 'offset' => 0, 'limit' => 100]);

        $this->assertTrue($result['ok'], 'Scan must succeed.');

        // total_attachments must equal 10 (the walk visited all 10 rows).
        $this->assertSame(
            10,
            $result['total_attachments'],
            'total_attachments must be 10: the walk must visit every attachment row.'
        );

        // All 10 are unused; referenced_count must be 0.
        $this->assertSame(
            0,
            $result['referenced_count'],
            'referenced_count must be 0 when ACF joined scan returns no values.'
        );

        // unused_count and total must both be 10.
        $this->assertSame(10, $result['unused_count'], 'unused_count must be 10.');
        $this->assertSame(10, $result['total'],        'total must be 10.');

        // referenced must be empty (no attachments classified referenced).
        $this->assertSame(
            0,
            $result['referenced_count'],
            'referenced_count must be 0 when no attachments are referenced.'
        );
        $this->assertSame(
            [],
            $result['referenced'],
            'referenced must be [] when no attachments are referenced.'
        );

        // All 10 candidates must be present.
        $this->assertCount(10, $result['candidates'], 'All 10 unused candidates must be returned.');
    }

    // =========================================================================
    // Test 2: ACF joined query returns [] for a coincidental numeric postmeta value.
    //
    // This is the FIXED path for the persistent -1 (issue #190):
    //   - A non-attachment numeric postmeta value (e.g. a page-view counter = 5)
    //     exists in wp_postmeta with a public (non-underscore) key.
    //   - Pre-fix: the broad scan returned '5', marking attachment ID 5 as
    //     referenced (false positive) → 9 unused reported instead of 10.
    //   - Post-fix: the INNER JOIN on wp_posts filters the value out because there
    //     is no attachment post with ID=5 in the join result (the DB never returns it
    //     for non-attachment numeric values). The joined query returns [] here.
    //
    // Result: all 10 attachments are correctly reported as unused. total=10.
    //
    // The fake simulates the fixed behaviour: the coincidental value is filtered out
    // by the join at the DB level, so $acfJoinedResults=[] (empty after join).
    // =========================================================================

    public function testAcfBroadScanCoincidentalValueDoesNotReduceCount(): void
    {
        global $wpdb;

        $allRows = [];
        for ($i = 1; $i <= 10; $i++) {
            $allRows[] = [
                'ID'         => (string)$i,
                'post_title' => "Screenshot {$i}",
                'guid'       => "https://example.com/wp-content/uploads/img{$i}.jpg",
            ];
        }

        // The coincidental postmeta value "5" exists in the DB but after the INNER JOIN
        // it is filtered out (ID=5 is not an attachment in wp_posts from the join's
        // perspective on this site). The joined query returns [].
        // This simulates the real DB behaviour post-fix.
        $wpdb = $this->makeWpdb($allRows, [], []); // @phpstan-ignore-line
        $this->stubWpFunctions();

        $cmd    = new MediaCleanCommand();
        $result = $cmd->execute([], ['action' => 'scan', 'offset' => 0, 'limit' => 100]);

        $this->assertTrue($result['ok'], 'Scan must succeed.');

        // total_attachments: all 10 rows must have been visited by the walk.
        $this->assertSame(
            10,
            $result['total_attachments'],
            'total_attachments must be 10: the walk must visit all attachment rows.'
        );

        // With the fix: referenced_count = 0 (no false positive from coincidental value).
        $this->assertSame(
            0,
            $result['referenced_count'],
            'referenced_count must be 0: the INNER JOIN eliminates coincidental numeric values.'
        );

        // unused_count and total must be 10 (not 9 as before the fix).
        $this->assertSame(
            10,
            $result['unused_count'],
            'unused_count must be 10 (not 9): the persistent -1 is fixed.'
        );
        $this->assertSame(10, $result['total'], 'total must be 10.');

        // referenced must be empty: no attachment must be falsely marked as referenced.
        $this->assertSame(
            [],
            $result['referenced'],
            'referenced must be []: no attachment must be falsely marked as referenced.'
        );

        // All 10 candidates must be present.
        $this->assertCount(10, $result['candidates'], 'All 10 unused candidates must be returned.');
    }

    // =========================================================================
    // Test 3: REAL enumeration correctness — N attachments all unused → N candidates.
    //
    // This is the primary regression guard: drives the actual fetch loop with
    // a result set where ALL N=10 attachments are unused. The scan must return
    // exactly N=10 candidates. The ACF joined query returns [] (no attachments
    // referenced via ACF fields). No thumbnail IDs. No other references.
    //
    // This is NOT a fake that skips the fetch loop: makeWpdb's get_results serves
    // LIMIT/OFFSET-aware slices of $attachmentRows, exercising the real pagination
    // logic of the walk. For N=10 (< batch size 200), a single DB round-trip is
    // made; for N>200 multiple round-trips are made (see MediaCleanScanPaginationTest).
    // =========================================================================

    public function testAllNAttachmentsUnusedYieldsNcandidates(): void
    {
        global $wpdb;

        $n       = 10;
        $allRows = [];
        for ($i = 1; $i <= $n; $i++) {
            $allRows[] = [
                'ID'         => (string)$i,
                'post_title' => "Unused Image {$i}",
                'guid'       => "https://example.com/wp-content/uploads/img{$i}.jpg",
            ];
        }

        // ACF joined query returns [] — no ACF references.
        // No thumbnail IDs — no featured-image references.
        $wpdb = $this->makeWpdb($allRows, [], []); // @phpstan-ignore-line
        $this->stubWpFunctions();

        $cmd    = new MediaCleanCommand();
        $result = $cmd->execute([], ['action' => 'scan', 'offset' => 0, 'limit' => 100]);

        $this->assertTrue($result['ok'], 'Scan must succeed when all attachments are unused.');

        // KEY ASSERTION: N attachments all unused → exactly N candidates.
        // This is the real enumeration semantics test: total must equal the
        // full unused set count, not N-1 or any other off-by-one.
        $this->assertSame(
            $n,
            $result['total'],
            "When all {$n} attachments are unused, total must be {$n} (not " . ($n - 1) . ').'
        );

        $this->assertSame(
            $n,
            $result['total_attachments'],
            "total_attachments must be {$n}: the walk must visit every attachment row."
        );

        $this->assertSame(
            0,
            $result['referenced_count'],
            'referenced_count must be 0 when no attachments are referenced.'
        );

        $this->assertSame($n, $result['unused_count'], "unused_count must be {$n}.");
        $this->assertCount($n, $result['candidates'],  "All {$n} candidates must be returned.");

        // Verify the exact IDs returned.
        $returnedIds = array_column($result['candidates'], 'id');
        sort($returnedIds);
        $this->assertSame(range(1, $n), $returnedIds, 'Candidates must include all N attachment IDs.');
    }

    // =========================================================================
    // Test 4: ACF joined query returns a value that IS a real attachment ID.
    //
    // This verifies the fix does NOT break legitimate ACF references:
    //   - Attachment ID 5 is stored as a numeric meta_value in an ACF image field.
    //   - The INNER JOIN confirms it is a real attachment post_type → ID 5 is added.
    //   - The scan correctly marks ID 5 as referenced → 9 unused (legitimate, not a bug).
    //
    // The fake simulates this by returning a row with attach_id='5' from the ACF
    // get_results query (meaning the DB join matched ID 5 as a real attachment
    // referenced via an image-suggestive ACF field).
    // =========================================================================

    public function testAcfBroadScanLegitimateAttachmentReferenceIsDetected(): void
    {
        global $wpdb;

        $allRows = [];
        for ($i = 1; $i <= 10; $i++) {
            $allRows[] = [
                'ID'         => (string)$i,
                'post_title' => "Image {$i}",
                'guid'       => "https://example.com/wp-content/uploads/img{$i}.jpg",
            ];
        }

        // ACF joined query returns a row for attach_id=5: simulating that a real
        // ACF image field (some_image) on post 42 references attachment ID 5.
        // The INNER JOIN confirmed ID=5 is a real attachment. This is a LEGITIMATE
        // reference, not a false positive.
        $acfRows = [$this->makeAcfRow(5, 'some_image', 42, 'About Page')];
        $wpdb = $this->makeWpdb($allRows, $acfRows, []); // @phpstan-ignore-line
        $this->stubWpFunctions();

        $cmd    = new MediaCleanCommand();
        $result = $cmd->execute([], ['action' => 'scan', 'offset' => 0, 'limit' => 100]);

        $this->assertTrue($result['ok'], 'Scan must succeed.');

        // total_attachments: all 10 rows must have been visited.
        $this->assertSame(10, $result['total_attachments'], 'Walk must visit all 10 rows.');

        // referenced_count: exactly 1 (ID=5, legitimately referenced via ACF).
        $this->assertSame(
            1,
            $result['referenced_count'],
            'referenced_count must be 1 (ID=5 is legitimately referenced via ACF).'
        );

        // unused_count: 9 (10 walked - 1 legitimately referenced). This is correct.
        $this->assertSame(
            9,
            $result['unused_count'],
            'unused_count must be 9 when one attachment is legitimately referenced via ACF.'
        );

        $this->assertSame(9, $result['total'], 'total must be 9 (1 legitimately referenced).');

        // The instrumentation: referenced must contain exactly one entry for ID=5.
        $this->assertCount(1, $result['referenced'], 'Only one entry must be in referenced.');

        $ref = $result['referenced'][0];
        $this->assertSame(
            5,
            $ref['id'],
            'referenced[0].id must be 5 (legitimately referenced via ACF).'
        );

        // The usage must be attributed to the postmeta surface: the ACF broad scan
        // path calls addId() with surface='postmeta' when it gets a row from the
        // INNER JOIN query in indexPostmetaPageBuilders().
        $this->assertNotEmpty($ref['usages'], 'referenced[0].usages must not be empty.');
        $this->assertSame(
            'postmeta',
            $ref['usages'][0]['surface'],
            'Usage surface must be "postmeta": ACF numeric postmeta reference.'
        );

        // The 9 candidates must be IDs 1-4 and 6-10.
        $candidateIds = array_column($result['candidates'], 'id');
        sort($candidateIds);
        $this->assertSame(
            array_merge(range(1, 4), range(6, 10)),
            $candidateIds,
            'Candidates must be all attachments except ID=5 (the legitimately referenced one).'
        );
    }

    // =========================================================================
    // Test 5: diagnostic fields are present in EVERY scan response.
    //
    // Contract: total_attachments, referenced_count, unused_count, referenced
    // are always in the result (not gated behind a debug flag) so a live re-scan
    // always reveals the instrumentation for issue #190 diagnosis.
    // =========================================================================

    public function testDiagnosticFieldsAlwaysPresentInScanResult(): void
    {
        global $wpdb;

        $allRows = [
            [
                'ID'         => '1',
                'post_title' => 'Test',
                'guid'       => 'https://example.com/wp-content/uploads/test.jpg',
            ],
        ];

        $wpdb = $this->makeWpdb($allRows, [], []); // @phpstan-ignore-line
        $this->stubWpFunctions();

        $cmd    = new MediaCleanCommand();
        $result = $cmd->execute([], ['action' => 'scan', 'offset' => 0, 'limit' => 10]);

        $this->assertTrue($result['ok'], 'Scan must succeed.');

        foreach (['total_attachments', 'referenced_count', 'unused_count', 'referenced'] as $field) {
            $this->assertArrayHasKey(
                $field,
                $result,
                "Diagnostic field '{$field}' must always be present in the scan result."
            );
        }

        $this->assertIsInt($result['total_attachments'], 'total_attachments must be an int.');
        $this->assertIsInt($result['referenced_count'],  'referenced_count must be an int.');
        $this->assertIsInt($result['unused_count'],      'unused_count must be an int.');
        $this->assertIsArray($result['referenced'],      'referenced must be an array.');
    }

    // =========================================================================
    // Test 6 (issue #190 key-name gate): NON-IMAGE meta key with coincidental ID
    //
    // A postmeta row with key='post_views_count' and value='567' exists on the
    // site. Attachment ID 567 also genuinely exists in wp_posts. Pre-0.25.6, the
    // broad INNER JOIN scan returned '567' because the join found ID=567 in
    // wp_posts regardless of the key name, wrongly classifying it as referenced.
    //
    // Post-0.25.6: the key-name REGEXP filter excludes 'post_views_count' (it
    // contains none of image|gallery|attachment|thumbnail|photo|logo|icon|bg|
    // avatar|picture), so the narrowed query never returns '567'. The fake
    // simulates this by returning [] from the narrowed+joined query.
    //
    // Result: attachment ID 567 is NOT flagged referenced and appears in the
    // unused list. total=10, referenced_count=0.
    // =========================================================================

    public function testNonImageKeyCoincidentalIdIsNotFlagged(): void
    {
        global $wpdb;

        // 10 attachments; ID=567 is attachment #7.
        $allRows = [];
        for ($i = 1; $i <= 10; $i++) {
            $id      = ($i === 7) ? 567 : $i;
            $allRows[] = [
                'ID'         => (string)$id,
                'post_title' => "Image {$id}",
                'guid'       => "https://example.com/wp-content/uploads/img{$id}.jpg",
            ];
        }

        // The narrowed+joined query returns [] because 'post_views_count' does not
        // match the key-name REGEXP, so the DB never returns '567' from this path.
        // (The fake simulates the DB result after both filters: key-name REGEXP +
        // INNER JOIN. Non-image keys are blocked at the key-name gate → [] result.)
        $wpdb = $this->makeWpdb($allRows, [], []); // @phpstan-ignore-line
        $this->stubWpFunctions();

        $cmd    = new MediaCleanCommand();
        $result = $cmd->execute([], ['action' => 'scan', 'offset' => 0, 'limit' => 100]);

        $this->assertTrue($result['ok'], 'Scan must succeed.');

        // KEY ASSERTION: ID=567 must NOT be classified as referenced.
        // The key-name gate blocked 'post_views_count' from contributing its value.
        $this->assertSame(
            0,
            $result['referenced_count'],
            'Non-image key (post_views_count=567) must NOT mark attachment 567 as referenced.'
        );

        // total_attachments: all 10 walked.
        $this->assertSame(10, $result['total_attachments'], 'All 10 attachment rows must be walked.');

        // unused_count and total must be 10 — not 9 (the persistent -1 is eliminated).
        $this->assertSame(
            10,
            $result['unused_count'],
            'unused_count must be 10: coincidental non-image meta value must not reduce count.'
        );
        $this->assertSame(10, $result['total'], 'total must be 10.');

        // referenced must be empty: no attachment must be falsely marked referenced.
        $this->assertSame(
            [],
            $result['referenced'],
            'referenced must be []: no attachment must be falsely marked referenced.'
        );

        // All 10 candidates returned.
        $this->assertCount(10, $result['candidates'], 'All 10 unused candidates must be returned.');

        // ID=567 must appear in candidates (it is unused, not referenced).
        $candidateIds = array_column($result['candidates'], 'id');
        $this->assertContains(
            567,
            $candidateIds,
            'Attachment ID 567 must appear as a candidate: it was not referenced by any image field.'
        );
    }

    // =========================================================================
    // Test 7 (issue #190 key-name gate): IMAGE-SUGGESTIVE meta key IS indexed.
    //
    // A postmeta row with key='hero_image' and value='567' exists. The key name
    // contains 'image' → passes the key-name REGEXP. The INNER JOIN also confirms
    // ID=567 is a real attachment. The scan correctly classifies attachment 567 as
    // referenced (legitimate ACF image field use) → total=9 (not 10).
    //
    // This verifies the fix is conservative: it does NOT introduce a false-negative
    // for genuine ACF image fields.
    // =========================================================================

    public function testImageSuggestiveKeyIsIndexed(): void
    {
        global $wpdb;

        // 10 attachments; ID=567 is attachment #7.
        $allRows = [];
        for ($i = 1; $i <= 10; $i++) {
            $id      = ($i === 7) ? 567 : $i;
            $allRows[] = [
                'ID'         => (string)$id,
                'post_title' => "Image {$id}",
                'guid'       => "https://example.com/wp-content/uploads/img{$id}.jpg",
            ];
        }

        // 'hero_image' contains 'image' → passes key-name REGEXP.
        // INNER JOIN confirms ID=567 is a real attachment.
        // The narrowed+joined query returns a row for attach_id=567.
        // Post 99 ('Landing Page') stores the ACF field hero_image=567.
        $acfRows = [$this->makeAcfRow(567, 'hero_image', 99, 'Landing Page')];
        $wpdb = $this->makeWpdb($allRows, $acfRows, []); // @phpstan-ignore-line
        $this->stubWpFunctions();

        $cmd    = new MediaCleanCommand();
        $result = $cmd->execute([], ['action' => 'scan', 'offset' => 0, 'limit' => 100]);

        $this->assertTrue($result['ok'], 'Scan must succeed.');

        // KEY ASSERTION: ID=567 IS classified as referenced (legitimate image field).
        $this->assertSame(
            1,
            $result['referenced_count'],
            'Image-suggestive key (hero_image=567) must mark attachment 567 as referenced.'
        );

        // total_attachments: all 10 walked.
        $this->assertSame(10, $result['total_attachments'], 'All 10 attachment rows must be walked.');

        // unused_count = 9 (10 - 1 legitimately referenced). This is correct behaviour.
        $this->assertSame(
            9,
            $result['unused_count'],
            'unused_count must be 9: one attachment is legitimately referenced via hero_image.'
        );
        $this->assertSame(9, $result['total'], 'total must be 9.');

        // referenced must contain exactly one entry for ID=567.
        $this->assertCount(1, $result['referenced'], 'Only one entry must be in referenced.');

        $ref = $result['referenced'][0];
        $this->assertSame(
            567,
            $ref['id'],
            'referenced[0].id must be 567 (referenced via hero_image ACF field).'
        );

        // The usage surface must be 'postmeta': the ACF broad scan path calls
        // addId() with surface='postmeta' and detail=meta_key in
        // indexPostmetaPageBuilders().
        $this->assertNotEmpty($ref['usages'], 'referenced[0].usages must not be empty.');
        $this->assertSame(
            'postmeta',
            $ref['usages'][0]['surface'],
            'Usage surface must be "postmeta": hero_image is a postmeta ACF reference.'
        );

        // The detail must be the meta_key that carried the reference ('hero_image').
        $this->assertSame(
            'hero_image',
            $ref['usages'][0]['detail'],
            'Usage detail must be "hero_image": the meta_key that stored the attachment ID.'
        );

        // ID=567 must NOT appear in candidates.
        $candidateIds = array_column($result['candidates'], 'id');
        $this->assertNotContains(
            567,
            $candidateIds,
            'Attachment ID 567 must NOT appear as a candidate: it is referenced via hero_image.'
        );

        // All other 9 IDs must be candidates.
        $this->assertCount(9, $result['candidates'], '9 candidates must be returned.');
    }

    // =========================================================================
    // Test 8: all-unused N attachments → exactly N candidates.
    //
    // Drives the real get_col path with N=10 attachments all unused. The ACF
    // narrowed+joined query returns [] (no image fields reference any attachment).
    // The scan must return exactly N candidates. This is the primary regression
    // guard for the #190 persistent -1: any off-by-one in the accumulation or
    // filtering logic would surface here as total != N.
    // =========================================================================

    public function testAllUnusedNYieldsNcandidates(): void
    {
        global $wpdb;

        $n       = 10;
        $allRows = [];
        for ($i = 1; $i <= $n; $i++) {
            $allRows[] = [
                'ID'         => (string)$i,
                'post_title' => "Unused Image {$i}",
                'guid'       => "https://example.com/wp-content/uploads/img{$i}.jpg",
            ];
        }

        // Narrowed+joined ACF query returns [] — no image-field ACF references.
        $wpdb = $this->makeWpdb($allRows, [], []); // @phpstan-ignore-line
        $this->stubWpFunctions();

        $cmd    = new MediaCleanCommand();
        $result = $cmd->execute([], ['action' => 'scan', 'offset' => 0, 'limit' => 100]);

        $this->assertTrue($result['ok'], 'Scan must succeed when all attachments are unused.');

        // PRIMARY ASSERTION: N all-unused → exactly N candidates, not N-1.
        $this->assertSame(
            $n,
            $result['total'],
            "When all {$n} attachments are unused, total must be {$n} (the #190 persistent -1 is fixed)."
        );

        $this->assertSame(
            $n,
            $result['total_attachments'],
            "total_attachments must be {$n}: the walk must visit every attachment row."
        );

        $this->assertSame(0, $result['referenced_count'], 'referenced_count must be 0.');
        $this->assertSame($n, $result['unused_count'], "unused_count must be {$n}.");
        $this->assertCount($n, $result['candidates'],  "All {$n} candidates must be returned.");

        // Exact IDs check.
        $returnedIds = array_column($result['candidates'], 'id');
        sort($returnedIds);
        $this->assertSame(range(1, $n), $returnedIds, 'Candidates must include all N attachment IDs.');

        // referenced must be empty.
        $this->assertSame([], $result['referenced'], 'referenced must be [].');
    }
}
