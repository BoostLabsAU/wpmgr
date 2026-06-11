# Object Cache security review fixes (2026-06-11)

Adversarial multi-lens review verdict: BLOCKED on 1 MUST-FIX; 9 SHOULD-FIX; 2 NOTE. 13 of 16 findings survived adversarial refutation. The trust architecture (RLS, RBAC, Ed25519 signed channel, CP secret redaction) was confirmed sound. Fix all before ship.

## MUST-FIX

M1. Redis password ships inside every file backup. The plaintext password is var_export'ed into WP_CONTENT_DIR/wpmgr-object-cache-config.php; FilesArchiver excludes only by exact path-segment name and the filename is in no list, so the wp-content catch-all packs it into the backup. The in-file `wpmgr-backup-exclude` marker (class-object-cache-config.php:155) is DEAD — no content-scan excluder exists.
  Fix: add literal 'wpmgr-object-cache-config.php' to DEFAULT_EXCLUDES in class-files-archiver.php (segment match covers the top-level file); also exclude it in the restore file-set; drop the misleading marker; add a backup-and-inspect regression test.

## SHOULD-FIX

S1. Heartbeat/stats ingest is UNWIRED: IngestStats (service.go:291) and IngestHeartbeat (service.go:322) have no production caller; statsReportBody (perf/agent_handler.go:65-82) has no object_cache field so the agent's emitted block is silently dropped. Live status pill stays "disabled" forever, stats-history never written, SSE never fires. Wire BOTH into the stats-report handler, binding siteID STRICTLY from the verified agent identity (mirror agent_handler.go:85,100-101), never from the body. Re-pass the attacker-controlled heartbeat block (class-object-cache-heartbeat.php:73-79) at wiring time (bounded sizes, no secrets, clamp/validate).

S2. Heartbeat UPDATE has no tenant binding (latent cross-tenant write): object_cache.sql.go UpdateHeartbeatState WHERE site_id only under InAgentTx, m68 agent RLS has no row predicate. Add AND tenant_id = $N (thread id.TenantID through IngestHeartbeat) or run under InTenantTx; regen sqlc. Do together with S1; add a cross-site-write test.

S3. has_password permanently false: configFromRow never assigns it; SELECT has no derived column. Add `(password_encrypted IS NOT NULL) AS has_password` to GetObjectCacheConfig SELECT (+ RETURNING shims as needed), map in configFromRow. Fixes API, UI, and audit.

S4. CP fallback config hash feeds the plaintext password into an unsalted sha256 (service.go:513-519), emitted in config_applied SSE and stored as the enable-gate fallback; also can never match the agent's password-redacted hash, permanently bricking Enable; a PermSiteRead member could offline-crack a weak password. Drop password from computeConfigHash; align the field set/encoding with the agent's redacted hash (class-object-cache-config.php:219-222).

S5. Drop-in installer overwrites an unreadable FOREIGN object-cache.php: class-object-cache-dropin-installer.php:177-191 short-circuits the foreign guard when file_get_contents fails. Treat is_file && unreadable as foreign (refuse without $force). SAME bug in the page-cache installer cache/class-dropin-installer.php:152-154 — fix both.

S6. Empty/whitespace prefix reachable end to end, defeating shared-Redis namespacing and making shared-mode flush SCAN `:*` delete the neighbor's keys. Agent: sanitizePrefix('') and fromParams fall back to 'wpmgr'. CP: TrimSpace + charset-validate prefix in validateConfig.

S7. Config save() skips opcache_invalidate: on validate_timestamps=0 hosts credential rotation silently no-ops while the new hash is persisted (blinds drift detection). Add opcache_invalidate($this->filePath, true) after the rename (and after unlink in delete()).

S8. Credential tmp file world-readable between write and chmod (class-object-cache-config.php:163 writes at umask perms, chmod 0600 only at :169). Bracket with umask(0077) or fopen('x')+chmod-before-write.

S9. Test/Enable/Disable/Flush use context.Background() (service.go:137,203,233,265) while pushApplyConfig threads ctx. Four one-line changes to pass the request ctx.

## NOTE

N1. Group flush glob `prefix:*:group:*` spans ':' so flushing group 'post' also hits interior ':post:' tokens (engine.php:1688-1698). Never crosses the prefix boundary; fail-safe. Post-filter SCAN results on the exact group segment.

N2. No RouterTest covers the widened [a-z0-9_.]+ command regex. Add a regression test for '..'-style names, cmd-binding mismatch, and objectcache.* dispatch.

## RE-REVIEW (post-fix, 2026-06-11)

Focused re-review of the S1+S2 ingest wiring returned **SHIP**: the cross-tenant
write (S2) and the attacker-controlled ingest surface (S1) are genuinely closed.
No MUST-FIX. Two non-blocking robustness notes:

R1. (FIXED) avg_wait_ms had no upper clamp: a forged value over numeric(8,3)'s
    range failed the INSERT and silently dropped the site's own stats row.
    Clamped to [0, 60000] in the stats-report handler; ops_per_sec and
    connected_clients (integer columns) clamped to [0, MaxInt32] for the same
    failure shape.

R2. (DEFERRED) No per-site insert rate limit on site_object_cache_stats_history.
    Same shape as the pre-existing m52 cache-stats history (accepted risk);
    blast radius is the attacker's own tenant rows. Fold into a shared
    agent-ingest rate-limit pass if/when m52 gets one.
