-- m65 — add the missing generated_reports.updated_at column.
--
-- m64 documented the project convention ("updated_at set by now() in queries,
-- no trigger") and added the column to report_schedules, but omitted it from
-- generated_reports. All three report mutations (MarkReportGenerating,
-- CompleteReport, FailReport) write updated_at, so every status transition
-- failed at runtime with SQLSTATE 42703 and on-demand reports sat in
-- 'pending' forever (the River job retried into the same error). sqlc compiled
-- the queries because its analyzer does not validate UPDATE SET column names.

ALTER TABLE "public"."generated_reports"
    ADD COLUMN IF NOT EXISTS "updated_at" timestamptz NOT NULL DEFAULT now();
