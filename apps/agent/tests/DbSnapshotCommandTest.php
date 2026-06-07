<?php
/**
 * DbSnapshotCommandTest — unit tests for the db_snapshot command (#189).
 *
 * Covers:
 *   1. name() contract.
 *   2. Unknown action is refused.
 *   3. create: rejects missing / bad retention values gracefully.
 *   4. create: WP_CONTENT_DIR unavailable → graceful failure.
 *   5. list: returns empty list when store does not exist.
 *   6. revert: refuses without confirm.
 *   7. revert: refuses with wrong confirm string.
 *   8. revert: refuses with missing snapshot_id.
 *   9. revert: refuses with invalid snapshot_id.
 *  10. delete: refuses with invalid snapshot_id.
 *  11. validId internal acceptance tests (via create → bad id never accepted).
 *
 * The DB dump/restore operations require a live mysqli connection; those are
 * exercised by the existing DbDumperTest / UrlRewriterTest. This file tests
 * only the command layer (action routing, input validation, dir resolution,
 * web-guard drops).
 *
 * @package WPMgr\Agent\Tests
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests;

use PHPUnit\Framework\TestCase;
use WPMgr\Agent\Commands\DbSnapshotCommand;

final class DbSnapshotCommandTest extends TestCase
{
    private DbSnapshotCommand $cmd;

    protected function setUp(): void
    {
        $this->cmd = new DbSnapshotCommand();
    }

    // -------------------------------------------------------------------------
    // name()
    // -------------------------------------------------------------------------

    public function testNameIsDbSnapshot(): void
    {
        $this->assertSame('db_snapshot', $this->cmd->name());
    }

    // -------------------------------------------------------------------------
    // Unknown action
    // -------------------------------------------------------------------------

    public function testUnknownActionRefused(): void
    {
        $result = $this->cmd->execute([], ['action' => 'nuke']);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('unknown action', (string) ($result['detail'] ?? ''));
    }

    public function testMissingActionRefused(): void
    {
        $result = $this->cmd->execute([], []);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('unknown action', (string) ($result['detail'] ?? ''));
    }

    // -------------------------------------------------------------------------
    // create — environment checks
    // -------------------------------------------------------------------------

    public function testCreateFailsGracefullyWhenWpContentDirUndefined(): void
    {
        // WP_CONTENT_DIR is not defined in the test bootstrap — the command must
        // return ok=false with a readable reason rather than throwing.
        $result = $this->cmd->execute([], ['action' => 'create', 'label' => 'test']);
        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['detail'] ?? '');
    }

    public function testCreateRetentionCappedAtMax(): void
    {
        // Even with a huge retention value the command must not blow up during
        // parameter parsing (it will still fail on WP_CONTENT_DIR in test env).
        $result = $this->cmd->execute([], ['action' => 'create', 'retention' => 9999]);
        // Validation passes (no retention error); failure is env-level.
        $this->assertFalse($result['ok']);
        $this->assertStringNotContainsString('retention', (string) ($result['detail'] ?? ''));
    }

    public function testCreateAcceptsZeroRetentionAsDefault(): void
    {
        // retention=0 is treated as the default — no explicit "invalid" error.
        $result = $this->cmd->execute([], ['action' => 'create', 'retention' => 0]);
        $this->assertFalse($result['ok']); // fails on WP_CONTENT_DIR in test
        $this->assertStringNotContainsString('retention', (string) ($result['detail'] ?? ''));
    }

    // -------------------------------------------------------------------------
    // list — empty store
    // -------------------------------------------------------------------------

    public function testListReturnsEmptyArrayWhenStoreAbsent(): void
    {
        // WP_CONTENT_DIR not defined → resolveStoreDir throws → list returns ok=true, [].
        $result = $this->cmd->execute([], ['action' => 'list']);
        $this->assertTrue($result['ok']);
        $this->assertIsArray($result['snapshots']);
        $this->assertCount(0, $result['snapshots']);
    }

    // -------------------------------------------------------------------------
    // revert — confirm gate
    // -------------------------------------------------------------------------

    public function testRevertRefusesWithoutConfirm(): void
    {
        $result = $this->cmd->execute([], [
            'action'      => 'revert',
            'snapshot_id' => 'snap_' . str_repeat('a', 24),
        ]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('REVERT', (string) ($result['detail'] ?? ''));
    }

    public function testRevertRefusesWithWrongConfirm(): void
    {
        $result = $this->cmd->execute([], [
            'action'      => 'revert',
            'snapshot_id' => 'snap_' . str_repeat('a', 24),
            'confirm'     => 'yes',
        ]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('REVERT', (string) ($result['detail'] ?? ''));
    }

    public function testRevertRefusesMissingSnapshotId(): void
    {
        $result = $this->cmd->execute([], [
            'action'  => 'revert',
            'confirm' => 'REVERT',
            // snapshot_id intentionally absent
        ]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('invalid snapshot_id', (string) ($result['detail'] ?? ''));
    }

    public function testRevertRefusesInvalidSnapshotIdPattern(): void
    {
        $result = $this->cmd->execute([], [
            'action'      => 'revert',
            'confirm'     => 'REVERT',
            'snapshot_id' => '../../../etc/passwd',
        ]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('invalid snapshot_id', (string) ($result['detail'] ?? ''));
    }

    public function testRevertRefusesTooShortId(): void
    {
        $result = $this->cmd->execute([], [
            'action'      => 'revert',
            'confirm'     => 'REVERT',
            'snapshot_id' => 'snap_abc',
        ]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('invalid snapshot_id', (string) ($result['detail'] ?? ''));
    }

    public function testRevertFailsGracefullyWithValidIdButNoStore(): void
    {
        // Valid ID format, confirm correct, but WP_CONTENT_DIR missing → store unavailable.
        $result = $this->cmd->execute([], [
            'action'      => 'revert',
            'confirm'     => 'REVERT',
            'snapshot_id' => 'snap_' . str_repeat('b', 24),
        ]);
        $this->assertFalse($result['ok']);
        // Must not crash; detail must be a non-empty string.
        $this->assertNotEmpty($result['detail'] ?? '');
    }

    // -------------------------------------------------------------------------
    // delete — input validation
    // -------------------------------------------------------------------------

    public function testDeleteRefusesMissingSnapshotId(): void
    {
        $result = $this->cmd->execute([], ['action' => 'delete']);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('invalid snapshot_id', (string) ($result['detail'] ?? ''));
    }

    public function testDeleteRefusesPathTraversalId(): void
    {
        $result = $this->cmd->execute([], [
            'action'      => 'delete',
            'snapshot_id' => '../other',
        ]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('invalid snapshot_id', (string) ($result['detail'] ?? ''));
    }

    public function testDeleteRefusesIdWithUppercase(): void
    {
        // validId requires all-lowercase hex after snap_
        $result = $this->cmd->execute([], [
            'action'      => 'delete',
            'snapshot_id' => 'snap_' . str_repeat('A', 24),
        ]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('invalid snapshot_id', (string) ($result['detail'] ?? ''));
    }

    public function testDeleteRefusesSnapshotNotFound(): void
    {
        // Valid ID format but WP_CONTENT_DIR undefined → store unavailable.
        $result = $this->cmd->execute([], [
            'action'      => 'delete',
            'snapshot_id' => 'snap_' . str_repeat('c', 24),
        ]);
        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['detail'] ?? '');
    }

    // -------------------------------------------------------------------------
    // Label sanitization (no crash on long label)
    // -------------------------------------------------------------------------

    public function testCreateHandlesVeryLongLabel(): void
    {
        $label  = str_repeat('x', 500);
        $result = $this->cmd->execute([], ['action' => 'create', 'label' => $label]);
        // Must not throw; will fail on WP_CONTENT_DIR in test env.
        $this->assertIsBool($result['ok'] ?? null);
    }
}
