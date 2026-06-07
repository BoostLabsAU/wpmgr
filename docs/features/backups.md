# Backups

Schedule, run, and restore backups of your WordPress files and database from a single dashboard. Backups are client-side encrypted; the control plane stores only ciphertext and never holds a decryption key.

Design: [ADR-051](../adr/ADR-051-archive-delta-incremental.md).
API: [api reference below](#api-reference).

---

## Backup types

| Kind | What it covers |
|------|----------------|
| `full` | Database dump + all wp-content files (default) |
| `files` | wp-content files only |
| `db` | Database dump only |

Within a `full` backup, wp-content is split into four independent **components** you can restore separately:

| Component | Archive parts |
|-----------|---------------|
| `plugin` | wp-content/plugins/ |
| `theme` | wp-content/themes/ |
| `upload` | wp-content/uploads/ |
| `wp-content` | Everything else (mu-plugins, languages, drop-ins) |

---

## Incremental backups (archive-delta model)

Enable `incremental_enabled` on a schedule to reduce upload size after the first run.

### How it works

1. **Base (generation 0).** A normal full backup runs. The agent produces part archives for every component plus a full DB dump, then also emits a `files.list` artifact: one line per packed file (`relpath\tsize\tmtime`).

2. **Increment (generation 1+).** The agent fetches the parent snapshot's `files.list` and builds a change set by comparing the live file tree against it. A file is changed if its `size` or `mtime` differs. Only changed and new files are re-archived; unchanged files are carried forward by reference. Deleted files are written to a `tombstones.list` sidecar. The DB is dumped in full on every backup (incremental applies to files only). The resulting archive is processed through the identical encrypt-and-upload pipeline as a full backup.

3. **Chain.** Up to six increments (configurable via `BACKUP_MAX_CHAIN_DEPTH`) share a `chain_id`. After the depth limit, or after `base_window_days` have elapsed, the next scheduled backup automatically starts a new full base.

### Change detection counters

The agent reports these fields in SSE `phase_detail` during `archiving_files`:

| Field | Meaning |
|-------|---------|
| `prevmap_size` | Files in the parent snapshot's `files.list` |
| `files_changed` | Files packed in this increment (new + changed) |
| `files_carried` | Files skipped (unchanged) |
| `tombstones` | Deleted files since the parent |

### Why the DB is dumped in full every time

Incremental applies only to files. A per-table DB delta would require understanding schema history across arbitrary plugins, which is out of scope. A fresh dump on every backup keeps restore simple: you always have one consistent DB state per snapshot.

---

## Restore

Restore reconstructs a point-in-time by replaying the chain in order.

### How overlay restore works

```
base (gen 0)        -> extract all parts
increment (gen 1)   -> extract, overwrite changed files (newest wins)
increment (gen 2)   -> extract, overwrite changed files (newest wins)
tombstones applied  -> delete files removed since the base
DB dump             -> from the highest-generation snapshot that carried one
```

The control plane validates chain integrity before issuing any presigned URLs: every generation from 0 to the target must be present and `completed`.

### Partial restore

Pass `components` to restore only a subset:

```jsonc
// restore only the database
{ "components": ["db"] }

// restore only plugins and uploads
{ "components": ["plugin", "upload"] }

// restore everything (default)
{}
```

`paths` and `db_tables` allow further narrowing to specific files or tables.

---

## Scheduling

Schedules are control-plane-driven. The agent is a stateless push target.

| Field | Notes |
|-------|-------|
| `cadence` | `daily` (default), `hourly`, `every_n_hours`, `weekly`, `monthly` |
| `run_hour` / `run_minute` | Time in the site's WordPress timezone |
| `keep_last` | Minimum snapshots to retain regardless of age (default 7) |
| `retention_days` | Maximum age before automatic deletion (0 = no age limit) |
| `monthly_archive_keep` | Extra snapshots pinned by the monthly-archive rule |
| `incremental_enabled` | Whether eligible runs take an increment instead of a full base |
| `base_window_days` | Days after which a chain is considered stale and a new full base is taken |

### Next run preview

`GET /api/v1/sites/{siteId}/backup-schedule` returns a `next_runs` array with the next three scheduled times (UTC), computed server-side from the stored cadence and site timezone.

---

## Encryption

Every chunk is age-encrypted on the agent to the site's public age recipient before upload. The control plane and object storage hold only ciphertext. To decrypt a backup you need the age private key, which only the agent holds.

The `age_recipient` field on a snapshot is the public recipient used at backup time. It is stored for provenance, not for decryption.

---

## Retention and deletion

- A snapshot with dependent increments (`chain_has_dependents`) cannot be deleted until the later generations are deleted first.
- A running snapshot cannot be deleted; cancel it first.
- The retention GC uses a shared reachability oracle so a chunk referenced by any surviving snapshot is never deleted.

---

## How a backup runs (phase sequence)

```
queued
  -> dumping_db          (full and db kinds)
  -> archiving_files     (full and files kinds)
  -> encrypting_uploading
  -> submitting_manifest
  -> completed
```

Progress streams live over SSE on `GET /api/v1/backups/{snapshotId}/events`.

---

## FAQ

**Why is my increment sometimes large?**
An increment packs every file whose `size` or `mtime` changed since the parent. A plugin update that touches many files, or a batch of new media uploads, can change a large number of files. The increment is a full archive of just those files, so its size grows with the change set.

**Why does the DB dump in full on every backup?**
Incremental applies to files only. A consistent, full DB dump on every backup means restore always reconstructs a coherent DB state with a single extraction step. Per-table DB deltas are a possible future optimization for very large databases.

**What happens if a backup fails mid-run?**
The watchdog re-enters the state machine and resumes from the last checkpoint (the archive cursor is tiny: ~25 part names). If the watchdog exceeds its retry cap, the snapshot is marked `failed`. You can delete a failed snapshot and start a new one.

**Can I restore to a different site URL?**
Yes. The agent rewrites `siteurl` and `home` references during restore. The control plane derives the target URL from the enrolled site automatically. For explicit cross-environment restores, pass `target_site_url` in the restore request.

**Why can't I delete this snapshot?**
If the snapshot is a base or mid-chain increment with dependent later generations, delete the newer increments first. Use `GET /api/v1/backups/{snapshotId}` to inspect `is_incremental`, `generation`, and `chain_id`.

---

## API Reference

All endpoints require an active session or API key. Permissions are noted per endpoint.

Base: `https://<your-wpmgr-host>/api/v1`

### Schemas

#### BackupSnapshot

```jsonc
{
  "id": "uuid",
  "tenant_id": "uuid",
  "site_id": "uuid",
  "created_by": "uuid",            // optional
  "kind": "full | files | db",
  "status": "pending | running | completed | failed",
  "age_recipient": "age1...",      // public recipient used at encrypt time
  "total_size": 1234567890,        // bytes, optional
  "chunk_count": 42,               // optional
  "archived": false,               // pinned by monthly-archive rule
  "error": "...",                  // optional, on failure
  "progress": {                    // optional, empty until first runner post
    "phase": "archiving_files",
    "phase_detail": { "files_done": 120, "files_total": 400 }
  },
  "progress_updated_at": "2026-06-07T10:00:00Z",
  "started_at": "2026-06-07T09:59:00Z",
  "finished_at": "2026-06-07T10:05:00Z",
  "created_at": "2026-06-07T09:58:00Z",
  "updated_at": "2026-06-07T10:05:00Z",
  // incremental chain fields
  "is_incremental": true,
  "generation": 2,
  "chain_id": "uuid",
  "parent_snapshot_id": "uuid",
  "base_snapshot_id": "uuid"
}
```

#### BackupEvent (SSE)

Each SSE frame carries `event: progress` with a JSON payload:

```jsonc
{
  "snapshot_id": "uuid",
  "phase": "archiving_files",      // see phase table below
  "phase_detail": { "files_done": 50, "files_total": 400 },
  "status": "running",
  "ts": "2026-06-07T10:00:00Z"
}
```

Phase values (closed set):

| Phase | Description |
|-------|-------------|
| `queued` | Waiting to start |
| `dumping_db` | Database export in progress |
| `archiving_files` | wp-content archiving in progress |
| `encrypting_uploading` | Chunk encrypt + upload in progress |
| `submitting_manifest` | Manifest submitted to control plane |
| `completed` | Terminal: success |
| `failed` | Terminal: failure |

#### BackupSchedule

```jsonc
{
  "id": "uuid",
  "tenant_id": "uuid",
  "site_id": "uuid",
  "cadence": "daily",
  "kind": "full",
  "enabled": true,
  "run_hour": 2,
  "run_minute": 0,
  "keep_last": 7,
  "retention_days": 30,
  "monthly_archive_keep": 1,
  "incremental_enabled": true,
  "base_window_days": 7,
  "day_of_week": null,
  "day_of_month": null,
  "frequency_hours": null,
  "timezone": "America/New_York",
  "gmt_offset": -5.0,
  "next_run_at": "2026-06-08T07:00:00Z",
  "next_runs": ["2026-06-08T07:00:00Z", "2026-06-09T07:00:00Z", "2026-06-10T07:00:00Z"],
  "last_run_at": "2026-06-07T07:00:00Z",
  "created_at": "2026-01-01T00:00:00Z",
  "updated_at": "2026-06-07T07:00:00Z"
}
```

---

### Endpoints

#### `POST /api/v1/sites/{siteId}/backups`

Start a backup.

Permission: `site:write` (operator+).

**Request body** (optional):
```jsonc
{ "kind": "full" }   // full | files | db; defaults to full
```

**Response 201:** `BackupSnapshot` (status `pending`).

**Response 422:** Site not enrolled, or no age recipient configured.

```bash
curl -X POST https://host/api/v1/sites/{siteId}/backups \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"kind":"full"}'
```

---

#### `GET /api/v1/sites/{siteId}/backups`

List a site's snapshots.

Permission: `site:read` (viewer+).

Query params: `limit` (default 50), `offset` (default 0).

**Response 200:** `{ "items": [BackupSnapshot, ...] }`.

```bash
curl "https://host/api/v1/sites/{siteId}/backups?limit=20" \
  -H "Authorization: Bearer $TOKEN"
```

---

#### `GET /api/v1/backups/{snapshotId}`

Get a snapshot with its manifest entries.

Permission: `site:read` (viewer+).

**Response 200:** `BackupSnapshotDetail`:

```jsonc
{
  "snapshot": { /* BackupSnapshot */ },
  "entries": [
    {
      "path": "plugins.g000.part001.zip",
      "entry_kind": "plugin",    // file | db | plugin | theme | upload | wp-content
      "size": 10485760,
      "chunk_count": 3,
      "table_name": null,        // set for db entries only
      "mode": 0
    }
  ]
}
```

Note: `files-list` and `tombstones` manifest entries are internal bookkeeping and are filtered out of this response.

**Response 404:** Snapshot not found or not in caller's tenant.

```bash
curl "https://host/api/v1/backups/{snapshotId}" \
  -H "Authorization: Bearer $TOKEN"
```

---

#### `DELETE /api/v1/backups/{snapshotId}`

Delete a terminal snapshot. Chain-safe: refuses with `chain_has_dependents` (422) if later-generation increments still exist. Refuses with `snapshot_in_progress` (422) if still running.

Permission: `site:write` (operator+).

**Response 204:** Deleted.

**Response 422:** `{ "code": "chain_has_dependents" | "snapshot_in_progress", "message": "..." }`.

```bash
curl -X DELETE "https://host/api/v1/backups/{snapshotId}" \
  -H "Authorization: Bearer $TOKEN"
```

---

#### `POST /api/v1/backups/{snapshotId}/cancel`

Cancel a running or pending snapshot. Transitions it to `status=failed` with message "cancelled by operator".

Permission: `site:write` (operator+).

**Response 200:** `BackupSnapshot` (now `status=failed`).

**Response 409:** Snapshot is already terminal (`snapshot_not_cancelable`).

```bash
curl -X POST "https://host/api/v1/backups/{snapshotId}/cancel" \
  -H "Authorization: Bearer $TOKEN"
```

---

#### `GET /api/v1/backups/{snapshotId}/events`

Stream live progress over SSE.

Permission: `site:read` (viewer+).

Response: `text/event-stream`. Each frame is `event: progress\ndata: <BackupEvent JSON>\n\n`. Heartbeat comment lines (`:\n\n`) are sent every 15 seconds to keep intermediaries from closing idle streams. The stream stays open until the client disconnects or a 30-minute safety timeout fires; the client closes the EventSource when it sees a terminal phase (`completed` or `failed`).

```bash
curl -N -H "Authorization: Bearer $TOKEN" \
  -H "Accept: text/event-stream" \
  "https://host/api/v1/backups/{snapshotId}/events"
# example output:
# event: progress
# data: {"snapshot_id":"...","phase":"archiving_files","phase_detail":{"files_done":120,"files_total":400},"status":"running","ts":"2026-06-07T10:00:00Z"}
```

---

#### `POST /api/v1/backups/{snapshotId}/restore`

Enqueue a restore. The control plane resolves the selection, issues presigned GET URLs, and dispatches a signed restore command to the agent. The agent downloads ciphertext, verifies BLAKE3 per chunk, decrypts, and reassembles.

Permission: `site:write` (operator+).

**Request body:**
```jsonc
{
  "full": true,           // true = restore everything (default if no narrowing)
  "components": ["db"],   // optional: narrow to specific components
  "paths": [],            // optional: specific file paths
  "db_tables": [],        // optional: specific table names
  "keep_old_files": false // true = agent keeps pre-restore tree for 24 h
}
```

**Response 202:**
```jsonc
{
  /* BackupSnapshot fields */
  "restore_run_id": "uuid"   // use to correlate SSE events for this restore
}
```

**Response 422:** Snapshot not completed, or selection resolves to zero entries.

```bash
curl -X POST "https://host/api/v1/backups/{snapshotId}/restore" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"components":["db"]}'
```

---

#### `GET /api/v1/sites/{siteId}/backup-schedule`

Get the site's backup schedule.

Permission: `site:read` (viewer+).

**Response 200:** `BackupSchedule`.

**Response 404:** No schedule configured for the site.

```bash
curl "https://host/api/v1/sites/{siteId}/backup-schedule" \
  -H "Authorization: Bearer $TOKEN"
```

---

#### `PUT /api/v1/sites/{siteId}/backup-schedule`

Create or update a backup schedule.

Permission: `site:write` (operator+).

**Request body:**
```jsonc
{
  "cadence": "daily",         // daily | hourly | every_n_hours | weekly | monthly
  "kind": "full",             // full | files | db
  "enabled": true,
  "run_hour": 2,              // 0-23, in the site's WordPress timezone
  "run_minute": 0,            // 0-59
  "keep_last": 7,
  "retention_days": 30,       // 0 = no age limit
  "monthly_archive_keep": 1,
  "incremental_enabled": true,
  "base_window_days": 7,
  "day_of_week": null,        // 0-6, required for weekly cadence
  "day_of_month": null,       // 1-31, required for monthly cadence
  "frequency_hours": null     // required for every_n_hours cadence
}
```

**Response 200:** `BackupSchedule` with computed `next_run_at` and `next_runs`.

**Response 422:** Validation failed.

```bash
curl -X PUT "https://host/api/v1/sites/{siteId}/backup-schedule" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"cadence":"daily","run_hour":3,"keep_last":14,"incremental_enabled":true}'
```

---

#### `GET /api/v1/backups/{snapshotId}/environment`

Get the environment fingerprint the agent recorded at backup time: PHP version, WordPress version, active plugins, server software, and similar. Returns the raw JSON the agent shipped as the `environment.json` manifest artifact.

Permission: `site:read` (viewer+).

**Response 200:** `application/json` (the agent-produced environment JSON).

**Response 404:** Snapshot not found, or snapshot pre-dates the environment-fingerprint feature (`env_not_recorded`).

**Response 503:** Environment reader not configured on this control plane.

```bash
curl "https://host/api/v1/backups/{snapshotId}/environment" \
  -H "Authorization: Bearer $TOKEN"
```

---

#### `GET /api/v1/backups/{snapshotId}/sql-inspection`

Return a structured report on the SQL dump: table inventory, row and byte estimates, charset, table prefix, and WordPress `siteurl`/`home`/`db_version` from `wp_options`.

Permission: `site:read` (viewer+).

**Response 200:** `SqlInspection` with `source: "agent" | "cp-legacy"`.

**Response 202:** Legacy inspection job enqueued; poll the same URL. `Location` header echoes the URL.

**Response 404:** Snapshot not found or has no DB artifact.
