All five-plus highest-risk claims were spot-checked against both trees before ruling. Verified directly this session: (1) L1 keyed `cache[group][keyStr]` with `switch_to_blog()` only mutating `$blogId` (engine:969/1400-1404); (2) incr/decr fabricating values in all three paths, with the keepttl path passing `['keepttl']` on a fresh key (engine:1186-1231/1250-1290) vs reference returning false on miss (PhpRedisObjectCache.php:707-731); (3) silent serializer/compression fallback (class-redis-connection.php:289-309); (4) OPT_SERIALIZER restore skipped on throw, catch only journals (engine:1989-2036); (5) `wasDegraded` is per-request instance state, never persisted (class-redis-connection.php:40-48/93-97) so the failback flush fires on in-request blips and never on real cross-request outages; (6) `wp_installing()` full bail (drop-in:29-32) vs reference WP_SETUP_CONFIG-only (stub:19-21); (7) `Lifecycle::wipeAll()` contains no object-cache drop-in/config/options teardown (class-lifecycle.php:292-352). All audit rows carried both-tree citations; **zero rows discarded**. Harness ground truth: E2E stages are `provision, assert-cli, cron-check, negative-check, disable` with exactly one wp_cache assertion today; unit tests live in `apps/agent/tests/` with an existing `ObjectCache/` subdir and `ObjectCacheDropinBuildTest.php`.

# IMPLEMENTATION CONTRACT — Object Cache 0.42.0

## 1. MUST-FIX NOW (HIGH, correctness)

**H1. Multisite L1 cross-blog poisoning** (audits 1+3; spot-checked)
- Files: `includes/object-cache/class-object-cache-engine.php` (storeL1:1684, every L1 read in get/get_multiple/add/replace/set/incr/decr/delete, flush_runtime, group-flush L1 clear), `tools/build-object-cache-dropin.php`.
- Mechanism: key L1 by the same fully-qualified id as Redis — use `buildKey()` output as the L1 index (memoize the per-group prefix so hot-path cost stays O(1)). Fold in the single-site gate: capture `is_multisite()` at boot into `$this->isMultisite`; `switch_to_blog()` becomes a no-op returning early when false. Also fold the audit-3 note: stats persistence/heartbeat consumption must agree on the main-site option under multisite.
- Tests: unit `tests/ObjectCache/MultisiteIsolationTest.php` — simulate blog switch, assert `get('alloptions','options')` misses after switch, write on blog 2 then switch back asserts blog 1 value intact, global groups unaffected, single-site `switch_to_blog(2)` is a no-op. E2E: new stage `multisite-check` (run.sh converts the container via `wp core multisite-convert`): `wp_cache_set('probe','b1','options')` → `switch_to_blog(2)` → assert `wp_cache_get('probe','options')===false` → set 'b2' → switch back → assert 'b1'.
- Drop-in regen: **YES**.

**H2. incr/decr: missing key must return false, never create; kill the INCRBY fallback** (audits 1+2; spot-checked; absorbs the audit-2 MEDIUM row and audit-1 INCRBY MEDIUM row)
- Files: engine:1170-1295 (incr/decr), drop-in regen.
- Mechanism: non-persistent branch returns false unless the key is set in L1; keepttl path returns false when `GET===false` (and when TTL probe returns -2 mid-flight); replace `incrBy/decrBy` fallback with GET + SET (+`['ex'=>$ttl]` when ttl>0) so values stay serializer-encoded on Redis < 6. Preserve the `(int)` cast (OURS-BETTER numeric-string handling) — regression-guard it.
- Tests: unit `tests/ObjectCache/IncrDecrContractTest.php` — incr/decr missing → false (persistent, non-persistent, array-mode); existing key preserves TTL; stored `'5'` incr → 6; fallback path never calls incrBy (mock spy). E2E `assert-cli` additions: `wp_cache_incr('e2e_missing')===false`; `wp_cache_add('e2e_ctr',1,'',120)` then incr → 2; direct `redis-cli TTL` on the built key asserts TTL > 0 (no immortal counter).
- Drop-in regen: **YES**.

**H3. Serializer/compression capability negotiation: fail loud, never mix formats** (audit 2; spot-checked)
- Files: `class-redis-connection.php` applyClientOptions:289-309 + probeCapabilities wiring, engine metadata writer (add **effective** serializer/compression fields), `class-objectcache-test-command.php` / apply-config gate, CP `apps/api/internal/objectcache/service.go` (stop discarding push errors at :127).
- Mechanism: configured-but-unsupported codec throws → boot lands in array mode with a distinct `unsupported_codec` journal/heartbeat cause; test/apply_config commands reject configs the runtime can't honor using probeCapabilities; metadata records effective codecs so a cross-SAPI mismatch trips the integrity flush.
- Tests: unit — refactor applyClientOptions to accept an injectable capability map; assert throw when igbinary configured/unavailable; assert metadata payload carries effective values. Artifact: extend `ObjectCacheDropinBuildTest` to assert the throw exists in the generated drop-in. E2E `assert-cli` addition: push a config with `serializer: igbinary` into the (igbinary-less) container, assert heartbeat diagnose reports `unsupported_codec` + array mode + site still serves 200.
- Drop-in regen: **YES**.

**H4. try/finally around all OPT_SERIALIZER raw windows** (audit 2; spot-checked)
- Files: engine checkMetadataIntegrity:1989-2036 (both NONE windows), and apply the same finally-discipline to the sync flushDB read-timeout suspension added in M9/M10.
- Mechanism: wrap each raw GET/SET window in try/finally restoring `$savedSerializer`, mirroring reference `withoutMutations()`. Add 2 short retries on the metadata GET (audit-2 row 6 partial).
- Tests: unit `tests/ObjectCache/MetadataIntegrityTest.php` — mock Redis throws on `get()` after `setOption(NONE)`; assert OPT_SERIALIZER restored post-call; same for `set()` throw. Artifact: build test greps the generated drop-in for the finally blocks. (Not runtime-dependent beyond this — no E2E needed.)
- Drop-in regen: **YES**.

**H5. Failback flush redesign: persisted outage epoch, remove the in-request trigger** (audit 2; spot-checked; this is the judge's ruling on the pending failback decision)
- Files: engine redisOp:1747-1758 (delete trigger), executeFailbackFlush, boot path; `class-redis-connection.php` markDegraded.
- Mechanism: on first markDegraded, immediately best-effort persist an outage marker (option write, not shutdown-hook-only); at next **healthy boot**, if marker present, attempt `SET NX EX 300` on `prefix:__wpmgr_oc_failback_lock__` — only the winner flushes, then clears the marker. `flush_on_failback` default stays true; the mid-request degrade→recover trigger is removed entirely (reference ships nothing here; ours currently fires only when least needed).
- Tests: unit `tests/ObjectCache/FailbackEpochTest.php` — blip-then-success issues NO flush; boot-with-marker + NX win → exactly one flush + marker cleared; NX loss → no flush. E2E: new stage `outage-failback` — write sentinel key, `docker stop` redis, request the site (marker written, site serves), `docker start` redis, request again, assert sentinel gone from Redis and lock key present.
- Drop-in regen: **YES**.

**H6. wp_installing() bail → WP_SETUP_CONFIG-only** (audits 1+3; spot-checked)
- Files: `tools/build-object-cache-dropin.php`:183-185 preamble; keep a distinct `setup_config` breadcrumb so heartbeat diagnose stays exhaustive (preserves the OURS-BETTER attribution row).
- Mechanism: replace the `wp_installing()` bail with `if (defined('WP_SETUP_CONFIG')) return;` so wp_upgrade()'s flushes and wp-activate.php invalidations reach Redis. Add the env kill-switch (`getenv('WPMGR_OBJECT_CACHE_DISABLED')`, breadcrumb `killswitch_env`) in the same preamble edit.
- Tests: artifact — `ObjectCacheDropinBuildTest`: generated drop-in contains NO `wp_installing()` bail, contains the WP_SETUP_CONFIG bail and the getenv check. E2E: new stage `installing-check` — auto_prepend defines `WP_INSTALLING`, loads WP, `wp_cache_set('e2e_install_probe',...)`, asserts the key exists in Redis via redis-cli (invalidation path live during install mode).
- Drop-in regen: **YES** (preamble = stub bump).

**H7. CLI cross-uid: unreadable 0600 config must not silently no-op `wp cache flush`** (audit 3)
- Files: `class-object-cache-config.php` load():97-130 (distinguish `config_unreadable` from `config_empty`), engine flush() (return false + `error_log` naming the file when arrayMode && config file exists), heartbeat cause.
- Mechanism: `is_file()` + include-failure ⇒ reason `config_unreadable`; flush() in that state returns false so WP-CLI surfaces an error instead of reporting success while Redis keeps serving stale data.
- Tests: unit `tests/ObjectCache/ConfigUnreadableTest.php` — chmod 0000 fixture → load() reason `config_unreadable`; engine in that state: flush() false. E2E: new stage `cli-uid-check` — seed a Redis key, run `wp cache flush` as a non-owner uid (`su -s /bin/sh www-data` or a created user), assert non-zero exit / error text, assert the Redis key survived (the honesty assertion).
- Drop-in regen: **YES**.

**H8. Deactivate/uninstall must tear down the object-cache drop-in + creds file** (audit 3; spot-checked)
- Files: `includes/class-lifecycle.php` on_uninstall/wipeAll, `includes/class-plugin.php` deactivate():828-859, reuse `ObjectcacheDisableCommand::standaloneFlush`, `ObjectCacheDropinInstaller::uninstall()`, `ObjectCacheConfig::delete()`.
- Mechanism: uninstall = best-effort standalone flush + drop-in removal + config-file delete + `delete_option('wpmgr_object_cache_stats'|'wpmgr_object_cache_config_hash')`. Deactivate = remove drop-in, KEEP the config file (re-activation re-enables), mirroring the existing page-cache teardown.
- Tests: unit `tests/ObjectCache/LifecycleTeardownTest.php` — after on_uninstall: installer state not-installed, config file gone, both options gone; deactivate keeps config. E2E: extend the existing `disable` stage — after plugin deactivate+uninstall, assert `wp-content/object-cache.php` and the config file are absent.
- Drop-in regen: **NO** (plugin-side only).

## 2. SHOULD-FIX (MEDIUM) — all batched into 0.42.0 unless marked DEFER

Same release because they share the files H1–H7 already touch and one drop-in regen covers all:

- **M1. delete/delete_multiple false-on-missing** — engine:1130-1167: `del()/unlink() > 0`, OR'd with actual L1 removal; non-persistent gated on prior existence. Unit: `EngineCoreContractTest::testDeleteMissingReturnsFalse`. E2E `assert-cli`: `wp_cache_delete('never_set')===false`.
- **M2. get($force) on non-persistent/array-mode serves L1** — hoist the branch above the force check (engine:969-981), matching our own get_multiple. Unit + E2E assertion `wp_cache_get($k,$g,true)` finds the L1 value.
- **M3. set/set_multiple L1 only on Redis success** — move storeL1 behind redisOp result (engine:849, 904), per-key on pipeline ack; matches add/replace. Unit: failing-Redis mock → L1 unchanged.
- **M4. replace() drop the pre-get** — rely on the existing SET XX (engine:814-827); hasInMemory check only for non-persistent. Fixes replace-on-stored-false; saves a round trip. Unit test with stored-false fixture.
- **M5. Reject empty/whitespace keys** — validateKey requires `trim((string)$key) !== ''` (ints exempt), journal `invalid_key`. Unit + E2E: `wp_cache_set('', 'x')===false`.
- **M6. get_multiple input-order preservation** — pre-fill `$results[$key]=false` in the partition loop; keep invalid-keys-as-false (ours-better). Unit asserts `array_keys($out) === input order` for mixed L1/Redis batches.
- **M7. __get/__isset back-compat bridge** — expose cache/global_groups/non_persistent_groups/multisite/blog_prefix copies, E_USER_WARNING on unknown; optional core-compatible stats() stub. Unit: reading `->cache` on the instance does not fatal.
- **M8. phpredis 6 flushDB flag gate** — version_compare on `phpversion('redis')`, invert the flag on >=6.0 (engine:1807-1813). Unit on the flag computation (extract to a pure helper).
- **M9. Read-timeout suspension around sync FLUSHDB** — save/setOption(-1)/try-finally-restore (same discipline as H4). Unit: mock asserts restore on throw.
- **M10. wp_version in metadata riskyChanged + missing-metadata-with-existing-keys flush** — field already written (engine:2027); add the comparison + retries. Unit: metadata with older wp_version triggers executeFlush.
- **M11. config_hash drift detection** — agent: add `config_hash` to the heartbeat object_cache block (`class-object-cache-heartbeat.php`:151-167); CP: ingest compare vs computeConfigHash, surface drift state, stop discarding the push error (`service.go:127`). Ships in the same release train (api+web+agent per the full-stack checklist). Unit: heartbeat block contains the option value; Go test on the compare.
- **M12. Enable-command flush after install** — reuse the disable command's standalone prefix flush, report `flushed:bool`. Unit on the command result shape; E2E `provision` stage asserts a pre-seeded stale key is gone after enable.
- **M13. Multisite transient purge parity** — options + sitemeta `_site_transient_%` + get_sites loop, with `$wpdb->prepare`. Unit with wpdb spy; covered behaviorally by `multisite-check`.
- **M14. Performance Lab hijack guard** — `add_filter('perflab_disable_object_cache_dropin','__return_true')` at agent boot when OC configured. Unit: filter registered.
- **M15. CP flush command: remove scope 'site' from the contract** until flushBlog exists (`class-objectcache-flush-command.php`:51-55) — a button must not secretly nuke the whole network. Unit: scope 'site' now rejected with explicit error.
- **M16. Bridge de-typing pass** — cast `(bool)$force/(int)$offset/(array)$keys` in bridges, untype `&$found` (closes the residual TypeError-eats-result corner). Artifact test greps generated drop-in signatures.

**DEFERRED MEDIUMs:**
- **Mid-request reconnect cool-down** (audit 2) — DEFER to 0.43: H5's persisted epoch marker now bounds the staleness consequence (the dropped-invalidation request marks the epoch; next healthy boot flushes), so this becomes an availability optimization, and it touches the same degradation state machine H5 is changing — do not change it twice in one release.
- **Foreign `$wp_object_cache` duck-typing** (audit 3) — DEFER to 0.43: heartbeat already detects and attributes `engine_replaced`; delegating to arbitrary foreign objects mid-request needs design care to not regress our own boot/recovery guarantees.

**LOW one-liners folded into 0.42.0** (each gets a line in `EngineCoreContractTest` or the build test): group `'0'` → 'default'; negative expire → 0; queryttl suffix-match only; drop 'comment'/'themes' from DEFAULT_NON_PERSISTENT; `wp_cache_remember/sear/supports_group_flush/reset` bridge functions (OCP-migration fatal insurance — E2E `assert-cli`: `function_exists` all four); `$GLOBALS['wp_object_cache_errors']` appended in journalError; CP flush-command colon-segment post-filter copied from the engine.

## 3. ACCEPTED DIVERGENCE / DEFER (one line each)

- **Boot-failure array mode (no strict wp_die)** — deliberate availability-over-strictness stance; document; optional down-marker is 0.43 polish.
- **maxttl 604800 default + expire=0 clamped** — held for user decision D6; document "forever is 7d-bounded"; D6 must allow maxttl=0 passthrough.
- **Byte-exact key passthrough** (no lowercasing/colon-stripping) — OURS-BETTER, keep; reference's rewrite causes silent key collisions.
- **Unmemoized buildKey / late add_global_groups correctness** — OURS-BETTER, keep; any future memo must include the global flag.
- **incr numeric-string `(int)` coercion** — OURS-BETTER (core-faithful), regression-guarded in H2 tests.
- **pconnect identity scheme + per-acquire AUTH/SELECT** — OURS-BETTER, keep.
- **Shallow clone-on-read/write** — shared limitation with reference; deep clone is a perf trade-off neither makes; accept.
- **Huge-value guard, TTL jitter** — parity (neither tree has them); optional observability later.
- **Prefix-SCAN shared-Redis flush, non-atomic group flush batches** — OURS-BETTER blast-radius/blocking profile; keep.
- **TLS stream-context allow-list** — defer passthrough keys until a managed-Redis case demands it.
- **Site Health panel/tests, flush interception filter+flushlog, network-admin flushBlog, config constant/env override** — defer to 0.43 operator-surface batch (H7 removes the correctness part of the CLI/config story).
- **split_alloptions, prefetch, getWithMeta, pipelined multis, WP-CLI command set, Sentinel/Cluster, QM panel, metrics-to-Redis** — remain on the standing deferred list; audits confirmed nothing needs pulling forward (H4 removes the one leak).

## 4. RELEASE PLAN

- **Agent 0.42.0**, ENGINE_VERSION `0.42.0`, drop-in stub **2.1.0** (preamble changed: installing bail + env kill-switch), regenerated via `tools/build-object-cache-dropin.php`; existing maybeAutoRefresh rolls it out. No Redis key-format change (buildKey untouched), so no migration flush; metadata gains fields — comparison must treat absent-old-field as not-changed (no false integrity flush on upgrade).
- **Build/test gates, in order:**
  1. `composer test` in apps/agent — full PHPUnit including the new `tests/ObjectCache/{MultisiteIsolationTest, IncrDecrContractTest, MetadataIntegrityTest, FailbackEpochTest, ConfigUnreadableTest, LifecycleTeardownTest, EngineCoreContractTest}.php`.
  2. Drop-in regen gate: run the builder, `git diff --exit-code assets/wpmgr-object-cache-dropin.php` (committed asset must equal generated output), `php -l` on it, `ObjectCacheDropinBuildTest` (extended: serializer-throw present, finally blocks present, WP_SETUP_CONFIG bail, getenv kill-switch, de-typed bridge signatures, four new wp_cache_* functions).
  3. E2E `tests-e2e/run.sh` full stage matrix: `provision` (now asserts enable-flush), `assert-cli` (extended core-contract block), `cron-check`, `negative-check`, **`multisite-check`**, **`installing-check`**, **`cli-uid-check`**, **`outage-failback`**, `disable` (extended teardown asserts).
  4. CP train: Go tests for config_hash drift + flush-scope contract; api+web images + `make agent-release` per the full-stack checklist (CP deploys before agent).
- **E2E assertions added this release (the regress-loudly list):** incr/decr-missing-false + counter-TTL>0; delete-missing-false; get-force-nonpersistent-hit; get_multiple order; empty-key rejected; remember/sear/supports_group_flush/reset defined; switch_to_blog cross-blog isolation (multisite) and single-site no-op; WP_INSTALLING writes reach Redis; non-owner-uid `wp cache flush` fails loudly and leaves Redis intact; outage→recovery flushes exactly once via the NX lock; uninstall leaves no drop-in/config file; unsupported-codec config → array mode + heartbeat cause + site serves.

## 5. SANITY LIST — top 5 residual field-bug vectors

1. **Engine/drop-in drift** — the fix lands in the engine but the committed drop-in is stale (the historical failure mode). Mitigation: the regen-diff CI gate (builder output must equal committed asset) plus the artifact greps in ObjectCacheDropinBuildTest; both block release.
2. **L1 re-keying perf/semantics regression** — H1 touches every hot path; a subtle change (e.g. global-group routing through the new index) could mis-route or slow per-op. Mitigation: memoized per-group prefix, a unit test asserting L1 index == Redis key for global/non-global/switched-blog cases, and keep the cheap "clear non-global L1 on blog switch" as belt-and-braces inside switch_to_blog.
3. **Failback marker not persisted during a hard outage** — if the DB write of the outage marker also fails (full infra outage), the next healthy boot won't flush and staleness persists. Mitigation: marker write is immediate-on-first-degrade (not shutdown-only), heartbeat surfaces `was_degraded` so the CP can offer a one-click flush, and the 7d maxttl remains the hard staleness bound.
4. **Serializer fail-loud = surprise array mode on upgrade** — sites silently running the php fallback today (configured igbinary, no extension) drop to array mode at 0.42.0 and lose their object cache. Mitigation: CP pre-push capability gate via probeCapabilities, distinct `unsupported_codec` heartbeat cause with a dashboard surface, and CP auto-corrects the config to `php` with an operator notice instead of leaving the site uncached.
5. **incr/decr/delete return-shape change breaks plugins that adapted to our wrong behavior** — code on existing sites may rely on incr-creating or delete-always-true. Mitigation: the new semantics are exactly WP core's (any such plugin is already broken on vanilla WP), journal first-N `incr_missing`/`delete_missing` breadcrumbs for post-release triage, and call the change out in the 0.42.0 release notes and CHANGELOG per the docs SOP