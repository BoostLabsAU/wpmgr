# WPMgr — Hosted SaaS Pricing & Cloud-Infra Unit Economics

> Research dated 2026-06-02. Grounded in the real `wpmgr-prod` GCP stack (asia-south1).
> This is a decision document for review, not a committed plan. Nothing here is
> built yet. All hosted enforcement is intended to sit behind a `WPMGR_HOSTED`
> flag so the self-hosted AGPL build stays fully featured and uncapped.

## TL;DR

- **The economics are a fixed floor, not a per-unit cost.** The hosted control
  plane costs **~$470/mo always-on**, of which **~65% is the media-encoder**
  (4 vCPU / 16 GiB, `minScale=1`) running even with zero customers. The
  **per-site marginal cost is ~$0.01–0.10/mo** (a few DB rows + one uptime probe).
- **Biggest single lever:** scale the media-encoder to `minScale=0` → floor drops
  to **~$140/mo** (tradeoff: ~5–15 s cold start on the first async optimize).
- **Site caps are a willingness-to-pay / packaging lever, not a cost lever.**
- **The one real margin risk is restore egress** ($0.12/GB) — a couple of large
  full-restores can exceed a tier's monthly price. Meter it.
- **Market is per-site, packaged as tiers** ($1–5/site/mo), with free tiers that
  are generous on sites and gated on features/frequency/retention.
- **Recommended:** Free (2–3 sites, BYO storage) → Starter $15 → **Agency $59 (core)**
  → Scale $169, plus a BYO-Storage −30% modifier, with self-host free forever.

---

## 1. Cloud-infra unit economics

### 1.1 Always-on fixed floor (~$470/mo on-demand list, no committed-use discounts)

| Component | ~$/mo | Notes |
|---|---:|---|
| Cloud Run **media-encoder** (4 vCPU/16 GiB, `minScale=1`) | **$300–328** | **Dominant cost (~65%).** Only does work when Media Optimization runs. Prime scale-to-zero candidate. |
| Cloud Run **api** (1 vCPU/1 GiB, `minScale=1`) | $40–63 | Carries the 60 s uptime-probe fan-out + all requests. `maxScale=4`. |
| Cloud SQL Postgres **db-g1-small** (shared-core, ZONAL, 10 GB SSD) | $26–28 | **No SLA, not CUD-eligible** (shared-core). A real risk for a paid SaaS. |
| Memorystore **Redis BASIC 1 GB** | $36–39 | No HA/failover. |
| Global **HTTPS LB** (1 forwarding rule) | $18 | + small data-processing. |
| Cloud Run **web** (1 vCPU/512 MiB, `minScale=0`) | $1–3 | Scale-to-zero; mostly inside free tier. |
| GCS (5.1 GB dedup chunks + landing) + Artifact Registry | $1–2 | Egress only on restore/download, mostly customer-initiated. |

> Scale the media-encoder to `minScale=0` and the floor falls to **~$140/mo**.

### 1.2 Marginal cost per site: ~$0.01–0.10/mo (rounding error)

Drivers, in order:

1. **Uptime-probe DB rows** — the 60 s probe writes ~43,800 rows/site/month
   (~150 B each). At 90-day retention that's ~19 MB resident/site ≈ **$0.004/site/mo**
   SSD, and **$0 marginal** until the Cloud SQL tier saturates.
2. **Probe compute** — absorbed by the already-paid always-on `api` instance until
   probe fan-out forces extra instances.
3. **Encoder compute** — **$0 unless Media Optimization is on** for that site; when
   on, a one-time ~500-image pass is ~$0.02 of request-billed compute (no image
   bytes transit the CP — presigned URLs only).
4. **Managed-bucket storage/egress** — **$0 when the customer brings their own
   S3/local** (WPMgr stores zero backup bytes); only WPMgr's managed bucket adds
   ~$0.023/GB-mo + egress on restore.

### 1.3 Cost at scale (current stack, where upgrades kick in)

| Sites | ~$/mo | ~$/site | Note |
|---:|---:|---:|---|
| 10 | ~$470 | ~$47.00 | Fixed floor dominates entirely. |
| 100 | ~$470 | ~$4.70 | Still fits current stack. |
| 1,000 | ~$555 | ~$0.56 | **Upgrade point:** shared-core db-g1-small → `db-custom-2-7680` (~+$80); raise `api maxScale`. |
| 10,000 | ~$950 | ~$0.10 | **Upgrade point:** Cloud SQL → `db-custom-4-16384` + HA; Redis → STANDARD_HA; partition the probe table or route metrics to ClickHouse (ADR-028 path). |

### 1.4 Cost optimizations (highest-impact first)

1. **Media-encoder → `minScale=0`** — kills ~$300/mo (≈65% of the floor). Accept a
   cold start on async optimize. (Keep the image shipped first per the
   media-encoder deploy-ordering note so enqueued jobs don't dangle.)
2. **Re-tier Cloud SQL off shared-core** — `db-g1-small` has no SLA and is not
   CUD-eligible. Move to a small dedicated `db-custom-1-3840` for an SLA + 1yr/3yr
   committed-use discounts (~25–52% off vCPU+RAM).
3. **Cloud Run committed-use discounts** once the always-on floor is stable
   (~17% at 1yr on the `api` and any retained encoder min instance).
4. **Tame probe DB growth** — 30–90 day retention prune, partition by day, or route
   probe rows to ClickHouse at scale. Widen the probe interval to 120–300 s for
   free-tier sites to quarter the row volume + CPU.
5. **Default to BYO-S3 / local** so storage + egress stays the customer's cost.
   Reserve the managed bucket for paid tiers; add a GCS lifecycle rule to drop cold
   chunks to Nearline/Coldline.
6. **Region/tier** — asia-south1 (Mumbai) is Cloud Run Tier-2 (~20% higher always-on
   CPU). A Tier-1 region (e.g. us-central1) trims the Cloud Run line ~15–20% if
   India latency isn't a hard requirement.

> **Assumptions:** on-demand asia-south1 list prices, 730 hr/mo, no CUDs (real bills
> run lower); Cloud SQL shared-core price is the softest figure (quotes $9–26/mo);
> probe rows ~150 B at 30–90 day retention (unbounded retention makes Cloud SQL disk
> the silent scaler — ~194 GB at 10k sites/90 d).

---

## 2. Market benchmarks

**The space is overwhelmingly per-site priced** ($1–5/site/mo) and that's what
fleet managers expect. Three sub-models dominate: per-site pay-as-you-go (best fit),
tiered site bundles, and flat-unlimited (the anti-SaaS positioning).

| Product | Model | Entry | Free tier |
|---|---|---|---|
| ManageWP (GoDaddy) | Per-site PAYG + per-feature add-ons | $0 base; add-ons $1–2/site each | Unlimited sites, monthly backups, basic |
| WP Umbrella | Per-site base + add-ons | ~$1.99/site/mo | None (14-day trial) |
| MainWP | Flat unlimited license (anti-SaaS) | $29/mo, $199/yr, $499 lifetime | Free self-hosted plugin |
| InfiniteWP | Free panel + annual license tiers | $147–$647/yr | Free self-hosted base |
| WP Remote | Tiered site bundles + add-ons | ~$29/mo (5 sites) → ~$999/mo (100) | Free monitoring |
| BlogVault | Tiered bundles, annual, storage bundled | $149/yr (1 site) | Trial only |
| MalCare | Tiered bundles (security+backup) | $99/yr (1 site) | Free scanner |
| Patchstack | Per-site + bundles | Free 3 slots; ~$5/site; Dev $79/mo (25) | Free 3 site slots |
| UpdraftPlus Premium | Per-license-count + storage tiers | $70/yr (2 sites) | Free backup plugin |
| WPMU DEV | Tiered all-in-one membership | ~$2.50/mo (1) → $83.33/mo (unlimited) | Free Hub dashboard |
| GoDaddy Pro | Free fleet dashboard + flat tiers | Free (500 sites); $49.99/$99.99/mo | Free: 500 sites, basic |
| UptimeRobot / Pingdom / Better Stack | Per-monitor tiers | Free 50 / $15/mo / from $24/mo | Generous free monitors |

**Free-tier norm:** generous on site count, gated on features/frequency/retention.
A 2-site free tier reads stingy vs peers, but is sustainable **if** Free can't use
the managed bucket.

**Price bands that matter:** monitoring + basic management $0–2/site/mo; managed
daily backup ~$2–4/site/mo (drops sharply in bundles).

---

## 3. Recommended pricing

| Tier | Price | Sites / key limits | Target | Margin |
|---|---|---|---|---|
| **Free (Hosted)** | $0 | 2–3 sites, full-featured, **BYO / no managed bucket**, 5-min uptime, 1 org | Solo dev evaluating | Funnel |
| **Starter** | $15/mo ($144/yr) | 10 sites, 5 seats, 50 GB managed, 30-day retention, daily backups | Freelancer / small studio | >90% |
| **Agency** ⭐ | $59/mo ($590/yr) | 50 sites, 20 seats, 250 GB managed, 90-day retention, hourly backups | WP agency (core revenue tier) | ~80% |
| **Scale** | $169/mo ($1690/yr) | 200 sites, unlimited seats, 1 TB managed then $0.03/GB-mo, 1-min uptime | Large agency / reseller | High |
| **BYO-Storage** (modifier) | −30% on any paid tier | Same limits, backups must target customer-owned S3/local | Cost-sensitive agencies | Higher |
| **Self-Hosted (AGPL)** | Free forever | Unlimited sites/seats/storage; run your own CP | OSS / privacy-sensitive | — |

**Open-core stance:** hosted-convenience-first with a *thin, honest* open-core seam —
**not feature-crippling.** The entire multi-tenant substrate (orgs, 4-role RBAC,
per-site RLS, per-site sharing, tamper-evident audit, age-encrypted backups,
BYO-S3/local) is already in the AGPL tree and stays there. The hosted tiers sell
convenience (managed infra, managed storage, higher frequency/retention), not
gated-off existing capability.

---

## 4. What to meter (and how it maps onto the code)

- **Sites** — the headline cap, a **hard gate at create time** (not a background
  sweep). `COUNT(*) FROM sites WHERE tenant_id=:org AND status != 'archived'` so
  archiving frees a slot. Enforced inside the **same tenant-scoped tx** as the
  INSERT, at **all three enrollment paths** (`site.Service.Create`, the
  `InEnrollTx` consume path in `connection_repo.go`, and
  `ConsumeEnrollmentCode`/`CreatePending`) via one shared `quota.CheckSiteCreate`
  helper — otherwise an agent enrolls past the cap via a pairing code (**top
  bypass risk**).
- **Managed backup storage bytes** — `SUM(size) FROM backup_chunks WHERE
  tenant_id=:org` **only for `Kind='cp'`** destinations. `KindLocal`/`KindS3Compat`
  (BYO) consume zero WPMgr storage and **must be excluded** or BYO customers are
  wrongly blocked. Soft gate: warn at 80%, block new `cp` snapshots at 100% (never
  half-fail an in-flight backup).
- **Restore egress** per billing period — the real margin tail-risk ($0.12/GB).
  Per-tier allowance + metered overage; surface it in the billing UI. **The one
  place to be disciplined.**
- **Seats** — `COUNT(DISTINCT user_id) FROM memberships WHERE tenant_id=:org`,
  soft cap, enforced at invitation-accept / membership-upsert. (Decide whether
  per-site-share collaborators count.)
- **Media-optimizer encoder minutes** — a paid/usage add-on + boolean entitlement;
  off on Free.
- **Feature toggles** (boolean, not counters) — uptime probe interval (5-min free
  vs 1-min paid, which also quarters free-tier probe rows), backup cadence
  (daily vs hourly/real-time — the most-monetized lever), scan cadence, audit
  retention window. **Do not** meter raw backup storage when the customer owns
  the bucket.

---

## 5. Unit-economics verdict

Margins are **healthy-to-excellent on every paid tier**; the business is gated by
the **fixed floor**, not per-unit cost. Floor ~$300–470/mo (list, no CUDs),
dominated by the always-on media-encoder. Per-site marginal cost is a rounding
error. Therefore: **don't optimize per-site; optimize the floor** (encoder
`minScale=0`, CUDs, right-size Cloud SQL) and **discipline restore egress** — the
only line that can make a heavy user unprofitable under flat tiers.

---

## 6. Build roadmap (behind `WPMGR_HOSTED`)

0. **Plan/quota model.** Migration: `ALTER TABLE tenants ADD plan text DEFAULT 'free',
   plan_overrides jsonb, stripe_customer_id, stripe_subscription_id,
   subscription_status, plan_period_end`. (`tenants` has no RLS — no policy work.)
   `internal/billing` with a typed `Quota` + `planLadder map[string]Quota` as the
   code source of truth; `QuotaService.Resolve(tenantID)`. Gate all enforcement
   behind `WPMGR_HOSTED` so self-host stays uncapped.
1. **Site-cap enforcement at every create path** (load-bearing). COUNT inside the
   same `InTenantTx` before INSERT; return `domain.Forbidden('site_quota_exceeded')`.
   Shared `quota.CheckSiteCreate(tx, tenantID)` at all three paths + a test (mirror
   `rls_isolation_test.go`) proving the 3rd site is rejected on Free **and** the
   public `/enroll` path is gated.
2. **Seat + storage soft-gates.** Seats at invitation-accept/membership-upsert.
   Storage: `SUM(backup_chunks.size)` where `Kind='cp'` before minting CP presigned
   PUTs; BYO bypasses. Warn 80% / block 100%.
3. **Stripe** (under `WPMGR_HOSTED`). Lazy Customer on first upgrade; Checkout
   Session; Billing Portal. Signature-verified `POST /billing/webhook` handling
   `subscription.*` + `invoice.payment_failed`; map price→plan; idempotent (store
   last-processed event id) + a reconcile job.
4. **Free-tier wiring + non-destructive downgrade.** Seed `plan='free'` on hosted
   signup. On downgrade/`payment_failed` past grace: **never delete sites** — set
   `past_due` (grace) then an over-limit read-only state blocking new site creation
   + new CP backups while keeping data + restores available. Self-host →
   `plan='unlimited'`.
5. **Usage metering + exposure.** Redis-cached (~5 min) rollup → `GET
   /api/v1/orgs/{orgId}/usage` (sites, seats, storage, restore-egress). Report
   Scale-tier metered storage to Stripe. Record plan changes in the audit log.
6. **Upgrade/downgrade flows.** Owner-gated `POST /orgs/{orgId}/billing/checkout`
   and `/billing/portal`. Stripe handles proration; next `quota.Resolve` picks up
   the new plan.
7. **Billing/admin UI (web).** Org Settings → Billing: plan, usage meters
   (sites x/2, storage GB/cap, seats, restore-egress), Upgrade → Checkout, Manage →
   Portal, over-limit banner with a "switch to BYO-Storage" nudge. Owner/admin only;
   reuse the Impeccable settings shell.
8. **Cost-control follow-ups** (ship alongside). Media-encoder `minScale=0`; re-tier
   Cloud SQL to a CUD-eligible dedicated instance; per-tier restore-egress allowance;
   default new orgs to BYO/no-managed-storage on Free.

---

## 7. Decisions still open (founder's call)

- **Target market:** solo/freelancer-first (Starter is the anchor, Free is the
  funnel) vs agency/reseller-first (Agency/Scale are the anchors, must beat
  flat-unlimited rivals) vs balanced.
- **Hosted vs self-host emphasis:** is hosted the business and self-host goodwill,
  or is self-host the heart and hosted a convenience? Sets where engineering goes.
- **Free-tier size:** keep 2 sites (full-featured) or raise to 3–5 to match peer
  perception. Pure positioning — both are financially safe if Free can't use the
  managed bucket.
- **Annual vs monthly default + discount depth** (~2 months free is the norm).
- **Feature gating:** recommend **no** hard gates on existing AGPL features; decide
  whether to reserve a future SAML/SCIM/white-label enterprise module as a paid
  seam.
- **Seat-vs-share semantics:** do per-site-share collaborators consume a paid seat?
  (A pricing *and* an enforcement-correctness decision — settle before Step 2.)
- **Trial strategy:** permanent Free tier, a time-boxed 14-day full-feature trial,
  or both.

---

## 8. Risks

- **TOCTOU / cap-bypass across enroll paths** — three ways a site enters an org;
  miss one and an agent enrolls past the cap. Single shared helper + tests.
- **Always-on media-encoder bleed** — ~$300/mo even with zero customers; can make
  early unit economics negative. Scale to `minScale=0`.
- **Restore egress** — the real margin tail-risk under flat tiers; needs an
  allowance + overage.
- **AGPL fairness backlash** — if the cap or any existing feature appears removed
  from the self-hosted build, the community reacts. Keep self-host uncapped + full.
- **Stripe webhook reliability / state drift** — missed/mis-ordered/non-idempotent
  webhooks desync `tenants.plan`. Idempotency + reconcile job.
- **Downgrade data-loss footgun** — naive enforcement could delete the 3rd+ site on
  downgrade. Must be non-destructive (read-only over-limit, never delete).
- **Storage quota must exclude BYO** — or BYO customers get wrongly blocked for
  bytes you never stored.
- **"A site" counting semantics** — archived/soft-disconnected sites and shared
  collaborators need explicit, tested rules in the count predicate.
