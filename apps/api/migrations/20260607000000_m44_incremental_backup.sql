-- File: apps/api/migrations/20260607000000_m44_incremental_backup.sql
-- m44 — Incremental backup engine V1 (backup-creation only; restore/scheduler/UI in later migrations).
-- Forward-only. No seed data → no GRANT/REVOKE footgun.

ALTER TABLE "public"."backup_snapshots"
  ADD COLUMN "is_incremental"       boolean  NOT NULL DEFAULT false,
  ADD COLUMN "parent_snapshot_id"   uuid     NULL
    CONSTRAINT "backup_snapshots_parent_snapshot_id_fkey"
      REFERENCES "public"."backup_snapshots" ("id") ON UPDATE NO ACTION ON DELETE SET NULL,
  ADD COLUMN "base_snapshot_id"     uuid     NULL
    CONSTRAINT "backup_snapshots_base_snapshot_id_fkey"
      REFERENCES "public"."backup_snapshots" ("id") ON UPDATE NO ACTION ON DELETE SET NULL,
  ADD COLUMN "chain_id"             uuid     NULL,
  ADD COLUMN "generation"           integer  NOT NULL DEFAULT 0,
  ADD COLUMN "cycle_files_scanned"  bigint   NOT NULL DEFAULT 0,
  ADD COLUMN "cycle_files_changed"  bigint   NOT NULL DEFAULT 0,
  ADD COLUMN "cycle_files_deleted"  bigint   NOT NULL DEFAULT 0,
  ADD COLUMN "cycle_bytes_uploaded" bigint   NOT NULL DEFAULT 0;

CREATE INDEX "backup_snapshots_chain_id_idx"
  ON "public"."backup_snapshots" ("chain_id")
  WHERE "chain_id" IS NOT NULL;

CREATE INDEX "backup_snapshots_parent_id_idx"
  ON "public"."backup_snapshots" ("parent_snapshot_id")
  WHERE "parent_snapshot_id" IS NOT NULL;

CREATE TABLE "public"."backup_file_index" (
  "id"           uuid     NOT NULL DEFAULT gen_random_uuid(),
  "tenant_id"    uuid     NOT NULL,
  "snapshot_id"  uuid     NOT NULL,
  "file_path"    text     NOT NULL,
  "file_size"    bigint   NOT NULL DEFAULT 0,
  "file_mtime"   bigint   NOT NULL DEFAULT 0,
  "file_blake3"  text     NOT NULL DEFAULT '',
  "chunk_hashes" text[]   NOT NULL DEFAULT '{}',
  "is_tombstone" boolean  NOT NULL DEFAULT false,
  "created_at"   timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY ("id"),
  CONSTRAINT "backup_file_index_snapshot_id_fkey"
    FOREIGN KEY ("snapshot_id") REFERENCES "public"."backup_snapshots" ("id")
    ON UPDATE NO ACTION ON DELETE CASCADE,
  CONSTRAINT "backup_file_index_tenant_id_fkey"
    FOREIGN KEY ("tenant_id") REFERENCES "public"."tenants" ("id")
    ON UPDATE NO ACTION ON DELETE CASCADE
);

CREATE UNIQUE INDEX "backup_file_index_snapshot_path_key"
  ON "public"."backup_file_index" ("snapshot_id", "file_path");

CREATE INDEX "backup_file_index_snapshot_path_idx"
  ON "public"."backup_file_index" ("snapshot_id", "file_path");

CREATE INDEX "backup_file_index_tenant_id_idx"
  ON "public"."backup_file_index" ("tenant_id");

ALTER TABLE "public"."backup_file_index" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "public"."backup_file_index" FORCE ROW LEVEL SECURITY;
CREATE POLICY "backup_file_index_tenant_isolation"
  ON "public"."backup_file_index"
  USING  ("tenant_id" = nullif(current_setting('app.tenant_id', true), '')::uuid)
  WITH CHECK ("tenant_id" = nullif(current_setting('app.tenant_id', true), '')::uuid);
CREATE POLICY "backup_file_index_agent"
  ON "public"."backup_file_index"
  FOR SELECT
  USING (current_setting('app.agent', true) = 'on');
