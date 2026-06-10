package email

import (
	"context"
	"crypto/sha256"
	"encoding/json"
	"errors"
	"fmt"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgtype"

	"github.com/mosamlife/wpmgr/apps/api/internal/db"
	"github.com/mosamlife/wpmgr/apps/api/internal/db/sqlc"
	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
)

// epochStart / farFuture are sentinel bounds used when no date range is supplied.
// Using time.Time{} (zero) as epoch-start and a year far in the future as upper
// bound avoids NULL handling in SQL while keeping queries simple.
var (
	epochStart = time.Date(2000, 1, 1, 0, 0, 0, 0, time.UTC)
	farFuture  = time.Date(2099, 12, 31, 23, 59, 59, 0, time.UTC)
	// cursorIDMax is the string representation of the max UUID (all f's), used as
	// the initial cursor so the first page gets all rows.
	cursorIDMax = uuid.MustParse("ffffffff-ffff-ffff-ffff-ffffffffffff")
)

// ErrNotFound is returned when no config row exists yet for the given key.
var ErrNotFound = errors.New("email: not found")

// Repo is the persistence layer for per-site email config. Every operator
// read/write runs under pool.InTenantTx (app.tenant_id GUC). The
// provider_secret_encrypted column is NEVER returned to callers — only the
// SecretSet bool is surfaced (mirrors perf repo CDN credentials pattern).
type Repo struct {
	pool *db.Pool
}

// NewRepo wires a Repo with the shared pgx pool.
func NewRepo(pool *db.Pool) *Repo { return &Repo{pool: pool} }

// GetSiteConfig returns the per-site config row (without the encrypted secret).
// Returns ErrNotFound when no row exists yet.
func (r *Repo) GetSiteConfig(ctx context.Context, tenantID, siteID uuid.UUID) (Config, error) {
	var cfg Config
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		sid := pgtype.UUID{Bytes: siteID, Valid: true}
		row, qerr := sqlc.New(tx).GetSiteEmailConfig(ctx, sqlc.GetSiteEmailConfigParams{
			TenantID: tenantID,
			SiteID:   sid,
		})
		if qerr != nil {
			if errors.Is(qerr, pgx.ErrNoRows) {
				return ErrNotFound
			}
			return qerr
		}
		cfg = configFromRow(row)
		return nil
	})
	return cfg, err
}

// GetOrgConfig returns the org-wide default config row (site_id IS NULL).
// Returns ErrNotFound when no org-wide default is configured.
func (r *Repo) GetOrgConfig(ctx context.Context, tenantID uuid.UUID) (Config, error) {
	var cfg Config
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		row, qerr := sqlc.New(tx).GetOrgEmailConfig(ctx, tenantID)
		if qerr != nil {
			if errors.Is(qerr, pgx.ErrNoRows) {
				return ErrNotFound
			}
			return qerr
		}
		cfg = configFromRow(row)
		return nil
	})
	return cfg, err
}

// GetSecretCiphertext returns the age-encrypted secret for a site config row.
// Returns (nil, nil) when no secret is stored. Never decrypts — that is the
// service's responsibility.
func (r *Repo) GetSecretCiphertext(ctx context.Context, tenantID, siteID uuid.UUID) ([]byte, error) {
	var ct []byte
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		sid := pgtype.UUID{Bytes: siteID, Valid: true}
		row, qerr := sqlc.New(tx).GetSiteEmailConfig(ctx, sqlc.GetSiteEmailConfigParams{
			TenantID: tenantID,
			SiteID:   sid,
		})
		if qerr != nil {
			if errors.Is(qerr, pgx.ErrNoRows) {
				return nil // not found = no secret
			}
			return qerr
		}
		ct = row.ProviderSecretEncrypted
		return nil
	})
	return ct, err
}

// GetOrgSecretCiphertext returns the age-encrypted secret for the org-wide row.
func (r *Repo) GetOrgSecretCiphertext(ctx context.Context, tenantID uuid.UUID) ([]byte, error) {
	var ct []byte
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		row, qerr := sqlc.New(tx).GetOrgEmailConfig(ctx, tenantID)
		if qerr != nil {
			if errors.Is(qerr, pgx.ErrNoRows) {
				return nil
			}
			return qerr
		}
		ct = row.ProviderSecretEncrypted
		return nil
	})
	return ct, err
}

// GetConfigByRouteTokenHash looks up a config row by the SHA-256 of its route
// token. Used by the webhook dispatcher to resolve a config row without knowing
// the tenant. Runs under InAgentTx (no tenant GUC available at lookup time).
func (r *Repo) GetConfigByRouteTokenHash(ctx context.Context, tokenHash []byte) (Config, error) {
	var cfg Config
	err := r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		row, qerr := sqlc.New(tx).GetEmailConfigByRouteTokenHash(ctx, tokenHash)
		if qerr != nil {
			if errors.Is(qerr, pgx.ErrNoRows) {
				return ErrNotFound
			}
			return qerr
		}
		cfg = configFromRow(row)
		return nil
	})
	return cfg, err
}

// GetConfigByRouteTokenHashWithSecret looks up a config row by route token hash
// AND returns the encrypted signing key so the caller can decrypt it.
// Returns (Config, signingKeyCiphertext, error). Runs under InAgentTx.
func (r *Repo) GetConfigByRouteTokenHashWithSecret(ctx context.Context, tokenHash []byte) (Config, []byte, error) {
	var cfg Config
	var signingKeyCT []byte
	err := r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		row, qerr := sqlc.New(tx).GetEmailConfigByRouteTokenHash(ctx, tokenHash)
		if qerr != nil {
			if errors.Is(qerr, pgx.ErrNoRows) {
				return ErrNotFound
			}
			return qerr
		}
		cfg = configFromRow(row)
		signingKeyCT = row.WebhookSigningKeyEnc
		return nil
	})
	return cfg, signingKeyCT, err
}

// SetWebhookFields writes the webhook security columns on a config row.
// Runs under InTenantTx (operator path).
func (r *Repo) SetWebhookFields(ctx context.Context, tenantID, configID uuid.UUID, tokenHash, signingKeyCT []byte, setSigningKey bool, sesTopicArns []string) (Config, error) {
	var cfg Config
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		row, qerr := sqlc.New(tx).SetEmailConfigWebhookFields(ctx, sqlc.SetEmailConfigWebhookFieldsParams{
			TokenHash:     tokenHash,
			SesTopicArns:  sesTopicArns,
			SetSigningKey: setSigningKey,
			SigningKeyEnc: signingKeyCT,
			TenantID:      tenantID,
			ID:            configID,
		})
		if qerr != nil {
			return qerr
		}
		cfg = configFromRow(row)
		return nil
	})
	return cfg, err
}

// UpsertSiteConfig creates or updates the per-site config row. When
// in.SecretCiphertext is nil the existing ciphertext is preserved (nil-sentinel).
func (r *Repo) UpsertSiteConfig(ctx context.Context, in upsertRepoInput) (Config, error) {
	cfgJSON, err := jsonMarshal(in.Config)
	if err != nil {
		return Config{}, err
	}
	mappingsJSON, err := jsonMarshal(in.Mappings)
	if err != nil {
		return Config{}, err
	}

	var cfg Config
	dbErr := r.pool.InTenantTx(ctx, in.TenantID, func(tx pgx.Tx) error {
		sid := pgtype.UUID{Bytes: *in.SiteID, Valid: true}
		row, qerr := sqlc.New(tx).UpsertSiteEmailConfigByTenantSite(ctx,
			sqlc.UpsertSiteEmailConfigByTenantSiteParams{
				TenantID:                in.TenantID,
				SiteID:                  sid,
				Provider:                in.Provider,
				FromAddress:             in.FromAddress,
				FromName:                in.FromName,
				ForceFromEmail:          in.ForceFromEmail,
				ForceFromName:           in.ForceFromName,
				ReturnPath:              in.ReturnPath,
				Config:                  cfgJSON,
				SetSecret:               in.SetSecret,
				ProviderSecretEncrypted: in.SecretCiphertext,
				Mappings:                mappingsJSON,
				DefaultConnection:       in.DefaultConnection,
				FallbackConnection:      in.FallbackConnection,
				LogEmails:               in.LogEmails,
				StoreBody:               in.StoreBody,
				RetentionDays:           int32(in.RetentionDays),
			})
		if qerr != nil {
			return qerr
		}
		cfg = configFromRow(row)
		return nil
	})
	return cfg, dbErr
}

// UpsertOrgConfig creates or updates the org-wide default config row.
func (r *Repo) UpsertOrgConfig(ctx context.Context, in upsertRepoInput) (Config, error) {
	cfgJSON, err := jsonMarshal(in.Config)
	if err != nil {
		return Config{}, err
	}
	mappingsJSON, err := jsonMarshal(in.Mappings)
	if err != nil {
		return Config{}, err
	}

	var cfg Config
	dbErr := r.pool.InTenantTx(ctx, in.TenantID, func(tx pgx.Tx) error {
		row, qerr := sqlc.New(tx).UpsertOrgEmailConfig(ctx,
			sqlc.UpsertOrgEmailConfigParams{
				TenantID:                in.TenantID,
				Provider:                in.Provider,
				FromAddress:             in.FromAddress,
				FromName:                in.FromName,
				ForceFromEmail:          in.ForceFromEmail,
				ForceFromName:           in.ForceFromName,
				ReturnPath:              in.ReturnPath,
				Config:                  cfgJSON,
				SetSecret:               in.SetSecret,
				ProviderSecretEncrypted: in.SecretCiphertext,
				Mappings:                mappingsJSON,
				DefaultConnection:       in.DefaultConnection,
				FallbackConnection:      in.FallbackConnection,
				LogEmails:               in.LogEmails,
				StoreBody:               in.StoreBody,
				RetentionDays:           int32(in.RetentionDays),
			})
		if qerr != nil {
			return qerr
		}
		cfg = configFromRow(row)
		return nil
	})
	return cfg, dbErr
}

// ListSiteConfigs returns all per-site config rows for a tenant (excludes the
// org-wide default). Used by the portfolio overview.
func (r *Repo) ListSiteConfigs(ctx context.Context, tenantID uuid.UUID, limit, offset int32) ([]Config, error) {
	var out []Config
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		rows, qerr := sqlc.New(tx).ListSiteEmailConfigs(ctx, sqlc.ListSiteEmailConfigsParams{
			TenantID:  tenantID,
			RowLimit:  limit,
			RowOffset: offset,
		})
		if qerr != nil {
			return qerr
		}
		for _, row := range rows {
			out = append(out, configFromRow(row))
		}
		return nil
	})
	return out, err
}

// ---------------------------------------------------------------------------
// internal helpers
// ---------------------------------------------------------------------------

// upsertRepoInput is the internal wire type between service and repo.
// It carries the already-encrypted ciphertext (or nil = preserve existing).
type upsertRepoInput struct {
	TenantID uuid.UUID
	SiteID   *uuid.UUID // nil = org-wide default

	Provider       string
	FromAddress    string
	FromName       string
	ForceFromEmail bool
	ForceFromName  bool
	ReturnPath     bool

	Config   map[string]any
	Mappings map[string]any

	// SetSecret flags whether SecretCiphertext should be written.
	SetSecret        bool
	SecretCiphertext []byte

	DefaultConnection  *string
	FallbackConnection *string

	LogEmails     bool
	StoreBody     bool
	RetentionDays int
}

// configFromRow maps a sqlc SiteEmailConfig row to the domain Config. The
// provider_secret_encrypted and webhook_signing_key_enc columns are NEVER
// copied — only SecretSet and WebhookSigningKeySet bools are surfaced.
func configFromRow(row sqlc.SiteEmailConfig) Config {
	cfg := Config{
		ID:                       row.ID,
		TenantID:                 row.TenantID,
		Provider:                 row.Provider,
		FromAddress:              row.FromAddress,
		FromName:                 row.FromName,
		ForceFromEmail:           row.ForceFromEmail,
		ForceFromName:            row.ForceFromName,
		ReturnPath:               row.ReturnPath,
		SecretSet:                len(row.ProviderSecretEncrypted) > 0,
		LogEmails:                row.LogEmails,
		StoreBody:                row.StoreBody,
		RetentionDays:            int(row.RetentionDays),
		// m61: webhook security masked reads.
		WebhookSigningKeySet:     len(row.WebhookSigningKeyEnc) > 0,
		WebhookRouteTokenHashSet: len(row.WebhookRouteTokenHash) > 0,
		SesTopicArns:             row.SesTopicArns,
		CreatedAt:                row.CreatedAt,
		UpdatedAt:                row.UpdatedAt,
	}

	if row.SiteID.Valid {
		id := uuid.UUID(row.SiteID.Bytes)
		cfg.SiteID = &id
	}

	if len(row.Config) > 0 {
		_ = json.Unmarshal(row.Config, &cfg.Config)
	}
	if cfg.Config == nil {
		cfg.Config = map[string]any{}
	}

	if len(row.Mappings) > 0 {
		_ = json.Unmarshal(row.Mappings, &cfg.Mappings)
	}
	if cfg.Mappings == nil {
		cfg.Mappings = map[string]any{}
	}

	if row.DefaultConnection != nil {
		cfg.DefaultConnection = row.DefaultConnection
	}
	if row.FallbackConnection != nil {
		cfg.FallbackConnection = row.FallbackConnection
	}

	return cfg
}

func jsonMarshal(v map[string]any) ([]byte, error) {
	if v == nil {
		return []byte("{}"), nil
	}
	return json.Marshal(v)
}

// ---------------------------------------------------------------------------
// Email log (Phase 3)
// ---------------------------------------------------------------------------

// IngestLogBatch idempotently upserts a batch of agent-pushed log entries.
// Runs under InAgentTx (app.agent='on') so the agent RLS policy allows the
// INSERT/UPDATE. The batch is bounded to maxIngestBatch entries.
// Returns the maximum agent_seq accepted.
func (r *Repo) IngestLogBatch(ctx context.Context, tenantID, siteID uuid.UUID, entries []IngestEntry) (int64, error) {
	if len(entries) == 0 {
		return 0, nil
	}
	var maxSeq int64
	err := r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		q := sqlc.New(tx)
		for _, e := range entries {
			respJSON, merr := json.Marshal(e.Response)
			if merr != nil || len(respJSON) == 0 {
				respJSON = []byte("{}")
			}
			agentSeq := &e.AgentSeq
			var messageID *string
			if e.MessageID != "" {
				s := e.MessageID
				messageID = &s
			}
			body := ""
			if e.Body != nil {
				body = *e.Body
			}
			createdAt := e.CreatedAt
			if createdAt.IsZero() {
				createdAt = time.Now().UTC()
			}
			_, qerr := q.IngestEmailLogEntry(ctx, sqlc.IngestEmailLogEntryParams{
				TenantID:    tenantID,
				SiteID:      siteID,
				AgentSeq:    agentSeq,
				MessageID:   messageID,
				ToAddresses: e.ToAddresses,
				FromAddress: e.FromAddress,
				Subject:     e.Subject,
				Provider:    e.Provider,
				Status:      e.Status,
				Response:    respJSON,
				Error:       e.Error,
				Retries:     int32(e.Retries),
				ResentCount: int32(e.ResentCount),
				BodyStored:  e.BodyStored,
				Body:        body,
				CreatedAt:   createdAt,
			})
			if qerr != nil {
				return domain.Internal("email_ingest_entry", "failed to upsert email log entry").WithCause(qerr)
			}
			if e.AgentSeq > maxSeq {
				maxSeq = e.AgentSeq
			}
		}
		return nil
	})
	return maxSeq, err
}

// ListSiteLog returns a keyset-paginated list of log entries for a single site.
// Body is never included in list results.
func (r *Repo) ListSiteLog(ctx context.Context, tenantID, siteID uuid.UUID, f LogListFilter) (LogListPage, error) {
	limit := clampLimit(f.Limit, 50, 200)
	cursorTs, cursorID := parseCursor(f.Cursor)
	rangeFrom, rangeTo := resolveRange(f.From, f.To)

	var page LogListPage
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		rows, qerr := sqlc.New(tx).ListSiteEmailLog(ctx, sqlc.ListSiteEmailLogParams{
			TenantID:     tenantID,
			SiteID:       siteID,
			CursorTs:     cursorTs,
			CursorID:     cursorID,
			FilterStatus: f.Status,
			RangeFrom:    rangeFrom,
			RangeTo:      rangeTo,
			SearchQ:      f.Q,
			RowLimit:     int32(limit + 1),
		})
		if qerr != nil {
			return domain.Internal("email_list_log", "failed to list email log").WithCause(qerr)
		}
		for _, row := range rows {
			page.Entries = append(page.Entries, logListRowToEntry(row))
		}
		if len(page.Entries) > limit {
			last := page.Entries[limit-1]
			page.NextCursor = encodeCursor(last.CreatedAt, last.ID)
			page.Entries = page.Entries[:limit]
		}
		return nil
	})
	return page, err
}

// GetLogEntry returns a single email log entry including body (if stored).
func (r *Repo) GetLogEntry(ctx context.Context, tenantID, siteID, id uuid.UUID) (LogDetail, error) {
	var detail LogDetail
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		q := sqlc.New(tx)
		row, qerr := q.GetEmailLog(ctx, sqlc.GetEmailLogParams{
			TenantID: tenantID,
			SiteID:   siteID,
			ID:       id,
		})
		if qerr != nil {
			if errors.Is(qerr, pgx.ErrNoRows) {
				return ErrNotFound
			}
			return domain.Internal("email_get_log", "failed to fetch email log entry").WithCause(qerr)
		}
		detail.Entry = logRowToEntry(row)

		// Prev (older row).
		prevID, perr := q.GetEmailLogPrev(ctx, sqlc.GetEmailLogPrevParams{
			TenantID: tenantID,
			SiteID:   siteID,
			ThisTs:   row.CreatedAt,
			ThisID:   row.ID,
		})
		if perr == nil {
			detail.PrevID = &prevID
		}

		// Next (newer row).
		nextID, nerr := q.GetEmailLogNext(ctx, sqlc.GetEmailLogNextParams{
			TenantID: tenantID,
			SiteID:   siteID,
			ThisTs:   row.CreatedAt,
			ThisID:   row.ID,
		})
		if nerr == nil {
			detail.NextID = &nextID
		}
		return nil
	})
	return detail, err
}

// ListFleetLog returns a keyset-paginated cross-site log list for a tenant.
// Body is never included in list results.
func (r *Repo) ListFleetLog(ctx context.Context, tenantID uuid.UUID, f LogListFilter) (LogListPage, error) {
	limit := clampLimit(f.Limit, 50, 200)
	cursorTs, cursorID := parseCursor(f.Cursor)
	rangeFrom, rangeTo := resolveRange(f.From, f.To)

	var page LogListPage
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		rows, qerr := sqlc.New(tx).ListFleetEmailLog(ctx, sqlc.ListFleetEmailLogParams{
			TenantID:     tenantID,
			CursorTs:     cursorTs,
			CursorID:     cursorID,
			FilterStatus: f.Status,
			RangeFrom:    rangeFrom,
			RangeTo:      rangeTo,
			SearchQ:      f.Q,
			RowLimit:     int32(limit + 1),
		})
		if qerr != nil {
			return domain.Internal("email_list_fleet_log", "failed to list fleet email log").WithCause(qerr)
		}
		for _, row := range rows {
			page.Entries = append(page.Entries, fleetLogRowToEntry(row))
		}
		if len(page.Entries) > limit {
			last := page.Entries[limit-1]
			page.NextCursor = encodeCursor(last.CreatedAt, last.ID)
			page.Entries = page.Entries[:limit]
		}
		return nil
	})
	return page, err
}

// GetSiteStats returns the email stats (summary + per-day + per-provider) for a site.
func (r *Repo) GetSiteStats(ctx context.Context, tenantID, siteID uuid.UUID, from, to time.Time) (EmailStats, error) {
	rangeFrom, rangeTo := resolveRange(from, to)
	var stats EmailStats
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		q := sqlc.New(tx)
		sumRow, serr := q.GetEmailStats(ctx, sqlc.GetEmailStatsParams{
			TenantID:  tenantID,
			SiteID:    siteID,
			RangeFrom: rangeFrom,
			RangeTo:   rangeTo,
		})
		if serr != nil {
			return domain.Internal("email_get_stats", "failed to get email stats").WithCause(serr)
		}
		stats.Total = sumRow.Total
		stats.SentCount = sumRow.SentCount
		stats.FailedCount = sumRow.FailedCount
		stats.ProviderCount = sumRow.ProviderCount

		dayRows, derr := q.GetEmailStatsByDay(ctx, sqlc.GetEmailStatsByDayParams{
			TenantID:  tenantID,
			SiteID:    siteID,
			RangeFrom: rangeFrom,
			RangeTo:   rangeTo,
		})
		if derr != nil {
			return domain.Internal("email_get_stats_by_day", "failed to get email stats by day").WithCause(derr)
		}
		for _, d := range dayRows {
			stats.ByDay = append(stats.ByDay, StatsByDay{
				Day:         d.Day,
				Total:       d.Total,
				SentCount:   d.SentCount,
				FailedCount: d.FailedCount,
			})
		}

		provRows, prerr := q.GetEmailStatsByProvider(ctx, sqlc.GetEmailStatsByProviderParams{
			TenantID:  tenantID,
			SiteID:    siteID,
			RangeFrom: rangeFrom,
			RangeTo:   rangeTo,
		})
		if prerr != nil {
			return domain.Internal("email_get_stats_by_provider", "failed to get email stats by provider").WithCause(prerr)
		}
		for _, p := range provRows {
			stats.ByProvider = append(stats.ByProvider, StatsByProvider{
				Provider:    p.Provider,
				Total:       p.Total,
				SentCount:   p.SentCount,
				FailedCount: p.FailedCount,
			})
		}
		return nil
	})
	return stats, err
}

// GetFleetStats returns the fleet-wide email stats for a tenant.
func (r *Repo) GetFleetStats(ctx context.Context, tenantID uuid.UUID, from, to time.Time) (EmailStats, error) {
	rangeFrom, rangeTo := resolveRange(from, to)
	var stats EmailStats
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		q := sqlc.New(tx)
		sumRow, serr := q.GetFleetEmailStats(ctx, sqlc.GetFleetEmailStatsParams{
			TenantID:  tenantID,
			RangeFrom: rangeFrom,
			RangeTo:   rangeTo,
		})
		if serr != nil {
			return domain.Internal("email_get_fleet_stats", "failed to get fleet email stats").WithCause(serr)
		}
		stats.Total = sumRow.Total
		stats.SentCount = sumRow.SentCount
		stats.FailedCount = sumRow.FailedCount
		stats.ProviderCount = sumRow.ProviderCount
		stats.SiteCount = sumRow.SiteCount

		dayRows, derr := q.GetFleetEmailStatsByDay(ctx, sqlc.GetFleetEmailStatsByDayParams{
			TenantID:  tenantID,
			RangeFrom: rangeFrom,
			RangeTo:   rangeTo,
		})
		if derr != nil {
			return domain.Internal("email_get_fleet_stats_by_day", "failed to get fleet email stats by day").WithCause(derr)
		}
		for _, d := range dayRows {
			stats.ByDay = append(stats.ByDay, StatsByDay{
				Day:         d.Day,
				Total:       d.Total,
				SentCount:   d.SentCount,
				FailedCount: d.FailedCount,
			})
		}
		return nil
	})
	return stats, err
}

// ---------------------------------------------------------------------------
// Suppression (Phase 4a)
// ---------------------------------------------------------------------------

// UpsertSuppression upserts a suppression entry. When in.SiteID is nil the
// fleet-wide variant is used (different partial-index conflict target).
// Runs under InAgentTx (webhook path) or InTenantTx (operator manual-add).
func (r *Repo) UpsertSuppression(ctx context.Context, in UpsertSuppressionInput) (Suppression, error) {
	hash := suppressionHash(in.Email)
	var emailPtr *string
	if in.StorePlaintext {
		e := strings.ToLower(strings.TrimSpace(in.Email))
		emailPtr = &e
	}
	var eventAt pgtype.Timestamptz
	if in.EventAt != nil {
		eventAt = pgtype.Timestamptz{Time: *in.EventAt, Valid: true}
	}

	var row sqlc.EmailSuppression
	var err error

	if in.SiteID == nil {
		// Fleet-wide row.
		err = r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
			var qerr error
			row, qerr = sqlc.New(tx).UpsertEmailSuppressionFleet(ctx, sqlc.UpsertEmailSuppressionFleetParams{
				TenantID:        in.TenantID,
				EmailHash:       hash,
				Email:           emailPtr,
				Reason:          in.Reason,
				Provider:        in.Provider,
				EventAt:         eventAt,
				SourceMessageID: in.SourceMessageID,
			})
			return qerr
		})
	} else {
		sid := pgtype.UUID{Bytes: *in.SiteID, Valid: true}
		err = r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
			var qerr error
			row, qerr = sqlc.New(tx).UpsertEmailSuppression(ctx, sqlc.UpsertEmailSuppressionParams{
				TenantID:        in.TenantID,
				SiteID:          sid,
				EmailHash:       hash,
				Email:           emailPtr,
				Reason:          in.Reason,
				Provider:        in.Provider,
				EventAt:         eventAt,
				SourceMessageID: in.SourceMessageID,
			})
			return qerr
		})
	}
	if err != nil {
		return Suppression{}, err
	}
	return suppressionFromRow(row), nil
}

// UpsertSuppressionTenantTx upserts a suppression entry using InTenantTx
// (operator manual-add path). Same logic but runs under tenant context.
func (r *Repo) UpsertSuppressionTenantTx(ctx context.Context, in UpsertSuppressionInput) (Suppression, error) {
	hash := suppressionHash(in.Email)
	var emailPtr *string
	if in.StorePlaintext {
		e := strings.ToLower(strings.TrimSpace(in.Email))
		emailPtr = &e
	}
	var eventAt pgtype.Timestamptz
	if in.EventAt != nil {
		eventAt = pgtype.Timestamptz{Time: *in.EventAt, Valid: true}
	}

	var row sqlc.EmailSuppression
	var err error

	if in.SiteID == nil {
		err = r.pool.InTenantTx(ctx, in.TenantID, func(tx pgx.Tx) error {
			var qerr error
			row, qerr = sqlc.New(tx).UpsertEmailSuppressionFleet(ctx, sqlc.UpsertEmailSuppressionFleetParams{
				TenantID:        in.TenantID,
				EmailHash:       hash,
				Email:           emailPtr,
				Reason:          in.Reason,
				Provider:        in.Provider,
				EventAt:         eventAt,
				SourceMessageID: in.SourceMessageID,
			})
			return qerr
		})
	} else {
		sid := pgtype.UUID{Bytes: *in.SiteID, Valid: true}
		err = r.pool.InTenantTx(ctx, in.TenantID, func(tx pgx.Tx) error {
			var qerr error
			row, qerr = sqlc.New(tx).UpsertEmailSuppression(ctx, sqlc.UpsertEmailSuppressionParams{
				TenantID:        in.TenantID,
				SiteID:          sid,
				EmailHash:       hash,
				Email:           emailPtr,
				Reason:          in.Reason,
				Provider:        in.Provider,
				EventAt:         eventAt,
				SourceMessageID: in.SourceMessageID,
			})
			return qerr
		})
	}
	if err != nil {
		return Suppression{}, err
	}
	return suppressionFromRow(row), nil
}

// GetSuppression fetches a single suppression row by id.
func (r *Repo) GetSuppression(ctx context.Context, tenantID, id uuid.UUID) (Suppression, error) {
	var s Suppression
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		row, qerr := sqlc.New(tx).GetEmailSuppression(ctx, sqlc.GetEmailSuppressionParams{
			ID:       id,
			TenantID: tenantID,
		})
		if qerr != nil {
			if errors.Is(qerr, pgx.ErrNoRows) {
				return ErrNotFound
			}
			return qerr
		}
		s = suppressionFromRow(row)
		return nil
	})
	return s, err
}

// IsSuppressed returns true when email is suppressed for the given tenant/site
// (including fleet-wide entries). Runs under InTenantTx.
func (r *Repo) IsSuppressed(ctx context.Context, tenantID, siteID uuid.UUID, email string) (bool, error) {
	hash := suppressionHash(email)
	var suppressed bool
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		var qerr error
		suppressed, qerr = sqlc.New(tx).IsSuppressed(ctx, sqlc.IsSuppressedParams{
			TenantID:  tenantID,
			EmailHash: hash,
			SiteID:    pgtype.UUID{Bytes: siteID, Valid: true},
		})
		return qerr
	})
	return suppressed, err
}

// ListSiteSuppression returns a keyset-paginated suppression list for a site
// (including fleet-wide entries).
func (r *Repo) ListSiteSuppression(ctx context.Context, tenantID, siteID uuid.UUID, f SuppressionFilter) (SuppressionPage, error) {
	limit := clampLimit(f.Limit, 50, 200)
	cursorTs, cursorID := parseCursor(f.Cursor)

	var page SuppressionPage
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		rows, qerr := sqlc.New(tx).ListEmailSuppression(ctx, sqlc.ListEmailSuppressionParams{
			TenantID:     tenantID,
			SiteID:       pgtype.UUID{Bytes: siteID, Valid: true},
			IncludeFleet: true,
			CursorTs:     cursorTs,
			CursorID:     cursorID,
			FilterReason: f.Reason,
			RowLimit:     int32(limit + 1),
		})
		if qerr != nil {
			return qerr
		}
		for _, row := range rows {
			page.Entries = append(page.Entries, suppressionFromRow(row))
		}
		if len(page.Entries) > limit {
			last := page.Entries[limit-1]
			page.NextCursor = encodeCursor(last.CreatedAt, last.ID)
			page.Entries = page.Entries[:limit]
		}
		return nil
	})
	return page, err
}

// ListFleetSuppression returns a keyset-paginated fleet-scope suppression list.
func (r *Repo) ListFleetSuppression(ctx context.Context, tenantID uuid.UUID, f SuppressionFilter) (SuppressionPage, error) {
	limit := clampLimit(f.Limit, 50, 200)
	cursorTs, cursorID := parseCursor(f.Cursor)

	var page SuppressionPage
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		rows, qerr := sqlc.New(tx).ListFleetEmailSuppression(ctx, sqlc.ListFleetEmailSuppressionParams{
			TenantID:     tenantID,
			CursorTs:     cursorTs,
			CursorID:     cursorID,
			FilterReason: f.Reason,
			RowLimit:     int32(limit + 1),
		})
		if qerr != nil {
			return qerr
		}
		for _, row := range rows {
			page.Entries = append(page.Entries, suppressionFromRow(row))
		}
		if len(page.Entries) > limit {
			last := page.Entries[limit-1]
			page.NextCursor = encodeCursor(last.CreatedAt, last.ID)
			page.Entries = page.Entries[:limit]
		}
		return nil
	})
	return page, err
}

// DeleteSuppression deletes a suppression entry by id. Runs under InTenantTx.
func (r *Repo) DeleteSuppression(ctx context.Context, tenantID, id uuid.UUID) error {
	return r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		return sqlc.New(tx).DeleteEmailSuppression(ctx, sqlc.DeleteEmailSuppressionParams{
			ID:       id,
			TenantID: tenantID,
		})
	})
}

// ListSuppressionDeltas returns suppression entries created after the cursor
// for the given tenant+site (for the agent suppression-fetch endpoint).
// Runs under InAgentTx.
func (r *Repo) ListSuppressionDeltas(ctx context.Context, tenantID, siteID uuid.UUID, sinceCursor string, limit int) (SuppressionDeltaPage, error) {
	lim := clampLimit(limit, 200, 1000)
	sinceTs, sinceID := parseCursor(sinceCursor)
	// For the delta (ascending) cursor we use epoch-start sentinel on the first call.
	if sinceCursor == "" {
		sinceTs = epochStart
		sinceID = uuid.Nil
	}

	var page SuppressionDeltaPage
	err := r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		rows, qerr := sqlc.New(tx).ListEmailSuppressionDeltas(ctx, sqlc.ListEmailSuppressionDeltasParams{
			TenantID: tenantID,
			SiteID:   pgtype.UUID{Bytes: siteID, Valid: true},
			SinceTs:  sinceTs,
			SinceID:  sinceID,
			RowLimit: int32(lim + 1),
		})
		if qerr != nil {
			return qerr
		}
		for _, row := range rows {
			page.Entries = append(page.Entries, suppressionFromRow(row))
		}
		if len(page.Entries) > lim {
			last := page.Entries[lim-1]
			// Delta cursor is (created_at ASC, id ASC) — same encodeCursor format.
			page.NextCursor = encodeCursor(last.CreatedAt, last.ID)
			page.Entries = page.Entries[:lim]
		}
		return nil
	})
	return page, err
}

// InsertWebhookEventDedup inserts a dedup sentinel. Returns (inserted=false)
// when the event is a duplicate. Runs under InAgentTx.
func (r *Repo) InsertWebhookEventDedup(ctx context.Context, in WebhookEventInput, suppressionID *uuid.UUID) (bool, error) {
	var tenantPG pgtype.UUID
	if in.TenantID != nil {
		tenantPG = pgtype.UUID{Bytes: *in.TenantID, Valid: true}
	}
	var sitePG pgtype.UUID
	if in.SiteID != nil {
		sitePG = pgtype.UUID{Bytes: *in.SiteID, Valid: true}
	}
	var supPG pgtype.UUID
	if suppressionID != nil {
		supPG = pgtype.UUID{Bytes: *suppressionID, Valid: true}
	}
	// m61: store email_hash (SHA-256) rather than plaintext email (SHOULD-FIX #2).
	// in.EmailHash is pre-computed by the webhook handler; fall back to computing
	// it here if the caller did not set it (backwards-compat for the fakeRepo path).
	emailHash := in.EmailHash
	if len(emailHash) == 0 && in.Email != "" {
		emailHash = suppressionHash(in.Email)
	}

	var inserted bool
	err := r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		_, qerr := sqlc.New(tx).InsertWebhookEventDedup(ctx, sqlc.InsertWebhookEventDedupParams{
			ProviderEventID: in.ProviderEventID,
			Provider:        in.Provider,
			TenantID:        tenantPG,
			SiteID:          sitePG,
			EmailHash:       emailHash,
			EventType:       in.EventType,
			SuppressionID:   supPG,
		})
		if qerr != nil {
			if errors.Is(qerr, pgx.ErrNoRows) {
				// ON CONFLICT DO NOTHING → duplicate; not an error.
				inserted = false
				return nil
			}
			return qerr
		}
		inserted = true
		return nil
	})
	return inserted, err
}

// MarkEmailLogBounced updates the status of a log entry to 'bounced' or
// 'complained' by message_id + tenant_id + site_id.
// m61 SHOULD-FIX #3: site_id now scopes the update so a forged/colliding
// message_id from another site in the same tenant cannot flip a different
// site's log row. Runs under InAgentTx.
func (r *Repo) MarkEmailLogBounced(ctx context.Context, tenantID, siteID uuid.UUID, messageID, status string) error {
	mid := &messageID
	return r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		return sqlc.New(tx).MarkEmailLogBounced(ctx, sqlc.MarkEmailLogBouncedParams{
			MessageID: mid,
			TenantID:  tenantID,
			SiteID:    siteID,
			Status:    status,
		})
	})
}

// PruneWebhookDedup deletes dedup rows older than cutoffTs. Cross-tenant (InAgentTx).
func (r *Repo) PruneWebhookDedup(ctx context.Context, cutoffTs time.Time) (int64, error) {
	var deleted int64
	err := r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		n, qerr := sqlc.New(tx).PruneWebhookEventDedup(ctx, cutoffTs)
		deleted = n
		return qerr
	})
	return deleted, err
}

// ---------------------------------------------------------------------------
// Log actions (Phase 4a)
// ---------------------------------------------------------------------------

// GetEmailLogBodyStored fetches the body_stored flag for a log entry (resend gate).
func (r *Repo) GetEmailLogBodyStored(ctx context.Context, tenantID, siteID, id uuid.UUID) (bool, error) {
	var bodyStored bool
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		row, qerr := sqlc.New(tx).GetEmailLogBodyStored(ctx, sqlc.GetEmailLogBodyStoredParams{
			ID:       id,
			TenantID: tenantID,
			SiteID:   siteID,
		})
		if qerr != nil {
			if errors.Is(qerr, pgx.ErrNoRows) {
				return ErrNotFound
			}
			return qerr
		}
		bodyStored = row.BodyStored
		return nil
	})
	return bodyStored, err
}

// IncrEmailLogResentCount increments resent_count on a log entry. Runs under InTenantTx.
func (r *Repo) IncrEmailLogResentCount(ctx context.Context, tenantID, siteID, id uuid.UUID) error {
	return r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		return sqlc.New(tx).IncrEmailLogResentCount(ctx, sqlc.IncrEmailLogResentCountParams{
			ID:       id,
			TenantID: tenantID,
			SiteID:   siteID,
		})
	})
}

// DeleteEmailLogsBulk bulk-deletes log entries by id list. Runs under InTenantTx.
// Returns the number of rows deleted.
func (r *Repo) DeleteEmailLogsBulk(ctx context.Context, tenantID, siteID uuid.UUID, ids []uuid.UUID) (int64, error) {
	var deleted int64
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		n, qerr := sqlc.New(tx).DeleteEmailLogsBulk(ctx, sqlc.DeleteEmailLogsBulkParams{
			TenantID: tenantID,
			SiteID:   siteID,
			Ids:      ids,
		})
		deleted = n
		return qerr
	})
	return deleted, err
}

// ---------------------------------------------------------------------------
// suppression helpers
// ---------------------------------------------------------------------------

// suppressionHash returns the SHA-256 hash of the lower-cased, trimmed email.
// This is used as email_hash in the suppression table so raw emails are never
// required at rest.
func suppressionHash(email string) []byte {
	norm := strings.ToLower(strings.TrimSpace(email))
	sum := sha256.Sum256([]byte(norm))
	return sum[:]
}

// suppressionFromRow maps a sqlc EmailSuppression row to the domain type.
func suppressionFromRow(row sqlc.EmailSuppression) Suppression {
	s := Suppression{
		ID:              row.ID,
		TenantID:        row.TenantID,
		EmailHash:       row.EmailHash,
		Email:           row.Email,
		Reason:          row.Reason,
		Provider:        row.Provider,
		SourceMessageID: row.SourceMessageID,
		CreatedAt:       row.CreatedAt,
	}
	if row.SiteID.Valid {
		id := uuid.UUID(row.SiteID.Bytes)
		s.SiteID = &id
	}
	if row.EventAt.Valid {
		t := row.EventAt.Time
		s.EventAt = &t
	}
	return s
}

// DeleteLogsOlderThan deletes one batch of rows older than cutoffTs across all
// tenants (runs under InAgentTx). Returns the number of rows deleted.
func (r *Repo) DeleteLogsOlderThan(ctx context.Context, cutoffTs time.Time, batchSize int64) (int64, error) {
	var deleted int64
	err := r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		n, qerr := sqlc.New(tx).DeleteEmailLogsOlderThan(ctx, sqlc.DeleteEmailLogsOlderThanParams{
			CutoffTs:  cutoffTs,
			BatchSize: batchSize,
		})
		if qerr != nil {
			return domain.Internal("email_delete_logs", "failed to delete old email logs").WithCause(qerr)
		}
		deleted = n
		return nil
	})
	return deleted, err
}

// ---------------------------------------------------------------------------
// repo helpers
// ---------------------------------------------------------------------------

// clampLimit bounds limit to [1, max] with a default when 0 or negative.
func clampLimit(limit, defaultVal, maxVal int) int {
	if limit <= 0 {
		return defaultVal
	}
	if limit > maxVal {
		return maxVal
	}
	return limit
}

// resolveRange returns effective from/to bounds. Zero values are replaced with
// epochStart / farFuture so the SQL range predicates always have concrete values.
func resolveRange(from, to time.Time) (time.Time, time.Time) {
	if from.IsZero() {
		from = epochStart
	}
	if to.IsZero() {
		to = farFuture
	}
	return from, to
}

// parseCursor decodes an opaque cursor string into the (created_at, id) pair
// used by the composite keyset predicate. On any parse failure it returns
// (farFuture, cursorIDMax) so the first page is returned.
//
// Cursor format: "<unix-nano-int64>_<uuid>". This is internal — the frontend
// must not construct cursors manually.
func parseCursor(cursor string) (time.Time, uuid.UUID) {
	if cursor == "" {
		return farFuture, cursorIDMax
	}
	// Find the separator between the timestamp and UUID parts.
	sep := len(cursor) - 37 // UUID is always 36 chars + 1 underscore separator
	if sep <= 0 {
		return farFuture, cursorIDMax
	}
	tsStr := cursor[:sep]
	uuidStr := cursor[sep+1:]

	var nanos int64
	for _, ch := range tsStr {
		if ch < '0' || ch > '9' {
			return farFuture, cursorIDMax
		}
		nanos = nanos*10 + int64(ch-'0')
	}
	id, err := uuid.Parse(uuidStr)
	if err != nil {
		return farFuture, cursorIDMax
	}
	return time.Unix(0, nanos).UTC(), id
}

// encodeCursor encodes a (created_at, id) pair into the opaque cursor string.
func encodeCursor(t time.Time, id uuid.UUID) string {
	return fmt.Sprintf("%d_%s", t.UnixNano(), id.String())
}

// logListRowToEntry maps a ListSiteEmailLogRow to a LogEntry (no body).
func logListRowToEntry(row sqlc.ListSiteEmailLogRow) LogEntry {
	e := LogEntry{
		ID:          row.ID,
		TenantID:    row.TenantID,
		SiteID:      row.SiteID,
		AgentSeq:    row.AgentSeq,
		MessageID:   row.MessageID,
		ToAddresses: row.ToAddresses,
		FromAddress: row.FromAddress,
		Subject:     row.Subject,
		Provider:    row.Provider,
		Status:      row.Status,
		Error:       row.Error,
		Retries:     int(row.Retries),
		ResentCount: int(row.ResentCount),
		BodyStored:  row.BodyStored,
		CreatedAt:   row.CreatedAt,
		UpdatedAt:   row.UpdatedAt,
	}
	if len(row.Response) > 0 {
		_ = json.Unmarshal(row.Response, &e.Response)
	}
	if e.Response == nil {
		e.Response = map[string]any{}
	}
	if e.ToAddresses == nil {
		e.ToAddresses = []string{}
	}
	return e
}

// fleetLogRowToEntry maps a ListFleetEmailLogRow to a LogEntry (no body).
func fleetLogRowToEntry(row sqlc.ListFleetEmailLogRow) LogEntry {
	e := LogEntry{
		ID:          row.ID,
		TenantID:    row.TenantID,
		SiteID:      row.SiteID,
		AgentSeq:    row.AgentSeq,
		MessageID:   row.MessageID,
		ToAddresses: row.ToAddresses,
		FromAddress: row.FromAddress,
		Subject:     row.Subject,
		Provider:    row.Provider,
		Status:      row.Status,
		Error:       row.Error,
		Retries:     int(row.Retries),
		ResentCount: int(row.ResentCount),
		BodyStored:  row.BodyStored,
		CreatedAt:   row.CreatedAt,
		UpdatedAt:   row.UpdatedAt,
	}
	if len(row.Response) > 0 {
		_ = json.Unmarshal(row.Response, &e.Response)
	}
	if e.Response == nil {
		e.Response = map[string]any{}
	}
	if e.ToAddresses == nil {
		e.ToAddresses = []string{}
	}
	return e
}

// logRowToEntry maps a full SiteEmailLog row (including body) to a LogEntry.
func logRowToEntry(row sqlc.SiteEmailLog) LogEntry {
	e := LogEntry{
		ID:          row.ID,
		TenantID:    row.TenantID,
		SiteID:      row.SiteID,
		AgentSeq:    row.AgentSeq,
		MessageID:   row.MessageID,
		ToAddresses: row.ToAddresses,
		FromAddress: row.FromAddress,
		Subject:     row.Subject,
		Provider:    row.Provider,
		Status:      row.Status,
		Error:       row.Error,
		Retries:     int(row.Retries),
		ResentCount: int(row.ResentCount),
		BodyStored:  row.BodyStored,
		Body:        row.Body,
		CreatedAt:   row.CreatedAt,
		UpdatedAt:   row.UpdatedAt,
	}
	if len(row.Response) > 0 {
		_ = json.Unmarshal(row.Response, &e.Response)
	}
	if e.Response == nil {
		e.Response = map[string]any{}
	}
	if e.ToAddresses == nil {
		e.ToAddresses = []string{}
	}
	return e
}
