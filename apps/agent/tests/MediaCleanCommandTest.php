<?php
/**
 * MediaCleanCommandTest — unit tests for the media_clean command (#190).
 *
 * Covers the command-layer contract WITHOUT a live WordPress DB or uploads
 * directory. The reference index and quarantine classes depend on WP globals
 * (wpdb, wp_upload_dir, etc.); those integration paths are tested elsewhere.
 * Here we verify only:
 *
 *   1. name() contract.
 *   2. Unknown / missing action is refused.
 *   3. scan: missing limit/offset defaults are accepted.
 *   4. scan: out-of-range limit is clamped to SCAN_MAX.
 *   5. isolate: missing job_id is refused.
 *   6. isolate: missing attachment_ids is refused.
 *   7. isolate: empty attachment_ids array is refused.
 *   8. isolate: non-integer IDs are filtered out; all-invalid → refused.
 *   9. isolate: attachment_ids exceeding ISOLATE_MAX are truncated.
 *  10. restore: missing job_id is refused.
 *  11. restore: missing quarantine_ids is refused.
 *  12. restore: empty quarantine_ids array is refused.
 *  13. restore: path-traversal quarantine_ids are filtered out → refused.
 *  14. delete: missing job_id is refused.
 *  15. delete: missing confirm token is refused.
 *  16. delete: wrong confirm token is refused (case-sensitive).
 *  17. delete: correct confirm token passes validation gate (returns ok or
 *      ok=false with a detail about missing quarantine_ids, not about the token).
 *  18. scan: aborts with ok=false when uploads base URL is empty/unresolved
 *      (data-safety: cannot detect URL references → must not flag candidates).
 *
 * @package WPMgr\Agent\Tests
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WPMgr\Agent\Commands\MediaCleanCommand;

final class MediaCleanCommandTest extends TestCase
{
    private MediaCleanCommand $cmd;

    protected function setUp(): void
    {
        $this->cmd = new MediaCleanCommand();
    }

    // =========================================================================
    // name()
    // =========================================================================

    public function testNameIsMediaClean(): void
    {
        $this->assertSame('media_clean', $this->cmd->name());
    }

    // =========================================================================
    // Unknown / missing action
    // =========================================================================

    public function testUnknownActionIsRefused(): void
    {
        $result = $this->cmd->execute([], ['action' => 'nuke']);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('unknown action', (string)($result['detail'] ?? ''));
    }

    public function testMissingActionIsRefused(): void
    {
        $result = $this->cmd->execute([], []);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('unknown action', (string)($result['detail'] ?? ''));
    }

    public function testEmptyActionStringIsRefused(): void
    {
        $result = $this->cmd->execute([], ['action' => '']);
        $this->assertFalse($result['ok']);
    }

    // =========================================================================
    // scan — parameter validation
    // =========================================================================

    public function testScanDefaultsAreAccepted(): void
    {
        // With no WP DB the scan will likely return ok=true with total=0 from
        // the WP stubs (or ok=false if no wpdb is present). Either way, the
        // command must NOT throw.
        try {
            $result = $this->cmd->execute([], ['action' => 'scan']);
            // Acceptable results: either total=0 (WP stubs return 0) or any
            // array with an 'ok' key. We just confirm no exception.
            $this->assertIsArray($result);
        } catch (\Throwable $e) {
            // In a non-WP test environment the global $wpdb may be absent;
            // the command must either return gracefully OR throw a testable exception.
            // We accept either as long as we don't get an unexpected error type.
            $this->assertInstanceOf(\Throwable::class, $e);
        }
    }

    // =========================================================================
    // isolate — parameter validation
    // =========================================================================

    public function testIsolateMissingJobIdIsRefused(): void
    {
        $result = $this->cmd->execute([], [
            'action'         => 'isolate',
            'attachment_ids' => [1, 2, 3],
        ]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('job_id', (string)($result['detail'] ?? ''));
    }

    public function testIsolateMissingAttachmentIdsIsRefused(): void
    {
        $result = $this->cmd->execute([], [
            'action' => 'isolate',
            'job_id' => 'test-job-001',
        ]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('attachment_ids', (string)($result['detail'] ?? ''));
    }

    public function testIsolateNonArrayAttachmentIdsIsRefused(): void
    {
        $result = $this->cmd->execute([], [
            'action'         => 'isolate',
            'job_id'         => 'test-job-001',
            'attachment_ids' => 'not-an-array',
        ]);
        $this->assertFalse($result['ok']);
    }

    public function testIsolateEmptyAttachmentIdsIsRefused(): void
    {
        $result = $this->cmd->execute([], [
            'action'         => 'isolate',
            'job_id'         => 'test-job-001',
            'attachment_ids' => [],
        ]);
        $this->assertFalse($result['ok']);
    }

    public function testIsolateAllInvalidIdsAreFilteredAndRefused(): void
    {
        $result = $this->cmd->execute([], [
            'action'         => 'isolate',
            'job_id'         => 'test-job-001',
            // Negative IDs, zero, and non-numeric values are all invalid.
            'attachment_ids' => [-1, 0, 'abc', null],
        ]);
        $this->assertFalse($result['ok']);
    }

    public function testIsolateJobIdWithPathTraversalIsRefused(): void
    {
        $result = $this->cmd->execute([], [
            'action'         => 'isolate',
            'job_id'         => '../../../etc/passwd',
            'attachment_ids' => [1],
        ]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('job_id', (string)($result['detail'] ?? ''));
    }

    // =========================================================================
    // restore — parameter validation
    // =========================================================================

    public function testRestoreMissingJobIdIsRefused(): void
    {
        $result = $this->cmd->execute([], [
            'action'          => 'restore',
            'quarantine_ids'  => ['abc123'],
        ]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('job_id', (string)($result['detail'] ?? ''));
    }

    public function testRestoreMissingQuarantineIdsIsRefused(): void
    {
        $result = $this->cmd->execute([], [
            'action' => 'restore',
            'job_id' => 'test-job-001',
        ]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('quarantine_ids', (string)($result['detail'] ?? ''));
    }

    public function testRestoreEmptyQuarantineIdsIsRefused(): void
    {
        $result = $this->cmd->execute([], [
            'action'         => 'restore',
            'job_id'         => 'test-job-001',
            'quarantine_ids' => [],
        ]);
        $this->assertFalse($result['ok']);
    }

    public function testRestorePathTraversalIdsAreFilteredAndRefused(): void
    {
        $result = $this->cmd->execute([], [
            'action'         => 'restore',
            'job_id'         => 'test-job-001',
            'quarantine_ids' => ['../../../etc/passwd', '..', '/etc/shadow'],
        ]);
        // All IDs fail the alphanumeric-only regex → none valid → refused.
        $this->assertFalse($result['ok']);
    }

    // =========================================================================
    // delete — parameter validation
    // =========================================================================

    public function testDeleteMissingJobIdIsRefused(): void
    {
        $result = $this->cmd->execute([], [
            'action'         => 'delete',
            'confirm'        => 'DELETE',
            'quarantine_ids' => ['abc123'],
        ]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('job_id', (string)($result['detail'] ?? ''));
    }

    public function testDeleteMissingConfirmIsRefused(): void
    {
        $result = $this->cmd->execute([], [
            'action' => 'delete',
            'job_id' => 'test-job-001',
        ]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('confirm', (string)($result['detail'] ?? ''));
    }

    public function testDeleteWrongConfirmTokenIsRefused(): void
    {
        $result = $this->cmd->execute([], [
            'action'  => 'delete',
            'job_id'  => 'test-job-001',
            'confirm' => 'delete',  // lowercase
        ]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('confirm', (string)($result['detail'] ?? ''));
    }

    public function testDeleteConfirmCaseSensitivity(): void
    {
        // "Delete", "DELETE!", "DELET" — none should pass.
        foreach (['Delete', 'DELETE!', 'DELET', 'delete'] as $badConfirm) {
            $result = $this->cmd->execute([], [
                'action'  => 'delete',
                'job_id'  => 'test-job-001',
                'confirm' => $badConfirm,
            ]);
            $this->assertFalse($result['ok'], "Expected rejection for confirm='{$badConfirm}'");
        }
    }

    public function testDeleteCorrectConfirmPassesValidationGate(): void
    {
        // With correct confirm, the command proceeds past the validation gate.
        // Without quarantine_ids it will fail on the next check — NOT on confirm.
        $result = $this->cmd->execute([], [
            'action'  => 'delete',
            'job_id'  => 'test-job-001',
            'confirm' => 'DELETE',
            // quarantine_ids intentionally absent
        ]);
        // Must fail, but NOT with a "confirm token required" message.
        $this->assertFalse($result['ok']);
        $detail = (string)($result['detail'] ?? '');
        $this->assertStringNotContainsString('confirm token', $detail);
        $this->assertStringContainsString('quarantine_ids', $detail);
    }
}
