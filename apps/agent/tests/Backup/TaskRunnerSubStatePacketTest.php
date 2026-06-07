<?php
/**
 * REGRESSION (the recurring "incremental BASE completes with 0 FILES" bug).
 *
 * The live failure: an incremental base over a 101 MB WP site (thousands of
 * files) keeps completing with Files: 0 entries / 0 chunks — only the DB. The
 * scan populates scan.changed[] correctly (in memory), but the SUBMITTED
 * incremental manifest carries files_entries === [] and the defense-in-depth
 * guard does NOT fire (files_changed reloads as 0 too).
 *
 * ROOT CAUSE proven here: TaskRunner::saveTaskState() used to (a) write the
 * full sub_state JSON inline into the wpmgr_backup_tasks.sub_state column and
 * (b) ignore $wpdb->update()'s return value. On a real site the incremental
 * scan cursor serializes to > @@max_allowed_packet (1 MiB default on many
 * hosts). MySQL/MariaDB reject the over-packet UPDATE — $wpdb->update() returns
 * false and the row KEEPS its prior (cursor-less) value. The in-memory pass
 * continues, but the next watchdog/cron RE-ENTRY reloads the stale row,
 * scan.changed[] is gone, and the manifest is submitted with 0 files.
 *
 * This test reproduces the EXACT multi-request handoff with a $wpdb double that
 * enforces @@max_allowed_packet, then asserts the fix (sidecar spill keeps the
 * DB write tiny + the update-return is honored) makes the re-entry reload the
 * full cursor and submit a per-file manifest.
 *
 * @package WPMgr\Agent\Tests\Backup
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests\Backup;

use ReflectionClass;
use WPMgr\Agent\Backup\TaskRunner;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Backup\TaskRunner
 */
final class TaskRunnerSubStatePacketTest extends TestCase
{
    private string $scratch = '';

    protected function set_up(): void
    {
        parent::set_up();
        $this->scratch = sys_get_temp_dir() . '/wpmgr-substate-' . bin2hex(random_bytes(6));
        mkdir($this->scratch, 0777, true);
    }

    protected function tear_down(): void
    {
        foreach (glob($this->scratch . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->scratch);
        unset($GLOBALS['wpdb']);
        parent::tear_down();
    }

    /**
     * A large incremental scan cursor (thousands of changed files) must survive
     * the save -> DB -> reload round-trip even when @@max_allowed_packet is
     * smaller than the encoded cursor. Before the fix this reloaded as an empty
     * scan; the assertion proves it now reloads intact.
     */
    public function test_large_scan_cursor_survives_reload_under_small_max_allowed_packet(): void
    {
        // 256 KiB packet — smaller than the ~1 MB cursor below. Mirrors a
        // shared/managed host's @@max_allowed_packet.
        $wpdb = new PacketLimitedWpdb(256 * 1024);
        $GLOBALS['wpdb'] = $wpdb;

        $snapshotId = '11111111-2222-3333-4444-555555555555';
        $params = $this->params($snapshotId);

        // request #1: seed the row exactly like BackupCommand::seedTaskRow does
        // (sub_state = {"params": ...}, NOT '{}').
        $wpdb->seedRow($snapshotId, 'full', $params);

        // request #2: the cron worker scans and saves the big cursor, then dies.
        $runner = new TaskRunner($params);
        $ref    = new ReflectionClass($runner);
        $save   = $ref->getMethod('saveTaskState');

        $subState = ['params' => $params, '_is_incremental' => true];
        $subState['scan'] = $this->bigScan(3000);

        $beforeRejections = $wpdb->packetRejections;
        $save->invoke($runner, TaskRunner::PHASE_DUMPING_DB, $subState);

        // The DB write must have SUCCEEDED (no packet rejection) because the
        // bulky cursor was spilled to a sidecar and only a tiny pointer hit the
        // column. (Pre-fix: this write was rejected and the cursor was lost.)
        $this->assertSame(
            $beforeRejections,
            $wpdb->packetRejections,
            'the sub_state write was rejected by max_allowed_packet — cursor would be lost'
        );

        // request #3: watchdog re-entry. Fresh runner, reload FROM the DB.
        $runner2 = new TaskRunner($params);
        $ref2    = new ReflectionClass($runner2);
        $load    = $ref2->getMethod('loadTask');
        $task    = $load->invoke($runner2);

        $this->assertIsArray($task);
        $sub = $task['sub_state'];
        $this->assertArrayHasKey('scan', $sub, 'scan cursor was lost across the DB round-trip');
        // bigScan(3000) emits 3000 generated files + 1 non-UTF-8 path = 3001
        // rows in changed[]; files_changed is the generated count (3000).
        $this->assertCount(
            3001,
            $sub['scan']['changed'],
            'scan.changed[] must reload intact (this is the 0-files regression)'
        );
        $this->assertSame(3000, $sub['scan']['files_changed']);
        // The non-UTF-8 path round-trips through the sidecar (substituted bytes).
        $paths = array_column($sub['scan']['changed'], 'file_path');
        $this->assertContains('plugins/plugin-0/inc/class-thing-0.php', $paths);
        // params must still be reachable so the watchdog can rehydrate the runner.
        $this->assertArrayHasKey('params', $sub);
    }

    /**
     * saveTaskState() must FAIL LOUDLY (throw) when the DB write genuinely fails
     * — e.g. a real DB fault unrelated to size. Silently swallowing the false
     * return was what let the run continue with an unpersisted cursor and submit
     * a 0-files manifest.
     */
    public function test_save_throws_when_db_update_fails(): void
    {
        $wpdb = new PacketLimitedWpdb(8 * 1024 * 1024);
        $wpdb->forceUpdateFailure = true; // simulate a DB fault on every UPDATE
        $GLOBALS['wpdb'] = $wpdb;

        $snapshotId = '22222222-3333-4444-5555-666666666666';
        $params = $this->params($snapshotId);
        $wpdb->seedRow($snapshotId, 'full', $params);

        $runner = new TaskRunner($params);
        $ref    = new ReflectionClass($runner);
        $save   = $ref->getMethod('saveTaskState');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/persist failed/');
        $save->invoke($runner, TaskRunner::PHASE_DUMPING_DB, ['params' => $params, 'scan' => $this->bigScan(10)]);
    }

    /** Build a realistic large scan result with $n changed single-chunk files. */
    private function bigScan(int $n): array
    {
        $changed = [];
        for ($i = 0; $i < $n; $i++) {
            $h = str_repeat(dechex($i % 16), 64);
            $changed[] = [
                'file_path'    => "plugins/plugin-$i/inc/class-thing-$i.php",
                'file_size'    => 4096 + $i,
                'file_mtime'   => 1700000000 + $i,
                'file_blake3'  => $h,
                'chunk_hashes' => [$h],
            ];
        }
        // A non-UTF-8 latin1 path (0xE9) — a real-world filename byte.
        $changed[] = [
            'file_path'    => "uploads/caf\xE9/photo.png",
            'file_size'    => 87,
            'file_mtime'   => 1700001000,
            'file_blake3'  => str_repeat('a', 64),
            'chunk_hashes' => [str_repeat('a', 64)],
        ];
        return [
            'done'            => true,
            'changed'         => $changed,
            'carry_forward'   => [],
            'tombstones'      => [],
            'files_scanned'   => $n + 1,
            'files_changed'   => $n,
            'files_deleted'   => 0,
            'bytes_to_upload' => 4096 * $n,
            'uploaded_hashes' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function params(string $snapshotId): array
    {
        return [
            'snapshot_id'         => $snapshotId,
            'kind'                => 'full',
            'age_recipient'       => 'age1' . str_repeat('q', 58),
            'presign_endpoint'    => 'https://cp.invalid/agent/v1/backups/x/presign',
            'manifest_endpoint'   => 'https://cp.invalid/agent/v1/backups/x/manifest',
            'progress_endpoint'   => '',
            'chunk_bytes'         => 4 * 1024 * 1024,
            'scratch_dir'         => $this->scratch,
            'wp_content_path'     => $this->scratch,
            'db'                  => ['host' => 'localhost', 'user' => 'u', 'password' => 'p', 'name' => 'n', 'prefix' => 'wp_'],
            'is_incremental'      => true,
            'file_index_endpoint' => '',
        ];
    }
}

/**
 * A $wpdb double that enforces @@max_allowed_packet on UPDATE — the one real
 * DB-layer constraint behind the 0-files base bug. The sub_state column itself
 * is LONGTEXT (no length truncation); the packet limit is the live gate: an
 * over-packet UPDATE returns false and the row keeps its prior value.
 */
final class PacketLimitedWpdb
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
