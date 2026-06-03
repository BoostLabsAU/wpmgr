<?php
/**
 * DbCleanCommand — database cleanup (Phase 4).
 *
 * Invokes the Phase-4 DbCleanup engine, which runs each gated housekeeping task
 * (post revisions, auto-drafts, trashed posts, spam/trashed comments, expired
 * transients, OPTIMIZE TABLE) with prepared statements and returns per-task row
 * counts. Each task is gated by its perf-config flag; the optional `tasks` body
 * field further restricts the run to an explicit allow-list.
 *
 * Wire contract (CP → agent):
 *   POST /wp-json/wpmgr/v1/command/db_clean
 *   Authorization: Bearer <Ed25519 JWT, cmd="db_clean", aud=<siteId>>
 *   Body: { "tasks": ["revisions","auto_drafts","trashed_posts",
 *                      "spam_comments","trashed_comments","expired_transients",
 *                      "optimize_tables"] }   // optional; empty = all enabled
 *
 * Response: { "ok": true, "detail": "db cleaned", "cleaned": { "<task>": <int>, ... } }
 *
 * Auth: the Router's permission_callback already enforced the signed JWT +
 * anti-replay contract (Connector::verifyCommand) before execute() runs;
 * current_user_can('manage_options') is not applicable (no WP user on the
 * signed-command path) but the cleanup is confined to disposable rows.
 *
 * @package WPMgr\Agent\Commands
 */

declare(strict_types=1);

namespace WPMgr\Agent\Commands;

use WPMgr\Agent\Optimizer\DbCleanup;

/**
 * Database cleanup command (Phase 4).
 */
final class DbCleanCommand implements CommandInterface
{
    /** Recognised task keys (anything else in the body is ignored). */
    private const KNOWN_TASKS = [
        'revisions', 'auto_drafts', 'trashed_posts', 'spam_comments',
        'trashed_comments', 'expired_transients', 'optimize_tables',
    ];

    private ?DbCleanup $cleanup;

    /**
     * @param DbCleanup|null $cleanup Injected for tests; defaults to a freshly
     *                                built engine reading the live perf config +
     *                                global $wpdb.
     */
    public function __construct(?DbCleanup $cleanup = null)
    {
        $this->cleanup = $cleanup;
    }

    /**
     * {@inheritDoc}
     */
    public function name(): string
    {
        return 'db_clean';
    }

    /**
     * Run the gated DB-cleanup tasks and return the per-task counts.
     *
     * @param array<string,mixed> $claims Validated JWT claims (unused).
     * @param array<string,mixed> $params { tasks?: string[] } allow-list.
     * @return array{ok:bool,detail:string,cleaned:array<string,int>}
     */
    public function execute(array $claims, array $params): array
    {
        $only = [];
        if (isset($params['tasks']) && is_array($params['tasks'])) {
            foreach ($params['tasks'] as $task) {
                if (is_string($task) && in_array($task, self::KNOWN_TASKS, true)) {
                    $only[] = $task;
                }
            }
        }

        try {
            $engine  = $this->cleanup ?? new DbCleanup();
            $cleaned = $engine->run($only);
        } catch (\Throwable $e) {
            return ['ok' => false, 'detail' => 'db clean failed', 'cleaned' => []];
        }

        return [
            'ok'      => true,
            'detail'  => 'db cleaned',
            'cleaned' => $cleaned,
        ];
    }
}
