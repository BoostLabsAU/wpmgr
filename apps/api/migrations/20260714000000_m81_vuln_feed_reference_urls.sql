-- m81 — Rename wordfence_vuln_feed.references → reference_urls.
--
-- "references" is a RESERVED KEYWORD in PostgreSQL (SQL:2003 and later).
-- The column was defined quoted in CREATE TABLE, so the table was created
-- successfully, but any unquoted use of "references" in INSERT column lists
-- or ON CONFLICT SET clauses triggers SQLSTATE 42601 (syntax error at or
-- near "references").  Because the feed ingester uses raw SQL with the
-- unquoted name, UpsertFeedRecord fails for every record.
--
-- The table has had 0 rows ever committed (every upsert errored), so the
-- rename carries no data-migration cost.  The JSON/API response field stays
-- "references" (DTO/JSON tags unchanged).
--
-- Idempotency: the RENAME is guarded by a column-existence check so re-runs
-- are safe.

DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name   = 'wordfence_vuln_feed'
          AND column_name  = 'references'
    ) THEN
        ALTER TABLE "public"."wordfence_vuln_feed"
            RENAME COLUMN "references" TO "reference_urls";
    END IF;
END;
$$;
