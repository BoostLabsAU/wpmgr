# Per-Site Email / SMTP Management — Implementation Plan (research-backed, 2026-06-10)

Source: 6-investigator research workflow (FluentSMTP v2.2.95 teardown + email-logging deep-dive + competitor gap (WP Mail SMTP/Post SMTP/SMTP2GO/Brevo/Solid Mail) + WPMgr convention mapping + provider mechanics + security). FluentSMTP at `_vendor-reference/fluent-smtp/` is **reference only — we improve on it**.

## Goal
Every managed WordPress site configures its outgoing email (multi-provider) from the WPMgr dashboard, with a best-in-class **email log**, implemented per-site across **agent (PHP) + control plane (Go) + web (React/Radix)**. Match FluentSMTP, then exceed it with fleet-scale features no single-site plugin can do.

## Existing vs greenfield
- **Exists** (ADR-045): a CP/instance mailer (`apps/api/internal/mailer/*`) + `email_log`/`smtp_settings` tables that send the CP's OWN email (password resets, alerts). This is **instance-level**, not per-site — do NOT overload it.
- **Greenfield**: the agent has **zero** mail code today. Per-site is built fresh.

## Architecture (the decision: HYBRID log, CP-central secrets)
- **Agent**: own outgoing mail via a non-destructive `pre_wp_mail` short-circuit (WP 5.7+), `wp_mail`-pluggable only as fallback. New `ProviderRouter` picks a connection by FROM-email → default, instantiates a per-provider Handler (SMTP via PHPMailer; SES/SendGrid/Mailgun/Postmark via `wp_remote_post`), stamps an `X-WPMgr-Site` correlation header. Writes **every send to a local WP buffer table** (durable, survives CP outage), fire-and-forget pushes recent rows to CP. New signed commands: `sync_email_config`, `send_test_email`, `email_log_query`. Secret stored in the agent keystore (see decision #2).
- **CP**: new `apps/api/internal/email/` package modeled on `internal/perf/` (handler/agent_handler/repo/service). Per-site config store with **age-encrypted** secrets + masked reads (`secret_set` bool only) + nil-sentinel write-only preserve + age-guard. Static provider catalog. `/email/test` dispatches the signed test command. Central email-log ingest (`POST /agent/v1/email/log`, identity from the verified Ed25519 key, idempotent `ON CONFLICT`, `InTenantTx`). CP-hosted per-provider **webhook** endpoints → suppression list. River-backed digest + log pruner. OpenAPI + sqlc the disciplined way.
- **Web**: new per-site **Email tab** (Radix Tabs): Provider Config (Radix Select + dynamic fields + write-only secret), Routing & Fallback (TanStack Table + Select), **Email Log viewer** (TanStack Table + Radix Dialog detail w/ Prev/Next, search + column-scoped, status/date filters, resend single/bulk, CSV/JSON export), Test Email (Dialog), Deliverability Dashboard (recharts). Plus a **fleet-wide cross-site deliverability page** (the agency differentiator). All Radix.
- **Log residency = HYBRID**: agent-local buffer (full fidelity, deep history on demand via signed `email_log_query`) + CP-central recent/summary ingest powering ONE cross-site dashboard. **Secrets = CP-central** age-encrypted, pushed to the agent only via the signed `sync_email_config` (configure once, RLS-isolated, survives re-enroll).

## Provider catalog
- **v1**: Generic SMTP, Amazon SES, SendGrid, Mailgun, Postmark (all four API providers have first-class signed CP-hostable webhooks; SMTP universal send-only).
- **v2**: Brevo, SparkPost, Zoho ZeptoMail, SMTP2GO, Elastic Email.
- **v3 (separate workstream)**: Gmail + Outlook/O365 OAuth (CP-brokered: shared Google project / multi-tenant Azure app + per-site refresh tokens; restricted-scope verification; NO bounce webhooks).

## Feature list
**Parity (FluentSMTP):** wp_mail interception; multi-connection (provider+creds+sender identity); per-FROM routing → default; single global fallback w/ 1 retry; per-connection test-send; full email log (to/from/subject/body/headers/attachments-meta/status/response/retries/resent); log UI (paginated, status+date filters, free-text + `column:value` search, detail w/ Prev/Next, resend single+bulk, delete); age-based retention pruner; connection diagnostics; log on/off; dashboard stats (sent/failed, heatmap, time-series); per-failure chat alerts + scheduled HTML digest.

**Beyond FluentSMTP (ranked):** (1) fleet-wide cross-site deliverability dashboard + open/click tracking; (2) CP-terminated bounce/complaint **webhooks** → fleet-shared **suppression list** consulted pre-send; (3) smart AND/OR routing + multi-provider failover + durable resend queue (River); (4) WP email controls (per-email-type enable/disable centrally); (5) SPF/DKIM/DMARC validator + guided DNS fixes on a schedule; (6) broader alerts (SMS/Teams/webhook) + single weekly agency digest; (7) privacy-first log (body-off default, per-site retention, CSV/JSON export, GDPR erase-by-recipient); (8) rate-limit / async queue / IP-warming; (9) stronger crypto (age vs FluentSMTP's DB-readable AES + plaintext OAuth); (10) auto-provision provider webhooks via API.

## Data model (new migration mNN — IF-NOT-EXISTS, RLS ENABLE+FORCE m36 dual policy)
- **site_email_config** (PK site_id): tenant_id, provider, from_address/name, force flags, non-secret config jsonb, `provider_secret_encrypted bytea` (age), oauth_*_encrypted (later), default/fallback keys, mappings jsonb. Upsert w/ nil-sentinel secret preserve.
- **site_email_log**: id, tenant_id, site_id, agent_seq (cursor), message_id, to/from, subject, provider, status (pending|sent|failed|bounced|complained), response jsonb, error, retries, resent_count, body_stored bool, body text NULL (off by default), timestamps. Indexes: (tenant,site,created_at DESC), (tenant,created_at DESC) fleet, partial WHERE status='failed', UNIQUE (tenant,site,agent_seq). Keyset = composite (created_at,id).
- **email_suppression**: tenant_id, site_id NULL=org-wide, email_hash, email NULL, reason (hard_bounce|complaint|unsubscribe|manual), provider, event_at, source_message_id. UNIQUE (tenant, COALESCE(site_id), email_hash). Consulted before send.
- (optional) **email_alert_channel** for chat/webhook configs.

## Security (highlights)
age(X25519) encrypt every secret column; decrypt only in CP memory at config-push/relay; age-guard mounts. Masked reads (never return secret; `secret_set` bool). RLS on all 3 tables + `PermEmailManage` + `RequireSiteAccess`. SSRF: route user SMTP host:port + custom endpoints through the existing `ssrf.New` resolve-then-pin dialer on send AND /test; https-only + provider hostname allowlist. Per-provider webhook signature verify (SES-SNS cert pinning, SendGrid ECDSA, Mailgun HMAC w/ dedicated signing key, Postmark secret-path) + replay defense + idempotency. Body-off default + retention pruner + GDPR erase. OAuth (v3) tokens age-encrypted, refreshed in CP only, least-scope, per-tenant PKCE state. Optional KMS-envelope the age key.

## Build phases (CP-first)
0. **Decisions + data model + contracts** (lead+backend): lock decisions, migration+sqlc+OpenAPI.
1. **CP domain core** (backend-architect): internal/email config+secrets+test-send.
2. **Agent mail layer** (wp-agent-engineer): pre_wp_mail + ProviderRouter + handlers + commands + local buffer.
3. **Log ingest + log UI** (backend + frontend): central ingest + viewer/resend/export + test dialog.
4. **Beyond**: webhooks+suppression+deliverability+alerts+fleet dashboard.
5. **Security review + deploy + docs**.

## Decisions — LOCKED (2026-06-10)
1. **Log residency = HYBRID** (agent-local buffer + CP-central recent ingest for the cross-site dashboard; deep history on demand).
2. **Send model = HYBRID**: SMTP sends from the AGENT (site IP, offline-capable, secret in agent keystore); API providers (SES/SendGrid/Mailgun/Postmark) also send agent-side in v1, with CP-brokered sending as a later enhancement. ⚠️ Phase-0 design point: with agent-side sending + a CP suppression list, decide the pre-send suppression check mechanism (CP pushes suppression deltas to the agent for a local check, vs agent queries CP — lean: push deltas to agent, eventual-consistency, no per-send CP dependency).
3. **v1 providers = SMTP + Amazon SES + SendGrid + Mailgun + Postmark.** v2: Brevo/SparkPost/ZeptoMail/SMTP2GO/Elastic. v3: Gmail/Outlook OAuth.
4. **Email log: body OFF by default**, per-tenant opt-in to capture bodies; **14-day retention** auto-pruner.
5. **v1 scope = FluentSMTP parity + fleet cross-site log + bounce/complaint webhooks → suppression list.** Defer to v2: SPF/DKIM/DMARC validator, smart AND/OR routing + failover queue, WP email controls, open/click tracking, rate-limit/IP-warming, broader alert channels.
6. **Credentials = per-site + org-wide default** (`site_email_config.site_id NULL` = org-wide inherited; `email_suppression.site_id NULL` = fleet-shared). Needs `canReadSite` by-id gating alongside tenant RLS.
7. Webhook hosting = CP per-provider HTTPS endpoints at `manage.wpmgr.app/webhooks/email/{provider}` (per region for SES-SNS/Mailgun). Confirm Cloud Run egress/ingress is stable for provider callbacks at build time.
8. KMS envelope = DEFER (stay on the single `WPMGR_*_AGE_SECRET` for parity with current SMTP/site-destination crypto; revisit as later hardening).
