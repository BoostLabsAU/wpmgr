-- M58 — Consecutive-miss hysteresis for connection-state sweeper.
--
-- Adds missed_heartbeats (int, default 0) to the sites table so the sweeper
-- can require N consecutive overdue evaluations before transitioning
-- connected→degraded, eliminating the one-late-beat flap on low-traffic or
-- page-cached WordPress sites whose wp-cron fires irregularly.
--
-- The column is reset to 0 by every agent heartbeat (TouchSiteHeartbeat /
-- ResetSiteMissedHeartbeats) and incremented by the sweeper on each overdue
-- evaluation (IncrementSiteMissedHeartbeats). The sweeper calls
-- MarkDegradedTenant only once the counter reaches the configured threshold
-- (default N=3). Disconnect logic remains a hard time threshold; the counter
-- governs only the connected→degraded transition.
--
-- Idempotency: the column add is guarded by a column-existence check inside a
-- DO block so re-runs are safe. Forward-only.

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name   = 'sites'
          AND column_name  = 'missed_heartbeats'
    ) THEN
        ALTER TABLE "public"."sites"
            ADD COLUMN "missed_heartbeats" integer NOT NULL DEFAULT 0;
    END IF;
END $$;
