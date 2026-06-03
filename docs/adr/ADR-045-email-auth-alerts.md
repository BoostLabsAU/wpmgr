---

# ADR-045: UI-Configured SMTP Email, Self-Serve Auth Flows, and Alert Channels

- **Status:** Accepted (2026-06-02) — building
- **Date:** 2026-06-02
- **Deciders:** Lead architect (this ADR), product owner (decisions in §0)
- **Supersedes/extends:** ADR-029 (instance-global SMTP), ADR-037 (alert delivery seam)
- **Milestones:** m30 (Phase 1), m31 (Phase 2), m32 (Phase 3), m33+ (Phase 4, later)
- **Target:** Unblock pre-alpha — real users self-serve sign up + verify email, reset password, and change password; an admin configures SMTP in the UI instead of env vars.

---

## 0. Locked Decisions (product owner, 2026-06-02)

These resolve §11's open questions and are **binding** over any contradicting prose below.

1. **SMTP scope = instance-level**, owner-configured in the UI (one relay per install; nothing in env beyond the bootstrap fallback per §2.3 / addendum G11). Per-org sending deferred to Phase 4 alert channels.
2. **Onboarding = OPEN self-serve signup** (not invite-only). This **changes Phase 3**: `POST /auth/register` must accept ANY new email (create the user **plus their own Default tenant + owner membership**, not just the first admin), be **enumeration-safe** (always return a generic `200`; **never** establish a session at register time), and require **email verification** to activate the account. A new `email_verification_tokens` flow (same hashed/TTL/single-use token machinery as password reset, purpose=`verify_email`) sends a verify link on register; clicking it sets `users.email_verified_at` + `status='active'`. **Login requires `status='active'`** (verified) for password accounts; OIDC accounts are verified by the IdP assertion. Admin **invites coexist** (reuse `internal/invitation`); an invited user who sets a password via `/accept` is created already-verified. Bootstrap/env-seed/CLI admins are verified (trusted by shell access).
3. **Hosted email = operator configures SMTP themselves**; nothing baked in. The SMTP settings page shows static SPF/DKIM/DMARC/PTR guidance. No default relay credentials are committed.
4. Defaults accepted from §11: passwords **min 12** everywhere; session invalidation via **`users.password_changed_at`** + reject-older-sessions in `middleware/auth.go` (the only portable option — addendum G3); reset TTL **30 min**, verify/invite TTL **7 days**; reuse **`WPMGR_SITE_DEST_AGE_SECRET`**; **hand-rolled checked-in `html/template`** emails (no MJML/Node build step in the API binary — supersedes §2.2's MJML pipeline; the email HTML in Appendix A is used directly as templates); SMTP dial routed through the existing `httpclient` ssrf guardian via go-mail `WithDialContextFunc` (addendum G5); env SMTP is a one-shot seed that writes the encrypted DB row then defers to it (addendum G11, option a).

---

## 1. Context & Goals

### 1.1 Where we are today

WPMgr is a modular-monolith Go control plane (`apps/api`) plus a React 19 / Vite / TanStack web app (`apps/web`) and a marketing site (`apps/landing`). Authentication is an **opaque server-side session** (SCS over Redis, cookie `wpmgr_session`), **not** a JWT; passwords are `argon2id` (`apps/api/internal/auth/password.go`, 19 MiB OWASP profile). Auth routes are mounted on the **root** Gin engine at `/auth/*` (`apps/api/internal/auth/handler.go:37`, `apps/api/internal/server/server.go:167`), while feature routes live under `/api/v1` (gated by `RequireAuth()` + `RequireTenant()`).

Three pre-alpha-blocking gaps exist:

1. **No password reset.** `grep` for reset/forgot returns nothing. An email/password user who forgets their password and is not on SSO is permanently locked out.
2. **No self-serve activation / email-verified invites.** The first admin is created by an open, self-gating `Bootstrap()` (`apps/api/internal/auth/service.go:124`, succeeds only when `CountUsers()==0`), with an immediate session and zero email. Org-member invites use the legacy `auth.Service.Invite` (password-in-body, no token, no email) — and the members UI is already coded for a token/link flow the backend does not implement. A complete tokenized `invitations` table + `/accept` page + `invitation.CreateOrgInvitation` exist but the org path is **dead code** (no HTTP route).
3. **SMTP is instance-global env-only.** `config.SMTPConfig` (ADR-029, `WPMGR_SMTP_*`) is consumed by the uptime dispatcher and invitation mailer; there is no DB row, no UI, no per-tenant override, and the comment scopes it to "downtime/recovery alert emails."

The proven secret-at-rest pattern is **age X25519** (`apps/api/internal/sitedestination/service.go` — `AgeIdentity.Encrypt/Decrypt` → `bytea`, key from `WPMGR_SITE_DEST_AGE_SECRET` via `os.Getenv` at `main.go:357`). The proven SSRF-hardened egress client is `apps/api/internal/httpclient/httpclient.go` (`code.dny.dev/ssrf`, dial-time IP pinning, `IsSSRFBlocked`). The background queue is **River v0.38.0** (`*river.Client[pgx.Tx]`), with all workers registered in `startRiver()` (`apps/api/cmd/wpmgr/main.go`). Public links are built from `WPMGR_PUBLIC_BASE_URL` (`main.go:772`).

### 1.2 Goals

- **G1** — An owner configures SMTP **in the UI** (host/port/username/password/from/TLS mode), stores the password encrypted at rest, and can **send a test email**, without touching env vars.
- **G2** — A user who forgets their password can **self-serve reset** it; any logged-in user can **change** their password.
- **G3** — A new user can be **invited by email** and **activate** by setting their own password via a tokenized link; the first-run admin remains frictionless and SMTP-independent.
- **G4** (later) — Generalize the single hard-coded `alert_configs` channel pair into pluggable **alert channels** (email/webhook/Slack/Telegram) with rules + delivery log.

### 1.3 Non-goals (this ADR)

- No 2FA / step-up auth (machinery does not exist; out of scope).
- No GCP Secret Manager / KMS Go client (age via Cloud-Run-injected env stays the boundary).
- No live DNS deliverability validation (SPF/DKIM/DMARC live checks) — static help panel only.
- No session-secret rotation / multi-key support.
- No CSRF token system swap — we keep `SameSite=Lax` and add `current-password` proof + Origin checks (see §7).

---

## 2. Decisions

### 2.1 Go mail library — keep `github.com/wneessen/go-mail`

**Decision:** Use `github.com/wneessen/go-mail` (MIT, v0.7.3), already vendored and used in `apps/api/internal/uptime/notify.go`. No migration.

**Rationale:** It is the only candidate covering STARTTLS policy selection, implicit TLS on 465 (`WithSSLPort`), the full auth matrix, `context`/timeout (`WithTimeout`, `DialAndSendWithContext`), and HTML+plaintext multipart — and we already depend on it. `net/smtp` is too low-level; `jordan-wright/email` and `gopkg.in/gomail.v2` are stale. Select TLS by **`tls_mode`, not port**: `starttls` → `TLSMandatory`, `tls` → `WithSSLPort(true)`, `none` → `NoTLS`. Always send with a context + timeout so a hung user relay cannot block a worker.

### 2.2 Email templating — MJML → checked-in `html/template`, plaintext via go-premailer

**Decision:** Author emails in **MJML**, compile to **checked-in Go `html/template` files** at build time (turbo task), inject variables with `html/template` at send time, and derive the plaintext alternative at runtime with **`github.com/vanng822/go-premailer`** (`Transform`/`TransformText`). Templates ship in the binary via `embed.FS`. One shared MJML layout partial (header logo + footer). Pure-Go runtime — **no Node dependency in the API binary**.

**Rationale:** Bullet-proof Outlook table HTML with inlined CSS, a single shared layout, an auto-derived plaintext part, and a small runtime supply chain. `html/template` gives context-aware auto-escaping — critical because org names / user names / site URLs flow into bodies. Emails are 600px table layout, system-font stack, hex colors only (no `oklch`/`var()`), VML buttons, `color-scheme` meta + explicit dark variants (off-black/off-white, never pure `#000`/`#FFF`), teal brand `#1F9E97`. All links built from `WPMGR_PUBLIC_BASE_URL`. A CI check recompiles MJML and diffs the committed artifact so a stale template fails the build.

**Phase-1 pragmatic fallback:** Phase 1's only emails are the **test email** (and Phase 2's reset/changed/activation). To avoid blocking on the MJML toolchain, Phase 1 may ship a single hand-rolled layout template; the MJML pipeline lands in Phase 1's "infra" deliverable and all subsequent templates use it.

### 2.3 SMTP config storage — instance-level, admin/owner-only, new dedicated table

**Decision (v1):** Store SMTP as a **single instance-level row** in a new dedicated table **`smtp_settings`** (one row, enforced by a `singleton` boolean unique), **not** per-org. Editing is **owner-only** (new `PermSMTPManage` → `RoleOwner`); reads (to render the masked form) are admin+. The password is **age-encrypted** in a `bytea` column reusing the existing `AgeIdentity` pattern. The static `config.SMTPConfig` env vars remain the **boot/bootstrap fallback** when no DB row exists.

Table (canonical DDL in §3):

```
smtp_settings(
  id uuid pk,
  singleton boolean NOT NULL DEFAULT true,   -- UNIQUE(singleton) enforces one row
  enabled boolean NOT NULL DEFAULT false,
  host text NOT NULL DEFAULT '',
  port int NOT NULL DEFAULT 587,
  username text NOT NULL DEFAULT '',
  password_enc bytea NULL,                    -- age(X25519) ciphertext, never plaintext
  from_address text NOT NULL DEFAULT '',
  from_name text NOT NULL DEFAULT '',
  tls_mode text NOT NULL DEFAULT 'starttls'   -- CHECK in ('starttls','tls','none')
    CHECK (tls_mode IN ('starttls','tls','none')),
  allow_insecure_tls boolean NOT NULL DEFAULT false,
  updated_by uuid NULL REFERENCES users(id),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
)
```

**Rationale for instance-level (not per-org) in v1:**

- **Bootstrap correctness.** SMTP must exist before *any* org's password-reset/activation email can be sent. A self-hosted instance has exactly one mail relay; modeling it per-tenant means the first org's owner can't be invited/reset until *they* configure SMTP — re-introducing the chicken-and-egg this ADR is trying to remove. Reset/activation emails are sent by the **instance**, on behalf of users, before tenant context is even resolved (forgot-password is unauthenticated).
- **Operational reality.** A self-hosted deployment has one egress IP, one PTR/reverse-DNS, one DKIM-signing domain. Per-org SMTP gives no real isolation benefit at this stage and multiplies the deliverability surface (§ research: GitLab/Gitea/Mattermost are all instance-level).
- **Smaller blast radius & simpler RLS.** One row, no per-tenant credential sprawl, owner-only edit. It is genuinely instance infrastructure + a credential store — the same bucket as `PermTenantManage` (owner), not `PermMemberManage` (admin).
- **Forward-compatible.** Per-org SMTP (and SES/SendGrid API providers) can be added later as `alert_channels` rows (Phase 4) layered *over* the instance default, without re-platforming v1. The instance row remains the auth-email transport.

**Consequence:** Because `smtp_settings` is instance-global and not tenant-keyed, it does **not** get tenant-isolation RLS. It is protected entirely by route gating (`PermSMTPManage` + `RequireOrgScope()`), the same hand-rolled pattern `org/handler.go` uses for rename. The encrypted password and singleton row are read only by the mailer at send time and by the owner-only settings handler.

### 2.4 Token model — hashed, TTL'd, single-use, purpose-bound

**Decision:** Activation and password-reset tokens are **opaque high-entropy CSPRNG** values (`crypto/rand`, ≥128 bits → 32 url-safe base64 chars). Only **`sha256(token)`** is stored (a fast hash is correct and sufficient for high-entropy secrets — do **not** argon2/bcrypt them). Each token is **single-use**, **short-TTL**, and **bound to a specific user + purpose** so an activation token can never be replayed as a reset token. Lookup is by token hash (unique index) then constant-time compare; expiry + single-use are checked **atomically** in one `UPDATE ... WHERE used_at IS NULL AND expires_at > now()`. Issuing a new token of a purpose invalidates outstanding tokens of that purpose for that user.

This mirrors the existing `invitations` table (token_hash UNIQUE, expiry, attempts, single-use via atomic `MarkInvitationAccepted`) — we copy that proven model. TTLs: **password reset 30 min**, **activation/invite 7 days** (matches existing invitation default), **change-password notification** is informational only.

### 2.5 First-run bootstrap — keep frictionless, add SMTP-independent escape hatches

**Decision:** Keep the existing no-email-verification `Bootstrap()` (gated on `CountUsers()==0`) as the **primary** first-admin path — the bootstrap admin is trusted by deploy/shell access, not by email. Add two redundant, SMTP-independent escape hatches:

1. **Env-seeded admin** — when `CountUsers()==0` and `WPMGR_INIT_ADMIN_EMAIL` / `WPMGR_INIT_ADMIN_PASSWORD` are set, create the first user + Default tenant + owner membership at boot (idempotent; instruct rotate + delete env after first login).
2. **CLI escape hatch** — `wpmgr admin create` and `wpmgr admin reset-password <email>` that print a **single-use, 15-min** one-time login/reset link to **stdout** (emitted only on explicit CLI invocation, never on every boot). This is the lock-out recovery path that does **not** depend on SMTP.

**Activation policy:** the bootstrap admin is **not** email-verified (no SMTP exists yet). Activation/`email_verified` applies only to **invited** users (Phase 3). We add a nullable `email_verified_at timestamptz` to `users`; bootstrap and env-seed set it to `now()` (trusted), OIDC sets it when the IdP asserts `email_verified`, invited users set it on activation.

---

## 3. Data Model

All new tables live in `apps/api/db/schema.sql` (source of truth for sqlc + Atlas) with matching idempotent migrations under `apps/api/migrations/`. RLS ALTER/POLICY blocks are **hand-appended** (Atlas CE can't diff RLS), then `atlas migrate hash`.

### 3.1 `users` — add columns (m31/m32)

```sql
ALTER TABLE users ADD COLUMN IF NOT EXISTS email_verified_at timestamptz NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS status text NOT NULL DEFAULT 'active'
  CHECK (status IN ('active','pending','disabled'));
```

`status='pending'` represents an invited-but-not-activated account. RLS: unchanged — `users` is **not** tenant-scoped (membership is the join). No tenant-isolation policy added.

### 3.2 `smtp_settings` — instance-level (m30, Phase 1)

DDL per §2.3. **No tenant-isolation RLS** (instance-global). Gated by `PermSMTPManage` + `RequireOrgScope()`. Index: `UNIQUE(singleton)` (singleton constraint — only one row can exist). `password_enc` is age-encrypted; the API never echoes it.

### 3.3 `password_reset_tokens` (m31, Phase 2)

```sql
CREATE TABLE password_reset_tokens (
  id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id     uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  token_hash  bytea NOT NULL,                 -- sha256(raw token)
  expires_at  timestamptz NOT NULL,
  used_at     timestamptz NULL,
  attempts    int NOT NULL DEFAULT 0,
  requested_ip inet NULL,
  created_at  timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX password_reset_tokens_token_hash_key ON password_reset_tokens(token_hash);
CREATE INDEX password_reset_tokens_user_active_idx
  ON password_reset_tokens(user_id) WHERE used_at IS NULL;
```

**RLS:** Not tenant-scoped (keyed on `user_id`, mirrors `users`). The package reads/writes via the migration-owner pool in an `InAgentTx` (the forgot/reset flow is unauthenticated and pre-tenant). No tenant-isolation policy. Tokens are never returned to a client.

### 3.4 `user_invites` (activations) (m32, Phase 3)

We **reuse the existing `invitations` table** (`schema.sql:920`: `token_hash` UNIQUE, `scope` org/site, `role`, `expires_at`, `attempts`, `accepted_at`, `accepted_user_id`, `revoked_at`) rather than create a parallel table. Phase 3 simply **wires `invitation.CreateOrgInvitation` to an HTTP route** (it is dead code today) and adds a `kind` discriminator only if activation semantics must diverge from invite semantics. Decision: **no new table** — invites *are* activations (the invitee sets their own password on first `Accept`, which is exactly the activation semantic). RLS on `invitations` is already correct (tenant-isolation present in m19).

### 3.5 `email_log` (m30, Phase 1)

```sql
CREATE TABLE email_log (
  id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id     uuid NULL REFERENCES tenants(id) ON DELETE CASCADE, -- NULL for instance/auth mail
  to_addresses  text[] NOT NULL,
  subject       text NOT NULL,
  template      text NOT NULL,                  -- 'test','password_reset','password_changed','invite'
  status        text NOT NULL DEFAULT 'pending' -- pending|sent|failed
    CHECK (status IN ('pending','sent','failed')),
  error         text NULL,
  river_job_id  bigint NULL,
  attempts      int NOT NULL DEFAULT 0,
  created_at    timestamptz NOT NULL DEFAULT now(),
  sent_at       timestamptz NULL
);
CREATE INDEX email_log_tenant_created_idx ON email_log(tenant_id, created_at DESC);
CREATE INDEX email_log_status_idx ON email_log(status) WHERE status = 'failed';
```

**RLS:** dual policy. Tenant-scoped rows (`tenant_id` not null) get `email_log_tenant_isolation` USING/WITH CHECK `tenant_id = nullif(current_setting('app.tenant_id', true),'')::uuid`. Auth mail (`tenant_id` NULL) and the send-email worker run under `app.agent='on'` (separate `email_log_agent` policy, SELECT/INSERT/UPDATE). **Never log the token or password into `email_log` body** — subject + template name only, never the rendered HTML.

### 3.6 Alert tables — **stubbed for Phase 4 (LATER), DDL only, not migrated in Phases 1–3**

Documented here for forward-compat; created in m33+. Five-table flat-rules model (Sentry/Kuma-shaped, **not** an Alertmanager routing tree):

```sql
-- alert_channels: generalizes alert_configs into N named destinations
CREATE TABLE alert_channels (
  id uuid PK, tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  type text NOT NULL CHECK (type IN ('email','webhook','slack','telegram')),
  name text NOT NULL, enabled boolean NOT NULL DEFAULT true,
  config jsonb NOT NULL DEFAULT '{}',          -- non-secret fields
  secret_enc bytea NULL,                        -- age(token/webhook-url/HMAC secret)
  verified_at timestamptz NULL,
  created_at timestamptz NOT NULL DEFAULT now()
);
-- alert_rules: (event_types[], min_severity, scope filter) -> channel_ids[]
CREATE TABLE alert_rules (
  id uuid PK, tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  name text NOT NULL, enabled boolean NOT NULL DEFAULT true,
  event_types text[] NOT NULL, min_severity text NOT NULL DEFAULT 'high'
    CHECK (min_severity IN ('low','medium','high')),
  scope jsonb NOT NULL DEFAULT '{}',           -- {site_ids:[]} | {} = all
  channel_ids uuid[] NOT NULL, throttle_seconds int NOT NULL DEFAULT 300,
  digest_window_seconds int NULL, priority int NOT NULL DEFAULT 100,
  created_at timestamptz NOT NULL DEFAULT now()
);
-- alert_deliveries: per-channel attempt log + retry ledger + dedup guard
CREATE TABLE alert_deliveries (
  id uuid PK, tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  event_id uuid NOT NULL, rule_id uuid NULL, channel_id uuid NOT NULL,
  status text NOT NULL CHECK (status IN ('pending','sent','failed','throttled','digested')),
  attempts int NOT NULL DEFAULT 0, response_code int NULL, last_error text NULL,
  is_test boolean NOT NULL DEFAULT false,
  requested_at timestamptz NOT NULL DEFAULT now(), delivered_at timestamptz NULL,
  UNIQUE(event_id, channel_id)                 -- idempotency
);
-- (alert_events + alert_state generalize site_alert_state; see ADR-037 follow-up)
```

**RLS (Phase 4):** all five tables get the codebase's dual policy — `tenant_isolation` on `app.tenant_id` + an `app.agent='on'` SELECT escape for the cross-tenant evaluator (exact pattern from m5 `ListAlertConfigsAllTenants`). `scope.site_ids` validated against `canReadSite` at rule-write and re-checked at evaluation. **No migration in Phases 1–3** — this section is a forward-compat stub only.

---

## 4. API Surface

Convention reminders: **auth/account routes mount on the ROOT engine at `/auth/*`** (`auth.Handler.Register`, `apps/api/internal/auth/handler.go:37`). **Tenant feature routes mount under `/api/v1`** (gated globally by `RequireAuth()`+`RequireTenant()`). Public token-driven routes that must be reachable unauthenticated mount on root (`/auth/*`) or via `RegisterPublic` (like `invitation.Handler`). The frontend client uses `baseUrl:''` (same-origin) with the real prefix in the path.

### 4.1 Phase 1 — SMTP settings + test email

| Method | Path | Auth | Request | Response |
|---|---|---|---|---|
| `GET` | `/api/v1/settings/smtp` | session, `PermSMTPManage` read (admin+) + `RequireOrgScope()` | — | `SmtpSettings{enabled, host, port, username, from_address, from_name, tls_mode, allow_insecure_tls, password_set:bool, updated_at}` — **never** `password` |
| `PUT` | `/api/v1/settings/smtp` | session, `PermSMTPManage` (owner) + `RequireOrgScope()` | `SmtpSettingsUpdate{enabled, host, port, username, from_address, from_name, tls_mode, allow_insecure_tls, password?:string}` — `password` write-only; omit/empty = leave unchanged (nil-sentinel) | `SmtpSettings` (masked) |
| `POST` | `/api/v1/settings/smtp/test` | session, `PermSMTPManage` (owner) + `RequireOrgScope()` | `SmtpTestRequest{to_address, settings?:SmtpSettingsUpdate}` — uses just-submitted config OR stored secret | `SmtpTestResult{ok:bool, message:string}` — upstream SMTP error scrubbed of internal IPs/topology |

`POST /test` runs the **identical SSRF validation + TLS path** as production sends and is **rate-limited** (per-user + per-instance). It never returns the stored password.

### 4.2 Phase 2 — password reset + change password

| Method | Path | Auth | Request | Response |
|---|---|---|---|---|
| `POST` | `/auth/password/forgot` | **public** (no auth) | `{email}` | `200 {ok:true}` **always** (generic, constant-time; never reveals existence) |
| `POST` | `/auth/password/reset` | **public**, token-driven | `{token, password}` (token in body, POST not GET) | `200 {ok:true}` on success; generic `400/410` on invalid/expired/used. **No auto-login.** |
| `POST` | `/auth/me/password` | session | `{current_password, new_password}` (already exists — `ChangePassword`) | `200`; on success invalidates other sessions + sends notification email |

`POST /auth/password/forgot` and the reset page must run the **same code path** (dummy argon2 hash for missing accounts) so timing is constant. OIDC-only accounts (empty `password_hash`) are rejected the same way `Login`/`ChangePassword` do ("use SSO"). On successful reset/change: regenerate session, invalidate all **other** sessions, invalidate outstanding reset tokens, send "your password was changed" email. Align password policy to **min 12** everywhere (fix the existing `ChangePassword` min=8 inconsistency).

### 4.3 Phase 3 — first-run + invite/activation

| Method | Path | Auth | Request | Response |
|---|---|---|---|---|
| `POST` | `/api/v1/members` | session, `PermMemberManage` (admin) | `{email, name?, role}` — **drop the required `password`** | `{membership, accept_link?}` — calls `invitation.CreateOrgInvitation`, returns `accept_link`; emails it when SMTP configured |
| `POST` | `/api/v1/invitations/accept` | **public**, token-driven (already exists) | `{token, name?, password}` | `200` + establishes session (invitee sets own password) |
| `POST` | `/auth/activation/resend` | **public**, generic | `{email}` | `200 {ok:true}` always (generic, rate-limited) |

The Phase-3 change is primarily a **route swap**: replace `members_handler.invite → auth.Service.Invite` (legacy password-in-body) with `invitationSvc.CreateOrgInvitation`, returning `accept_link`. The `/accept` page and accept endpoint already work for org-scope tokens. Preserve the privilege-ceiling check (`actorRole.AtLeast(targetRole)`) and last-owner protections.

### 4.4 Phase 4 (LATER) — alert channels

`GET/POST /api/v1/alert-channels`, `GET/PATCH/DELETE /api/v1/alert-channels/{id}`, `POST /api/v1/alert-channels/{id}/test`, `GET/POST /api/v1/alert-rules`, `GET /api/v1/alert-deliveries`. All `PermMemberManage`/`PermAuditRead` + `RequireOrgScope()`; by-id routes gate `canReadSite` for scope. Webhook egress through `httpclient` (SSRF). **Not implemented in Phases 1–3.**

---

## 5. Email Service Design

### 5.1 New neutral package `apps/api/internal/mailer`

Today email logic lives in `internal/uptime` (`Mailer`/`SMTPMailer`/`NoopMailer`) and is imported by sharing/invitation. We **lift it to a neutral `internal/mailer` package** so no feature depends on `uptime`. `uptime.Mailer` becomes a thin alias / re-export to avoid churn.

```go
package mailer

// Resolver loads SMTP transport config: DB row first, env fallback.
type Resolver interface { Resolve(ctx context.Context) (Transport, error) }

// Transport = decrypted SMTP config -> a go-mail client factory.
type Transport struct { Host string; Port int; Username, From, FromName string;
    Password string /* decrypted only here */; TLSMode string; AllowInsecureTLS bool }

// Renderer renders (template, data) -> (subject, htmlBody, textBody).
type Renderer interface { Render(name string, data any) (Email, error) }

type Email struct { Subject, HTML, Text string }

// Service: load transport, render, build go-mail message (HTML primary +
// plaintext alternative), send, write email_log.
type Service struct { resolver Resolver; renderer Renderer; logRepo Repo; enqueuer Enqueuer }
```

### 5.2 Config resolution (DB → env fallback)

`Resolver.Resolve` reads the singleton `smtp_settings` row under `InAgentTx`; if `enabled` and `host != ''`, it **age-decrypts** `password_enc` (via the shared `cryptbox.AgeIdentity`, §7.5) and returns that `Transport`. If no row / disabled, it falls back to `config.SMTPConfig` (the env default). If both are empty, the mailer is a **NoopMailer** (logs + skips) — preserving "fully usable before SMTP" and the existing "return the raw accept link when SMTP off" behavior for invites.

### 5.3 Templating

`Renderer` loads compiled `html/template` files from `embed.FS` (`apps/api/internal/mailer/templates/`), composes the shared layout (`{{define "layout"}}` + per-email `{{block "body" .}}`), injects variables (context-aware auto-escaped), runs `go-premailer.Transform()` for inline CSS and `TransformText()` for the plaintext part. go-mail message: `SetBodyString(TypeTextHTML, html)` + `AddAlternativeString(TypeTextPlain, text)`. Links built from `WPMGR_PUBLIC_BASE_URL`.

Templates (Phase 1→3): `test`, `password_reset`, `password_changed`, `invite`.

### 5.4 Durable send via River `send_email` worker

Email today is sent **synchronously inline** (a transient SMTP failure is only logged, never retried). We add a durable, retried job:

```go
// apps/api/internal/mailer/email_job.go
type SendEmailArgs struct {
    EmailLogID     uuid.UUID `json:"email_log_id"`
    TenantID       uuid.UUID `json:"tenant_id,omitempty"` // zero for auth mail
    Recipients     []string  `json:"recipients"`
    Template       string    `json:"template"`
    Data           map[string]any `json:"data"`
    IdempotencyKey string    `json:"idempotency_key,omitempty"`
}
func (SendEmailArgs) Kind() string { return "send_email" }

type SendEmailWorker struct {
    river.WorkerDefaults[SendEmailArgs]
    svc *mailer.Service; logger *slog.Logger
}
func (w *SendEmailWorker) Work(ctx context.Context, job *river.Job[SendEmailArgs]) error {
    // resolve transport, render template, send via go-mail; update email_log
    // status sent/failed; RETURN err so River retries the SMTP transport.
}
```

Enqueue opts: `&river.InsertOpts{Queue:"email", MaxAttempts:5, UniqueOpts:{ByArgs:true, ByPeriod:10*time.Minute}}`. Register the queue `queues["email"]=river.QueueConfig{MaxWorkers:2}` and the worker in `startRiver()` (`main.go`), reusing the existing `mailer` var; wire the enqueuer via the deferred `SetEnqueuer` pattern (`main.go:591-619`). Flow: write `email_log(status=pending)` → enqueue `send_email` → worker sends → update `status=sent|failed`. Reset/activation flows enqueue rather than inline-send, so SMTP flakiness is retried by River.

### 5.5 Send-test path

`POST /api/v1/settings/smtp/test` → `mailer.Service.SendTest(ctx, toAddr, overrideSettings)`: builds a `Transport` from the **just-submitted** config (or stored secret if password omitted), runs the **same** SSRF guard + TLS path (synchronous, short timeout, **not** queued so the admin gets an immediate result), renders the `test` template, sends, and returns `{ok, message}` with the upstream SMTP error **scrubbed** of internal IPs/hostnames/timing. Rate-limited.

---

## 6. Frontend

Conventions: authenticated settings pages are flat files under `apps/web/src/routes/_authed/settings/<name>.tsx` (PageHeader + shadcn Cards, feature hooks in `apps/web/src/features/<area>/use-<area>.ts`, `react-hook-form` + `zod` + `@hookform/resolvers/zod`, with the documented `zodResolver(...) as never` cast when using coerce/optional/refine). Unauthenticated/token pages live directly in `apps/web/src/routes/` (centered Card + brand lockup, `validateSearch` zod for `?token=`). New authed pages register in `SETTINGS_GROUP.items` (`apps/web/src/components/layout/sidebar.tsx`). Hooks may use the hand-rolled raw-`client` pattern (`use-auth.ts`) for routes not yet in the generated SDK.

### 6.1 Phase 1 pages/routes/hooks

- **`apps/web/src/routes/_authed/settings/smtp.tsx`** — SMTP settings page, mirror `organization.tsx`/`alert-config-form.tsx`. `FormSection`s for host/port/username/**password (masked, write-only)**/from/from-name/TLS mode/allow-insecure-TLS, `StickySaveBar`, gated `canManage(me)` (edit owner-only, render admin+). Password field shows a `••••••••` placeholder when `password_set:true`, only submits on non-empty entry. A **"Send test email"** button (recipient input + result toast). A static **deliverability help panel** (SPF/DKIM/DMARC/PTR/From-alignment guidance, copy-paste record stubs — non-validating).
- **`apps/web/src/features/settings/use-smtp.ts`** — `useSmtp` (GET), `usePutSmtp` (PUT), `useTestSmtp` (POST test); `smtpKeys` query factory; `invalidateQueries` on success; `toast.success`.
- **Sidebar:** add `{ label: 'SMTP', to: '/settings/smtp' }` to `SETTINGS_GROUP.items`.

### 6.2 Phase 2 pages/routes/hooks

- **`apps/web/src/routes/forgot-password.tsx`** — unauthenticated, modeled on `login.tsx`. `{email}` form → `useForgotPassword`; on success shows neutral "If that email exists, we sent a link" (no enumeration). **No** `beforeLoad ensureMe` guard.
- **`apps/web/src/routes/reset-password.tsx`** — unauthenticated, token-driven, modeled on `accept.tsx`. `validateSearch z.object({token})`, render missing/invalid-token error Card up front, then `{new_password, confirm}` (zod `.refine` match, **min 12**). POST via hand-rolled `client.post('/auth/password/reset')`; branch on HTTP status (410/400 = expired/used/invalid) exactly as `accept.tsx` does. On success → redirect to `/login` (no auto-login).
- **`login.tsx`** — add a **"Forgot password?"** Link (none exists today).
- **Change password** — already present as `PasswordCard` in `account.tsx` via `useChangePassword`. No new page; align the min-length to 12.
- **`apps/web/src/features/auth/use-auth.ts`** — add `useForgotPassword`, `useResetPassword` (hand-rolled raw `client`).

### 6.3 Phase 3 pages/routes/hooks

- **`apps/web/src/routes/_authed/settings/members.tsx` / `use-members.ts`** — already coded for the link flow; just consume the now-real `accept_link` from `POST /api/v1/members`. Drop the password field expectation.
- **`apps/web/src/routes/accept.tsx`** — already handles org-scope activation tokens; no change beyond copy.
- Optional **`apps/web/src/routes/set-password.tsx`** alias if invite links route to `/set-password?token=` instead of `/accept?token=` (decision in §11).

### 6.4 Shared chrome (recommended, Phase 2)

Factor the centered `<main>` + brand lockup into one `AuthLayout` wrapper reused by login/forgot/reset/set-password (today login uses `FleetHubLogo`, register/accept use Globe+text — unify on `FleetHubLogo`+Wordmark).

---

## 7. Security Requirements

Folds in OWASP Forgot-Password / Authentication / Session-Management cheat sheets, NIST SP 800-63B, and the SSRF survey.

### 7.1 Enumeration protection
`POST /auth/password/forgot`, `/auth/activation/resend`, and registration return an **identical generic 200** whether or not the address exists. Run the **same code path** including a **dummy argon2 hash** for missing accounts so the "send email" branch does not leak existence via timing. Do **not** lock accounts on repeated forgot requests (that is itself an enumeration/DoS oracle) — rate-limit instead. Login already returns generic `invalid_credentials`; keep it.

### 7.2 Rate limiting
Add per-account **and** per-IP rate limiting on `/auth/password/forgot`, `/auth/password/reset` (attempt counter), `/auth/activation/resend`, and `POST /settings/smtp/test`. Login has **no** rate limit today — add an account-bound failed-attempt throttle (mirror the `autologin` ratelimit package). Token-verify attempts on the reset endpoint are bounded via `password_reset_tokens.attempts`.

### 7.3 Token hashing & lifecycle
Tokens from `crypto/rand` (≥128 bits, never `math/rand`/sequential/derived). Store **`sha256(token)`** only — fast hash is correct for high-entropy secrets; **never** argon2/bcrypt here (and bcrypt silently truncates >72 bytes). Single-use + short-TTL + user+purpose-bound, checked atomically. Issuing a new token invalidates outstanding ones of that purpose. **Never** log the plaintext token (scrub from access logs, `email_log`, error traces); the token exists only in the email URL.

### 7.4 Session invalidation on password change/reset
On any successful password change **or** reset: `scs.RenewToken()` (regenerate session, anti-fixation — already done at login), invalidate **all other** active sessions for that user, invalidate outstanding reset tokens, and send a "your password was changed" notification email. **Do not auto-login** after reset — force a fresh login. (Invalidating other sessions requires enumerating a user's sessions in the SCS/Redis store; if SCS lacks per-user session indexing, add a `password_changed_at` column and reject sessions older than it in `Authenticate()` — the simplest portable invalidation.)

### 7.5 SSRF mitigation for SMTP host
Treat the admin-supplied SMTP host as **untrusted** even though only an owner sets it. Route the SMTP dial through the existing **`httpclient`/`code.dny.dev/ssrf`** dial-time IP-pinning approach (resolve → validate **resolved IP** against the denylist `169.254.169.254`, `169.254.0.0/16`, `127.0.0.0/8`, `::1`, `10/8`, `172.16/12`, `192.168/16`, `fc00::/7`, `0.0.0.0/8`, instance IPs → **pin** that IP for the connection to defeat DNS-rebinding/TOCTOU). Restrict to SMTP ports **25/465/587** and the chosen scheme; the **test path uses the identical validation** (a test path that skips it is an SSRF bypass). Default **TLS verify ON**; expose `allow_insecure_tls` only as an explicit, scary-labeled toggle; refuse AUTH over cleartext to non-loopback hosts. Provide an audit-logged allowlist escape hatch for legitimate internal relays.

### 7.6 Credential encryption (reuse existing secret pattern)
Extract `sitedestination.AgeIdentity` (`NewAgeIdentity/Encrypt/Decrypt`) into a shared **`apps/api/internal/cryptbox`** package so both `sitedestination` and `mailer` depend on it without an import cycle. Store `smtp_settings.password_enc` as age `bytea`; encrypt on write, decrypt **only** at send time. Reuse `WPMGR_SITE_DEST_AGE_SECRET` (simplest) — document it in `.env.example`. **Add a production startup guard** (mirror `config.ValidateSessionSecret`): refuse to boot in production if the age secret is empty/unparseable, so a missing env var can never silently switch to an ephemeral key and orphan stored SMTP passwords. Use the **nil-sentinel UPDATE** (`CASE WHEN $n THEN $m::bytea ELSE password_enc END`) so editing settings without re-entering the password preserves ciphertext.

### 7.7 Masking secrets in API responses
`GET /settings/smtp` returns `password_set:boolean`, **never** the password (write-only field, masked placeholder in UI, overwrite only on non-empty submit). Test-email responses never echo the password and **scrub internal IPs/hostnames/timing** from upstream SMTP errors (the tester is otherwise a port-scan/SSRF oracle). Never log SMTP passwords or tokens (already an invariant in `notify.go`).

### 7.8 Secure link construction
Build all reset/activation URLs from **`WPMGR_PUBLIC_BASE_URL`** (allowlisted base) — **never** the inbound `Host`/`X-Forwarded-Host` header (Host-header injection). Force HTTPS. Add **`Referrer-Policy: no-referrer`** on reset/activation pages and avoid third-party resources so the token can't leak via Referer/history. POST the token (not a navigable GET). Post-reset redirect targets must be validated relative/allowlisted paths (open-redirect prevention).

### 7.9 CSRF
Change-password is a state-changing authenticated POST. The cookie is `SameSite=Lax` (mitigates most cross-site POST). **Require the current password** on change (OWASP requirement + strong CSRF defense) and add an **Origin/Referer check** on `/auth/me/password` and `/settings/smtp*`. The reset-submit endpoint is authenticated by the high-entropy token itself; still POST it with SameSite/Origin defenses. (Confirm `SameSite=Lax` suffices before adding a full synchronizer-token system — out of scope for v1.)

---

## 8. Codegen & Migration Steps

`make gen` is a **stub** — do not use it. The real commands (from `apps/api/README.md`):

### 8.1 Per-phase migration (each new table)
1. Edit **`apps/api/db/schema.sql`** — add `CREATE TABLE` + indexes + RLS (`ENABLE`/`FORCE` + policies where tenant-scoped).
2. Add **`apps/api/db/query/<table>.sql`** — `-- name: Verb :one|:many|:exec` annotated queries.
3. Regen sqlc:
   ```
   cd apps/api && go tool sqlc -f sqlc.yaml generate     # (or: go generate ./internal/db/sqlc)
   ```
4. Generate the migration (throwaway Postgres in `ATLAS_DEV_URL`):
   ```
   atlas migrate diff <name> --env local
   ```
   Then **hand-append** the RLS ALTER/POLICY block to the new `apps/api/migrations/YYYYMMDDHHMMSS_mNN_<desc>.sql`, then:
   ```
   atlas migrate hash --dir file://migrations
   ```
   Naming/order: m30 Phase 1, m31 Phase 2, m32 Phase 3, m33+ Phase 4. Migrations are `//go:embed`'d (`migrations/migrations.go`) and auto-applied on startup.

### 8.2 OpenAPI (ogen) + TS client (hey-api)
Add paths + `components.schemas` to **`packages/openapi/openapi.yaml`** (`SmtpSettings`, `SmtpSettingsUpdate`, `SmtpTestRequest`, `SmtpTestResult` in Phase 1; reset/forgot schemas in Phase 2 — or ship those hand-rolled). Bump `info.version`. Then:
```
cd apps/api && go generate ./internal/api/gen          # ogen -> internal/api/gen
pnpm -C packages/openapi-client generate               # hey-api -> @wpmgr/api
```
Surface new ops in `packages/openapi-client/src/index.ts`. Routes not added to OpenAPI use the hand-rolled raw-`client` pattern (`use-auth.ts`).

### 8.3 Wiring (every new domain)
- **`apps/api/internal/server/server.go`** — add `SmtpH *settings.Handler` (etc.) to `Deps`, nil-guarded `deps.SmtpH.Register(v1)` in the `/api/v1` group; auth routes register via `auth.Handler.Register` on root.
- **`apps/api/cmd/wpmgr/main.go`** — construct repo→service→handler; build `cryptbox.AgeIdentity`; build `mailer.Service`; register `send_email` worker + `email` queue in `startRiver()`; wire enqueuer via deferred `SetEnqueuer`; add the production age-secret startup guard.

### 8.4 Build/test gate (every phase)
```
cd apps/api && go build ./... && go vet ./... && go test ./...
pnpm -C apps/web typecheck && pnpm -C apps/web build      # picks up routeTree.gen.ts
```

---

## 9. Phased Rollout

### Phase 1 — Email infra + SMTP settings UI + test email (m30)

**Deliverables**
- `internal/cryptbox` (extracted `AgeIdentity`); `sitedestination` refactored to depend on it.
- `internal/mailer` package (Resolver/Renderer/Service), MJML→`html/template` pipeline + turbo task + CI diff check, `embed.FS` templates (`test`), go-premailer plaintext.
- `send_email` River worker + `email` queue + `email_log` table.
- `smtp_settings` table + sqlc queries + `internal/settings` domain (model/repo/service/handler) with SSRF-guarded send-test.
- `PermSMTPManage` (owner) in `authz/role.go`; added to `orgLevelPerms` in `authz/middleware.go`.
- OpenAPI `SmtpSettings*` schemas + paths; ogen + hey-api regen.
- Frontend: `settings/smtp.tsx`, `features/settings/use-smtp.ts`, sidebar entry, deliverability help panel.
- Production age-secret startup guard; `.env.example` documents `WPMGR_SITE_DEST_AGE_SECRET`.

**Files touched (primary)**
`apps/api/internal/cryptbox/*` (new), `apps/api/internal/mailer/*` (new), `apps/api/internal/settings/*` (new), `apps/api/db/schema.sql`, `apps/api/db/query/smtp_settings.sql`, `apps/api/db/query/email_log.sql`, `apps/api/migrations/20260603..._m30_smtp_email.sql`, `apps/api/internal/authz/role.go`, `apps/api/internal/authz/middleware.go`, `apps/api/internal/server/server.go`, `apps/api/cmd/wpmgr/main.go`, `apps/api/internal/sitedestination/service.go` (use cryptbox), `apps/api/internal/config/config.go` (age guard), `packages/openapi/openapi.yaml`, `apps/web/src/routes/_authed/settings/smtp.tsx`, `apps/web/src/features/settings/use-smtp.ts`, `apps/web/src/components/layout/sidebar.tsx`, `.env.example`.

**Definition of done**
Owner sets host/port/user/password/from/TLS in the UI → password persists age-encrypted, never echoed (`password_set:true`). "Send test email" delivers a branded HTML+plaintext mail through the configured relay and surfaces scrubbed errors on failure. SSRF guard blocks `169.254.169.254`/RFC1918 hosts including via DNS rebind (test). App boots with NoopMailer when unset. Prod refuses to boot without a stable age secret. `go build/vet/test` + web typecheck green.

### Phase 2 — Password reset + change password (m31)

**Deliverables**
- `users` columns `email_verified_at`, `status`; `password_reset_tokens` table + sqlc queries.
- `auth` service: `RequestPasswordReset` (mint+hash+TTL+enqueue email, generic+constant-time), `ResetPassword` (atomic single-use, set hash, invalidate other sessions + tokens, notify email), policy aligned to **min 12** (fix `ChangePassword` min=8).
- Routes `POST /auth/password/forgot`, `POST /auth/password/reset` on root engine; rate limiting; Origin checks; `Referrer-Policy: no-referrer` on token pages.
- Templates `password_reset`, `password_changed`.
- Session invalidation (`password_changed_at` reject-older-sessions, or per-user session enumeration).
- CLI escape hatch `wpmgr admin reset-password` (stdout one-time link).
- Frontend: `forgot-password.tsx`, `reset-password.tsx`, "Forgot password?" link on `login.tsx`, `AuthLayout`, `useForgotPassword`/`useResetPassword`.

**Files touched (primary)**
`apps/api/db/schema.sql`, `apps/api/db/query/password_reset_tokens.sql`, `apps/api/db/query/users.sql`, `apps/api/migrations/..._m31_password_reset.sql`, `apps/api/internal/auth/service.go`, `apps/api/internal/auth/handler.go`, `apps/api/internal/auth/session.go`, `apps/api/internal/middleware/auth.go` (session age check), `apps/api/cmd/wpmgr/*` (CLI), `apps/web/src/routes/forgot-password.tsx`, `apps/web/src/routes/reset-password.tsx`, `apps/web/src/routes/login.tsx`, `apps/web/src/features/auth/use-auth.ts`, `apps/web/src/components/layout/auth-layout.tsx`.

**Definition of done**
Forgot-password returns identical generic 200 for existing/unknown emails with constant timing; a valid link resets the password (single-use, 30-min TTL, no auto-login), invalidates all other sessions, and triggers a "password changed" email. OIDC-only accounts rejected. Change-password requires current password + Origin check. CLI reset prints a working one-time link with no SMTP. Rate limits enforced. Build/test green.

### Phase 3 — First-run + invite/activation (m32)

**Deliverables**
- Wire `invitation.CreateOrgInvitation` to `POST /api/v1/members` (replace legacy `auth.Service.Invite`); return + email `accept_link`; preserve privilege-ceiling + last-owner protections; deprecate/remove dead `auth.Service.Invite`.
- Env-seeded admin (`WPMGR_INIT_ADMIN_EMAIL`/`_PASSWORD`, only when `CountUsers()==0`, idempotent, sets `email_verified_at`).
- `POST /auth/activation/resend` (generic, rate-limited); invited users created `status='pending'`, set `active`+`email_verified_at` on accept.
- `invite` template.
- CLI `wpmgr admin create`.
- Frontend: members UI consumes real `accept_link`, drops password field; `/accept` (or `/set-password`) activation copy.

**Files touched (primary)**
`apps/api/internal/auth/members_handler.go`, `apps/api/internal/invitation/handler.go` + `service.go`, `apps/api/internal/auth/service.go` (env-seed, resend), `apps/api/cmd/wpmgr/main.go`, `apps/web/src/routes/_authed/settings/members.tsx`, `apps/web/src/features/orgs/use-members.ts`, `apps/web/src/routes/accept.tsx`.

**Definition of done**
Admin invites a teammate by email → invitee receives a branded link, sets their **own** password, lands authenticated with the granted role; account is `email_verified`. Env-seed creates the first admin headlessly when configured. Resend is generic + rate-limited. Legacy password-in-body invite path removed. Build/test green.

### Phase 4 — Alert channels (LATER, m33+)

**Deliverables (not built in 1–3)**
`alert_channels`/`alert_rules`/`alert_deliveries` (+`alert_events`/`alert_state`) tables with dual RLS; `Channel` interface (Email/Webhook/Slack/Telegram) with per-channel render; `NotificationService` fan-out via River `alert_dispatch` jobs; secrets age-encrypted in `*_enc`; webhook egress via `httpclient` (SSRF) + HMAC signing; per-channel test-send + delivery log; backfill `alert_configs` → channels; wire `notify_security` end-to-end (fix `putAlertConfig` + OpenAPI + UI toggle) as the first proof; reframe `/settings/alerts` UI to multi-channel/multi-event.

**Definition of done**
A tenant configures multiple channels + rules; events fan out durably with dedup/throttle, per-channel retry, and a delivery log; test-send validates each channel; webhook SSRF-guarded + signed; existing uptime/security alerts migrated with zero regression.

**Phase boundary:** Phase 4 starts only after Phases 1–3 are in production and the `mailer`/`cryptbox` seams are stable; it does **not** block pre-alpha.

---

## 10. Landing + Changelog Updates (handoff to docs-writer)

There is no `CHANGELOG.md` and no `/changelog` page today; the version is a hand-typed `HERO.badge` in `apps/landing/src/data/content.ts`. All landing copy is data in `content.ts`; copy must pass `apps/landing/scripts/check-copy.mjs` (no em/en dashes, no competitor names) and `pnpm -C apps/landing impeccable`. Highlight Media; no em dashes; no competitor names.

| Phase | Landing (`apps/landing/src/data/content.ts`) | Changelog |
|---|---|---|
| **1** | Append `FEATURES.cards` entry: "UI-configured SMTP" (icon e.g. `Mail`) — admins set up email in the dashboard, encrypted at rest, with a test-send button. Bump `HERO.badge` to the m30 release version (track `spec.info.version`). | New `CHANGELOG.md` (Keep-a-Changelog), version keyed to `spec.info.version`: "Added — UI-configured SMTP settings with encrypted password storage and send-test." |
| **2** | Append `FEATURES.cards` entry: "Self-serve password reset" (icon e.g. `KeyRound`). | "Added — forgot/reset password and change password; sessions invalidated on password change." |
| **3** | Append `FEATURES.cards` entry: "Email invites & activation" (icon e.g. `UserPlus`). | "Added — invite teammates by email; invitees set their own password. Changed — member invites no longer require an admin-chosen password." |
| **4** | Append `FEATURES.cards` entry: "Multi-channel alerts (Slack, Telegram, webhooks)". | "Added — pluggable alert channels with rules and delivery log." |

Per phase, docs-writer: edit `content.ts` only (no JSX), run `node apps/landing/scripts/check-copy.mjs` + `pnpm -C apps/landing typecheck` + `pnpm -C apps/landing impeccable` + `pnpm -C apps/landing build`, then manual GCS upload (`gs://wpmgr-landing-prod`). Consider a single version constant fed by `spec.info.version` to stop badge drift. Also update `docs/` ADR index and `.env.example` per phase.

---

## 11. Open Questions / Decisions for the Product Owner

1. **Instance vs per-org SMTP confirmation.** This ADR recommends **instance-level, owner-only** for v1 (§2.3). Confirm we are not committing to per-org SMTP in pre-alpha. (Per-org becomes an `alert_channels` row in Phase 4.)
2. **Bootstrap admin email verification.** Recommendation: bootstrap/env-seed admins are trusted (no verification); only **invited** users activate. Agree?
3. **Activation route path.** Reuse the existing `/accept?token=` page for org invites (zero new UI), or introduce a distinct `/set-password?token=` for clearer "activate your account" semantics? Recommendation: reuse `/accept`.
4. **Password policy unification.** Align everything to **min 12** (fixing `ChangePassword` min=8). Any UX concern with forcing 12 on existing change-password users?
5. **Session invalidation mechanism.** Per-user session enumeration in SCS/Redis vs the simpler `password_changed_at` "reject sessions older than" approach (§7.4). Recommendation: `password_changed_at` for portability — confirm acceptable.
6. **Reset TTL.** 30 min recommended (OWASP/NIST range 15–60). Confirm.
7. **CSRF posture.** Keep `SameSite=Lax` + current-password proof + Origin checks, or invest now in a synchronizer-token system? Recommendation: defer the token system.
8. **Age key isolation.** Reuse `WPMGR_SITE_DEST_AGE_SECRET` for SMTP (simplest) vs a dedicated `WPMGR_NOTIFY_AGE_SECRET` (isolated blast radius). Recommendation: reuse in v1.
9. **Default From address & deliverability.** Will the hosted instance ship a default `From` (and at `manage.wpmgr.app` / a `mail.` subdomain) so reset emails work out of the box, or require operators to set it? Affects whether a system fallback relay is needed.
10. **Login rate limiting.** Add account-bound login throttle now (Phase 2) or as a separate hardening task? Recommendation: include in Phase 2 since we touch auth.
11. **MJML toolchain timing.** Land the MJML→`html/template` turbo pipeline + CI diff in Phase 1, or ship Phase 1's single test template hand-rolled and introduce MJML in Phase 2? Recommendation: introduce MJML in Phase 1.

---

**End of ADR-045.**

---

## 12. Addendum — Resolved Review Gaps (completeness critic)

A completeness/security critic reviewed §1-11. Verdict: *architecturally sound, close these load-bearing gaps before Phase 1.* Each is folded into the plan below and is binding over any contradicting prose above.

**G1. Auth flow completeness — register enumeration**

- *Issue:* §7.1 names registration as needing enumeration protection, but NO phase deliverable actually fixes it. The live register handler (apps/api/internal/auth/handler.go:80) returns 201 on success and a distinct duplicate-email error on collision via Bootstrap, which leaks account existence. After Phase 3 (env-seed + invites), public /register may also still be reachable when CountUsers>0, contradicting the 'first admin only' Bootstrap gate it calls.
- *Resolution (binding):* Add a concrete Phase 2/3 task: either disable public /register once CountUsers>0 (return generic 'registration closed') or make duplicate-email return the SAME generic 200/created shape with no distinguishing error. Decide and document whether self-serve /register survives at all in pre-alpha, since invites are the intended onboarding path.

**G2. Invitation accept contract mismatch**

- *Issue:* §4.3 specifies POST /api/v1/invitations/accept request body as {token, name?, password}, but the existing handler (apps/api/internal/invitation/handler.go:43) REQUIRES email in the body and rejects with 'email_required' when absent. The plan's accept shape will 400 against the real endpoint. (The session IS established inside Accept — service.go:304 — so that claim is fine.)
- *Resolution (binding):* Correct §4.3 to {token, email, name?, password}, OR change the handler to resolve email from the invitation row by token_hash (preferable — fewer fields for the invitee, removes an email-guess vector). Update reset-password/accept.tsx forms and OpenAPI accordingly.

**G3. Session invalidation on password change — store capability**

- *Issue:* §7.4 offers per-user session enumeration OR password_changed_at. The SCS redisstore (apps/api/internal/auth/session.go:54) only exposes All() returning ALL sessions globally (no per-user index and no value-side user_id), so 'invalidate all OTHER sessions for that user' is NOT implementable by enumeration without an O(all-sessions) scan and decode. The plan presents enumeration as a real option; it is not portable here.
- *Resolution (binding):* Commit to the password_changed_at approach as the ONLY mechanism. Add the column + a UpdatePasswordHash query that sets password_changed_at=now() (note: grep shows NO password_changed_at write in db/query today, so this query must be added), and enforce a check in Authenticator.Authenticate (apps/api/internal/middleware/auth.go:58) rejecting sessions whose SCS auth-time predates password_changed_at. Store the auth timestamp in the session at Login. Drop the enumeration option from the ADR.

**G4. ChangePassword min-length + token TTL not yet aligned**

- *Issue:* Confirmed live inconsistency: service.go:408 enforces len<8 for ChangePassword while Register/Invite use min=12 (service.go:114,185). The plan flags this but it is only a Phase 2 line item; if Phase 1 ships first there is still a weak-password path. No expired-token nor already-used-token RESPONSE codes are pinned for the reset endpoint beyond 'generic 400/410' — the happy/error/expired/used matrix is named but the exact status per case is unspecified.
- *Resolution (binding):* Raise ChangePassword to 12 in Phase 1 (one-line, no dependency). Pin the reset endpoint response table explicitly: valid→200; malformed/unknown-hash→400 invalid; expired→410; already-used→410; too-many-attempts→429. Make forgot ALWAYS 200. Document these so the frontend reset-password.tsx status branching (which mirrors accept.tsx) is deterministic.

**G5. SSRF for SMTP — concrete dial wiring is under-specified**

- *Issue:* The ssrf guardian (httpclient.go:121, guardian.Safe via net.Dialer.Control) is HTTP-oriented; the plan asserts reuse for SMTP but does not state HOW. Verified that go-mail v0.7.3 exposes WithDialContextFunc (client.go:710) — so it IS injectable — but the ADR never names this seam, and a naive SMTPMailer that lets go-mail dial directly would bypass SSRF entirely. The denylist in §7.5 also omits link-local IPv6 fe80::/10 and IPv4-mapped IPv6 (::ffff:169.254.169.254), a known metadata-IP bypass.
- *Resolution (binding):* State explicitly: build the go-mail Client with WithDialContextFunc bound to a dialer whose net.Dialer.Control = ssrf.New(WithPorts(25,465,587)).Safe, identical for production AND the /test path. Add fe80::/10 and IPv4-mapped-IPv6 forms to the denylist (or rely on ssrf lib defaults and assert they cover these in a test). Add a test that a hostname resolving to a metadata IP via IPv4-mapped IPv6 is blocked.

**G6. Email rendering claim vs evidence — plaintext + Outlook**

- *Issue:* Doc (B) claims go-premailer TransformText() yields the plaintext alternative, but premailer is an HTML-CSS-inliner; its text extraction is crude (strips tags, does not render button/link URLs as readable text). For reset/activation the plaintext MUST contain the literal URL or the email is unusable in text-only clients. Doc (B) hand-writes plaintext only for reset+activation and defers the rest to TransformText — the test/password_changed/invite plaintext could ship without a usable link/CTA.
- *Resolution (binding):* Require hand-authored .txt template parts for EVERY transactional email that contains an actionable link (reset, activation/invite, and the test email's nature), each embedding the raw {{.URL}} on its own line. Treat TransformText only as a fallback for link-free informational mail (password_changed). Add a render test asserting the plaintext body contains the token URL.

**G7. First-run chicken-and-egg — not fully closed for the FIRST invited owner**

- *Issue:* §2.3/§2.5 argue instance-level SMTP solves bootstrap, but the resolved sequence still has a hole: env-seed/CLI create the first ADMIN, but that admin must log in and configure SMTP in the UI before ANY invite/reset email can send. The CLI escape hatch (admin reset-password → stdout link) covers lockout, but there is no CLI to MINT an invite/activation link to stdout, so inviting the second user before SMTP is configured silently produces an accept_link the admin must copy out of the API JSON response (acceptable) — but the ADR never states this fallback for invites explicitly as the pre-SMTP path, and §5.2 only mentions it for the legacy invite mailer.
- *Resolution (binding):* Make explicit: POST /api/v1/members ALWAYS returns accept_link in the response (not only when SMTP off), so an admin can hand-deliver it pre-SMTP. Add wpmgr admin invite <email> --role CLI that prints the accept_link to stdout, matching the reset-password CLI, so headless onboarding never depends on SMTP.

**G8. Codegen / migration steps — gaps that will fail the build gate**

- *Issue:* §8 lists schema.sql/sqlc/atlas/ogen/hey-api but: (1) email_log and password_reset_tokens add NO sqlc query file for the worker's status-update path beyond inserts — the send_email worker needs MarkEmailSent/MarkEmailFailed queries not listed in db/query enumerations; (2) the users ALTER adds status NOT NULL DEFAULT 'active' which is fine, but email_verified_at backfill for EXISTING users is unaddressed (they have NULL → could be treated as unverified by any future gate); (3) §8.2 says reset/forgot schemas 'or ship those hand-rolled' — leaving OpenAPI partial means routeTree.gen / generated SDK won't cover them, which is allowed but the frontend hand-rolled client pattern must be explicitly chosen, not left ambiguous.
- *Resolution (binding):* Enumerate the worker's email_log mutation queries (MarkSent/MarkFailed/IncrAttempts) and password_reset query set (Insert/GetByHash-atomic-consume/InvalidateOutstanding) in §8.1. Add a backfill UPDATE setting email_verified_at=now() for all pre-existing users in the m31 migration (they're trusted, pre-feature). Decide firmly: forgot/reset are hand-rolled raw-client (no OpenAPI) — state it so no one waits on codegen.

**G9. Frontend route registration completeness**

- *Issue:* New unauthenticated routes forgot-password.tsx and reset-password.tsx are created but the plan never states they must be added to the router/route tree or that they must NOT carry the _authed guard / beforeLoad ensureMe (only reset-password's no-guard is mentioned; forgot-password's guard exemption is implied but login.tsx-style). TanStack file-based routing auto-registers via routeTree.gen.ts on build, but the build gate (§8.4) runs typecheck+build which regenerates it — fine — yet there is no check that these pages are reachable while logged-OUT (the global auth redirect could bounce a logged-out user away from /reset-password before the token is consumed).
- *Resolution (binding):* Explicitly require both pages live at apps/web/src/routes/ root (sibling to login.tsx/accept.tsx) with NO _authed prefix and NO ensureMe beforeLoad, and verify the global redirect/guard whitelists /forgot-password and /reset-password (as it must already for /login,/accept,/register). Add /set-password alias decision (Q3) before Phase 3 so members.tsx links to the real path.

**G10. Landing + changelog handoff — version source drift unresolved**

- *Issue:* §10 correctly identifies there is no CHANGELOG.md and the version is a hand-typed HERO.badge, but the handoff still leaves the version source ambiguous: it says 'track spec.info.version' AND 'bump HERO.badge' AND 'key changelog to spec.info.version' without designating ONE source of truth, so pre-alpha will drift across three places (OpenAPI info.version, landing badge, changelog). check-copy.mjs (no em dashes / no competitor names) is named but the templates doc (B) and ADR prose contain em dashes — if any of that copy lands in content.ts it fails the gate.
- *Resolution (binding):* Designate packages/openapi/openapi.yaml info.version as the SINGLE source; have the landing build import/inject it (a tiny version constant) rather than re-typing the badge, per the ADR's own 'consider' aside — make it a hard requirement. Add a Phase-1 step to create CHANGELOG.md so Phase 1's own entry has a home. Remind docs-writer that content.ts copy must be em-dash-free even though the ADR/templates use them.

**G11. Env-var SMTP must not be assumed — but env fallback re-introduces it**

- *Issue:* The directive is 'nothing assumes an env-var SMTP (must be UI/DB configured)', yet §2.3/§5.2 keep config.SMTPConfig (WPMGR_SMTP_*) as a boot/bootstrap FALLBACK that the Resolver uses when no DB row exists. This is defensible for migration, but it means an operator CAN still run entirely on env SMTP and never touch the UI, partially defeating the goal, and the env password is NOT age-encrypted (it's plaintext env), creating two credential paths with different security postures.
- *Resolution (binding):* Either (a) make env SMTP a one-shot SEED that writes the DB row (age-encrypting the env password) on first boot then is ignored, giving a single DB-backed path; or (b) explicitly scope the env fallback to 'auth/transactional bootstrap only, deprecated, logged with a warning' and document that the UI/DB row always wins. Pick (a) for pre-alpha cleanliness so there is exactly one encrypted credential store.

**Critic verdict:** Architecturally sound and unusually thorough, but NOT yet ready to start Phase 1 as written — close ~6 load-bearing gaps first (session-invalidation must commit to password_changed_at since redisstore can't enumerate per-user; the invitation accept body requires email contradicting §4.3; register-enumeration and the env-SMTP single-credential-path are unresolved; SSRF dial seam and per-email plaintext-with-URL need to be pinned), all of which are concrete and fixable within the existing phase boundaries without re-architecting.
