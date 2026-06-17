# ADR-045 Appendix B — Pluggable Alert Channels (Phase 4, LATER)

> Companion to [ADR-045](./ADR-045-email-auth-alerts.md). Design-only; built after Phases 1-3 ship.

# WPMgr Pluggable Alert System — Phase 4 Design (LATER)

Status: DESIGN ONLY. Phase 4 implements this. Do not build now.
Owner area: `apps/api/internal/alerts` (new), extending `apps/api/internal/uptime`.
Supersedes: nothing. Generalizes the M5/ADR-037 single-config, two-channel alert path.

---

## 1. Goals & non-goals

**Goals**
- Turn today's single `alert_configs` row + two hard-coded channels (email, one webhook) into a pluggable, multi-channel, multi-event alert system.
- One `Channel` interface in Go with `Email`, `Telegram`, `Slack`, `Webhook` implementations.
- A flat, deliberately-not-Alertmanager data model: channels + rules + deliveries (+ a small dedup/throttle state table).
- Routing, de-dup, throttle, and optional digest to prevent floods.
- Per-org (tenant) scoping under existing RLS, RBAC-gated, honoring site-scoped collaborators.
- "Test fire" affordance reusing the real delivery path.
- SSRF-hardened egress for user-supplied Telegram/Slack/webhook destinations.
- Extend `/alerts` (under Insights, next to Uptime), not a parallel UI. (Originally drafted as `/settings/alerts`; moved out of Settings in 0.51.1.)

**Non-goals (explicitly out of scope vs. Alertmanager)**
- No recursive routing tree, no `continue`/match_re label matching engine.
- No inhibition rules (alert A mutes alert B).
- No label-based grouping engine (we use a single `dedup_key` string).
- No silences UI in Phase 4 (a `suppressed_until` column exists for throttle; user-facing silences are a later phase).

---

## 2. Where this plugs into existing wiring (extend, don't duplicate)

The codebase already has the embryo of this system. The design reuses every seam:

| Existing thing | What it is today | What Phase 4 does with it |
|---|---|---|
| `alert_configs` (M5, `migrations/20260528060000_m5_uptime_alerts.sql`) | One row/tenant: `email_recipients[]`, `webhook_url`, `webhook_secret`, `enabled`, `notify_security` | KEEP for back-compat; **backfill** into `alert_channels` rows + one seeded `alert_rule`. Eventually deprecate, not drop. |
| `site_alert_state` (M5) | Per-site flap suppressor: `last_status`, `consecutive_down`, `in_incident`, `last_alert_at` | Generalize its semantics into `alert_state` keyed by `dedup_key`. The uptime probe keeps using `site_alert_state` for its up/down state machine; it then emits an `alert_event` instead of calling the dispatcher directly. |
| `uptime.Dispatcher` (`internal/uptime/alerts.go`) | Single fan-out point: `Fire()` (uptime down/recovery), `FireSecurityEvent()` (activity high-sev) | Becomes the **evaluator + fan-out over N channels**. Keep it as the only fan-out point; it loops over a tenant's matched channels instead of two hard-coded fields. |
| `Mailer` / `WebhookPoster` interfaces (`internal/uptime/notify.go`) | `SMTPMailer`/`NoopMailer`, `SSRFWebhookPoster` (HMAC-SHA256, `X-WPMgr-Signature`) | Become two `Channel` implementations. The SMTP mailer and the SSRF webhook poster are reused verbatim under the new interface. |
| `activity.SecurityAlerter` seam + `siteadapter.go` adapter | Activity ingest calls `NotifySecurity()` after tx commit | Stays as a seam, but the adapter now writes an `alert_event` (`event_type=security.<type>`) rather than calling `FireSecurityEvent` inline. Every NEW source (backup, scan, login-protection, php-error) gets the same thin adapter — no source package imports `uptime`. |
| `/alerts` (`apps/web/.../alerts.tsx`, `alert-config-form.tsx`) | Email recipients textarea + webhook URL only | Reframed into a multi-channel, multi-rule, delivery-log UI under a new `features/alerts` area. Route moved from `/settings/alerts` to `/alerts` (Insights group) in 0.51.1. |
| OpenAPI `/api/v1/alert-config` (`packages/openapi/openapi.yaml`) | `AlertConfig`/`AlertConfigUpdate` | Add `/api/v1/alert-channels`, `/alert-rules`, `/alert-deliveries`, `/alert-channels/{id}/test`. Regen ogen + hey-api per the codegen pipeline. |
| `httpclient` (`internal/httpclient/httpclient.go`) | SSRF-hardened `*http.Client`, dial-time IP pinning via `code.dny.dev/ssrf`, `Do`/`DoOnce`, `IsSSRFBlocked` | The egress client for ALL channel sends. No new SSRF code. |
| age-at-rest (`internal/sitedestination/service.go`, `AgeIdentity`) | `Encrypt/Decrypt` X25519, `*_enc bytea` columns | The secret-at-rest pattern for bot tokens / Slack tokens-or-URLs / webhook signing secrets. Extract `AgeIdentity` into a shared `internal/cryptbox` package so both `sitedestination` and `alerts` depend on it. |
| River queue (`startRiver()` in `cmd/wpmgr/main.go`) | Durable job queue | One `alert_dispatch` job + reuse of a `send_email`-style worker for retryable delivery. |

**Bridge step (proof of the model, do first in Phase 4):** wire the already-dark `notify_security` end-to-end (add to OpenAPI, fix `putAlertConfig` to persist it, add the UI toggle). This validates the full path on existing tables before any new channel type lands.

---

## 3. Event taxonomy

Events are normalized into a single typed `event_type` string namespaced by domain, plus a 3-tier `severity` (`low|medium|high`) that **reuses the activity model's existing scale** (`apps/agent/.../class-activity-log.php` already tags `meta.severity`). Producers write an `alert_event`; they never format channel payloads.

| Domain | `event_type` examples | Severity source | Producer (existing source → new adapter) |
|---|---|---|---|
| **activity** | `activity.post.updated`, `activity.user.created`, `activity.plugin.activated`, `activity.option.updated`, `activity.core.updated` | agent `meta.severity` (low/med) | `internal/activity` ingest → `alertsource` adapter |
| **security** | `security.user.deleted`, `security.user.role_changed`, `security.plugin.deleted`, `security.login_failed`, `security.option.updated` (admin_email/siteurl/home/users_can_register/default_role) | agent `OPTION_HIGH_SEVERITY` = `high` | same activity ingest, high-severity slice (security IS the high slice of activity, not a separate table) |
| **logs** | `logs.error.fatal`, `logs.error.warning`, `logs.error.deprecation` | diagnostics `error_events.severity` column | `internal/diagnostics` PHP error monitor → adapter (currently NOT wired) |
| **uptime** | `uptime.down`, `uptime.recovery` | derived: `down`=high, `recovery`=info | `internal/uptime` probe worker (already has `site_alert_state` state machine) |
| **backup** | `backup.failed`, `backup.succeeded`, `restore.completed`, `restore.failed` | `failed`=high, `succeeded`/`completed`=low | `internal/backup` worker → adapter (currently NOT wired) |
| **(meta)** | `test.fire` | low | the test endpoint only; flagged `is_test=true` |

Notes:
- `event_type` is a free string by convention `domain.subject.verb`; rules subscribe by **prefix or exact** (see §5). This keeps taxonomy additive — a new agent hook needs zero schema change.
- Three pre-existing streams stay distinct and are NOT merged: **activity** (hash-chained `agent_activity_log`), **uptime** (time-series + `site_alert_state`), and the internal **audit log** (`internal/audit`, forensic, NEVER delivered as an alert). The audit log gains entries for alert lifecycle (`alert.channel.changed`, `alert.rule.changed`, `alert.delivery.sent`, `alert.test.fired`) but is not itself an event source.

---

## 4. Channel interface (Go)

A single interface, three+1 implementations, each built from a *decrypted typed config* by a factory so the service layer only sees plaintext secrets at construction time.

```go
// internal/alerts/channel.go

// Message is channel-agnostic. Each Channel renders it into its native shape.
type Message struct {
    Event      string            // event_type, e.g. "uptime.down"
    Severity   string            // low|medium|high
    Title      string            // "Site down: blog.example.com"
    Body       string            // plaintext body (safe default everywhere)
    SiteID     *uuid.UUID
    SiteURL    string
    OccurredAt time.Time
    Links      []Link            // {Label, URL} — dashboard deep-links
    Fields     []KV              // {Key, Value} — structured detail
    IsTest     bool
    DedupKey   string
    EventID    uuid.UUID         // stable id for idempotency / X-WPMgr-Id
}

type Result struct {
    HTTPStatus int    // upstream status where applicable
    Provider   string // "telegram" | "slack" | "webhook" | "email"
    Detail     string // scrubbed, safe-to-store summary
}

type Channel interface {
    Kind() string                                  // "email"|"telegram"|"slack"|"webhook"
    Send(ctx context.Context, m Message) (Result, error)
}

// Factory: service never holds plaintext secrets except here.
func New(kind string, cfg DecryptedConfig, deps Deps) (Channel, error)

type Deps struct {
    HTTP   *httpclient.Client // SSRF-hardened, shared
    Mailer uptime.Mailer      // reused SMTPMailer/NoopMailer
    Logger *slog.Logger
}
```

`Send` errors are returned (River retries); `Send` MUST NOT panic and MUST honor `ctx` deadline. The `NotificationService` fan-out wraps each `Send` in its own goroutine + per-channel timeout, aggregating results — **one dead channel never blocks the others**.

### 4.1 Config fields & secrets-at-rest per channel

Each channel persists a typed config. Secret fields are **age-encrypted** into a `*_enc bytea` column (reuse `cryptbox.AgeIdentity.Encrypt/Decrypt`). The API returns only `kind` + label + redacted hints; secrets are **write-only** (overwrite only on non-empty submit; never echoed back — mirror `site_destinations.secret_key_enc` nil-sentinel UPDATE).

**EmailChannel**
- Config: `recipients []string` (≤50, validated addresses).
- Secrets: none at the channel level. Uses the instance/per-tenant SMTP relay (the SMTP credential lives in the separate SMTP-settings feature, not here).
- Transport: reuse `uptime.SMTPMailer`; `NoopMailer` when SMTP host is empty (degrade gracefully). Always send plaintext + (later) HTML multipart via the shared template.

**TelegramChannel**
- Config: `chat_id string` (negative for groups, `-100…` for supergroups).
- **Encrypted secret:** `bot_token_enc bytea` (one bot token at integration level).
- Endpoint (fixed host): `POST https://api.telegram.org/bot<token>/sendMessage`, body `{chat_id, text, parse_mode:"MarkdownV2", disable_web_page_preview:true}`.
- Rendering: `escapeMarkdownV2()` over the 18 reserved chars; prefer plain text + entities. On HTTP 429, honor `parameters.retry_after` exactly (not blind backoff).
- Onboarding: user DMs the bot / adds it to the group first; "Verify connection" calls `getUpdates` (or sends a test) to resolve/confirm `chat.id`.

**SlackChannel** (two modes)
- Mode `webhook` (default): **encrypted secret** `webhook_url_enc bytea` (the URL *is* the credential). `POST` Block Kit `{text, blocks}`; success = literal body `ok` with 200.
- Mode `bot_token` (power users): **encrypted secret** `bot_token_enc bytea` (`xoxb-…`, needs `chat:write`) + `channel string`. `POST https://slack.com/api/chat.postMessage`, `Authorization: Bearer …`; success = JSON `{ok:true}`.
- Always set top-level `text` as the notification fallback even with `blocks`.

**WebhookChannel** (the SSRF-critical one)
- Config: `url string` (plaintext but **validated**), `custom_headers map[string]string` (no `Authorization` unless explicitly set), `insecure_allow_http bool` (default false).
- **Encrypted secret:** `signing_secret_enc bytea` (whsec-style).
- Payload: versioned envelope `{schema_version:"1", id, type, occurred_at(RFC3339), tenant_id, site_id, data{…}}`, `data` additive-only.
- Signature: HMAC-SHA256 over `"<unix_ts>.<raw_body>"`; headers `X-WPMgr-Signature: t=<unix>,v1=<hex>`, `X-WPMgr-Id`, `X-WPMgr-Event`. Receiver verifies over RAW body with `hmac.Equal`, rejects `|now−t|>300s`, dedupes on id. Support two active `v1` signatures during secret rotation.

All four route outbound HTTP through the shared `httpclient.Client` (uniform retries/tracing); Telegram/Slack are fixed-host but still go through it so a future refactor can't let a user override the base URL.

---

## 5. Data model (5 tables, deliberately simpler than Alertmanager)

All tables tenant-scoped, RLS `tenant_isolation` on `app.tenant_id`, plus the dual `app.agent` SELECT-escape policy so the cross-tenant evaluator can enumerate (exactly like `ListAlertConfigsAllTenants` does today). Migrations are idempotent Atlas-diffed SQL with hand-appended RLS; mirror `schema.sql` and run sqlc/ogen/hey-api regen.

**(1) `alert_channels`** — destinations (generalizes `alert_configs`’s two fields into N named rows)
```
id uuid pk, tenant_id uuid fk, type text check in ('email','telegram','slack','webhook'),
name text, enabled bool default true,
config jsonb,                  -- non-secret typed config (recipients, chat_id, url, mode, channel, headers...)
secret_enc bytea null,         -- age-encrypted secret (bot token / slack url-or-token / webhook signing secret)
verified_at timestamptz null,  -- set on successful test-fire
created_at, updated_at
UNIQUE(tenant_id, type, name)
```

**(2) `alert_rules`** — maps event-type + severity + filter → channel set
```
id uuid pk, tenant_id uuid fk, name text, enabled bool default true,
event_types text[],            -- exact or prefix match, e.g. {'uptime','security','backup.failed'}
min_severity text check in ('low','medium','high'),
scope jsonb,                   -- {mode:'all'} | {mode:'sites', site_ids:[...]} | {mode:'tags', tags:[...]}
channel_ids uuid[],            -- which channels fire
throttle_seconds int default 0,
digest_window_seconds int null,-- null = send immediately (phase-2 feature)
priority int default 100,      -- lower evaluated first
created_at, updated_at
```

**(3) `alert_deliveries`** — per-channel attempt log (audit trail + retry ledger + idempotency guard)
```
id uuid pk, tenant_id uuid fk,
event_id uuid, rule_id uuid null, channel_id uuid fk,
status text check in ('pending','sent','failed','throttled','digested'),
attempts int default 0, last_error text null, response_code int null,
is_test bool default false,
requested_at timestamptz, delivered_at timestamptz null
UNIQUE(event_id, channel_id)   -- idempotency: one delivery per (event, channel)
```

**(4) `alert_state`** — dedup / throttle / incident memory (generalizes `site_alert_state`)
```
dedup_key text,
tenant_id uuid fk,
rule_id uuid null,
status text check in ('firing','resolved') default 'firing',
open_since timestamptz, last_notified_at timestamptz null,
notify_count int default 0, suppressed_until timestamptz null
PRIMARY KEY (tenant_id, dedup_key)
```

**(5) `alert_events`** — normalized incoming signal (optional persistence; can be ephemeral if you only need state+deliveries, but persisting gives a clean producer contract and a replay source)
```
id uuid pk, tenant_id uuid fk, event_type text, severity text,
site_id uuid null, dedup_key text, title text, body text,
payload jsonb, occurred_at timestamptz, is_test bool default false
```

This is **5 tables vs. Alertmanager's tree + grouping + inhibition + silence machinery**: one `dedup_key` string replaces fingerprints+group_by; one `throttle_seconds` replaces the three coupled timers; an optional `digest_window_seconds` replaces group_wait/group_interval.

---

## 6. Routing, de-dup, throttle, digest

### 6.1 Routing — linear, first-match-wins per channel (no tree)
On an `alert_event E`:
1. Load enabled `alert_rules` for `E.tenant_id` ordered by `priority` ASC.
2. For each rule `R`: include `R.channel_ids` iff
   `E.event_type` matches one of `R.event_types` (exact or `domain.` prefix) **AND** `severity(E) ≥ R.min_severity` **AND** `E.site_id` passes `R.scope` filter.
3. **First-match-wins per channel**: a channel already added by a higher-priority rule is not re-added (simple analogue of `continue=false`).
4. The union of matched channels is the route. O(rules), trivially previewable in the UI ("this event would notify Slack + Email").

### 6.2 De-dup — by `dedup_key` (edge-triggered, not per-event)
Producers compute a stable key:
- uptime → `site_id + ':down'`
- security → `site_id + ':' + event_type + ':' + actor`
- backup → `site_id + ':backup_failed'`
- activity → `site_id + ':' + event_type`

On ingest, upsert `alert_state` by `(tenant_id, dedup_key)`. **Only a state change** (`resolved→firing` or `firing→resolved`) triggers notification — identical repeats while already `firing` do not open a new incident (this is `site_alert_state.in_incident`, generalized; mirrors Alertmanager edge-triggering and Kuma up/down transitions). Recovery (`firing→resolved`) emits a recovery notification to the **same channels** that got the open alert, then resets `suppressed_until`.

### 6.3 Throttle — one interval (replaces repeat_interval / Sentry action-interval)
On notify: set `last_notified_at` and `suppressed_until = now + R.throttle_seconds`. Further events on that key while suppressed are counted (`notify_count++`) and logged as `status=throttled`, NOT delivered, **unless** severity escalates (escalation re-notifies **at most once per key**, then re-arms the throttle — escalation cannot be abused to defeat the cap) or status flips to `resolved`.

### 6.4 Digest — optional, opt-in, phase-2 of Phase 4 (replaces group_wait/group_interval)
If `R.digest_window_seconds` is set, matched events are buffered (`status=digested`) and a scheduled River job flushes one combined message per `(channel, rule)` per window (e.g. "3 plugin updates + 1 failed login in the last 15m on site X"). A short window doubles as `group_wait` (lets a burst coalesce before first send). **Default = throttle-only, no digest;** offer digest on noisy channels (email).

### 6.5 Delivery & retry
One River `alert_dispatch` job per `(event, channel)`. Reuse the `send_email`-style durable worker so SMTP/HTTP flakiness is retried by River, not silently logged. Transient (`408/425/429/5xx`, timeout) → backoff retry up to N, honoring `Retry-After`/`retry_after`; permanent (`4xx` except 429) → `failed`, and after M consecutive 4xx auto-disable + flag the channel in the UI. The `UNIQUE(event_id, channel_id)` constraint makes retries idempotent.

---

## 7. Per-org scoping & RBAC

- Every table carries `tenant_id` under the restrictive `app.site_scope` / RLS regime already used across ~24 tables (m19). Channels, rules, state, deliveries are tenant-isolated. The cross-tenant evaluator runs under the `app.agent` GUC, SELECT-only on config tables (exactly like the existing uptime evaluator), and MUST NOT leak channel secrets across tenants.
- **RBAC (add to `authz/role.go` `minRoleFor` + `orgLevelPerms` in `middleware.go`):**
  - `PermAlertManage` (write channels/rules) → **admin** (parity with member/apikey management; these tables hold credentials, so owner-only is a defensible alternative — pick admin to match `alert-config` today, gate the *secret* fields no looser than admin).
  - `PermAlertRead` (render forms with secrets redacted, read deliveries) → **viewer/operator**, but `alert_deliveries` GET is gated like the activity log because bodies can contain IPs/usernames/paths.
  - Add both to `orgLevelPerms` so **site-scoped collaborators are hard-blocked** from org-level alert config.
- **Site-scoped collaborators:** a rule's `scope.site_ids` MUST be authorized against `canReadSite` **at write time AND re-checked at evaluation time**, so a shared-site collaborator can neither route a site they don't own nor exfiltrate another org's events into their own channel. Every by-id channel/rule/delivery route gates with `RequireSiteAccess`/`canReadSite` where a site id is present.
- Routes mount on the `/api/v1` group (already `RequireAuth()+RequireTenant()`), plus `RequireOrgScope()` on the channel/rule collection routes.

---

## 8. Test-fire affordance

`POST /api/v1/alert-channels/{id}/test` (gated `PermAlertManage`, tenant-owned, rate-limited per channel + per user):
- Synthesizes a fake `alert_event` (`event_type=test.fire`, `severity=low`, `is_test=true`) and runs it **straight through the channel's real `Send()` path** — the single most valuable property (same code, surfaces real config errors: bad Slack webhook, wrong Telegram chat_id, dead SMTP relay).
- **Bypasses rules/dedup/throttle by design.** Writes a real `alert_deliveries` row tagged `is_test=true`.
- On success, stamps `alert_channels.verified_at` and records audit `alert.test.fired`.
- The synthesized event is clearly `is_test` so it can never be confused with a real security alert. Errors are scrubbed (see §9) before display/store. Test-fire is an abuse/spam vector → rate-limited and tenant-ownership-checked.

---

## 9. SSRF mitigations (user-supplied Telegram / Slack / webhook destinations)

The generic webhook URL is fully attacker-influenced; treat it as untrusted even though only an admin can set it.

1. **Validate the RESOLVED IP at DIAL time, every send** — route ALL outbound sends through `internal/httpclient` (`code.dny.dev/ssrf` `net.Dialer.Control = guardian.Safe`). This pins the resolved IP at connect and defeats DNS-rebinding/TOCTOU: a host that resolves public at save time but to `169.254.169.254` / `127.0.0.1` / `10.x` at send time is blocked atomically. Do **not** validate the hostname string at save and then trust it.
2. **Denylist (by resolved IP, canonicalized CIDR compare, not string match):** `169.254.169.254` + `169.254.0.0/16` (cloud metadata + link-local), `127.0.0.0/8` + `::1`, `10/8` + `172.16/12` + `192.168/16` + `fc00::/7`, `0.0.0.0/8`, the instance's own IPs. Reject IPv4-mapped IPv6 (`::ffff:127.0.0.1`), decimal/octal/encoded IP forms — handled by canonical-IP parsing in the ssrf guard, not regex.
3. **Scheme/host allowlist on top:** require `https://` (reject `http://` unless explicit `insecure_allow_http` opt-in, never to non-loopback), reject embedded credentials, non-standard ports, and metadata hosts. Cap redirects (the guard re-checks each hop; also limit count). `io.LimitReader` the response (we need status, not body).
4. **Test-fire uses the IDENTICAL validation + connection path** — a test path that skips validation is an SSRF bypass. It uses the just-submitted-or-stored config but never returns the secret.
5. **Safe errors:** return the upstream SMTP/HTTP error so admins can debug, but scrub internal IPs/hostnames/timing (mirror `friendlyS3Error`) so the tester isn't a port-scan/SSRF oracle. Rate-limit it.
6. **Fixed-host channels** (Telegram `api.telegram.org`, Slack `hooks.slack.com`/`slack.com`) still go through the hardened client; base URLs are never templated from user free-text.
7. **Defense in depth:** rely on the Cloud Run egress posture too; never solely on app-layer checks.
8. **Outbound HMAC + replay window** for the webhook (`t=…,v1=…`, `±5min`) so the receiver can authenticate us; constant-time `hmac.Equal` everywhere a MAC is compared.
9. **Log redaction:** scrub bot tokens / Slack URLs / signing secrets from `slog`, error strings, and the persisted `alert_deliveries.last_error` (Telegram/Slack error bodies can echo request fragments). Decrypt secrets only in the service layer at Send/Test time.

---

## 10. UI — extend `/alerts`

Relocate alert config from `features/monitoring` (uptime-coupled, page copy is "downtime alerts" only) into a new `features/alerts` area; reframe the page from uptime-only to **multi-channel, multi-event routing**. Follow existing settings conventions (PageHeader + shadcn Cards, rhf + zod + `zodResolver` cast where coerce/optional is used, `FormSection`/`FieldError`/`StickySaveBar`, `toast.*`, role gating via `canManage(me)`). The route lives at `/alerts` under the Insights sidebar group (moved from `/settings/alerts` in 0.51.1).

**`/alerts` becomes three sections (Cards):**
1. **Channels** — table of `alert_channels` (type icon, name, redacted hint, `verified_at`, enabled toggle). Create/edit dialog per type with type-specific fields (§4.1). Secret fields are write-only with a masked placeholder ("•••• leave blank to keep"). A **"Send test"** button per channel hits the test endpoint and toasts the scrubbed result. Empty state prompts adding the first channel; the legacy backfilled email/webhook channels appear here automatically.
2. **Rules** — list of `alert_rules` ordered by priority. Editor: name, event-type multiselect (grouped by domain: activity/security/logs/uptime/backup), `min_severity` select, scope (all sites / pick sites — site picker filtered by `canReadSite`), channel multiselect, throttle, optional digest window. A live **"this rule would notify: Slack + Email"** preview (the O(rules) routing is cheap to evaluate client-or-server-side).
3. **Delivery log** — paginated `alert_deliveries` (event, channel, status badge `sent/failed/throttled/digested`, response code, time), with a manual re-send action and `is_test` filter. Gated like the activity log (bodies can contain sensitive detail).

Sidebar/nav: a single `Alerts → /alerts` entry under the Insights group (next to Uptime). The page header copy changes from "Configure how this tenant is notified when monitored sites go down" to multi-event framing. New endpoints either get added to OpenAPI (regen ogen + `pnpm -C packages/openapi-client generate`) or use the hand-rolled raw-`client` hook pattern; new feature hooks live in `src/features/alerts/use-alerts.ts` mirroring `use-api-keys.ts`.

---

## 11. Migration path (Phase 4 sequencing)

1. **Bridge:** wire `notify_security` end-to-end on existing tables (proves the path; fixes the dark column).
2. Extract `AgeIdentity` → `internal/cryptbox`; add a production startup guard that refuses to boot without a stable age secret (model on `ValidateSessionSecret`).
3. Add `alert_channels` + backfill each tenant's `email_recipients[]` → one email channel and `webhook_url`+`webhook_secret` → one webhook channel. Keep `alert_configs` working.
4. Add `alert_rules`; seed each tenant's `notify_security` → a rule `{event_types:[security], min_severity:high, channels:[backfilled]}`.
5. Add `alert_state` (generalize `site_alert_state` key → `dedup_key='site:down'`), `alert_deliveries`, `alert_events`.
6. Repoint the uptime probe and the activity high-severity hook at the new evaluator via thin `alertsource` adapters; add adapters for backup/scan/logs (no source package imports `uptime`).
7. Add the `alert_dispatch` River job + durable delivery worker.
8. Ship the `features/alerts` UI + test-fire.

---

## 12. Open questions for Phase 4

- **Owner vs. admin** for `PermAlertManage` — these tables store credentials. Default to admin for parity; consider owner-only for the secret-bearing writes.
- **Persist `alert_events` or keep ephemeral?** Persisting eases producer contract + replay; costs storage + retention policy. Recommend persist with a retention window mirroring the activity log.
- **Digest** is marked opt-in/phase-2 — confirm whether email-only digest is enough for v1.
- **SMTP source for `EmailChannel`** depends on the separate per-tenant SMTP-settings feature landing; until then `EmailChannel` uses the instance `WPMGR_SMTP_*` relay and `NoopMailer` when unset.

---

Relevant existing files Phase 4 will touch (all absolute):
`/Users/mosamgor/Desktop/Terminal/wpmgr/apps/api/internal/uptime/alerts.go`, `/Users/mosamgor/Desktop/Terminal/wpmgr/apps/api/internal/uptime/notify.go`, `/Users/mosamgor/Desktop/Terminal/wpmgr/apps/api/internal/uptime/model.go`, `/Users/mosamgor/Desktop/Terminal/wpmgr/apps/api/internal/uptime/handler.go`, `/Users/mosamgor/Desktop/Terminal/wpmgr/apps/api/internal/activity/service.go`, `/Users/mosamgor/Desktop/Terminal/wpmgr/apps/api/internal/sitedestination/service.go`, `/Users/mosamgor/Desktop/Terminal/wpmgr/apps/api/internal/httpclient/httpclient.go`, `/Users/mosamgor/Desktop/Terminal/wpmgr/apps/api/cmd/wpmgr/main.go`, `/Users/mosamgor/Desktop/Terminal/wpmgr/apps/api/cmd/wpmgr/siteadapter.go`, `/Users/mosamgor/Desktop/Terminal/wpmgr/apps/api/db/schema.sql`, `/Users/mosamgor/Desktop/Terminal/wpmgr/apps/api/migrations/20260528060000_m5_uptime_alerts.sql`, `/Users/mosamgor/Desktop/Terminal/wpmgr/apps/api/internal/authz/role.go`, `/Users/mosamgor/Desktop/Terminal/wpmgr/apps/api/internal/authz/middleware.go`, `/Users/mosamgor/Desktop/Terminal/wpmgr/packages/openapi/openapi.yaml`, `/Users/mosamgor/Desktop/Terminal/wpmgr/apps/web/src/routes/_authed/settings/alerts.tsx`, `/Users/mosamgor/Desktop/Terminal/wpmgr/apps/web/src/features/monitoring/alert-config-form.tsx`.