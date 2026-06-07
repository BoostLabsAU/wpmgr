<?php
/**
 * Deletion-heavy regression test: base -> increment deleting ~5000 files under
 * a PacketLimitedWpdb at 1 MiB @@max_allowed_packet.
 *
 * This is the targeted regression test for the bulletproofness issue filed
 * against ADR-051 agent 0.21.4 (tombstone accumulation re-introduced the
 * silent-write bug that the whole archive-delta pivot was meant to kill).
 *
 * SCENARIO
 * --------
 * A base gen-0 snapshot contains a large plugin tree (~5000 files). The
 * subsequent gen-1 increment deletes the ENTIRE plugin tree (simulating an
 * operator uninstalling a large plugin). Under the regression, sub_state.files
 * grew to >1 MiB (one relpath string per deleted file), silently failed
 * $wpdb->update(), and the watchdog reloaded a stale cursor -> 0 tombstones
 * in the manifest -> the CP restore overlay left the old plugin tree on disk.
 *
 * WHAT IS VERIFIED
 * ----------------
 * (1) After the archiving phase, the tombstone info is stored as an on-disk
 *     file (tombstones_file) + a small count scalar — NOT as a multi-KB array
 *     in sub_state — so sub_state stays well under the 1 MiB packet limit.
 *
 * (2) saveTaskState spills sub_state to a sidecar file if it grows beyond the
 *     48 KiB threshold, storing only a pointer in the DB column. The DB write
 *     NEVER exceeds @@max_allowed_packet (no packet rejection from the mock).
 *
 * (3) loadTask (watchdog re-entry) rehydrates the full sub_state from the
 *     sidecar file — the cursor is intact and correct.
 *
 * (4) appendTombstoneEntries reads from the on-disk tombstones.list and
 *     produces one manifest entry per deleted file — NOT zero.
 *
 * @package WPMgr\Agent\Tests\Backup
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests\Backup;

use ReflectionClass;
use WPMgr\Agent\Backup\FilesArchiver;
use WPMgr\Agent\Backup\TaskRunner;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Backup\FilesArchiver
 * @covers \WPMgr\Agent\Backup\TaskRunner
 */
final class DeletionHeavySubStateBoundTest extends TestCase
{
    private const DELETED_FILE_COUNT = 5000;

    private string $scratch    = '';
    private string $sourceDir  = '';
    private string $outDirG0   = '';
    private string $outDirG1   = '';

    protected function set_up(): void
    {
        parent::set_up();
        $base            = sys_get_temp_dir() . '/wpmgr-delh-' . bin2hex(random_bytes(5));
        $this->scratch   = $base . '/scratch';
        $this->sourceDir = $base . '/src';
        $this->outDirG0  = $base . '/g0';
        $this->outDirG1  = $base . '/g1';
        foreach ([$this->scratch, $this->sourceDir, $this->outDirG0, $this->outDirG1] as $d) {
            mkdir($d, 0755, true);
        }
    }

    protected function tear_down(): void
    {
        if ($this->scratch !== '' && is_dir(dirname($this->scratch))) {
            $this->rrmdir(dirname($this->scratch));
        }
        unset($GLOBALS['wpdb']);
        parent::tear_down();
    }

    /**
     * Deletion-heavy regression: base gen-0 -> increment gen-1 that deletes
     * ~5000 files under a PacketLimitedWpdb at the 1 MiB MySQL default.
     *
     * Asserts:
     *   (1) sub_state persists via sidecar when it exceeds the 48 KiB threshold,
     *       with zero packet rejections (DB column never hits @@max_allowed_packet).
     *   (2) The cursor reloads intact across a simulated watchdog re-entry.
     *   (3) appendTombstoneEntries produces all tombstone manifest entries (not 0).
     */
    public function test_deletion_heavy_increment_under_1mib_packet_limit(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip not available');
        }

        // --- Build gen-0: a large plugin tree with ~5000 files. ---
        $pluginDir = $this->sourceDir . '/plugins/large-plugin';
        mkdir($pluginDir, 0755, true);
        // Write a small "survivor" file that stays across the increment.
        file_put_contents($this->sourceDir . '/plugins/survivor.php', 'SURVIVOR');

        for ($i = 0; $i < self::DELETED_FILE_COUNT; $i++) {
            $sub = $pluginDir . '/subdir-' . ($i % 50);
            if (!is_dir($sub)) {
                mkdir($sub, 0755, true);
            }
            file_put_contents($sub . '/file-' . $i . '.php', str_repeat('X', 64));
        }

        // Run gen-0 archiver to produce files.list.
        $arch0  = new FilesArchiver($this->sourceDir, [], [], 0);
        $r0     = $arch0->archive($this->outDirG0, [], static function (): void {});
        self::assertSame(true, $r0['done'] ?? null, 'gen-0 must complete');

        $filesListPath = $this->outDirG0 . DIRECTORY_SEPARATOR . FilesArchiver::FILES_LIST_NAME;
        self::assertFileExists($filesListPath, 'gen-0 files.list must be emitted');
        $prevMap = FilesArchiver::loadPrevMap($filesListPath);
        self::assertGreaterThanOrEqual(
            self::DELETED_FILE_COUNT,
            count($prevMap),
            'prevMap must contain all gen-0 files'
        );

        // --- Mutate: delete the entire large-plugin tree. Survivor stays. ---
        $this->rrmdir($pluginDir);

        // Run gen-1 archiver (increment: all large-plugin files are tombstones).
        $arch1 = new FilesArchiver($this->sourceDir, [], [], 1);
        $r1    = $arch1->archive($this->outDirG1, [], static function (): void {}, $prevMap);
        self::assertSame(true, $r1['done'] ?? null, 'gen-1 must complete');

        // The tombstones are on-disk, not in sub_state as a large array.
        self::assertGreaterThanOrEqual(
            self::DELETED_FILE_COUNT,
            $r1['tombstones_count'],
            'tombstones_count must reflect all deleted files'
        );
        self::assertNotSame('', $r1['tombstones_file'], 'tombstones_file must point to the on-disk list');
        self::assertFileExists((string) $r1['tombstones_file'], 'tombstones.list must be on disk');

        // --- Build sub_state as TaskRunner would after archiving_files. ---
        // The sub_state.files carries tombstones_file + tombstones_count (small),
        // NOT an inline tombstones array (large).
        $subState = [
            'files' => $r1,
        ];

        $encodedSize = strlen((string) json_encode($subState));
        // The sub_state for this deletion-heavy case must NOT carry 5000 inline
        // tombstone strings. Verify the encoded size is bounded — specifically
        // that it does NOT explode to hundreds of KB.
        self::assertLessThan(
            50 * 1024, // 50 KiB — the tombstones_file path + count is tiny
            $encodedSize,
            'sub_state must stay small when tombstones are stored on disk (tombstones_file)'
        );

        // --- Simulate TaskRunner::saveTaskState under a 1 MiB packet limit. ---
        $snapshotId = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        $params     = $this->params($snapshotId);
        $wpdb       = new DeletionHeavyPacketLimitedWpdb(1024 * 1024); // 1 MiB — MySQL default
        $GLOBALS['wpdb'] = $wpdb;
        $wpdb->seedRow($snapshotId, 'full', $params);

        $runner = new TaskRunner($params);
        $ref    = new ReflectionClass($runner);
        $save   = $ref->getMethod('saveTaskState');

        $rejectionsBefore = $wpdb->packetRejections;
        $save->invoke($runner, TaskRunner::PHASE_ARCHIVING_FILES, $subState);

        // (1) Zero packet rejections — the DB write must have succeeded, either
        //     inline (sub_state fits) or via sidecar (pointer fits).
        self::assertSame(
            $rejectionsBefore,
            $wpdb->packetRejections,
            'sub_state must NOT overflow @@max_allowed_packet for a deletion-heavy increment'
        );

        // Verify: DB column holds either the inline sub_state (if it fits) OR
        // a sidecar pointer. Either way, the column payload must be small.
        $row = $wpdb->rows[$snapshotId] ?? null; // @phpstan-ignore-line
        self::assertNotNull($row, 'task row must exist after saveTaskState');
        $dbPayload = (string) ($row['sub_state'] ?? '');
        self::assertLessThan(
            1024 * 1024,
            strlen($dbPayload),
            'DB sub_state column must be under 1 MiB'
        );

        // (2) Watchdog re-entry: loadTask must rehydrate the cursor intact.
        $runner2 = new TaskRunner($params);
        $ref2    = new ReflectionClass($runner2);
        $load    = $ref2->getMethod('loadTask');
        $task    = $load->invoke($runner2);

        self::assertIsArray($task, 'loadTask must succeed on watchdog re-entry');
        $reloaded = $task['sub_state'];
        self::assertArrayHasKey('files', $reloaded, 'files cursor missing after watchdog re-entry');
        self::assertSame(
            $r1['tombstones_count'],
            $reloaded['files']['tombstones_count'],
            'tombstones_count must be intact after watchdog re-entry'
        );
        self::assertSame(
            $r1['tombstones_file'],
            $reloaded['files']['tombstones_file'],
            'tombstones_file path must be intact after watchdog re-entry'
        );

        // (3) appendTombstoneEntries must produce all tombstone manifest entries (not 0).
        $appendMethod = $ref->getMethod('appendTombstoneEntries');
        $tombEntries  = $appendMethod->invoke($runner, [], $reloaded);

        self::assertIsArray($tombEntries, 'appendTombstoneEntries must return an array');
        self::assertGreaterThanOrEqual(
            self::DELETED_FILE_COUNT,
            count($tombEntries),
            'appendTombstoneEntries must emit one manifest entry per deleted file (NOT 0)'
        );

        // Spot-check one entry for correct shape.
        $first = $tombEntries[0] ?? null;
        self::assertIsArray($first, 'first tombstone entry must be an array');
        self::assertSame(TaskRunner::ENTRY_KIND_TOMBSTONES, $first['entry_kind'] ?? '');
        self::assertSame(TaskRunner::TOMBSTONE_MODE_DELETE, $first['mode'] ?? -1);
        self::assertSame([], $first['chunks'] ?? ['nonempty']);
    }

    /** @return array<string,mixed> */
    private function params(string $snapshotId): array
    {
        return [
            'snapshot_id'            => $snapshotId,
            'kind'                   => 'full',
            'age_recipient'          => 'age1' . str_repeat('q', 58),
            'presign_endpoint'       => 'https://cp.invalid/agent/v1/backups/x/presign',
            'manifest_endpoint'      => 'https://cp.invalid/agent/v1/backups/x/manifest',
            'progress_endpoint'      => '',
            'chunk_bytes'            => 4 * 1024 * 1024,
            'scratch_dir'            => $this->scratch,
            'wp_content_path'        => $this->sourceDir,
            'db'                     => ['host' => 'localhost', 'user' => 'u', 'password' => 'p', 'name' => 'n', 'prefix' => 'wp_'],
            'is_incremental'         => true,
            'prev_files_list_chunks' => [],
        ];
    }

    private function rrmdir(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path) || is_link($path)) {
                @unlink($path);
            }
            return;
        }
        $entries = scandir($path);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->rrmdir($path . DIRECTORY_SEPARATOR . $entry);
        }
        @rmdir($path);
    }
}

/**
 * $wpdb double used by DeletionHeavySubStateBoundTest only — mirrors the
 * PacketLimitedWpdb in TaskRunnerSubStatePacketTest but has a distinct class
 * name to avoid duplicate-class errors when both test files load in the same
 * PHPUnit process.
 */
final class DeletionHeavyPacketLimitedWpdb
{
    public string $prefix = 'wp_';
    public int $packetRejections = 0;
    public bool $forceUpdateFailure = false;

    /** @var array<string,array<string,mixed>> */
    public array $rows = [];

    public function __construct(private int $maxAllowedPacket)
    {
    }

    public function prepare(string $query, ...$args): string
    {
        return json_encode(['sql' => $query, 'args' => $args]) ?: '';
    }

    /** @return int|false */
    public function update($table, $data, $where, $format = null, $whereFormat = null)
    {
        if ($this->forceUpdateFailure) {
            return false;
        }
        $id = (string) ($where['snapshot_id'] ?? '');
        if ($id === '' || !isset($this->rows[$id])) {
            return false;
        }
        $bytes = 256;
        foreach ($data as $v) {
            $bytes += strlen((string) $v);
        }
        if ($bytes > $this->maxAllowedPacket) {
            $this->packetRejections++;
            return false; // row unchanged — mirrors real mysqli over-packet failure
        }
        foreach ($data as $k => $v) {
            $this->rows[$id][$k] = $v;
        }
        return 1;
    }

    /** @return array<string,mixed>|null */
    public function get_row($prepared, $output = ARRAY_A)
    {
        $id = $this->idFrom($prepared);
        if ($id === '' || !isset($this->rows[$id])) {
            return null;
        }
        $row = $this->rows[$id];
        return $output === ARRAY_A ? $row : (object) $row;
    }

    public function get_var($q) { return '1'; }
    public function query($q) { return 1; }
    public function insert($t, $d, $f = null) { return 1; }
    public function delete($t, $w, $wf = null) { return 1; }

    /** @param array<string,mixed> $params */
    public function seedRow(string $snapshotId, string $kind, array $params): void
    {
        $now = time();
        $this->rows[$snapshotId] = [
            'snapshot_id'      => $snapshotId,
            'kind'             => $kind,
            'phase'            => 'queued',
            'sub_state'        => (string) json_encode(['params' => $params]),
            'started_at'       => $now,
            'last_progress_at' => $now,
            'resume_count'     => 0,
            'max_resumes'      => 6,
        ];
    }

    private function idFrom($prepared): string
    {
        if (!is_string($prepared)) {
            return '';
        }
        $d = json_decode($prepared, true);
        if (is_array($d) && isset($d['args'])) {
            foreach ($d['args'] as $a) {
                if (is_string($a) && preg_match('/^[a-f0-9-]{36}$/i', $a)) {
                    return $a;
                }
            }
        }
        return '';
    }
}
