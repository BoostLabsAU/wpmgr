# ADR-051 — Archive-delta incremental backups

Status: Accepted (2026-06-07)
Supersedes the per-file content-addressed chunk incremental engine (ADR-048).

## Context

The first incremental engine (ADR-048) chunked **every file individually** into a
content-addressed store and persisted a **per-file cursor** — thousands of records
(`changed[]`, `carry_forward[]`, per-file chunk hashes) — across a multi-request
backup (the work runs in a wp-cron worker + watchdog re-entry, with state held in a
single `wpmgr_backup_tasks.sub_state` row).

That cursor was the root of a recurring, **silent data-loss** failure mode: any time
the cursor failed to round-trip the WP DB intact, the next request reloaded an empty
cursor and the backup "completed" with **zero files** (DB-only). We found and fixed
several distinct causes (lost-append, invalid-UTF-8 `json_encode` wipe, an
over-`max_allowed_packet` `$wpdb->update()` that failed silently) — but the **shape of
the bug was structural**: persisting thousands of per-file records across requests is
fragile. The model was also slow (thousands of tiny storage objects + a per-file
double-read).

The **full backup**, by contrast, has never had this problem: it streams the whole
site into one archive, chunks the *stream* into ~25 large parts, and persists only
~25 part **names** in `sub_state` (a few KB). Its multi-request state machine is
robust precisely because the cursor is tiny.

## Decision

Re-architect an increment to be **"a full-zip of just the changed files"**:

- **Change detection** — compare the live tree against the prior backup's file list by
  `size` + `mtime` (no content hashing, no per-file cursor). The prior list is a
  per-snapshot `files.list` artifact (`relpath\tsize\tmtime`, one line per packed file)
  the agent emits on **every** backup and fetches for the parent on an increment.
- **Packaging** — feed the changed-file allow-list to the **existing full-zip pipeline**
  (`FilesArchiver` + `EncryptAndUpload`). The increment inherits the proven ~25-part
  multi-request state machine; there is no per-file cursor to lose.
- **Deletions** — emit one per-path **tombstone** manifest entry (`mode = delete`).
  Tombstones stream to an on-disk list, never an unbounded `sub_state` array; the
  sidecar-spill + throw-on-false `sub_state` write nets remain as defence in depth.
- **Restore** — reconstruct the full tree by extracting the base then **overlaying each
  increment newest-wins** and applying tombstones (the chain restore already did this).
- **Database** — dumped in **full on every backup**. Incremental applies to *files
  only*; per-table DB deltas are out of scope (and not done by comparable WP backup
  tooling). A table-level DB delta is a possible future optimization for very large DBs.

## Consequences

- **Bulletproof:** the 0-files / lost-cursor class is structurally impossible — the
  increment runs the same path as a full backup, whose cursor is tiny. Proven by an
  over-the-wire change-detection test and a 5000-deletion `sub_state` bound test.
- **Reuse over new code:** the full-zip archive/encrypt/upload/manifest/restore-overlay
  pipeline is reused as-is; the agent reports change-detection counters
  (`prevmap_size`, `files_changed`, `files_carried`, `tombstones`) for observability.
- **Trade-off:** no cross-backup *file-level* content-addressed dedup — a changed file
  is re-archived whole (the archive stream is still chunked + stored). Accepted: the
  per-file chunk engine's dedup was the source of the fragility, and the dominant cost
  on real sites is the DB + changed media, not unchanged-file storage.
- **Retired (agent):** the per-file chunk scanner and per-file upload pipeline are no
  longer on the backup path (left in tree as dead code; scheduled for deletion in a
  follow-up cleanup once their unit tests are migrated/removed). **Retired (control
  plane):** the chain-merge file-index endpoint and `backup_file_index` as the diff
  source-of-truth (kept only as legacy-restore metadata for pre-pivot chains).
