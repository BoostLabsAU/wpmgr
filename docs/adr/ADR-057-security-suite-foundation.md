# ADR-057 — Security Suite Foundation: Per-Site Policy Model

**Status:** Accepted  
**Date:** 2026-06-20  
**Phase:** 1 of 7 (hardening tweaks + ban list)

---

## Context

WPMgr already ships a login-protection config (`site_security_config`, m13) and
brute-force lockout via the `sync_security_config` signed command (S2). The
security-suite plan adds six phases of new capabilities (hardening tweaks, full
file-integrity, site-user 2FA + passwords, WAF/virtual-patching, cross-fleet IP
reputation, geo-advanced auth) that all need the CP to store per-site policy and
push it to the agent via signed commands.

This ADR records the shared model that all six phases build on, plus the
Phase 1-specific data decisions.

---

## Decision

### 1. Per-site policy ownership

The **control plane owns the canonical policy**. The agent is a stateless
consumer: it applies whatever the CP sends via a signed command. The agent never
independently stores or mutates the policy.

The CP→agent transport is the existing signed-command channel: a JWT-bearing
POST to `/wp-json/wpmgr/v1/command/<verb>` (Ed25519, single-use jti,
`aud`=siteId). This is identical to how `sync_security_config`,
`sync_error_config`, `perf_config_update`, and `sync_email_config` work.

### 2. Extensibility across phases

The policy is split across multiple narrowly-scoped tables (one per feature
cluster) rather than a single fat config blob:

| Phase | Table(s) | Sync command |
|-------|----------|--------------|
| 1 (current) | `site_security_hardening_config` + `site_security_bans` | `sync_security_hardening` |
| 3 (site-user 2FA) | `site_security_auth_policy` (future) | `sync_security_auth_policy` (future) |
| 4 (WAF rules) | `site_waf_rules` (future) | `sync_waf_rules` (future) |

Each phase adds its own table and signed command. The agent accumulates them
independently; they do not conflict.

### 3. Sync trigger

Config is pushed immediately on every successful write (same pattern as
`sync_security_config` and `sync_perf_config`). The push is best-effort: a
push failure is surfaced as an `X-Agent-Push-Warning` header with 200 OK so
the UI can show a warning without discarding the stored config. The agent
applies the LAST successfully pushed state; a failed push is retried the next
time the operator saves.

Ban-list changes (create/delete) each trigger a full re-push of the current
config + ban list so the agent always receives a consistent snapshot.

### 4. Per-site scope for v1

All Phase 1 tables are keyed on `site_id`. Org-wide defaults (one row that
propagates down to all sites) are deferred to a later enhancement and are not
in scope for this ADR.

### 5. Hardening config — typed columns (not JSONB)

Phase 1 hardening toggles are stored as **typed boolean/enum columns** in
`site_security_hardening_config`, NOT as a JSONB blob. Rationale:

- Each toggle has a named semantic that is stable (the agent engineer maps it
  to a specific hook or define). A typed column makes the contract explicit and
  prevents drift between Go and PHP definitions.
- Postgres enforces NOT NULL + DEFAULT at the column level, giving safe
  defaults with no application-level unmarshalling.
- Adding a new toggle is a migration + one column; removing a deprecated one
  is a `DROP COLUMN IF EXISTS` — both are straightforward.
- JSONB would give flexibility at the cost of correctness: a misspelled key
  silently becomes a no-op rather than a compile/parse error.

The enum columns (`xmlrpc_mode`, `restrict_rest_api`, `restrict_login_identifier`)
are stored as `text` with a CHECK constraint, matching the existing `mode` column
on `site_security_config`.

### 6. Ban list table

`site_security_bans` stores durable per-site IP/CIDR/user-agent bans. The
ban list is included in the `sync_security_hardening` push so the agent receives
both hardening config and bans in one atomic command.

Design choice: `site_id` is NOT NULL (bans are per-site in v1). Fleet-wide
bans are a Phase 5 / network-brute-force enhancement.

IP/CIDR values are validated at write time (`net.ParseIP` / `net.ParseCIDR`).
Duplicates (same `site_id`, `type`, `value`) are rejected with a `409 Conflict`
domain error before reaching the DB.

### 7. Agent-side follow-ups (out of scope for Phase 1 CP build)

The following are action/report verbs — not config — and belong in the agent
step:

- **Salts regeneration** — one-time action, not a stored toggle; the agent
  runs it and reports back.
- **File permissions audit** — read-only scan; the agent runs it and pushes
  the result.
- **DB prefix change** — destructive DDL; orchestrated as a task, not config.

These are noted as Phase 1 agent-side follow-ups and will be tracked in the
agent specialist's brief.

---

## Consequences

- Every Phase N table must follow the m13/m36 RLS template (ENABLE + FORCE +
  `_tenant_isolation` policy + `_agent` policy + WITH CHECK on both).
- The `sync_security_hardening` contract is the handoff point for the agent
  engineer. The CP ships the contract definition; the agent implements the
  receiver.
- A new `PermSecurityManage` permission gates the hardening config and ban
  list routes at `Operator+`, consistent with `PermSiteCacheManage` and
  `PermEmailManage`.
- The Phase 1 handler lives in the existing `internal/security` package
  (handler.go, service.go, repo.go extensions) so the domain stays in one
  place and the existing security handler's `Register` call site continues
  to work.
