# Security suite: feature-parity gap analysis + build plan

> Built from a clean-room study of a leading commercial WordPress security plugin (GPLv2 reference kept OUTSIDE this repo, never committed or shipped). We replicate techniques and coverage with original code; we never copy the reference's source and never name it in shipped code.

## Decisions locked (2026-06-20)
- **Start:** Phase 1 (WordPress hardening tweaks + durable ban list) now — zero external-feed decisions, highest value/effort.
- **Phase 4 vulnerability feed:** plan around **Wordfence Intelligence** (free vulnerability data); we author virtual-patch WAF rules for top CVEs ourselves. (Patchstack/WPScan remain paid upgrade options for turnkey vPatch rules.)
- Build is CP-first, split across agent / control-plane / dashboard specialists, every feature ships its named test + docs DoD.

## Gap counts
**Totals (deduped across clusters — counting distinct the reference security features):** ~78 distinct features.

- **HAVE (shipped today): 6** — early-IP WAF deny gate, sliding-window brute-force lockout, agent hash-chained activity log, CP append-only audit log + verify, WP.org core-checksum file-integrity scan, operator-dashboard 2FA (TOTP+passkeys). Plus self-contained infra (RLS/RBAC/superadmin, uptime+TLS monitoring, autologin) that has no direct the reference equivalent but covers the "remote management" role.
- **PARTIAL (related capability, incomplete): ~18** — lockout→ban escalation, ban list, firewall engine (deny-only, no parameter rules), firewall lockout/logging, scheduled scans, security-check-pro probe (CP can probe), file-change engine (core-only), file-writing (perf suite writes config), online plugin-checksums infra, user-groups (CP RBAC only), notification center (CP alerts only), global settings, dashboard cards, server-config writer, geolocation (DB-IP ASN lib), CP operator TOTP/passkeys/backup-codes vs site-user, user action logging (activity log covers most), light DB backup (full backup suite).
- **MISSING (not in WPMgr): ~54.**

**Biggest missing clusters (by count and by value):**
1. **Vulnerability + malware scanning (entire domain) — ~9 features missing, all P1/P2, all gated on a feed decision.** This is the largest single gap and the headline product capability.
2. **WordPress hardening tweaks — ~13 features missing**, all self-contained, cheap, high parity-optics value (disable file editor, XML-RPC, REST restriction, SSL enforce, salts, db-prefix, admin-user, etc.).
3. **WP-site-user 2FA + password security — ~13 features missing** (TOTP/email/backup for managed-site users, interstitial, onboarding, strong-pw, HIBP, requirements engine, expiration). WPMgr only has *operator* 2FA today.
4. **Firewall parameter-rule engine + virtual patching — ~5 features**, P1, partly gated on the same feed.
5. **Server-config hardening (system-tweaks, file-perms, hide-backend, ban-rules) — ~8 features**, self-contained, P2.
6. **Pro advanced auth (passwordless/magic-link, site-user passkeys, fingerprinting/trusted-devices, restrict-by-country, version-mgmt) — ~9 features**, P3, several geo/feed-gated.

**Replicability split of the missing/partial set:** ~75% self-contained (copy/reimplement, no service), ~15% needs-feed (free or buildable: HIBP, plugin checksums, IP reputation), ~10% needs a paid/operator service (vuln+malware feed, CAPTCHA, MaxMind paid tier).

## Strategic recommendation
## Strategic Recommendation

**The honest shape of this:** ~75% of the reference plugin is plain GPL PHP you can re-implement with no service at all, and WPMgr is unusually well-positioned to host the rest because it's *already* a fleet manager with a Go control plane, centralized login-event ingestion, an SSRF-hardened outbound prober, a CP-signed updater, and a hash-chained audit log. So "bulletproof parity" is mostly a build-it problem, not a buy-it problem — with exactly **one** genuine buy-vs-build fork.

**Quick parity (do first, no decisions needed):** the WordPress hardening tweaks (Phase 1) and full file-integrity (Phase 2) are cheap, self-contained, and immediately make WPMgr look like a real security product. These ~25 features carry the best value/effort ratio and should ship before anything feed-dependent. Site-user 2FA + password policy (Phase 3) is the next-best self-contained block and closes the most glaring gap (today only *operators* have 2FA; managed-site users have none).

**The hard, service-dependent moat is the vulnerability + malware feed.** This is the only thing copying GPL code cannot give you. The WAF *engine* and the whole scan/scheduling/fixer pipeline are copyable; the *data* (which plugin version is vulnerable, the per-CVE virtual-patch rules) is a recurring feed cost. Everything the reference vendor charges for ultimately rides on this one hosted DB. There is no free-code substitute.

**What WPMgr can own without any third party** (and should, because it's differentiating): cross-fleet IP reputation (you already ingest every site's login events — this is *better* positioned than the reference vendor' opt-in network), the SSL/proxy/real-IP-header probe (CP already probes sites), HIBP compromised-passwords (free public API), and WP.org checksums (already have core; plugins are free to add).

**Build-first order:** Phase 1 (hardening) → Phase 2 (file integrity) → Phase 3 (site-user auth) — all parallelizable across agent/CP/dashboard specialists, all shippable before any feed contract. Then the scanner (Phase 4) once Gate 0 is decided.

**Product decisions you must make before we start building the gated phases:**
1. **Vulnerability feed: free vs paid.** Wordfence Intelligence is free and the most cost-effective vuln data source, but ships *no* WAF rules — you'd hand-author vPatch rules for top CVEs. Patchstack is paid but ships ready-made vPatch rules (closest to the reference's actual moat). WPScan is another paid option. Decide: free-vuln-data + self-authored-rules, or pay Patchstack for turnkey vPatch. This gates Phase 4.
2. **Malware/blocklist verdicts.** Separate from vuln data. Google Safe Browsing (free-ish) / Sucuri / VirusTotal / your own crawler. Decide scope — or defer malware-content scanning to a later release and ship vuln-only first.
3. **Geo feed.** Extend the existing offline DB-IP lib (free, you already own the asset) vs ship MaxMind GeoLite2 (license key). Gates Phase 6 only — low urgency.
4. **CAPTCHA.** Confirm "operator brings their own keys" is acceptable (it is the only sane model) — pluggable reCAPTCHA/Turnstile/hCaptcha.
5. **Network brute-force positioning.** Confirm we build the shared IP-reputation list *in-house* off existing event ingestion (recommended — it's a natural moat and needs no third party) rather than integrating AbuseIPDB.

**Net take:** Greenlight Phases 1-3 immediately (no decisions, high parity optics, ~75% of the feature count). Make the vuln-feed decision in parallel so Phase 4 — the actual differentiator — can start the moment Phases 1-2 free up the specialists. Treat the IP-reputation and SSL-probe features as in-house wins to highlight, since WPMgr can do them *better* than the reference vendor by virtue of being the fleet itself.

## What we cannot simply copy (data, not code)
**Plain answer: the code is all copyable; the *data* and the *license/update plumbing* are not.**

the reference plugin is GPLv2, so every line of its PHP — the lockout counters, ban list, hide-backend, the bundled Patchstack WAF *engine*, 2FA/TOTP/WebAuthn, password requirements, file-change hashing, server-config generators, hardening tweaks, the user-groups policy model, notification center — is legally and technically replicable. We can re-implement all of it agent-side and CP-side. Copying the GPL plugin gives you the *mechanics* of every feature.

**What you do NOT get by copying code — three categories:**

1. **Hosted DATA FEEDS (the actual moat).** These are populated and served by the reference vendor/Patchstack and carry the value:
   - **`the reference's hosted vulnerability/malware feed`** — the Patchstack-sourced **vulnerability + malware + virtual-patch rule database**. The plugin only sends an inventory and renders the answer; the firewall has *zero rules* without this feed. This is the single biggest non-replicable dependency. Build-or-buy: license **Patchstack** or **WPScan** vulnerability API, or build CVE ingestion from **Wordfence Intelligence** (the free vuln feed — most cost-effective) + the WordPress.org `.well-known` plugin-vulnerabilities feed; the WAF executor engine itself is copyable so we'd author/import vPatch rules separately.
   - **`the reference's hosted IP-reputation API`** — the **cross-fleet IP-reputation blocklist** for network brute force. Code gives you the client, not the shared list. **We can build this ourselves** — WPMgr already centrally ingests every managed site's login events, so the CP is a natural fleet-wide reputation aggregator. Optional augment: AbuseIPDB/StopForumSpam/Spamhaus.
   - **MaxMind/GeoLite2** geolocation DB (for fingerprinting / restrict-by-country). A licensed binary data feed; we'd ship GeoLite2 or extend our existing offline DB-IP lib.

2. **The LICENSE + UPDATE SERVER** (`the reference's license/update server` / `the reference's license/update server`). Gates which Pro modules load and delivers updates. A clone has no access. **Already solved** for WPMgr — we ship a CP-signed agent update manifest (ADR-042); the agent has no license gate.

3. **Operator-keyed third-party SaaS** (CAPTCHA: reCAPTCHA/Turnstile/hCaptcha) — not replicable by code, but inherently pluggable: the operator supplies their own keys. That's acceptable, not a blocker.

**Free/public feeds that look like dependencies but aren't a problem:** HIBP Pwned Passwords (free, k-anonymous, no key) and the WordPress.org core/plugin checksum + salt APIs (free, public) — we call them directly and cache in the CP.

**Bottom line:** The only thing money/effort genuinely buys that copying GPL code cannot is the **vulnerability/malware feed**. Everything else is either self-buildable (IP reputation, SSL-proxy probe, release-dates), free-public (HIBP, WP.org checksums), operator-supplied (CAPTCHA), or already solved (our own updater).

## External services + our replacement strategy
## External Services the reference Depends On + WPMgr Replacement Strategy

| # | Service / Endpoint | Purpose | Data sent | Returns | Auth | Free? | WPMgr replacement strategy |
|---|---|---|---|---|---|---|---|
| 1 | **the reference's hosted vulnerability/malware feed** `POST /api/scan` (Accept v1.1) | Master site scan: vuln + blocklist + malware | `{wordpress, plugins{slug:ver}, themes{slug:ver}, mutedIssues[]}` | `{entries:{vulnerabilities[]+firewall_rules, blacklist[], malware[]}}` | HMAC-SHA1 over body w/ license key (or X-SiteRegistration) | No (Patchstack/the reference vendor commercial) | **BUILD a CP-owned scanner orchestrator** that ingests a vulnerability feed (decision below) and matches agent-shipped software inventory. This is the moat — biggest decision. |
| 2 | same host `GET /available-firewall-rules?ids[]` | Which vulns have a vPatch rule | ps-vuln-ids | subset of ids | license HMAC | No | Comes free if we license a feed that ships WAF rules (Patchstack); otherwise author our own vPatch rules. |
| 3 | same host `POST /api/register-site` | Unlicensed sub-site verify handshake | `{url, keyPair, verifyTarget}` | `{key}` | — | No | **Not needed** — WPMgr CP↔agent is already a trusted Ed25519 channel. |
| 4 | **the reference's hosted IP-reputation API** `?action=check-ip / report-ip / request-key / activate-key` | Cross-fleet brute-force IP reputation | `{apikey, behavior, ip, site, timestamp, login{details,agent}}` + HMAC | `{block, cache_ttl, report_ttl}` | apikey + HMAC-SHA1 secret | No (proprietary network) | **BUILD our own** — WPMgr already centrally ingests every managed site's login events (IngestLoginEvents). The CP is the natural cross-fleet reputation aggregator: serve a shared blocklist back to agents. Optional 3rd-party augment: AbuseIPDB / StopForumSpam / Spamhaus DROP. |
| 5 | **api.pwnedpasswords.com** `GET /range/{5}` | Compromised-password check (HIBP) | first 5 hex of SHA1 (k-anonymity) | `SUFFIX:COUNT` lines | none | **YES (free, no key)** | **Call directly** — proxy through CP to cache the corpus fleet-wide, or call from agent. Optionally self-host the downloadable HIBP corpus (~25-40GB). |
| 6 | **ssl-proxy-detect** `POST /` + `GET /config.json` | External callback to find real-IP `$_SERVER` header + TLS support | `{site, key:time:HMAC, pid}` | `{complete, remote_ip?, ssl_supported?}` | time-HMAC | No (their prober) | **BUILD in CP** — WPMgr api already makes outbound probes (uptime/TLS). Have CP POST a signed nonce then hit the site over http+https from CP IPs; agent reports which header held the CP's known IP. Zero third party. |
| 7 | **api.wordpress.org/core/checksums/1.0/** | Official WP core file md5s | version+locale | `{checksums:{path:md5}}` | none | **YES** | **Already have** (apps/api/internal/scan/checksums.go, Postgres-cached 30d/6h). |
| 8 | **downloads.wordpress.org/plugin-checksums/{slug}/{ver}.json** | Official .org plugin file md5s | (URL only) | `{files:{path:{md5}}}` | none | **YES** | **Wire it** — extend the CP checksum cache to plugins; agent verifies plugin files. |
| 9 | **api.wordpress.org/plugins/info/1.0/** | Is slug a real .org plugin | `action=plugin_information, request=serialize({slug})` | plugin info | none | **YES** | Call from CP when deciding whether plugin-checksum verification applies. |
| 10 | **api.wordpress.org/secret-key/1.1/salt/** | Fresh wp-config salts | none | 8 define() lines | none | **YES (fallback only)** | **Generate locally** in agent (crypto/rand / random_int) — no network needed. |
| 11 | **s3.amazonaws.com/package-hash/** | the reference vendor' OWN product file hashes | package+version | `{path:md5}` | none | proprietary | **Not needed** — for our own first-party plugins, publish a signed checksum manifest (ADR-042 updater already does this). |
| 12 | **s3.amazonaws.com/downloads/.../wordpress-release-dates.json** | WP version→release date for "outdated" age scoring | none | version→date map | none | proprietary-hosted but trivial | **Self-generate** a static JSON in CP. |
| 13 | **geoip.maxmind.com/geoip/v2.1/city** + **download.maxmind.com geoip_download (GeoLite2)** + **ip-api.com/json** | IP→country/city/latlong for fingerprinting, restrict-admin, maps | IP | geo | MaxMind acct/key (ip-api free) | mixed | **Extend WPMgr's existing offline DB-IP lib** (already used for ASN/host detection) to city/country, or ship GeoLite2 mmdb in CP with a license key. Data-feed decision. |
| 14 | **www.google.com/recaptcha/api/siteverify** · **hcaptcha.com/siteverify** · **challenges.cloudflare.com/turnstile/v0/siteverify** | CAPTCHA bot verdicts | token+secret+remoteip | success/score | operator keys | provider tiers | **Pluggable per-site** — agent calls whichever the operator configures with their own keys. Not replicable by code; that's fine. |
| 15 | **qr-code** | TOTP enroll QR (fallback) | otpauth URI (incl username) | QR PNG | none | — | **Render locally** (GD / client-side JS QR). WPMgr operator TOTP already does this. |
| 16 | **api.mapbox.com / api.mapquestapi.com** | Static map image of login IP in emails | latlong+key | PNG | operator key | provider tiers | Cosmetic — drop or pluggable with operator key. |
| 17 | **vendor notices/news endpoints** | Vendor notices + dashboard news card | none | JSON | none | free | Drop (vendor marketing). WPMgr CP owns notices. |
| 18 | **the reference's license/update server / the reference's license/update server (/updater)** | Pro license validation + Pro module delivery | PBKDF2 auth_token + packages | update package | license | proprietary | **Replaced** by WPMgr's CP-signed agent update manifest (ADR-042). |
| 19 | **the reference's license/update server/app-passwords/...** | Cross-site settings-import app-password broker | OAuth-ish | app password | app_id | proprietary | Not needed — CP owns config; if cross-site import wanted, broker it ourselves. |

**Net external-service picture:** Free/public and directly callable — HIBP (5), WP.org core+plugin checksums+info+salt (7-10). Build-in-CP (no third party) — IP reputation (4), SSL/proxy probe (6), release-dates (12). Genuine third-party with operator keys — CAPTCHA (14), MaxMind paid tier (13). The one strategic buy-vs-build — the **vulnerability+malware feed (1,2)**.

## Feature matrix
## the reference plugin — Full Feature Matrix vs WPMgr

Status legend: **have** = shipped in WPMgr today · **partial** = related capability exists but incomplete · **missing** = not in WPMgr. Replicable: **self** = copyable from GPL code · **needs-feed** = code copyable but value needs a data feed · **needs-svc** = requires a third-party SaaS the operator configures. Priority P1 (table-stakes for "bulletproof") → P4 (cosmetic/optional). Effort in rough eng-weeks (agent+CP+dash combined).

### Domain: Login attack protection
| Feature | What it does | Technique | Layer | External service | WPMgr status | Replicable | Priority | Effort |
|---|---|---|---|---|---|---|---|---|
| Local brute-force lockout (per-IP + per-user) | Lock host/user after N fails in a window; optional instant 'admin' ban | authenticate@10000 + temp/lockouts COUNT-in-window | WP-PHP | none | **have** (sliding-window 3-tier in class-login-protection.php) | self | P1 | done |
| Lockout→permanent-ban escalation | Repeated lockouts promote a host to a durable ban | create_lockout() blacklist threshold → bans | WP-PHP | none | **partial** (has per-IP temp block + global tier; no durable promote-to-ban list) | self | P1 | 0.5 |
| Network brute-force (shared IP reputation) | Block IPs flagged across the fleet; report local offenders | HMAC-signed check-ip/report-ip to ipcheck-api | SaaS-call | the reference vendor IPCheck | **missing** (but WPMgr already ingests every site's login events centrally) | needs-svc OR self-build | P1 | 3 |
| NBF key registration/activation | Self-service apikey+secret fetch | request-key/activate-key | SaaS-call | the reference vendor IPCheck | **n/a** (replaced by WPMgr CP auth) | self | P3 | incl. above |
| Ban Users — durable IP/range ban list | Manual + auto IP/CIDR/user-agent bans | bans + Lib_IP_Tools intersect | WP-PHP | none | **partial** (deny_cidrs in config, no managed ban-list CRUD / comments / actor) | self | P1 | 1 |
| Ban Users — server-config (.htaccess/nginx) ban rules | Push bans to web-server for pre-PHP block | config-generators emit Deny/deny | server-config | none | **missing** (WPMgr blocks at mu-plugin PHP layer only) | self | P2 | 1.5 |
| Default blocklist (HackRepair bundled) | Static bad-bot/UA list | bundled .inc | server-config | none (bundled) | **missing** | self | P3 | 0.5 |
| Ban custom user-agents | Deny operator-supplied UA strings | config-generators 403 rules | server-config | none | **missing** | self | P3 | 0.5 |
| Hide Backend (secret login slug) | Move wp-login/wp-admin to secret slug + token | setup_theme request routing + token cookie | WP-PHP | none | **missing** | self | P2 | 1.5 |
| Email confirmation state | Track confirmed/unconfirmed email | after_password_reset/profile_update meta | WP-PHP | none | **missing** | self | P4 | 0.5 |
| Early-IP WAF deny gate | 403 deny_cidrs before WP boots | a-wpmgr-waf.php mu-plugin | WP-PHP | none | **have** | self | — | done |

### Domain: Two-factor / strong auth (WP site side)
| Feature | What it does | Technique | Layer | External | WPMgr status | Replicable | Priority | Effort |
|---|---|---|---|---|---|---|---|---|
| Pluggable 2FA provider architecture | Swappable TOTP/Email/Backup per-user | Two_Factor_Provider abstract + filters | WP-PHP | none | **missing** (WPMgr has OPERATOR 2FA, not managed-site-user 2FA) | self | P2 | 2 |
| TOTP (authenticator app) | RFC-6238 codes, QR enroll | calc_totp + local GD QR | WP-PHP | qr-code (fallback only) | **partial** (CP operator TOTP exists; site-user TOTP missing) | self | P2 | incl. |
| Email 2FA code | Mail a one-time code | wp_hash token + notification center | WP-PHP | none (site mailer) | **missing** | self | P2 | 1 |
| Backup codes | 10 single-use recovery codes | wp_hash_password set in meta | WP-PHP | none | **partial** (operator recovery codes exist; site-user missing) | self | P3 | 0.5 |
| Login interstitial enforcement | Demand 2FA after password | Login_Interstitial signed session | WP-PHP | none | **missing** | self | P2 | 1 |
| 2FA onboarding + reminders | Guided setup, re-prompt cadence | interstitial + reminder notification | WP-PHP | none | **missing** | self | P3 | 1 |
| Per-group enforcement + risk-based | Force 2FA by group / weak-pw / vuln-site | requirement_reason matcher | WP-PHP | none (vuln-site needs feed) | **missing** | self | P2 | 1 |
| Remember-device (trusted, 30d) | Skip 2FA on known device | hashed cookie + fingerprint ≥85% | WP-PHP | none (needs fingerprint lib) | **missing** | partial | P3 | 1 |
| Application Passwords hardening | Scope app-passwords REST/XML-RPC + RO/RW | wp_authenticate_application_password_errors | WP-PHP | none | **missing** | self | P3 | 1 |
| Block XML-RPC for 2FA users | Reject pw-based XML-RPC for 2FA users | authenticate@100 | WP-PHP | none | **missing** | self | P3 | 0.25 |
| CAPTCHA on auth forms | reCAPTCHA/Turnstile/hCaptcha | provider siteverify | SaaS-call | Google/CF/hCaptcha | **missing** | needs-svc | P3 | 1.5 |
| the reference vendor Sync remote 2FA mgmt | Remote list/override 2FA | inbound sync verbs | SaaS-call | the reference vendor Sync | **n/a** (WPMgr CP is the replacement) | self | P4 | incl. CP |

### Domain: Password security
| Feature | What it does | Technique | Layer | External | WPMgr status | Replicable | Priority | Effort |
|---|---|---|---|---|---|---|---|---|
| Refuse compromised passwords (HIBP) | Reject breached passwords | k-anonymity SHA1 range query | WP-PHP+SaaS | api.pwnedpasswords.com (free) | **missing** | needs-feed (free) | P2 | 1 |
| Strong password (zxcvbn 4) | Force strong pw on set/change | vendored zxcvbn-php server-side | WP-PHP | none | **missing** | self | P2 | 1 |
| Strong-password admin scanner | Flag admins without strong pw | cached strength meta → REST issues | WP-PHP | none | **missing** | self | P3 | 0.5 |
| Password requirements engine | Validate/reuse-block/last-changed/forced-change interstitial | hooks + meta registry | WP-PHP | none | **missing** | self | P2 | 1.5 |
| Password age/expiration | Force change after N days | time math vs last-changed | WP-PHP | none | **missing** | self | P3 | 0.5 |

### Domain: Firewall / WAF
| Feature | What it does | Technique | Layer | External | WPMgr status | Replicable | Priority | Effort |
|---|---|---|---|---|---|---|---|---|
| Application firewall rule engine | Inspect request, BLOCK/LOG/REDIRECT/WHITELIST | vendored Patchstack Processor, mu-early | WP-PHP | none (engine) | **partial** (early-IP WAF deny only; no parameter-rule engine) | self | P1 | 3 |
| Virtual-patching auto-ingest | Vuln→firewall rule auto-create/clean | Ingestor on vulnerability_was_seen | WP-PHP | scanner feed | **missing** | needs-feed | P1 | 1.5 |
| Firewall rule SOURCE / remote feed | Per-vuln WAF rules pulled per-site | scan response firewall_rules[] | SaaS-call | site-scanner (Patchstack) | **missing** | needs-svc | P1 | gated |
| User-defined firewall rules + REST | Operator CRUD custom rules | REST/Rules + Repository | WP-PHP | none | **missing** | self | P2 | 1 |
| Firewall-triggered lockout | Rate-limit rule-hit IPs | type=lockout do_lockout | WP-PHP | none | **partial** (login lockout exists; not rule-hit) | self | P2 | 0.5 |
| Firewall logging + Site Health | Record blocks + rule counts | Logs filters + add_action | WP-PHP | none | **partial** (activity log exists, no firewall events) | self | P2 | 0.5 |
| Protect system files (.htaccess) | Deny readme/wp-config/install/.git | system-tweaks config-gen | server-config | none | **missing** | self | P2 | 1 |
| Disable directory browsing | Options -Indexes | config-gen | server-config | none | **missing** | self | P3 | 0.25 |
| Disable PHP exec in uploads/plugins/themes | Block direct .php execution | per-dir RewriteRule [F] | server-config | none | **missing** | self | P2 | 0.5 |
| Server-config writer (Apache/LiteSpeed/nginx) | Materialize rules into config | Lib_Config_File marker blocks | server-config | none | **partial** (perf/cache suite already writes .htaccess/nginx) | self | P2 | 1 |

### Domain: Vulnerability + malware scanning
| Feature | What it does | Technique | Layer | External | WPMgr status | Replicable | Priority | Effort |
|---|---|---|---|---|---|---|---|---|
| Site Scan (master: vuln+blocklist+malware) | One scan → result code + issues | inventory POST, server returns findings | SaaS-call | site-scanner | **missing** | needs-svc | P1 | gated |
| Known-vuln detection (plugin/theme/core) | List CVEs + severity + fixed_in | server-side slug+version match | SaaS-call | Patchstack feed | **missing** | needs-feed | P1 | gated |
| Virtual-patch availability badge | Show which vulns are WAF-patchable | available-firewall-rules | SaaS-call | scanner | **missing** | needs-feed | P2 | incl. |
| Domain blocklist check | Flag domain on reputation lists | server-side aggregate (incl Sucuri) | SaaS-call | scanner/Sucuri | **missing** | needs-svc | P2 | 2 |
| Malware content scan | Detect site serving malware | external scan by URL | SaaS-call | scanner | **missing** | needs-svc | P1 | gated |
| Sub-site verify handshake | Prove site ownership pre-scan | keyPair + REST verify | SaaS-call+WP | scanner | **n/a** (WPMgr CP↔agent already trusted) | self | P4 | incl. |
| Vulnerability Fixer (auto-remediate) | One-click update to fixed version | WP upgrader gated by caps | WP-PHP | none (uses .org updates) | **missing** | self | P2 | 1 |
| Scheduled/recurring scans | Auto-scan on schedule + retry/back-off | scheduler loop + exponential back-off | WP-PHP wrapping SaaS | scanner | **partial** (WPMgr has River scan orchestration for core-integrity) | partial | P2 | 1 |
| Security Check Pro (proxy/IP-header + TLS probe) | External callback finds real-IP header + SSL | inbound HMAC callback | SaaS-call+WP | ssl-proxy-detect | **partial** (CP already probes sites for uptime/TLS — natural fit) | self (CP-side) | P2 | 1 |

### Domain: File integrity + filesystem hardening
| Feature | What it does | Technique | Layer | External | WPMgr status | Replicable | Priority | Effort |
|---|---|---|---|---|---|---|---|---|
| File-change detection (core engine) | Hash whole FS, diff vs baseline, report A/C/R | chunked resumable md5+mtime walker | WP-PHP | none | **partial** (CP-orchestrated CORE scan exists; no full-FS baseline/diff) | self | P2 | 1.5 |
| Baseline + self-write expected-hashes | Store last-good + own writes to avoid FPs | Distributed_Storage + expected_hashes | WP-PHP | none | **missing** | self | P2 | 1 |
| Online verify — WP.org CORE checksums | Compare core files to official md5 | core/checksums API | SaaS-call | api.wordpress.org (free) | **have** (checksums.go, Postgres-cached) | self | — | done |
| Online verify — WP.org PLUGIN checksums | Verify .org plugin files | plugin-checksums json | SaaS-call | downloads.wordpress.org (free) | **missing** | needs-feed (free) | P2 | 1 |
| Online verify — vendor S3 package hashes | Verify vendor's own products | s3 package-hash | SaaS-call | the reference vendor S3 (proprietary) | **partial** (WPMgr has ADR-042 signed-manifest updater for own agent) | self | P4 | incl. |
| File-change notify + log + sync verb | Email + audit-log + remote read | notification center + Log | WP-PHP | none | **partial** (audit log exists) | self | P3 | 0.5 |
| File permissions audit | List actual vs recommended perms | fileperms() read-only | WP-PHP | none | **missing** | self | P3 | 0.5 |
| File writing (managed .htaccess/nginx/wp-config) | Write delimited managed blocks | Lib_Config_File update_* | server-config | none | **partial** (perf suite writes config; no wp-config block writer) | self | P2 | 1 |

### Domain: WordPress hardening tweaks
| Feature | What it does | Technique | Layer | External | WPMgr status | Replicable | Priority | Effort |
|---|---|---|---|---|---|---|---|---|
| Disable file editor | DISALLOW_FILE_EDIT | wp-config define | WP-PHP | none | **missing** | self | P2 | 0.25 |
| XML-RPC control (disable/pingbacks) | 3-state XML-RPC control | filters + server-config deny | WP-PHP+config | none | **missing** | self | P2 | 0.5 |
| Block XML-RPC multiauth | Stop system.multicall amplification | authenticate@0 405-die | WP-PHP | none | **missing** | self | P3 | 0.25 |
| REST API restriction | Gate users/comments/etc anon access | rest_dispatch_request cap checks | WP-PHP | none | **missing** | self | P2 | 0.5 |
| Login identifier restriction | Email/username/both only | remove authenticate filters | WP-PHP | none | **missing** | self | P3 | 0.25 |
| Force unique nickname | Prevent username harvesting | profile_update hook | WP-PHP | none | **missing** | self | P3 | 0.25 |
| Disable zero-post author archives | 404 author enum pages | template_redirect 404 | WP-PHP | none | **missing** | self | P3 | 0.25 |
| Enforce SSL / force HTTPS | Redirect + FORCE_SSL_ADMIN + URL rewrite | wp-config + filters | WP-PHP | none | **missing** | self | P3 | 0.5 |
| Regenerate salts/keys | Rotate 8 auth keys | wp-config rewrite, local CSPRNG | WP-PHP | api.wordpress.org salt (fallback) | **missing** | self | P3 | 0.5 |
| Change DB table prefix | Rename wp_ + repoint options/meta | $wpdb DDL + wp-config | WP-PHP | none | **missing** (destructive, CP-orchestrated task) | self | P4 | 1 |
| Change content directory | Rename wp-content | rename + defines | WP-PHP | none | **missing** (deprecated upstream) | self | P4 | 0.5 |
| Change 'admin' username | Rename admin user | $wpdb UPDATE | WP-PHP | none | **missing** | self | P3 | 0.25 |
| Change user ID 1 | Reassign first user off ID 1 | delete+reinsert+repoint FKs | WP-PHP | none | **missing** | self | P4 | 0.5 |

### Domain: Policy, notifications, orchestration
| Feature | What it does | Technique | Layer | External | WPMgr status | Replicable | Priority | Effort |
|---|---|---|---|---|---|---|---|---|
| User Groups policy model | Per-role/user feature gating (the backbone) | user_groups + Matcher | WP-PHP | none | **partial** (WPMgr has RBAC/RLS on CP side, not per-WP-user site policy) | self | P2 | 2 |
| Notification Center | Unified security-email hub (recipients/schedule) | notifications registry + scheduler | WP-PHP | none (site mailer) | **partial** (CP has alerts/email pipeline) | self | P2 | 1.5 |
| Security Check (free) | One-click apply recommended hardening | enforce_activation/setting walk | WP-PHP | none | **missing** | self | P2 | 1 |
| Security Check Pro | Auto proxy/IP-header + SSL detection | hosted callback | SaaS-call+WP | ssl-proxy-detect | **partial** (CP can probe — build in-house) | self (CP) | P2 | incl. scanner |
| Global settings (lockout policy/allow-list/proxy mode) | Site-wide policy knobs | Config_Settings + filters | WP-PHP | none | **partial** (security config exists, narrower) | self | P2 | 1 |
| Usage telemetry opt-in | Anonymous analytics | StellarWP Telemetry | SaaS-call | telemetry.stellarwp.com | **n/a** (CP owns fleet telemetry) | self | P4 | — |
| Core logging infra | Shared event/audit log + severities | Log db/file backend | WP-PHP | none | **have** (agent hash-chained log + CP append-only) | self | — | done |
| Core module/settings framework | Registry + caps + actors REST | Modules + container | WP-PHP | none | **partial** (WPMgr has own module system) | self | P3 | — |
| Security dashboard cards | At-a-glance widgets | dashboard REST + cards | WP-PHP+React | reference vendor news feed (cosmetic) | **partial** (WPMgr has fleet dashboards, no security cards) | self | P2 | 2 |
| Light DB backup | Scheduled SQL dump + email | SHOW CREATE + INSERT batches | WP-PHP | none | **partial** (WPMgr has full backup suite) | self | P4 | — |
| Privacy/GDPR tools | Exporter/eraser + policy text | wp_privacy hooks | WP-PHP | none | **missing** | self | P4 | 0.5 |

### Domain: Pro premium tier
| Feature | What it does | Technique | Layer | External | WPMgr status | Replicable | Priority | Effort |
|---|---|---|---|---|---|---|---|---|
| Passwordless / magic-link login | Email one-click or passkey login | opaque token via mailer | WP-PHP | none | **missing** | self | P3 | 1.5 |
| Magic-link lockout bypass | Email link to bypass own lockout | OT_LOCKOUT_BYPASS token | WP-PHP | none | **missing** | self | P3 | 0.5 |
| WebAuthn / passkeys (site users) | FIDO2 register+login | pure-PHP ceremony + DB creds | WP-PHP | none | **partial** (CP operator passkeys exist; site-user missing) | self | P3 | 2 |
| Device fingerprinting + trusted devices | Detect unrecognized login, email approve | weighted IP+UA sources, geo distance | WP-PHP | geo feed (IP signal) | **missing** | needs-feed | P3 | 2 |
| Session-hijacking protection | Destroy session on fingerprint flip | on_auth compare + destroy | WP-PHP | geo feed | **missing** | needs-feed | P3 | 0.5 |
| Geolocation subsystem | IP→country/city/latlong + maps | MaxMind/GeoLite2/ip-api | SaaS-call | MaxMind/ip-api | **partial** (CP has offline DB-IP ASN lib — extend to city/country) | needs-feed | P3 | 1.5 |
| Restrict admin by country | Block admin from disallowed countries | pipeline + CF-IPCOUNTRY + geo | WP-PHP | geo feed | **missing** | needs-feed | P3 | 1 |
| Version management / auto-update policy | Update policy + vuln-driven auto-update + outdated scan | auto_update filters + transients | WP-PHP | scanner feed + release-dates JSON | **missing** | needs-feed | P2 | 2 |
| Security headers (emit + verify) | CSP/XFO/XCTO/Referrer + self-verify | header provider + self-request | server-config | none (self-request) | **missing** | self | P2 | 1 |
| User action logging | Login/post/plugin/theme events per group | hook fan-out → log | WP-PHP | none | **partial** (agent activity log covers most) | self | P3 | 0.5 |
| Settings import/export (cross-site) | Export/import + cross-site connect | PclZip + Role/User map + app-pw broker | WP-PHP | the reference's license/update server (cross-site only) | **n/a** (CP owns config) | self | P4 | — |
| Inactive-user / 2FA reminder check | Report stale users + nudge 2FA | scheduler + last-seen query | WP-PHP | none | **missing** | self | P4 | 0.5 |
| Privilege escalation (temp role) | Temporary auto-expiring role grant | meta + plugins_loaded inject | WP-PHP | none | **missing** | self | P4 | 0.5 |
| Pro dashboard widgets | Security profile/event widgets | REST cards + wp dashboard widget | WP-PHP | none | **partial** | self | P4 | — |
| WP-CLI surface | CLI for all features | wp the reference subcommands | WP-PHP | none | **n/a** (WPMgr drives via CP) | self | P4 | — |
| Remote messages/service status | Vendor notices + feature flags | scheduled remote get | SaaS-call | assets | **n/a** | self | P4 | — |
| Pro license + update server | License check + Pro delivery | the reference's license/update server/updater | SaaS-call | the reference's license/update server | **n/a** (WPMgr CP-signed updater, ADR-042) | self | — | done |

## Phased build plan
## Phased Build Plan — CP-first to "Bulletproof" Parity

Conventions per WPMgr: route to layer specialists (wp-agent-engineer / backend-architect / frontend-architect / security-reviewer / devops-engineer / docs-writer), CP-first, every feature deploys all touched layers, every fix ships its named test, docs + landing as DoD. Each phase: **goal · features · layer · deps**.

### GATE 0 — External-data-feed decisions (BLOCKS Phases 4 & 5; nothing else)
Two product decisions must be made before building the scanner / network-brute-force:
- **Vulnerability+malware feed:** Wordfence Intelligence (free vuln feed) vs Patchstack API (paid, ships ready-made vPatch WAF rules) vs WPScan API vs self-ingest. Recommendation: start with **Wordfence Intelligence** (free) for vuln data; decide separately whether to license Patchstack for vPatch rules or hand-author top-CVE rules.
- **Geo feed:** extend existing offline DB-IP lib to city/country vs ship GeoLite2 mmdb (license key). Only gates Phase 6.
Everything in Phases 1-3 is buildable today with no feed decision.

---

### PHASE 1 — Hardening tweaks + ban-list (quick parity wins, zero feed)
**Goal:** ship the broad, cheap, self-contained surface that makes WPMgr visibly "a security product." Highest value/effort ratio.
- **Features:** durable IP/range/UA ban list with comments+actor (CP CRUD + agent enforce); lockout→ban escalation; server-config ban rules (.htaccess/nginx/litespeed); WP hardening toggles — disable file editor, XML-RPC control + multiauth block, REST API restriction, login-identifier restriction, force-unique-nickname, disable zero-post author archives, enforce SSL, regenerate salts (local CSPRNG), disable directory browsing, disable PHP exec in uploads/plugins/themes, protect system files; file permissions audit.
- **Layer:** CP (policy store + signed push) → agent (apply via existing config-writer + wp-config block writer + mu-plugin) → dashboard (toggles UI).
- **Deps:** reuse the existing perf-suite .htaccess/nginx config-writer; add a wp-config managed-block writer (agent-empty-base-path-guard rule applies). RLS on any new tenant tables.

### PHASE 2 — Full file-integrity (extend the existing core scanner)
**Goal:** go from core-only to full filesystem integrity, the natural extension of what already ships.
- **Features:** full-FS chunked resumable baseline+diff (Added/Changed/Removed); self-write expected-hashes tracking; **plugin/theme integrity via free WP.org plugin-checksums** (downloads.wordpress.org) cached in CP; signed-manifest verify for first-party plugins; file-change notification + audit-log events + dashboard surface.
- **Layer:** agent (extend ScanCommand hash walker) + CP (extend checksums.go cache to plugins; diff classifier) + dashboard (findings UI).
- **Deps:** builds directly on internal/scan + checksums.go. Free feeds only.

### PHASE 3 — WP-site-user auth hardening (2FA + passwords for managed sites)
**Goal:** bring agency-managed-site *users* under 2FA + password policy (today only WPMgr operators have 2FA).
- **Features:** pluggable 2FA provider arch (TOTP/email/backup) for site users; login interstitial enforcement; per-group enforcement; 2FA onboarding/reminders; strong-password (vendored zxcvbn) + HIBP compromised-password check (free) + password requirements engine (reuse-block, last-changed, forced-change interstitial) + expiration; block XML-RPC for 2FA users; Application Passwords scoping; hide-backend secret slug; CAPTCHA (pluggable, operator keys).
- **Layer:** agent (all enforcement) + CP (policy config + HIBP proxy/cache + push) + dashboard (per-site/per-group policy UI).
- **Deps:** port zxcvbn-php; reuse operator-2FA crypto patterns; HIBP via CP cache (Phase 0 not required — it's free).

### PHASE 4 — Vulnerability scanner + virtual-patching firewall (THE moat) — GATED on Gate 0
**Goal:** the headline capability — detect vulnerable plugins/themes/core and virtually-patch them.
- **Features:** CP-owned scan orchestrator (agent ships inventory → CP matches vuln feed → findings); known-vuln detection w/ severity + fixed_in; Vulnerability Fixer (one-click WP-upgrader update — self-contained); scheduled scans + retry/back-off (extend River); the parameter-rule **WAF engine** in the agent (port the vendored Patchstack processor — copyable) with user-defined-rule REST/CRUD; **virtual-patch auto-ingest** (vuln→rule) and firewall-triggered lockout + firewall logging.
- **Layer:** CP (vuln-feed ingest + matcher + scan worker + rule distribution) → agent (WAF engine + rule store + scan inventory + fixer) → dashboard (vuln list, fixer, firewall rules + logs).
- **Deps:** **Gate 0 vuln-feed decision.** WAF engine is buildable now in parallel; only the *rule content* waits on the feed.

### PHASE 5 — Network brute-force / cross-fleet IP reputation (build in-house) — soft-gated
**Goal:** replicate Network Brute Force using WPMgr's own fleet, not a third party.
- **Features:** CP aggregates failed-login reports across all managed sites (already ingested) → computes a shared blocklist → distributes to agents; first-attempt block of known-bad IPs; optional 3rd-party augment (AbuseIPDB/StopForumSpam).
- **Layer:** CP (reputation aggregation + scoring + blocklist API) → agent (consume blocklist in login-protection + early WAF) → dashboard (reputation view).
- **Deps:** builds on existing IngestLoginEvents; no external service needed (decide only whether to augment).

### PHASE 6 — Geo-aware advanced auth (Pro tier) — GATED on geo feed
**Goal:** device fingerprinting, trusted devices, restrict-admin-by-country, session-hijacking protection.
- **Features:** device fingerprinting (IP+UA weighted, geo distance) + trusted-device email-approve; session-hijacking destroy-on-flip; restrict admin by country (+CF-IPCOUNTRY); version-management auto-update policy + vuln-driven auto-update (consumes Phase 4 feed); passwordless/magic-link login + magic-link lockout bypass; WebAuthn passkeys for site users.
- **Layer:** agent (fingerprint/session/login flows) + CP (geo lookup + policy) + dashboard.
- **Deps:** **Gate 0 geo decision**; version-management depends on Phase 4 feed.

### PHASE 7 — Orchestration + operator surface (polish to "bulletproof")
**Goal:** the glue that makes it feel like one product.
- **Features:** per-site/per-group **security policy model** (CP analogue of User Groups); **Security Check** one-click "apply recommended hardening" wizard; **CP-side SSL/proxy + real-IP-header detection probe** (build in-house, replaces security-check-pro); security headers emit+verify; security dashboard cards (active blocks, scan status, file changes, vuln summary); notification/alerts for new security event types (extend CP alerts); user action logging gaps; malware/domain-blocklist verdicts (decide feed: Google Safe Browsing / Sucuri / VirusTotal or own crawler); privilege escalation, inactive-user check (low priority).
- **Layer:** CP-heavy (policy, probe, alerts) + dashboard (cards, wizard, policy UI) + agent (headers, probe responder).
- **Deps:** rides on all prior phases; the CP SSL/proxy probe reuses the existing uptime-probe client.
