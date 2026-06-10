package email

import (
	"context"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"errors"
	"fmt"
	"log/slog"
	"strings"
	"time"

	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/agentcmd"
	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
)

// Encryptor age-encrypts and decrypts provider secrets. *cryptbox.AgeIdentity
// satisfies it. Declared as an interface so the service is unit-testable with a
// fake, and so the age-guard can be checked without importing cryptbox.
type Encryptor interface {
	Encrypt(plaintext []byte) ([]byte, error)
	Decrypt(ciphertext []byte) ([]byte, error)
}

// AgentEmailClient is the CP->agent command surface for email operations.
// *agentcmd.Client satisfies it. Declared as an interface for testability.
type AgentEmailClient interface {
	SyncEmailConfig(ctx context.Context, siteID uuid.UUID, siteURL string, req agentcmd.EmailConfigRequest) (agentcmd.EmailConfigResult, error)
	SendTestEmail(ctx context.Context, siteID uuid.UUID, siteURL string, req agentcmd.SendTestEmailRequest) (agentcmd.SendTestEmailResult, error)
	// ResendEmail is the Phase 4b agent command for resending a stored email.
	// Phase 4a: the client stub returns ok=false until the agent implements it.
	ResendEmail(ctx context.Context, siteID uuid.UUID, siteURL string, req agentcmd.ResendEmailRequest) (agentcmd.ResendEmailResult, error)
}

// SiteLookup resolves a site's agent URL. The perf package's pattern.
type SiteLookup interface {
	GetSiteURL(ctx context.Context, tenantID, siteID uuid.UUID) (string, error)
}

// repository is the persistence surface. *Repo satisfies it.
type repository interface {
	GetSiteConfig(ctx context.Context, tenantID, siteID uuid.UUID) (Config, error)
	GetOrgConfig(ctx context.Context, tenantID uuid.UUID) (Config, error)
	GetSecretCiphertext(ctx context.Context, tenantID, siteID uuid.UUID) ([]byte, error)
	GetOrgSecretCiphertext(ctx context.Context, tenantID uuid.UUID) ([]byte, error)
	UpsertSiteConfig(ctx context.Context, in upsertRepoInput) (Config, error)
	UpsertOrgConfig(ctx context.Context, in upsertRepoInput) (Config, error)
	ListSiteConfigs(ctx context.Context, tenantID uuid.UUID, limit, offset int32) ([]Config, error)
	// Phase 3 — email log
	IngestLogBatch(ctx context.Context, tenantID, siteID uuid.UUID, entries []IngestEntry) (int64, error)
	ListSiteLog(ctx context.Context, tenantID, siteID uuid.UUID, f LogListFilter) (LogListPage, error)
	GetLogEntry(ctx context.Context, tenantID, siteID, id uuid.UUID) (LogDetail, error)
	ListFleetLog(ctx context.Context, tenantID uuid.UUID, f LogListFilter) (LogListPage, error)
	GetSiteStats(ctx context.Context, tenantID, siteID uuid.UUID, from, to time.Time) (EmailStats, error)
	GetFleetStats(ctx context.Context, tenantID uuid.UUID, from, to time.Time) (EmailStats, error)
	DeleteLogsOlderThan(ctx context.Context, cutoffTs time.Time, batchSize int64) (int64, error)
	// Phase 4a — suppression + webhook dedup + log actions
	UpsertSuppression(ctx context.Context, in UpsertSuppressionInput) (Suppression, error)
	UpsertSuppressionTenantTx(ctx context.Context, in UpsertSuppressionInput) (Suppression, error)
	GetSuppression(ctx context.Context, tenantID, id uuid.UUID) (Suppression, error)
	IsSuppressed(ctx context.Context, tenantID, siteID uuid.UUID, email string) (bool, error)
	ListSiteSuppression(ctx context.Context, tenantID, siteID uuid.UUID, f SuppressionFilter) (SuppressionPage, error)
	ListFleetSuppression(ctx context.Context, tenantID uuid.UUID, f SuppressionFilter) (SuppressionPage, error)
	DeleteSuppression(ctx context.Context, tenantID, id uuid.UUID) error
	ListSuppressionDeltas(ctx context.Context, tenantID, siteID uuid.UUID, sinceCursor string, limit int) (SuppressionDeltaPage, error)
	InsertWebhookEventDedup(ctx context.Context, in WebhookEventInput, suppressionID *uuid.UUID) (bool, error)
	MarkEmailLogBounced(ctx context.Context, tenantID, siteID uuid.UUID, messageID, status string) error
	// m61: webhook security.
	GetConfigByRouteTokenHash(ctx context.Context, tokenHash []byte) (Config, error)
	GetConfigByRouteTokenHashWithSecret(ctx context.Context, tokenHash []byte) (Config, []byte, error)
	SetWebhookFields(ctx context.Context, tenantID, configID uuid.UUID, tokenHash, signingKeyCT []byte, setSigningKey bool, sesTopicArns []string) (Config, error)
	PruneWebhookDedup(ctx context.Context, cutoffTs time.Time) (int64, error)
	GetEmailLogBodyStored(ctx context.Context, tenantID, siteID, id uuid.UUID) (bool, error)
	IncrEmailLogResentCount(ctx context.Context, tenantID, siteID, id uuid.UUID) error
	DeleteEmailLogsBulk(ctx context.Context, tenantID, siteID uuid.UUID, ids []uuid.UUID) (int64, error)
}

// Service is the email domain business-logic layer. It owns:
//   - age-guard (refuses writes when no encryptor is wired)
//   - age-encrypt on secret writes; decrypt only when building a push command
//   - org-wide default resolution (per-site row → org default → ErrNotFound)
//   - provider validation
//   - dispatching sync_email_config and send_test_email commands to the agent
type Service struct {
	repo     repository
	enc      Encryptor // nil when WPMGR_SITE_DEST_AGE_SECRET not configured
	agent    AgentEmailClient
	siteLook SiteLookup
	log      *slog.Logger
	// pub is the site-event bus used to emit email.suppression_updated and
	// email.bounce SSE events. May be nil (guarded before use).
	pub EventPublisher
}

// NewService builds the email service. enc may be nil (all secret-write paths
// return ServiceUnavailable("email_crypto_unwired")); agent may be nil (command
// dispatch paths return graceful errors until Phase 2).
func NewService(repo *Repo, enc Encryptor, log *slog.Logger) *Service {
	if log == nil {
		log = slog.Default()
	}
	return &Service{repo: repo, enc: enc, log: log}
}

// SetAgentClient wires the CP->agent command client + site-URL resolver.
func (s *Service) SetAgentClient(agent AgentEmailClient, siteLook SiteLookup) {
	s.agent = agent
	s.siteLook = siteLook
}

// SetPublisher wires the SSE event publisher. Called from main.go after the
// publisher is constructed. A nil publisher is always safe (emits are skipped).
func (s *Service) SetPublisher(pub EventPublisher) {
	s.pub = pub
}

// ---------------------------------------------------------------------------
// GetConfig — per-site config with org-wide fallback resolution
// ---------------------------------------------------------------------------

// GetConfig returns the resolved config for a site. If no per-site row exists it
// falls back to the org-wide default. Returns domain.NotFound when neither exists.
func (s *Service) GetConfig(ctx context.Context, tenantID, siteID uuid.UUID) (Config, error) {
	cfg, err := s.repo.GetSiteConfig(ctx, tenantID, siteID)
	if err == nil {
		return cfg, nil
	}
	if !errors.Is(err, ErrNotFound) {
		return Config{}, domain.Internal("email_get_config", "failed to load site email config").WithCause(err)
	}

	// Fall back to the org-wide default.
	orgCfg, err := s.repo.GetOrgConfig(ctx, tenantID)
	if err != nil {
		if errors.Is(err, ErrNotFound) {
			return Config{}, domain.NotFound("email_config_not_found", "no email config for this site or org")
		}
		return Config{}, domain.Internal("email_get_org_config", "failed to load org email config").WithCause(err)
	}
	// Surface inherited config with the site's perspective (SiteID points to the
	// queried site so the frontend knows what was inherited).
	orgCfg.SiteID = &siteID
	return orgCfg, nil
}

// GetOrgConfig returns the org-wide default config row.
func (s *Service) GetOrgConfig(ctx context.Context, tenantID uuid.UUID) (Config, error) {
	cfg, err := s.repo.GetOrgConfig(ctx, tenantID)
	if err != nil {
		if errors.Is(err, ErrNotFound) {
			return Config{}, domain.NotFound("email_org_config_not_found", "no org-wide email config")
		}
		return Config{}, domain.Internal("email_get_org_config", "failed to load org email config").WithCause(err)
	}
	return cfg, nil
}

// ---------------------------------------------------------------------------
// UpsertConfig — per-site and org-wide
// ---------------------------------------------------------------------------

// UpsertSiteConfig creates or updates the per-site config. When in.SecretRaw is
// non-nil it age-encrypts it; nil preserves the existing stored ciphertext.
func (s *Service) UpsertSiteConfig(ctx context.Context, in UpsertInput) (Config, error) {
	if err := s.validateUpsert(in); err != nil {
		return Config{}, err
	}

	ri, err := s.buildRepoInput(ctx, in)
	if err != nil {
		return Config{}, err
	}

	saved, err := s.repo.UpsertSiteConfig(ctx, ri)
	if err != nil {
		return Config{}, domain.Internal("email_upsert_config", "failed to save email config").WithCause(err)
	}

	// Best-effort: push the decrypted config to the agent. A push failure is
	// non-fatal — the config is already saved. Log the warning and return the
	// saved config cleanly so the save always succeeds even when the agent is
	// offline.
	if s.agent != nil && s.siteLook != nil && in.SiteID != nil {
		siteURL, urlErr := s.siteLook.GetSiteURL(ctx, in.TenantID, *in.SiteID)
		if urlErr != nil {
			s.log.Warn("email: saved config but could not resolve site URL for agent sync",
				slog.String("site_id", in.SiteID.String()),
				slog.Any("error", urlErr),
			)
			return saved, nil
		}
		secret, secretErr := s.resolveEffectiveSecret(ctx, in, saved)
		if secretErr != nil {
			s.log.Warn("email: saved config but could not resolve secret for agent sync",
				slog.String("site_id", in.SiteID.String()),
				slog.Any("error", secretErr),
			)
			// Push without the secret — the agent will be configured for the
			// provider/from fields; the secret push will retry on next save.
			secret = ""
		}
		req := s.buildAgentConfigReq(saved, secret)
		if _, syncErr := s.agent.SyncEmailConfig(ctx, *in.SiteID, siteURL, req); syncErr != nil {
			s.log.Warn("email: config stored but agent sync failed",
				slog.String("site_id", in.SiteID.String()),
				slog.Any("error", syncErr),
			)
			// Non-fatal: the save succeeded; return the saved config cleanly.
			return saved, nil
		}
	}

	return saved, nil
}

// UpsertOrgConfig creates or updates the org-wide default config.
func (s *Service) UpsertOrgConfig(ctx context.Context, in UpsertInput) (Config, error) {
	if in.SiteID != nil {
		return Config{}, domain.Validation("email_org_config_site_id", "org-wide config must have no site_id")
	}
	if err := s.validateUpsert(in); err != nil {
		return Config{}, err
	}

	ri, err := s.buildRepoInput(ctx, in)
	if err != nil {
		return Config{}, err
	}

	saved, err := s.repo.UpsertOrgConfig(ctx, ri)
	if err != nil {
		return Config{}, domain.Internal("email_upsert_org_config", "failed to save org email config").WithCause(err)
	}
	// TODO(org-propagation): saving the org-wide default does NOT yet push to
	// sites that inherit it (sites with no per-site row). Propagating to all
	// enrolled sites on every org-config save risks surprise-routing mail for
	// operators who have not explicitly opted each site in. Implement as a
	// deliberate follow-up: enumerate inheriting sites and push SyncEmailConfig
	// to each, with rate-limiting and a per-site opt-in gate.
	return saved, nil
}

// ListSiteConfigs returns all per-site config rows for the tenant.
func (s *Service) ListSiteConfigs(ctx context.Context, tenantID uuid.UUID, limit, offset int32) ([]Config, error) {
	configs, err := s.repo.ListSiteConfigs(ctx, tenantID, limit, offset)
	if err != nil {
		return nil, domain.Internal("email_list_configs", "failed to list email configs").WithCause(err)
	}
	return configs, nil
}

// ---------------------------------------------------------------------------
// SendTest
// ---------------------------------------------------------------------------

// SendTest dispatches the send_test_email signed command to the site's agent.
// Phase 1: the agent does not yet implement this command and will return a
// "command not found" (404) response — that is expected until Phase 2. The
// route dispatches and surfaces the agent's response gracefully.
//
// TODO(phase2): the agent must implement send_test_email (see Phase 2 hooks
// section in the per-site-email plan).
func (s *Service) SendTest(ctx context.Context, tenantID, siteID uuid.UUID, in TestSendInput) (TestSendResult, error) {
	if s.agent == nil || s.siteLook == nil {
		// Agent not wired (signing key not configured). Surface gracefully.
		return TestSendResult{
			OK:     false,
			Detail: "agent command client not configured; test email cannot be dispatched",
		}, nil
	}

	siteURL, err := s.siteLook.GetSiteURL(ctx, tenantID, siteID)
	if err != nil {
		return TestSendResult{}, domain.NotFound("email_site_not_found", "site not found or not enrolled")
	}

	// Belt-and-suspenders: push the current email config to the agent before
	// sending so that a freshly saved config is always reflected, and so the
	// agent never hits "no email config — run sync_email_config first" on the
	// test path. Failures here surface as a clear TestSendResult rather than
	// the opaque downstream error from the agent.
	cfg, cfgErr := s.GetConfig(ctx, tenantID, siteID)
	if cfgErr != nil {
		return TestSendResult{OK: false, Detail: "could not load email config for agent sync: " + cfgErr.Error()}, nil
	}
	// Resolve the effective secret: try per-site first, then fall back to org
	// secret when the config was inherited (SiteID from GetConfig points to the
	// queried site in both cases, so we check SecretSet to know if there is
	// anything stored).
	var syncSecret string
	if s.enc != nil && cfg.SecretSet {
		// Per-site secret first.
		ct, ctErr := s.repo.GetSecretCiphertext(ctx, tenantID, siteID)
		if ctErr == nil && len(ct) > 0 {
			if plain, dErr := s.enc.Decrypt(ct); dErr == nil {
				syncSecret = string(plain)
			}
		}
		// If no per-site ciphertext (inherited org config), try the org secret.
		if syncSecret == "" {
			orgCt, orgCtErr := s.repo.GetOrgSecretCiphertext(ctx, tenantID)
			if orgCtErr == nil && len(orgCt) > 0 {
				if plain, dErr := s.enc.Decrypt(orgCt); dErr == nil {
					syncSecret = string(plain)
				}
			}
		}
	}
	syncReq := s.buildAgentConfigReq(cfg, syncSecret)
	if _, syncErr := s.agent.SyncEmailConfig(ctx, siteID, siteURL, syncReq); syncErr != nil {
		return TestSendResult{OK: false, Detail: "could not sync config to agent: " + syncErr.Error()}, nil
	}

	res, err := s.agent.SendTestEmail(ctx, siteID, siteURL, agentcmd.SendTestEmailRequest{
		To:      in.To,
		Subject: in.Subject,
		Body:    in.Body,
	})
	if err != nil {
		// Non-domain error from the agent (e.g. unknown command until Phase 2).
		// Surface as ok=false with the raw detail rather than a 5xx, matching
		// the perf/security pattern for non-fatal agent command failures.
		return TestSendResult{OK: false, Detail: err.Error()}, nil
	}
	return TestSendResult{OK: res.OK, Detail: res.Detail}, nil
}

// ---------------------------------------------------------------------------
// SyncConfigToAgent
// ---------------------------------------------------------------------------

// SyncConfigToAgent pushes the stored email config to the site's agent.
// This is the explicit "Sync to site" action — distinct from the implicit
// sync that runs on Save and the pre-sync that runs before SendTest.
//
// Errors from the agent command are returned as TestSendResult{OK:false}
// (non-fatal, graceful) so the handler always responds 200 and lets the
// frontend display the outcome. Domain errors (site not found, no config)
// are returned as TestSendResult{OK:false} for the same reason.
func (s *Service) SyncConfigToAgent(ctx context.Context, tenantID, siteID uuid.UUID) (TestSendResult, error) {
	if s.agent == nil || s.siteLook == nil {
		return TestSendResult{
			OK:     false,
			Detail: "agent command client not configured; cannot sync",
		}, nil
	}

	// Resolve effective config (per-site → org fallback).
	cfg, err := s.GetConfig(ctx, tenantID, siteID)
	if err != nil {
		// domain.NotFound is not a 5xx — surface as ok=false.
		return TestSendResult{OK: false, Detail: "no email config to sync"}, nil
	}

	// Resolve the effective decrypted secret: per-site first, then org fallback
	// for inherited configs. ErrNotFound → empty secret (non-fatal).
	var secret string
	if s.enc != nil && cfg.SecretSet {
		ct, ctErr := s.repo.GetSecretCiphertext(ctx, tenantID, siteID)
		if ctErr == nil && len(ct) > 0 {
			if plain, dErr := s.enc.Decrypt(ct); dErr == nil {
				secret = string(plain)
			}
		}
		// No per-site ciphertext (inherited org config) — try the org secret.
		if secret == "" {
			orgCt, orgCtErr := s.repo.GetOrgSecretCiphertext(ctx, tenantID)
			if orgCtErr == nil && len(orgCt) > 0 {
				if plain, dErr := s.enc.Decrypt(orgCt); dErr == nil {
					secret = string(plain)
				}
			}
		}
	}

	siteURL, urlErr := s.siteLook.GetSiteURL(ctx, tenantID, siteID)
	if urlErr != nil {
		return TestSendResult{}, domain.NotFound("email_site_not_found", "site not found or not enrolled")
	}

	req := s.buildAgentConfigReq(cfg, secret)
	if _, syncErr := s.agent.SyncEmailConfig(ctx, siteID, siteURL, req); syncErr != nil {
		return TestSendResult{OK: false, Detail: syncErr.Error()}, nil
	}
	return TestSendResult{OK: true, Detail: "email config synced to site agent"}, nil
}

// ---------------------------------------------------------------------------
// internal helpers
// ---------------------------------------------------------------------------

// validateUpsert validates the UpsertInput before any DB or crypto work.
func (s *Service) validateUpsert(in UpsertInput) error {
	if !ValidProviderSlug(in.Provider) {
		return domain.Validation("email_invalid_provider",
			"provider must be one of: smtp, ses, sendgrid, mailgun, postmark")
	}
	if in.RetentionDays < 1 || in.RetentionDays > 365 {
		return domain.Validation("email_invalid_retention",
			"retention_days must be between 1 and 365")
	}
	return nil
}

// buildRepoInput resolves the secret ciphertext and assembles the upsertRepoInput.
// Age-guard: if SecretRaw is non-nil and no encryptor is wired, it returns
// ServiceUnavailable to prevent a plaintext secret reaching the DB.
func (s *Service) buildRepoInput(ctx context.Context, in UpsertInput) (upsertRepoInput, error) {
	ri := upsertRepoInput{
		TenantID:           in.TenantID,
		SiteID:             in.SiteID,
		Provider:           in.Provider,
		FromAddress:        in.FromAddress,
		FromName:           in.FromName,
		ForceFromEmail:     in.ForceFromEmail,
		ForceFromName:      in.ForceFromName,
		ReturnPath:         in.ReturnPath,
		Config:             in.Config,
		Mappings:           in.Mappings,
		DefaultConnection:  in.DefaultConnection,
		FallbackConnection: in.FallbackConnection,
		LogEmails:          in.LogEmails,
		StoreBody:          in.StoreBody,
		RetentionDays:      in.RetentionDays,
	}

	if in.SecretRaw != nil {
		// Age-guard: refuse to store when no encryptor is configured.
		if s.enc == nil {
			return upsertRepoInput{}, domain.ServiceUnavailable(
				"email_crypto_unwired",
				"secret encryption is not configured (WPMGR_SITE_DEST_AGE_SECRET missing); "+
					"save the config without the secret first, or configure the key and restart",
			)
		}
		ct, err := s.enc.Encrypt([]byte(*in.SecretRaw))
		if err != nil {
			return upsertRepoInput{}, domain.Internal("email_encrypt_secret", "failed to encrypt provider secret").WithCause(err)
		}
		ri.SetSecret = true
		ri.SecretCiphertext = ct
	}
	// SetSecret=false → the nil-sentinel in the SQL query preserves existing.

	return ri, nil
}

// ---------------------------------------------------------------------------
// Phase 3 — Email log ingest + viewer
// ---------------------------------------------------------------------------

// IngestLogBatch accepts a batch of agent-pushed log entries and upserts them
// into site_email_log. The tenant_id and site_id come exclusively from the
// verified agent identity (never the request body). Returns the max agent_seq
// accepted so the agent can advance its high-water cursor.
//
// Batch size is capped at maxIngestBatch; larger batches are rejected.
func (s *Service) IngestLogBatch(ctx context.Context, tenantID, siteID uuid.UUID, entries []IngestEntry) (IngestResult, error) {
	if len(entries) == 0 {
		return IngestResult{}, nil
	}
	if len(entries) > maxIngestBatch {
		return IngestResult{}, domain.Validation("email_ingest_batch_too_large",
			"batch exceeds the maximum of 500 entries per request")
	}
	maxSeq, err := s.repo.IngestLogBatch(ctx, tenantID, siteID, entries)
	if err != nil {
		return IngestResult{}, domain.Internal("email_ingest_log", "failed to ingest email log batch").WithCause(err)
	}
	return IngestResult{AckedThrough: maxSeq}, nil
}

// ListSiteLog returns a keyset-paginated list of email log entries for a site.
// Body is never included in the list response — use GetLogEntry for detail.
func (s *Service) ListSiteLog(ctx context.Context, tenantID, siteID uuid.UUID, f LogListFilter) (LogListPage, error) {
	page, err := s.repo.ListSiteLog(ctx, tenantID, siteID, f)
	if err != nil {
		return LogListPage{}, domain.Internal("email_list_log", "failed to list email log").WithCause(err)
	}
	return page, nil
}

// GetLogEntry returns a single email log entry including body (if stored) plus
// prev/next navigation IDs.
func (s *Service) GetLogEntry(ctx context.Context, tenantID, siteID, id uuid.UUID) (LogDetail, error) {
	detail, err := s.repo.GetLogEntry(ctx, tenantID, siteID, id)
	if err != nil {
		if errors.Is(err, ErrNotFound) {
			return LogDetail{}, domain.NotFound("email_log_not_found", "email log entry not found")
		}
		return LogDetail{}, domain.Internal("email_get_log", "failed to fetch email log entry").WithCause(err)
	}
	return detail, nil
}

// ListFleetLog returns a keyset-paginated cross-site email log list for a tenant.
func (s *Service) ListFleetLog(ctx context.Context, tenantID uuid.UUID, f LogListFilter) (LogListPage, error) {
	page, err := s.repo.ListFleetLog(ctx, tenantID, f)
	if err != nil {
		return LogListPage{}, domain.Internal("email_list_fleet_log", "failed to list fleet email log").WithCause(err)
	}
	return page, nil
}

// GetSiteStats returns summary + per-day + per-provider email stats for a site.
func (s *Service) GetSiteStats(ctx context.Context, tenantID, siteID uuid.UUID, from, to time.Time) (EmailStats, error) {
	stats, err := s.repo.GetSiteStats(ctx, tenantID, siteID, from, to)
	if err != nil {
		return EmailStats{}, domain.Internal("email_get_stats", "failed to get email stats").WithCause(err)
	}
	return stats, nil
}

// GetFleetStats returns fleet-wide email stats for a tenant.
func (s *Service) GetFleetStats(ctx context.Context, tenantID uuid.UUID, from, to time.Time) (EmailStats, error) {
	stats, err := s.repo.GetFleetStats(ctx, tenantID, from, to)
	if err != nil {
		return EmailStats{}, domain.Internal("email_get_fleet_stats", "failed to get fleet email stats").WithCause(err)
	}
	return stats, nil
}

// PruneOldLogs deletes one batch of expired email log rows across all tenants.
// Returns the number of rows deleted; the caller should loop until 0.
// Called by the EmailLogGCWorker periodic River job.
func (s *Service) PruneOldLogs(ctx context.Context, cutoffTs time.Time, batchSize int64) (int64, error) {
	deleted, err := s.repo.DeleteLogsOlderThan(ctx, cutoffTs, batchSize)
	if err != nil {
		s.log.Error("email log retention: prune failed", slog.String("err", err.Error()))
		return 0, err
	}
	return deleted, nil
}

// ---------------------------------------------------------------------------
// Phase 4a — Webhook fan-out + suppression
// ---------------------------------------------------------------------------

// HandleWebhookEvent is the central dispatch point for a verified webhook event.
// It:
//  1. Skips if the event type is not a suppression trigger.
//  2. Deduplicates via InsertWebhookEventDedup (ON CONFLICT DO NOTHING).
//  3. Upserts the suppression row for hard_bounce / complaint.
//  4. Marks the matching site_email_log row as bounced/complained.
//
// The (tenant_id, site_id) come from the event metadata injected by the agent.
// If metadata is absent both are nil and the suppression row is orphaned
// (logged with a warning; no cross-tenant guessing).
func (s *Service) HandleWebhookEvent(ctx context.Context, ev WebhookEventInput) error {
	if !isSuppressionEventType(ev.EventType) {
		return nil // not a suppression-triggering event; nothing to do
	}
	if ev.Email == "" {
		return nil
	}

	// Log a warning when tenant metadata is absent but continue — we still
	// write an orphaned dedup row for idempotency, and we suppress the email
	// if we can resolve tenant later.
	if ev.TenantID == nil {
		s.log.Warn("email webhook: no tenant metadata; suppression row will be orphaned",
			slog.String("provider", ev.Provider),
			slog.String("event_id", ev.ProviderEventID),
			slog.String("email", ev.Email),
		)
	}

	var suppressionID *uuid.UUID
	if ev.TenantID != nil {
		// Upsert the suppression row.
		sup, err := s.repo.UpsertSuppression(ctx, UpsertSuppressionInput{
			TenantID: *ev.TenantID,
			SiteID:   ev.SiteID,
			Email:    ev.Email,
			Reason:   ev.EventType, // hard_bounce | complaint
			Provider: ev.Provider,
			EventAt:  ptrNow(),
			// Store masked email (lower-cased) for display in the operator UI.
			// Full address is masked per PII policy (not the body content).
			StorePlaintext: true,
		})
		if err != nil {
			s.log.Error("email webhook: upsert suppression failed",
				slog.String("err", err.Error()),
				slog.String("email", ev.Email),
			)
			return domain.Internal("webhook_suppression_upsert", "failed to upsert suppression").WithCause(err)
		}
		suppressionID = &sup.ID

		// SSE: notify the dashboard that a suppression row was written.
		var displayEmail string
		if sup.Email != nil {
			displayEmail = maskEmail(*sup.Email)
		}
		publishSuppressionUpdated(ctx, s.pub, *ev.TenantID, ev.SiteID, displayEmail, sup.Reason)

		// Best-effort: mark the matching log entry bounced/complained.
		// m61 SHOULD-FIX #3: pass siteID so the update is site-scoped.
		if ev.ProviderEventID != "" && ev.SiteID != nil {
			logStatus := webhookEventToLogStatus(ev.EventType)
			if err := s.repo.MarkEmailLogBounced(ctx, *ev.TenantID, *ev.SiteID, ev.ProviderEventID, logStatus); err != nil {
				s.log.Warn("email webhook: mark log bounced failed",
					slog.String("err", err.Error()),
					slog.String("message_id", ev.ProviderEventID),
				)
				// Non-fatal — the suppression write succeeded.
			}

			// SSE: notify the dashboard that a log entry was flipped to bounced/complained.
			if *ev.SiteID != uuid.Nil {
				publishBounce(ctx, s.pub, *ev.TenantID, *ev.SiteID, ev.ProviderEventID, logStatus)
			}
		}
	}

	// Dedup sentinel write (always, even for orphaned events).
	inserted, err := s.repo.InsertWebhookEventDedup(ctx, ev, suppressionID)
	if err != nil {
		s.log.Warn("email webhook: dedup insert failed", slog.String("err", err.Error()))
		// Non-fatal — the suppression was already written.
	}
	if !inserted {
		s.log.Debug("email webhook: duplicate event dropped",
			slog.String("provider", ev.Provider),
			slog.String("event_id", ev.ProviderEventID),
		)
	}
	return nil
}

// AddSuppression adds a manual suppression entry for a site or fleet.
// reason must be "manual" or "unsubscribe"; hard_bounce and complaint come from webhooks.
func (s *Service) AddSuppression(ctx context.Context, in UpsertSuppressionInput) (Suppression, error) {
	if in.Reason == "" {
		in.Reason = "manual"
	}
	if in.Reason != "manual" && in.Reason != "unsubscribe" {
		return Suppression{}, domain.Validation("suppression_reason_invalid",
			"manual suppression reason must be 'manual' or 'unsubscribe'")
	}
	if in.Email == "" {
		return Suppression{}, domain.Validation("suppression_email_required", "email is required")
	}
	sup, err := s.repo.UpsertSuppressionTenantTx(ctx, UpsertSuppressionInput{
		TenantID:       in.TenantID,
		SiteID:         in.SiteID,
		Email:          in.Email,
		Reason:         in.Reason,
		Provider:       "manual",
		StorePlaintext: true,
	})
	if err != nil {
		return Suppression{}, domain.Internal("suppression_add", "failed to add suppression").WithCause(err)
	}
	return sup, nil
}

// ListSiteSuppression returns a paginated suppression list for a site.
func (s *Service) ListSiteSuppression(ctx context.Context, tenantID, siteID uuid.UUID, f SuppressionFilter) (SuppressionPage, error) {
	page, err := s.repo.ListSiteSuppression(ctx, tenantID, siteID, f)
	if err != nil {
		return SuppressionPage{}, domain.Internal("suppression_list", "failed to list suppression").WithCause(err)
	}
	return page, nil
}

// ListFleetSuppression returns a paginated fleet-scope suppression list.
func (s *Service) ListFleetSuppression(ctx context.Context, tenantID uuid.UUID, f SuppressionFilter) (SuppressionPage, error) {
	page, err := s.repo.ListFleetSuppression(ctx, tenantID, f)
	if err != nil {
		return SuppressionPage{}, domain.Internal("suppression_list_fleet", "failed to list fleet suppression").WithCause(err)
	}
	return page, nil
}

// DeleteSuppression removes a suppression entry by id.
func (s *Service) DeleteSuppression(ctx context.Context, tenantID, id uuid.UUID) error {
	if err := s.repo.DeleteSuppression(ctx, tenantID, id); err != nil {
		return domain.Internal("suppression_delete", "failed to delete suppression").WithCause(err)
	}
	return nil
}

// ListSuppressionDeltas returns suppression entries created after the cursor
// for the agent suppression-fetch endpoint.
func (s *Service) ListSuppressionDeltas(ctx context.Context, tenantID, siteID uuid.UUID, sinceCursor string) (SuppressionDeltaPage, error) {
	page, err := s.repo.ListSuppressionDeltas(ctx, tenantID, siteID, sinceCursor, 500)
	if err != nil {
		return SuppressionDeltaPage{}, domain.Internal("suppression_deltas", "failed to list suppression deltas").WithCause(err)
	}
	return page, nil
}

// PruneWebhookDedup deletes webhook dedup rows older than the cutoff.
// Called by the GC worker.
func (s *Service) PruneWebhookDedup(ctx context.Context, cutoffTs time.Time) (int64, error) {
	deleted, err := s.repo.PruneWebhookDedup(ctx, cutoffTs)
	if err != nil {
		s.log.Error("webhook dedup gc: prune failed", slog.String("err", err.Error()))
		return 0, err
	}
	return deleted, nil
}

// ---------------------------------------------------------------------------
// Phase 4a — Log actions (resend + bulk delete)
// ---------------------------------------------------------------------------

// ResendEmail dispatches the resend_email agent command for a single log entry.
// Gate: body_stored must be true; returns 409 otherwise.
func (s *Service) ResendEmail(ctx context.Context, tenantID, siteID, logID uuid.UUID) (ResendResult, error) {
	bodyStored, err := s.repo.GetEmailLogBodyStored(ctx, tenantID, siteID, logID)
	if err != nil {
		if errors.Is(err, ErrNotFound) {
			return ResendResult{}, domain.NotFound("email_log_not_found", "email log entry not found")
		}
		return ResendResult{}, domain.Internal("resend_get_log", "failed to fetch log entry").WithCause(err)
	}
	if !bodyStored {
		return ResendResult{}, domain.Conflict("resend_body_not_stored",
			"resend is only available when body was captured at send time (body_stored=true); "+
				"this entry was sent without body capture enabled")
	}

	// Increment the resent_count counter before dispatching.
	if err := s.repo.IncrEmailLogResentCount(ctx, tenantID, siteID, logID); err != nil {
		return ResendResult{}, domain.Internal("resend_incr_count", "failed to increment resent_count").WithCause(err)
	}

	// Dispatch the signed resend_email agent command (Phase 4b implements the
	// agent side). Failure is non-fatal — the counter was already incremented
	// so the operator knows a resend was attempted.
	if s.agent == nil || s.siteLook == nil {
		return ResendResult{
			OK:     false,
			Detail: "agent command client not configured; resend dispatched counter incremented but command not sent",
		}, nil
	}

	siteURL, err := s.siteLook.GetSiteURL(ctx, tenantID, siteID)
	if err != nil {
		return ResendResult{OK: false, Detail: "site not enrolled or unavailable"}, nil
	}

	res, err := s.agent.ResendEmail(ctx, siteID, siteURL, agentcmd.ResendEmailRequest{LogID: logID.String()})
	if err != nil {
		return ResendResult{OK: false, Detail: err.Error()}, nil
	}
	return ResendResult{OK: res.OK, Detail: res.Detail, MessageID: res.MessageID}, nil
}

// BulkResendEmail dispatches resend_email commands for multiple log entries.
// Each entry is processed independently; per-entry body_stored gate is checked.
func (s *Service) BulkResendEmail(ctx context.Context, tenantID, siteID uuid.UUID, logIDs []uuid.UUID) ([]BulkResendResult, error) {
	if len(logIDs) == 0 {
		return nil, nil
	}
	if len(logIDs) > 100 {
		return nil, domain.Validation("resend_bulk_too_large", "bulk resend maximum is 100 entries per request")
	}
	results := make([]BulkResendResult, 0, len(logIDs))
	for _, id := range logIDs {
		res, err := s.ResendEmail(ctx, tenantID, siteID, id)
		if err != nil {
			var de *domain.Error
			if errors.As(err, &de) {
				results = append(results, BulkResendResult{LogID: id, OK: false, Detail: de.Message})
			} else {
				results = append(results, BulkResendResult{LogID: id, OK: false, Detail: err.Error()})
			}
			continue
		}
		results = append(results, BulkResendResult{LogID: id, OK: res.OK, Detail: res.Detail})
	}
	return results, nil
}

// BulkDeleteLogs deletes email log entries by id list.
// Returns the number of rows deleted.
func (s *Service) BulkDeleteLogs(ctx context.Context, tenantID, siteID uuid.UUID, logIDs []uuid.UUID) (int64, error) {
	if len(logIDs) == 0 {
		return 0, nil
	}
	if len(logIDs) > 500 {
		return 0, domain.Validation("bulk_delete_too_large", "bulk delete maximum is 500 entries per request")
	}
	deleted, err := s.repo.DeleteEmailLogsBulk(ctx, tenantID, siteID, logIDs)
	if err != nil {
		return 0, domain.Internal("bulk_delete_logs", "failed to delete log entries").WithCause(err)
	}
	return deleted, nil
}

// ---------------------------------------------------------------------------
// m61 — Webhook config management
// ---------------------------------------------------------------------------

// UpsertWebhookConfig sets the webhook security fields on a config row.
// It can rotate the route token (generating a new random token), store a new
// signing key (age-encrypted), and update the SES TopicArn allowlist.
//
// Returns the updated Config plus the plain route token when a rotation was
// requested (the only time the caller can see the plain token — store it immediately).
func (s *Service) UpsertWebhookConfig(ctx context.Context, in UpsertWebhookInput) (WebhookConfigResult, error) {
	var tokenHash []byte
	var plainToken string

	if in.RotateToken {
		var raw [32]byte
		if _, err := rand.Read(raw[:]); err != nil {
			return WebhookConfigResult{}, domain.Internal("webhook_token_gen", "failed to generate route token").WithCause(err)
		}
		// Store the URL-safe base64 of the raw bytes as the token in the URL.
		// Hash it with SHA-256 for storage (token never at rest).
		plainToken = base64.RawURLEncoding.EncodeToString(raw[:])
		sum := sha256.Sum256([]byte(plainToken))
		tokenHash = sum[:]
	}

	var signingKeyCT []byte
	setSigningKey := false
	if in.SigningKeyRaw != nil {
		if s.enc == nil {
			return WebhookConfigResult{}, domain.ServiceUnavailable(
				"email_crypto_unwired",
				"secret encryption is not configured; cannot store webhook signing key",
			)
		}
		ct, err := s.enc.Encrypt([]byte(*in.SigningKeyRaw))
		if err != nil {
			return WebhookConfigResult{}, domain.Internal("webhook_signing_key_encrypt", "failed to encrypt webhook signing key").WithCause(err)
		}
		signingKeyCT = ct
		setSigningKey = true
	}

	var sesTopicArns []string
	if in.SesTopicArns != nil {
		sesTopicArns = *in.SesTopicArns
	}

	cfg, err := s.repo.SetWebhookFields(ctx, in.TenantID, in.ConfigID, tokenHash, signingKeyCT, setSigningKey, sesTopicArns)
	if err != nil {
		return WebhookConfigResult{}, domain.Internal("webhook_config_set", "failed to set webhook fields").WithCause(err)
	}

	if plainToken != "" {
		cfg.WebhookRouteToken = plainToken
	}

	return WebhookConfigResult{
		Config: cfg,
	}, nil
}

// ResolveWebhookConfig looks up a config row by routeToken (from the webhook URL)
// and returns the decrypted signing key for signature verification.
//
// It hashes the provided plain token and looks it up by hash — constant-time at
// the DB level (unique index scan). Returns ErrNotFound when unknown → 404.
func (s *Service) ResolveWebhookConfig(ctx context.Context, plainToken string) (WebhookResolvedConfig, error) {
	if strings.TrimSpace(plainToken) == "" {
		return WebhookResolvedConfig{}, ErrNotFound
	}
	sum := sha256.Sum256([]byte(plainToken))
	tokenHash := sum[:]

	cfg, signingKeyCT, err := s.repo.GetConfigByRouteTokenHashWithSecret(ctx, tokenHash)
	if err != nil {
		return WebhookResolvedConfig{}, err // ErrNotFound bubbles up as-is
	}

	var signingKeyPlain string
	if len(signingKeyCT) > 0 && s.enc != nil {
		plain, derr := s.enc.Decrypt(signingKeyCT)
		if derr != nil {
			return WebhookResolvedConfig{}, domain.Internal("webhook_signing_key_decrypt", "failed to decrypt webhook signing key").WithCause(derr)
		}
		signingKeyPlain = string(plain)
	}

	return WebhookResolvedConfig{
		Config:          cfg,
		SigningKeyPlain: signingKeyPlain,
	}, nil
}

// WebhookURL returns the public-facing URL for a config row's webhook endpoint.
// baseURL must not have a trailing slash (e.g. "https://manage.wpmgr.app").
// Returns "" when the config row has no route token yet.
func WebhookURL(baseURL, provider, plainToken string) string {
	if plainToken == "" {
		return ""
	}
	return fmt.Sprintf("%s/webhooks/email/%s/%s", baseURL, provider, plainToken)
}

// ---------------------------------------------------------------------------
// service helpers
// ---------------------------------------------------------------------------

func isSuppressionEventType(eventType string) bool {
	switch eventType {
	case "hard_bounce", "complaint", "unsubscribe":
		return true
	}
	return false
}

func webhookEventToLogStatus(eventType string) string {
	switch eventType {
	case "hard_bounce":
		return "bounced"
	case "complaint":
		return "complained"
	}
	return eventType
}

func ptrNow() *time.Time {
	t := time.Now().UTC()
	return &t
}

// buildAgentConfigReq maps a Config domain value and an already-decrypted
// plaintext secret into the wire shape sent to the agent. Both UpsertSiteConfig
// and SendTest use this so the mapping stays in one place.
// secret is the plaintext provider secret; empty string means "no secret
// configured" — the agent will clear any previously stored secret.
func (s *Service) buildAgentConfigReq(cfg Config, secret string) agentcmd.EmailConfigRequest {
	return agentcmd.EmailConfigRequest{
		Provider:       cfg.Provider,
		FromAddress:    cfg.FromAddress,
		FromName:       cfg.FromName,
		ForceFromEmail: cfg.ForceFromEmail,
		ForceFromName:  cfg.ForceFromName,
		ReturnPath:     cfg.ReturnPath,
		Config:         cfg.Config,
		Secret:         secret,
		Mappings:       cfg.Mappings,
		LogEmails:      cfg.LogEmails,
		StoreBody:      cfg.StoreBody,
		RetentionDays:  cfg.RetentionDays,
	}
}

// resolveEffectiveSecret decrypts the stored per-site ciphertext for use in an
// agent push. When in.SecretRaw is non-nil (the operator just supplied a fresh
// secret), it is used directly — no DB round-trip needed. Otherwise the stored
// ciphertext is loaded and decrypted. Returns an empty string when no secret is
// configured (not an error).
func (s *Service) resolveEffectiveSecret(ctx context.Context, in UpsertInput, saved Config) (string, error) {
	// Operator just supplied a fresh plaintext secret — use it directly.
	if in.SecretRaw != nil {
		return *in.SecretRaw, nil
	}
	// No encryptor: cannot decrypt.
	if s.enc == nil {
		return "", nil
	}
	// No secret stored: nothing to decrypt.
	if !saved.SecretSet || in.SiteID == nil {
		return "", nil
	}
	ct, err := s.repo.GetSecretCiphertext(ctx, in.TenantID, *in.SiteID)
	if err != nil || len(ct) == 0 {
		// Tolerate ErrNotFound: some provider configs legitimately have no secret.
		return "", nil
	}
	plain, err := s.enc.Decrypt(ct)
	if err != nil {
		return "", fmt.Errorf("decrypt stored secret: %w", err)
	}
	return string(plain), nil
}

// decryptSecret decrypts the stored ciphertext for a site config. Called only
// when building a config-push command (not in any handler response path).
// Returns nil when no secret is stored.
func (s *Service) decryptSecret(ctx context.Context, tenantID, siteID uuid.UUID) ([]byte, error) {
	if s.enc == nil {
		return nil, nil // no encryptor → no decryption
	}
	var (
		ct  []byte
		err error
	)
	if siteID == uuid.Nil {
		ct, err = s.repo.GetOrgSecretCiphertext(ctx, tenantID)
	} else {
		ct, err = s.repo.GetSecretCiphertext(ctx, tenantID, siteID)
	}
	if err != nil || len(ct) == 0 {
		return nil, err
	}
	plain, err := s.enc.Decrypt(ct)
	if err != nil {
		return nil, domain.Internal("email_decrypt_secret", "failed to decrypt provider secret").WithCause(err)
	}
	return plain, nil
}
