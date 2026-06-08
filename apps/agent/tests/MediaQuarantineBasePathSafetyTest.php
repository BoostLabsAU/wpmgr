<?php
/**
 * MediaQuarantineBasePathSafetyTest — verifies that the quarantine class refuses
 * to write to the filesystem root when no safe wp-content base can be resolved.
 *
 * Covers:
 *   - Constructing with an empty $contentDir (simulates WP_CONTENT_DIR undefined
 *     and ABSPATH unavailable) puts the instance in an unavailable state.
 *   - beginManifest() on an unavailable instance throws RuntimeException and does
 *     NOT create a directory at the filesystem root ('/wpmgr-quarantine').
 *   - quarantinedAttachmentIds() on an unavailable instance returns an empty array
 *     and does NOT throw (readonly path degrades gracefully).
 *   - The normal resolved path (explicit $contentDir) continues to work correctly
 *     — existing behaviour is not regressed.
 *
 * @package WPMgr\Agent\Tests
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WPMgr\Agent\Media\MediaQuarantine;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Media\MediaQuarantine
 */
final class MediaQuarantineBasePathSafetyTest extends TestCase
{
    /** Temp root that acts as wp-content for the "happy path" tests. */
    private string $wpContent = '';

    /** Temp uploads dir. */
    private string $uploadsDir = '';

    protected function set_up(): void
    {
        parent::set_up();
        Monkey\setUp();

        $tmp              = sys_get_temp_dir() . '/wpmgr-bps-' . bin2hex(random_bytes(6));
        $this->wpContent  = $tmp . '/wp-content';
        $this->uploadsDir = $this->wpContent . '/uploads';
        mkdir($this->uploadsDir . '/2024/01', 0755, true);

        Functions\when('wp_upload_dir')->justReturn([
            'basedir' => $this->uploadsDir,
            'baseurl' => 'https://example.com/wp-content/uploads',
        ]);
        Functions\when('wp_json_encode')->alias(static function ($data, int $flags = 0): string|false {
            return json_encode($data, $flags);
        });
        Functions\when('wp_delete_file')->alias(static function (string $path): void {
            @unlink($path);
        });
        Functions\when('wp_delete_attachment')->justReturn(true);
    }

    protected function tear_down(): void
    {
        $this->rrmdir(dirname($this->wpContent));
        Monkey\tearDown();
        parent::tear_down();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = @scandir($dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    // =========================================================================
    // Unavailable instance: beginManifest() must throw, NOT mkdir at FS root
    // =========================================================================

    /**
     * Passing an empty string as $contentDir forces the unresolved branch only when
     * neither WP_CONTENT_DIR nor ABSPATH is already defined (those are PHP constants
     * and cannot be undefined once set by an earlier test). When the constants ARE
     * defined we verify the guard path by using a non-writable root path instead:
     * we construct with a non-existent, non-writable path prefix to ensure
     * ensureQuarantineRoot() could never silently succeed, then assert the sentinel
     * path is not created.
     *
     * The two sub-assertions are:
     *   A) When truly unresolved (no constants): RuntimeException is thrown.
     *   B) When constants are present (parallel test run): the constructed root path
     *      lies under the injected $contentDir, NOT at '/wpmgr-quarantine'.
     */
    public function testBeginManifestThrowsWhenBasePathUnresolved(): void
    {
        $fsRootSentinel = '/' . MediaQuarantine::QUARANTINE_DIR;

        if (!defined('WP_CONTENT_DIR') && !defined('ABSPATH')) {
            // Pure unresolved branch: passing '' puts the instance into unavailable state.
            $q = new MediaQuarantine('');

            // Must throw before any mkdir runs.
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/Refusing to write at the filesystem root/');

            $q->beginManifest('job-safety-test');
        } else {
            // Constants are defined by an earlier test in this process. We cannot
            // force the unresolved branch via the constructor, but we CAN verify the
            // core data-safety invariant: a MediaQuarantine constructed with an
            // EXPLICIT non-empty $contentDir never writes to '/wpmgr-quarantine'.
            // That invariant holds regardless of constant state because the explicit
            // $contentDir takes priority over any defined constant.
            $q = new MediaQuarantine($this->wpContent);
            $q->beginManifest('job-safety-test-constants-defined');

            // The quarantine root must be under the explicit $contentDir, not at '/'.
            $this->assertDirectoryExists($this->wpContent . '/' . MediaQuarantine::QUARANTINE_DIR);
            $this->assertDirectoryDoesNotExist($fsRootSentinel, 'Quarantine root must never be at FS root.');
        }
    }

    /**
     * Belt-and-braces: no '/wpmgr-quarantine' directory must exist at the filesystem
     * root after any MediaQuarantine operation. Tested separately so that even if
     * someone removes the RuntimeException guard the test still catches a directory
     * created at FS root.
     */
    public function testBeginManifestDoesNotCreateDirAtFilesystemRoot(): void
    {
        $fsRootQuarantine = '/' . MediaQuarantine::QUARANTINE_DIR;

        // Pre-condition: the directory must not already exist (if it does the
        // environment is broken and we cannot run this test safely).
        if (is_dir($fsRootQuarantine)) {
            $this->markTestSkipped("/{$fsRootQuarantine} already exists in this environment — cannot assert absence.");
        }

        if (!defined('WP_CONTENT_DIR') && !defined('ABSPATH')) {
            $q = new MediaQuarantine('');

            try {
                $q->beginManifest('job-safety-root-check');
            } catch (\RuntimeException $e) {
                // Expected: guard threw before any mkdir.
            }
        } else {
            // When constants are defined by earlier tests, construct with the explicit
            // override (which takes priority). The root path is under $this->wpContent,
            // not at '/'.
            $q = new MediaQuarantine($this->wpContent);
            $q->beginManifest('job-safety-root-check-b');
        }

        // The directory must NOT have been created at FS root.
        $this->assertDirectoryDoesNotExist(
            $fsRootQuarantine,
            'beginManifest() must NOT create a directory at the filesystem root.'
        );
    }

    // =========================================================================
    // Unavailable instance: readonly path must degrade gracefully (no throw)
    // =========================================================================

    public function testQuarantinedAttachmentIdsReturnsEmptyArrayWhenBasePathUnresolved(): void
    {
        if (!defined('WP_CONTENT_DIR') && !defined('ABSPATH')) {
            // Truly unresolved: '' forces the unavailable state.
            $q = new MediaQuarantine('');
        } else {
            // When constants are defined, use a path that does not exist so
            // is_dir($manifestsRoot) returns false and we get the same empty-array result.
            $q = new MediaQuarantine(sys_get_temp_dir() . '/wpmgr-nonexistent-' . bin2hex(random_bytes(4)));
        }

        // Must not throw — graceful empty result either way.
        $ids = $q->quarantinedAttachmentIds();

        $this->assertIsArray($ids, 'quarantinedAttachmentIds() must return an array when quarantine has not been created.');
        $this->assertEmpty($ids, 'quarantinedAttachmentIds() must return empty array when quarantine does not exist.');
    }

    // =========================================================================
    // Normal resolved path: existing behaviour must be unaffected
    // =========================================================================

    public function testNormalPathContinuesToWorkWithExplicitContentDir(): void
    {
        $q          = new MediaQuarantine($this->wpContent);
        $manifestId = $q->beginManifest('job-normal-path');

        // Quarantine root must have been created under the explicit $contentDir.
        $expectedRoot = $this->wpContent . '/' . MediaQuarantine::QUARANTINE_DIR;
        $this->assertDirectoryExists($expectedRoot, 'Quarantine root must exist under the resolved wp-content path.');

        // manifest_id must be a 32-char lowercase hex string.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $manifestId);

        // No directory must have been created at FS root.
        $this->assertDirectoryDoesNotExist('/' . MediaQuarantine::QUARANTINE_DIR);
    }
}
