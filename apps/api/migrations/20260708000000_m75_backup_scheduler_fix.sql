-- m75: backup scheduler correctness fixes (issue #68)
--
-- Two changes, both idempotent:
--
-- 1. RECONCILE duplicate in-flight snapshots first (data-heal).
--    For each (site_id) with more than one pending/running snapshot, mark
--    all but the newest as 'failed' so the unique index can be created.
--    The RLS on backup_snapshots is enforced at runtime, not at DDL time, so
--    this raw UPDATE is safe to run here under the migration role.
--
-- 2. PARTIAL UNIQUE INDEX: at most one pending-or-running snapshot per site.
--    Belt-and-suspenders behind the Go-level in-flight guard in CreateSnapshot
--    and EnqueueScheduledBackup. The index is partial (WHERE status IN (...))
--    so completed/failed rows are unconstrained and retention GC is unaffected.
--    Name: backup_snapshots_one_inflight_per_site (vendor-neutral, no PG prefix).

-- -----------------------------------------------------------------------
-- Step 1: reconcile duplicate in-flight snapshots
-- -----------------------------------------------------------------------
DO $$
BEGIN
    -- Mark older duplicates as failed so the unique index creation succeeds.
    -- The subquery picks the NEWEST in-flight snapshot per site; the UPDATE
    -- targets all other in-flight rows for the same site.
    UPDATE backup_snapshots
       SET status      = 'failed',
           error       = 'duplicate_in_flight_healed',
           finished_at = now(),
           updated_at  = now()
     WHERE status IN ('pending', 'running')
       AND id NOT IN (
           SELECT DISTINCT ON (site_id) id
             FROM backup_snapshots
            WHERE status IN ('pending', 'running')
            ORDER BY site_id, created_at DESC
       );
END;
$$;

-- -----------------------------------------------------------------------
-- Step 2: partial unique index — at most one in-flight snapshot per site
-- -----------------------------------------------------------------------
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_class c
          JOIN pg_namespace n ON n.oid = c.relnamespace
         WHERE n.nspname = 'public'
           AND c.relname = 'backup_snapshots_one_inflight_per_site'
           AND c.relkind = 'i'
    ) THEN
        CREATE UNIQUE INDEX backup_snapshots_one_inflight_per_site
            ON backup_snapshots (site_id)
         WHERE status IN ('pending', 'running');
    END IF;
END;
$$;
