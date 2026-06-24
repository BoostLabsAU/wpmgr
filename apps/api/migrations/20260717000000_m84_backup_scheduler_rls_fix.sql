-- m84: fix backup_schedules_scheduler RLS policy (issue #96)
--
-- Root cause: ClaimAndAdvanceDueSchedules (called by ScheduleWorker every 5 min)
-- executes a "SELECT … FOR UPDATE SKIP LOCKED" inside InAgentTx. PostgreSQL
-- applies BOTH SELECT and UPDATE policies to a SELECT … FOR UPDATE query. The
-- existing backup_schedules_scheduler policy was FOR SELECT only, which means
-- the UPDATE-policy side (backup_schedules_tenant_isolation, requiring
-- app.tenant_id) was never satisfied under the agent GUC context. As a result,
-- FOR UPDATE returned 0 rows silently — every scheduler tick claimed nothing,
-- next_run_at never advanced, last_run_at stayed NULL, and no snapshots were
-- enqueued. The boot-time heal (HealOverdueSchedules) appeared to work because
-- it issues a bare SELECT under InAgentTx then a separate UPDATE under
-- InTenantTx — no FOR UPDATE — avoiding this path entirely.
--
-- Fix: replace the FOR SELECT scheduler policy with a FOR ALL policy (mirroring
-- the backup_schedule_runs_agent policy that was already correct). This grants
-- the agent context SELECT, UPDATE, INSERT, and DELETE on backup_schedules when
-- app.agent = 'on', which is exactly what ClaimAndAdvanceDueSchedules needs.
--
-- Idempotent: drops then re-creates the policy inside a DO block.

DO $$
BEGIN
    -- Drop the old SELECT-only policy if it exists.
    IF EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public'
          AND tablename  = 'backup_schedules'
          AND policyname = 'backup_schedules_scheduler'
    ) THEN
        DROP POLICY backup_schedules_scheduler ON backup_schedules;
    END IF;

    -- Re-create as FOR ALL so that SELECT … FOR UPDATE (and the subsequent
    -- UPDATE) in ClaimAndAdvanceDueSchedules work under InAgentTx.
    CREATE POLICY backup_schedules_scheduler ON backup_schedules
        FOR ALL
        USING (current_setting('app.agent', true) = 'on')
        WITH CHECK (current_setting('app.agent', true) = 'on');
END;
$$;
