<?php
/**
 * Coverage for the "WP attachment deleted" Media Optimizer fix:
 *
 *   1. The extracted MediaDeleteOriginalsCommand::originalPathsFor() helper —
 *      the single enumeration shared by deleteOne() (the command) and
 *      Plugin::onDeleteAttachment() (the delete_attachment hook). Both archive
 *      modes + the array_unique contract.
 *   2. deleteOne() still routes through originalPathsFor() (no behavior drift):
 *      it unlinks exactly the helper's paths and flips the blob.
 *   3. MediaSyncCommand threads job_id into EVERY sync-batch page AND calls
 *      sync-finalize after a CLEAN run — but NOT after an errored page
 *      (the blast-radius guard).
 *
 * @package WPMgr\Agent\Tests
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WPMgr\Agent\Commands\MediaDeleteOriginalsCommand;
use WPMgr\Agent\Commands\MediaSyncCommand;
use WPMgr\Agent\Media\AttachmentMeta;
use WPMgr\Agent\Media\DiskWriter;
use WPMgr\Agent\Media\MediaUploader;
use WPMgr\Agent\Media\Rename;
use WPMgr\Agent\MediaKeystore;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Commands\MediaDeleteOriginalsCommand
 * @covers \WPMgr\Agent\Commands\MediaSyncCommand
 * @covers \WPMgr\Agent\Media\MediaUploader
 */
final class MediaDeleteHookTest extends TestCase
{
    protected function set_up(): void
    {
        parent::set_up();
        Monkey\setUp();
        Functions\when('wp_json_encode')->alias(static fn ($d) => json_encode($d));
    }

    protected function tear_down(): void
    {
        // Remove any $wpdb global injected by stubOnePageLibrary() so other
        // tests are not affected.
        unset($GLOBALS['wpdb']);
        Monkey\tearDown();
        parent::tear_down();
    }

    /**
     * A MediaDeleteOriginalsCommand whose only real seam is Rename (used by
     * originalPathsFor); the uploader/keystore/writer are inert fakes.
     */
    private function command(): MediaDeleteOriginalsCommand
    {
        $uploader = new class extends MediaUploader {
            public function __construct()
            {
            }
        };

        return new MediaDeleteOriginalsCommand($uploader);
    }

    // -- originalPathsFor() ------------------------------------------------

    public function test_original_paths_replace_mode_returns_archive_path(): void
    {
        $blob = [
            'original_data'  => ['file' => '2026/05/banner.jpg'],
            'optimized_data' => [
                'full'   => ['path' => '/up/2026/05/banner.jpg', 'archive_mode' => AttachmentMeta::MODE_REPLACE],
                'medium' => ['path' => '/up/2026/05/banner-300x200.jpg', 'archive_mode' => AttachmentMeta::MODE_REPLACE],
            ],
        ];

        $paths = $this->command()->originalPathsFor($blob);

        // REPLACE: the *.wpmgr-original.<ext> archive beside the live path.
        $this->assertSame([
            '/up/2026/05/banner.wpmgr-original.jpg',
            '/up/2026/05/banner-300x200.wpmgr-original.jpg',
        ], $paths);
    }

    public function test_original_paths_coexist_mode_returns_original_ext_twin(): void
    {
        // COEXIST: optimized path is the .avif; the untracked twin is the
        // original-ext (.jpg) sibling derived from original_data['file'].
        $blob = [
            'original_data'  => ['file' => '2026/05/banner.jpg'],
            'optimized_data' => [
                'full'   => ['path' => '/up/2026/05/banner.avif', 'archive_mode' => AttachmentMeta::MODE_COEXIST],
                'medium' => ['path' => '/up/2026/05/banner-300x200.avif', 'archive_mode' => AttachmentMeta::MODE_COEXIST],
            ],
        ];

        $paths = $this->command()->originalPathsFor($blob);

        $this->assertSame([
            '/up/2026/05/banner.jpg',
            '/up/2026/05/banner-300x200.jpg',
        ], $paths);
    }

    public function test_original_paths_coexist_with_empty_original_ext_emits_nothing(): void
    {
        // No original extension to derive the twin from => nothing deletable.
        $blob = [
            'original_data'  => ['file' => ''],
            'optimized_data' => [
                'full' => ['path' => '/up/2026/05/banner.avif', 'archive_mode' => AttachmentMeta::MODE_COEXIST],
            ],
        ];

        $this->assertSame([], $this->command()->originalPathsFor($blob));
    }

    public function test_original_paths_is_array_unique(): void
    {
        // Two size records pointing at the SAME path (degenerate) collapse to one.
        $blob = [
            'original_data'  => ['file' => '2026/05/banner.jpg'],
            'optimized_data' => [
                'a' => ['path' => '/up/banner.jpg', 'archive_mode' => AttachmentMeta::MODE_REPLACE],
                'b' => ['path' => '/up/banner.jpg', 'archive_mode' => AttachmentMeta::MODE_REPLACE],
            ],
        ];

        $this->assertSame(['/up/banner.wpmgr-original.jpg'], $this->command()->originalPathsFor($blob));
    }

    public function test_original_paths_skips_records_without_path(): void
    {
        $blob = [
            'original_data'  => ['file' => '2026/05/banner.jpg'],
            'optimized_data' => [
                'full'    => ['path' => '/up/banner.jpg', 'archive_mode' => AttachmentMeta::MODE_REPLACE],
                'broken'  => ['archive_mode' => AttachmentMeta::MODE_REPLACE], // no path
                'notarr'  => 'nope',                                            // not an array
            ],
        ];

        $this->assertSame(['/up/banner.wpmgr-original.jpg'], $this->command()->originalPathsFor($blob));
    }

    // -- deleteOne() still uses the helper (no behavior drift) -------------

    public function test_delete_one_unlinks_exactly_helper_paths_and_flips_blob(): void
    {
        $dir = sys_get_temp_dir() . '/wpmgr-del-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);

        // REPLACE archive + COEXIST twin both on disk, plus the WP-tracked files
        // we must NOT touch.
        $archive = $dir . '/banner.wpmgr-original.jpg';
        $twin    = $dir . '/logo.jpg';
        $live    = $dir . '/banner.jpg';   // WP-tracked (REPLACE in-place) — keep
        $avif    = $dir . '/logo.avif';    // WP-tracked (COEXIST optimized) — keep
        foreach ([$archive, $twin, $live, $avif] as $f) {
            file_put_contents($f, 'x');
        }

        $blob = [
            'original_deleted' => 0,
            'original_data'    => ['file' => '2026/05/logo.jpg'],
            'optimized_data'   => [
                'full' => ['path' => $live, 'archive_mode' => AttachmentMeta::MODE_REPLACE],
                'logo' => ['path' => $avif, 'archive_mode' => AttachmentMeta::MODE_COEXIST],
            ],
        ];

        $store = [77 => $blob];
        Functions\when('get_post_meta')->alias(static fn ($id, $k, $s) => $store[$id] ?? []);
        Functions\when('update_post_meta')->alias(static function ($id, $k, $v) use (&$store) {
            $store[$id] = $v;
            return true;
        });
        Functions\when('wp_delete_file')->alias(static fn ($f) => @unlink((string) $f));

        $ok = $this->command()->deleteOne(77);

        $this->assertTrue($ok);
        // The untracked originals are gone.
        $this->assertFileDoesNotExist($archive, 'REPLACE archive deleted');
        $this->assertFileDoesNotExist($twin, 'COEXIST twin deleted');
        // The WP-tracked files are untouched.
        $this->assertFileExists($live, 'in-place optimized (WP-tracked) kept');
        $this->assertFileExists($avif, 'optimized .avif (WP-tracked) kept');
        // Blob flipped to originals_deleted.
        $this->assertSame(1, $store[77]['original_deleted']);
        $this->assertSame(MediaKeystore::STATUS_ORIGINALS_DELETED, $store[77]['status']);

        foreach ((array) glob($dir . '/*') as $f) {
            @unlink((string) $f);
        }
        @rmdir($dir);
    }

    public function test_delete_one_refuses_when_already_deleted(): void
    {
        $store = [9 => ['original_deleted' => 1, 'optimized_data' => []]];
        Functions\when('get_post_meta')->alias(static fn ($id, $k, $s) => $store[$id] ?? []);

        $this->assertFalse($this->command()->deleteOne(9), 'guard short-circuits');
    }

    // -- sync job_id threading + finalize ----------------------------------

    /**
     * A recording uploader that captures every syncBatch job_id and the
     * syncFinalize call (if any).
     */
    private function recordingUploader(bool $batchOk = true): MediaUploader
    {
        return new class($batchOk) extends MediaUploader {
            /** @var list<string> */
            public array $batchJobIds = [];
            public ?string $finalizedJobId = null;
            public string $finalizeEndpoint = '';
            private bool $batchOk;

            public function __construct(bool $batchOk = true)
            {
                $this->batchOk = $batchOk;
            }

            public function syncBatch(string $endpoint, array $attachments, string $jobId = ''): array
            {
                $this->batchJobIds[] = $jobId;
                return ['ok' => $this->batchOk, 'upserted_count' => count($attachments)];
            }

            public function syncFinalize(string $endpoint, string $jobId): bool
            {
                $this->finalizedJobId   = $jobId;
                $this->finalizeEndpoint = $endpoint;
                return true;
            }
        };
    }

    /**
     * Stub WP so MediaSyncCommand sees one page of one attachment then stops.
     *
     * MediaSyncCommand uses $wpdb->get_col() (keyset query) rather than
     * get_posts(). We inject a lightweight $wpdb stub into the global scope
     * that returns [101] on the first page and [] on the second, mirroring the
     * single-page scenario the old get_posts() stub produced.
     */
    private function stubOnePageLibrary(): void
    {
        // $wpdb stub: first get_col() call returns one attachment id; subsequent
        // calls return [] so the pagination loop terminates.
        $wpdbStub = new class {
            public string $posts = 'wp_posts';
            private int $calls   = 0;

            public function prepare(string $sql, ...$args): string
            {
                return $sql; // return as-is; value isn't inspected by the test.
            }

            public function get_col(string $sql): array
            {
                $this->calls++;
                return $this->calls === 1 ? [101] : [];
            }
        };

        // Inject into global scope so MediaSyncCommand's `global $wpdb` picks it up.
        $GLOBALS['wpdb'] = $wpdbStub;

        // Attachment meta / WP helpers used by MediaAttachmentRow::build().
        Functions\when('wp_get_attachment_metadata')->justReturn(['width' => 10, 'height' => 10, 'filesize' => 123]);
        Functions\when('get_attached_file')->justReturn('/up/2026/05/a.jpg');
        Functions\when('wp_get_attachment_url')->justReturn('https://s/a.jpg');
        Functions\when('get_post_mime_type')->justReturn('image/jpeg');
        Functions\when('get_the_title')->justReturn('A');
        // get_post_meta is called by MediaAttachmentRow::build() for the size-backfill
        // path; returning false (no blob) keeps the test simple.
        Functions\when('get_post_meta')->justReturn(false);
    }

    public function test_sync_threads_job_id_and_finalizes_on_clean_run(): void
    {
        $this->stubOnePageLibrary();
        $uploader = $this->recordingUploader(true);

        $res = (new MediaSyncCommand($uploader))->execute([], [
            'job_id'            => '01JOB',
            'batch_endpoint'    => 'https://cp/agent/v1/media/sync-batch',
            'finalize_endpoint' => 'https://cp/agent/v1/media/sync-finalize',
        ]);

        $this->assertTrue($res['ok'], $res['detail'] ?? '');
        // Every page body carried the job id.
        $this->assertSame(['01JOB'], $uploader->batchJobIds);
        // Finalize was called once, with the job id + the CP-supplied endpoint.
        $this->assertSame('01JOB', $uploader->finalizedJobId);
        $this->assertSame('https://cp/agent/v1/media/sync-finalize', $uploader->finalizeEndpoint);
    }

    public function test_sync_derives_finalize_endpoint_when_not_supplied(): void
    {
        $this->stubOnePageLibrary();
        $uploader = $this->recordingUploader(true);

        (new MediaSyncCommand($uploader))->execute([], [
            'job_id'         => '01JOB',
            'batch_endpoint' => 'https://cp/agent/v1/media/sync-batch',
        ]);

        // Fallback: trailing /sync-batch swapped for /sync-finalize.
        $this->assertSame('https://cp/agent/v1/media/sync-finalize', $uploader->finalizeEndpoint);
    }

    public function test_sync_does_NOT_finalize_on_errored_page(): void
    {
        $this->stubOnePageLibrary();
        $uploader = $this->recordingUploader(false); // batch rejected

        $res = (new MediaSyncCommand($uploader))->execute([], [
            'job_id'            => '01JOB',
            'batch_endpoint'    => 'https://cp/agent/v1/media/sync-batch',
            'finalize_endpoint' => 'https://cp/agent/v1/media/sync-finalize',
        ]);

        $this->assertFalse($res['ok']);
        $this->assertNull($uploader->finalizedJobId, 'a partial/errored run must NOT finalize');
    }

    public function test_sync_does_NOT_finalize_when_no_job_id(): void
    {
        $this->stubOnePageLibrary();
        $uploader = $this->recordingUploader(true);

        (new MediaSyncCommand($uploader))->execute([], [
            'batch_endpoint'    => 'https://cp/agent/v1/media/sync-batch',
            'finalize_endpoint' => 'https://cp/agent/v1/media/sync-finalize',
        ]);

        $this->assertNull($uploader->finalizedJobId);
        $this->assertSame([''], $uploader->batchJobIds, 'empty job id threaded as ""');
    }

    // -- MediaUploader::syncFinalize / syncBatch payload -------------------

    public function test_sync_finalize_refuses_empty_inputs(): void
    {
        $uploader = new class extends MediaUploader {
            public function __construct()
            {
            }
        };
        $this->assertFalse($uploader->syncFinalize('', '01JOB'));
        $this->assertFalse($uploader->syncFinalize('https://cp/x', ''));
    }
}
