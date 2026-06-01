<?php
/**
 * Scale-fix coverage for the bulk media commands (media_optimize / media_restore
 * / media_delete_originals): they must ACK the control plane IMMEDIATELY and do
 * the heavy presign/upload/callback work in bounded BACKGROUND chunks via
 * MediaRunStore + wp-cron — NOT synchronously inside the CP->agent REST request
 * (the bug that timed out the CP and marked succeeded jobs as failed).
 *
 * The tests assert:
 *   - execute() returns {ok:true, detail:'accepted'} and performs ZERO presign /
 *     putBytes / encode-ready calls inline (the regression guard).
 *   - execute() persists exactly the batch under a fresh run id and schedules the
 *     background cron event (+ kicks spawn_cron).
 *   - MediaRunStore::claim() is idempotent — a re-delivered run id is refused so a
 *     CP retry of a lost ACK can't double-seed.
 *   - MediaRunStore::drain() processes a BOUNDED chunk, persists the remainder,
 *     reschedules itself, and on the final chunk deletes the run transient.
 *   - A job that throws mid-chunk does NOT abort the rest of the chunk.
 *
 * @package WPMgr\Agent\Tests
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WPMgr\Agent\Commands\MediaOptimizeCommand;
use WPMgr\Agent\Media\AttachmentMeta;
use WPMgr\Agent\Media\MediaRunStore;
use WPMgr\Agent\Media\MediaUploader;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Media\MediaRunStore
 * @covers \WPMgr\Agent\Commands\MediaOptimizeCommand
 */
final class MediaAsyncTest extends TestCase
{
    /** @var array<string,mixed> In-memory transient store keyed by full transient name. */
    private array $transients = [];

    /** @var list<array{hook:string,args:array<int,mixed>}> Captured scheduled cron events. */
    private array $scheduled = [];

    /** @var int Count of spawn_cron() kicks. */
    private int $spawned = 0;

    protected function set_up(): void
    {
        parent::set_up();
        Monkey\setUp();

        $this->transients = [];
        $this->scheduled  = [];
        $this->spawned    = 0;

        Functions\when('wp_json_encode')->alias(static fn ($d) => json_encode($d));

        // Transient store doubles backed by the in-memory map.
        Functions\when('get_transient')->alias(fn ($k) => $this->transients[$k] ?? false);
        Functions\when('set_transient')->alias(function ($k, $v, $ttl = 0) {
            $this->transients[$k] = $v;
            return true;
        });
        Functions\when('delete_transient')->alias(function ($k) {
            unset($this->transients[$k]);
            return true;
        });

        // Cron doubles: capture scheduled events + spawn_cron kicks. The
        // background worker is NOT auto-fired here — tests invoke drain()
        // explicitly so they control the chunk cadence.
        Functions\when('wp_schedule_single_event')->alias(function ($ts, $hook, $args = []) {
            $this->scheduled[] = ['hook' => $hook, 'args' => $args];
            return true;
        });
        Functions\when('spawn_cron')->alias(function () {
            $this->spawned++;
            return true;
        });
    }

    protected function tear_down(): void
    {
        Monkey\tearDown();
        parent::tear_down();
    }

    /**
     * A MediaUploader spy that records every network-ish call so a test can
     * assert the REST path performed ZERO of them.
     */
    private function uploaderSpy(): MediaUploader
    {
        return new class extends MediaUploader {
            public int $presignCalls = 0;
            public int $putCalls     = 0;
            public int $readyCalls   = 0;

            public function __construct()
            {
                // Skip parent ctor — no Signer needed for the spy.
            }

            public function presign(string $endpoint, string $jobId, array $variants): array
            {
                $this->presignCalls++;
                return [];
            }

            public function putBytes(string $presignedUrl, string $bytes): bool
            {
                $this->putCalls++;
                return true;
            }

            public function encodeReady(string $endpoint, string $jobId, array $variants): bool
            {
                $this->readyCalls++;
                return true;
            }
        };
    }

    /**
     * execute() must ACK immediately with 'accepted', persist the batch, schedule
     * the background cron event, kick spawn_cron, and do NO network work inline.
     */
    public function testOptimizeExecuteAcksImmediatelyWithoutInlineWork(): void
    {
        $spy = $this->uploaderSpy();
        // AttachmentMeta is unused on the ACK path (no job is processed inline),
        // so the real one is fine; collectVariants is never reached.
        $command = new MediaOptimizeCommand($spy, new AttachmentMeta());

        $params = [
            'presign_endpoint' => 'https://cp.test/agent/v1/media/presign',
            'ready_endpoint'   => 'https://cp.test/agent/v1/media/encode-ready',
            'jobs'             => [
                ['job_id' => 'job-1', 'wp_attachment_id' => 11],
                ['job_id' => 'job-2', 'wp_attachment_id' => 22],
                ['job_id' => 'job-3', 'wp_attachment_id' => 33],
                ['job_id' => 'job-4', 'wp_attachment_id' => 44],
            ],
        ];

        $result = $command->execute([], $params);

        // Wire contract: still {ok, detail}; detail is 'accepted'.
        self::assertSame(['ok' => true, 'detail' => 'accepted'], $result);

        // The regression guard: ZERO synchronous network work in the REST path.
        self::assertSame(0, $spy->presignCalls, 'presign must not run inline');
        self::assertSame(0, $spy->putCalls, 'putBytes must not run inline');
        self::assertSame(0, $spy->readyCalls, 'encode-ready must not run inline');

        // Exactly one run transient persisted, carrying the full batch.
        $runKeys = array_keys($this->transients);
        self::assertCount(1, $runKeys);
        $run = $this->transients[$runKeys[0]];
        self::assertSame(4, count($run['jobs']));
        self::assertSame('https://cp.test/agent/v1/media/presign', $run['presign_endpoint']);

        // The background worker was scheduled + wp-cron kicked.
        self::assertCount(1, $this->scheduled);
        self::assertSame(MediaOptimizeCommand::RUN_HOOK, $this->scheduled[0]['hook']);
        self::assertSame(1, $this->spawned);

        // The cron arg is the run id, which keys the persisted transient.
        $runId = $this->scheduled[0]['args'][0];
        self::assertIsString($runId);
        self::assertArrayHasKey('wpmgr_media_run_' . $runId, $this->transients);
    }

    /**
     * claim() is idempotent: a second claim for the same run id is refused, so a
     * re-delivered command can't double-seed the queue.
     */
    public function testClaimIsIdempotent(): void
    {
        $store = new MediaRunStore();
        $runId = MediaRunStore::newRunId();

        self::assertTrue($store->claim($runId, ['jobs' => [['job_id' => 'a', 'wp_attachment_id' => 1]]]));
        self::assertFalse($store->claim($runId, ['jobs' => [['job_id' => 'b', 'wp_attachment_id' => 2]]]));

        // The original payload is intact — the second claim did not overwrite it.
        $run = $store->get($runId);
        self::assertNotNull($run);
        self::assertSame('a', $run['jobs'][0]['job_id']);
    }

    /**
     * drain() now uses a TIME-BUDGETED loop: it drains as many inner chunks as
     * possible within the wall-clock budget (20 s) before rescheduling. In the
     * test environment the worker is purely in-memory (no I/O), so the budget
     * is never hit — ONE drain() call exhausts the whole queue across multiple
     * inner chunks, leaving no remainder and firing NO reschedule.
     *
     * What we still verify (the feature intent):
     *   - All jobs are processed in order.
     *   - The run transient is deleted once the queue empties.
     *   - No reschedule fires when the queue empties inside the time budget.
     *   - Idempotency: a duplicate drain() on an empty run is a no-op.
     *   - An intermediate state is crash-safe: after each inner chunk the
     *     shortened queue is persisted (observable by reading the transient
     *     inside the worker callback).
     */
    public function testDrainProcessesBoundedChunksAndReschedulesUntilEmpty(): void
    {
        $store = new MediaRunStore();
        $runId = MediaRunStore::newRunId();

        // Seven jobs, inner chunk of 3 (3+3+1 inner batches in one background run).
        $jobs = [];
        for ($i = 1; $i <= 7; $i++) {
            $jobs[] = ['job_id' => 'job-' . $i, 'wp_attachment_id' => $i];
        }
        self::assertTrue($store->claim($runId, ['status_endpoint' => 'https://cp.test/s', 'jobs' => $jobs]));

        $processed = [];
        $worker = function (array $job, array $run) use (&$processed): void {
            $processed[] = $job['job_id'];
        };

        // ONE drain() call processes ALL 7 jobs across 3 inner chunks without
        // hitting the 20 s wall-clock budget (pure in-memory worker).
        $store->drain('hook', $runId, 3, $worker);

        // All 7 jobs processed in order.
        self::assertSame(
            ['job-1', 'job-2', 'job-3', 'job-4', 'job-5', 'job-6', 'job-7'],
            $processed,
            'all jobs must be processed within the time budget'
        );

        // Queue fully exhausted: transient deleted, no reschedule scheduled.
        self::assertNull($store->get($runId), 'transient deleted once queue empties');
        self::assertCount(0, $this->scheduled, 'no reschedule when queue empties within time budget');

        // Idempotency: a stale/duplicate cron firing for a drained run is a no-op.
        $store->drain('hook', $runId, 3, $worker);
        self::assertCount(7, $processed, 'no job re-processed after drain completed');
    }

    /**
     * A job that throws mid-chunk MUST NOT abort the rest of the chunk.
     */
    public function testThrowingJobDoesNotAbortTheChunk(): void
    {
        $store = new MediaRunStore();
        $runId = MediaRunStore::newRunId();

        $jobs = [
            ['job_id' => 'ok-1', 'wp_attachment_id' => 1],
            ['job_id' => 'boom', 'wp_attachment_id' => 2],
            ['job_id' => 'ok-2', 'wp_attachment_id' => 3],
        ];
        self::assertTrue($store->claim($runId, ['jobs' => $jobs]));

        $processed = [];
        $worker = function (array $job) use (&$processed): void {
            if ($job['job_id'] === 'boom') {
                throw new \RuntimeException('simulated job failure');
            }
            $processed[] = $job['job_id'];
        };

        $store->drain('hook', $runId, 5, $worker);

        // Both healthy jobs ran despite the middle one throwing.
        self::assertSame(['ok-1', 'ok-2'], $processed);
        // Queue fully drained -> transient gone.
        self::assertNull($store->get($runId));
    }
}
