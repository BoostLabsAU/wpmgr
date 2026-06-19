package diagnostics

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"strconv"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/mosamlife/wpmgr/apps/api/internal/db"
	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
)

// Repo is the tenant-scoped persistence layer for diagnostics + php-error
// rows. Mutating calls run inside InTenantTx so the RLS policy filters every
// row by the active tenant.
//
// The agent ingestion paths set BOTH `app.tenant_id` (so the WITH CHECK clause
// passes) AND `app.agent` (so the agent-write policy applies); the
// `InTenantTxAsAgent` helper centralises that. Operator reads use the
// standard InTenantTx.
type Repo struct {
	pool *db.Pool
}

// NewRepo wires a Repo with the shared pgx pool.
func NewRepo(pool *db.Pool) *Repo {
	return &Repo{pool: pool}
}

// UpsertDiagnostic stores the latest payload for the given (site, category).
// On conflict (one row per (tenant, site, category) by the unique index) we
// overwrite payload + collected_at and refresh received_at.
func (r *Repo) UpsertDiagnostic(ctx context.Context, tenantID, siteID uuid.UUID, category Category, payload json.RawMessage, collectedAt time.Time) (Diagnostic, error) {
	if !ValidCategory(category) {
		return Diagnostic{}, domain.Validation("invalid_category", "diagnostics category is not one of the 14 known buckets")
	}
	var out Diagnostic
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		row := tx.QueryRow(ctx,
			`INSERT INTO agent_diagnostics
				(id, tenant_id, site_id, category, payload, collected_at, received_at)
			 VALUES (gen_random_uuid(), $1, $2, $3, $4, $5, now())
			 ON CONFLICT (tenant_id, site_id, category) DO UPDATE
			   SET payload = EXCLUDED.payload,
			       collected_at = EXCLUDED.collected_at,
			       received_at = now()
			 RETURNING id, tenant_id, site_id, category, payload, collected_at, received_at`,
			tenantID, siteID, string(category), payload, collectedAt,
		)
		var d Diagnostic
		var catStr string
		if err := row.Scan(&d.ID, &d.TenantID, &d.SiteID, &catStr, &d.Payload, &d.CollectedAt, &d.ReceivedAt); err != nil {
			return domain.Internal("diagnostics_upsert_failed", "failed to upsert diagnostics").WithCause(err)
		}
		d.Category = Category(catStr)
		out = d
		return nil
	})
	return out, err
}

// ListDiagnosticsBySite returns every category row stored for the site, in no
// particular order — the handler keys them into a category-string map.
func (r *Repo) ListDiagnosticsBySite(ctx context.Context, tenantID, siteID uuid.UUID) ([]Diagnostic, error) {
	var out []Diagnostic
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		rows, err := tx.Query(ctx,
			`SELECT id, tenant_id, site_id, category, payload, collected_at, received_at
			 FROM agent_diagnostics
			 WHERE tenant_id = $1 AND site_id = $2`,
			tenantID, siteID,
		)
		if err != nil {
			return domain.Internal("diagnostics_list_failed", "failed to list diagnostics").WithCause(err)
		}
		defer rows.Close()
		for rows.Next() {
			var d Diagnostic
			var catStr string
			if err := rows.Scan(&d.ID, &d.TenantID, &d.SiteID, &catStr, &d.Payload, &d.CollectedAt, &d.ReceivedAt); err != nil {
				return domain.Internal("diagnostics_list_failed", "failed to read diagnostics").WithCause(err)
			}
			d.Category = Category(catStr)
			out = append(out, d)
		}
		if err := rows.Err(); err != nil {
			return domain.Internal("diagnostics_list_failed", "failed to iterate diagnostics").WithCause(err)
		}
		return nil
	})
	return out, err
}

// UpsertPHPError batch-applies the agent-shipped errors. For each row we
// ON CONFLICT bump occurrence_count by the agent-reported delta, refresh
// last_seen, and keep the first_seen the existing row already carried.
// Returns the highest agent-supplied id from the batch so the handler can
// echo the cursor advance back.
type UpsertPHPErrorInput struct {
	MD5             string
	Code            int
	Severity        string
	Message         string
	File            string
	Line            int
	RequestPath     string
	FirstSeenAt     time.Time
	LastSeenAt      time.Time
	OccurrenceCount int64
	AgentRowID      int64        // the agent's local id for the row (cursor tracking)
	Backtrace       []ErrorFrame // up to 10 frames, most-recent-call-first
}

func (r *Repo) UpsertPHPError(ctx context.Context, tenantID, siteID uuid.UUID, in UpsertPHPErrorInput) error {
	if in.MD5 == "" {
		return domain.Validation("invalid_md5", "php error md5 fingerprint is required")
	}
	// Marshal backtrace to JSON for the jsonb column; tolerate nil → [].
	frames := in.Backtrace
	if frames == nil {
		frames = []ErrorFrame{}
	}
	backtraceJSON, err := json.Marshal(frames)
	if err != nil {
		return domain.Internal("php_error_backtrace_marshal_failed", "failed to marshal backtrace").WithCause(err)
	}
	return r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		if _, err := tx.Exec(ctx,
			`INSERT INTO agent_php_errors
				(id, tenant_id, site_id, md5, code, severity, message, file, line,
				 request_path, first_seen_at, last_seen_at, occurrence_count,
				 silenced, backtrace, created_at, updated_at)
			 VALUES (gen_random_uuid(), $1, $2, $3, $4, $5, $6, $7, $8,
			         $9, $10, $11, $12, false, $13, now(), now())
			 ON CONFLICT (tenant_id, site_id, md5) DO UPDATE
			   SET last_seen_at = GREATEST(agent_php_errors.last_seen_at, EXCLUDED.last_seen_at),
			       occurrence_count = GREATEST(agent_php_errors.occurrence_count, EXCLUDED.occurrence_count),
			       severity = EXCLUDED.severity,
			       message = EXCLUDED.message,
			       file = EXCLUDED.file,
			       line = EXCLUDED.line,
			       request_path = EXCLUDED.request_path,
			       backtrace = EXCLUDED.backtrace,
			       updated_at = now()`,
			tenantID, siteID, in.MD5, in.Code, in.Severity, in.Message,
			in.File, in.Line, in.RequestPath, in.FirstSeenAt, in.LastSeenAt,
			in.OccurrenceCount,
			backtraceJSON,
		); err != nil {
			return domain.Internal("php_error_upsert_failed", "failed to upsert php error").WithCause(err)
		}
		return nil
	})
}

// ListPHPErrorsFilter narrows ListPHPErrorsBySite. Zero-value fields are ignored.
//
// Cursor encoding: base64(url, std) of "<unix-microseconds>:<uuid-string>" where
// unix-microseconds is the last_seen_at of the last row on the previous page and
// uuid-string is its id. The keyset predicate is (last_seen_at, id) < ($ts, $id)
// matching the ORDER BY last_seen_at DESC, id DESC sort.
//
// Known caveat: a fingerprint whose last_seen_at is updated mid-pagination (new
// PHP-error occurrence while the operator is paging) may shift its position in
// the result set. This is acceptable for a monitoring UI and is not worth the
// complexity of a snapshot-based approach.
type ListPHPErrorsFilter struct {
	Since    time.Time // last_seen_at > since (zero = no filter)
	Silenced *bool     // nil = both
	Limit    int
	// Cursor is the opaque keyset cursor for the next page; see encoding above.
	Cursor string
}

// encodePHPErrorCursor encodes the composite keyset cursor for PHP error
// pagination. Format: base64url("<unix-microseconds>:<uuid-string>").
// The cursor is opaque to callers; decodePHPErrorCursor is the inverse.
func encodePHPErrorCursor(lastSeenAt time.Time, id uuid.UUID) string {
	raw := fmt.Sprintf("%d:%s", lastSeenAt.UnixMicro(), id.String())
	return base64.URLEncoding.EncodeToString([]byte(raw))
}

// decodePHPErrorCursor parses a cursor produced by encodePHPErrorCursor.
// Returns ok=false on any malformed input so the caller can silently fall back
// to the first page (never 500 on a bad cursor).
func decodePHPErrorCursor(cursor string) (lastSeenAt time.Time, id uuid.UUID, ok bool) {
	b, err := base64.URLEncoding.DecodeString(cursor)
	if err != nil {
		return time.Time{}, uuid.UUID{}, false
	}
	parts := strings.SplitN(string(b), ":", 2)
	if len(parts) != 2 {
		return time.Time{}, uuid.UUID{}, false
	}
	micros, err := strconv.ParseInt(parts[0], 10, 64)
	if err != nil {
		return time.Time{}, uuid.UUID{}, false
	}
	id, err = uuid.Parse(parts[1])
	if err != nil {
		return time.Time{}, uuid.UUID{}, false
	}
	return time.UnixMicro(micros).UTC(), id, true
}

func (r *Repo) ListPHPErrorsBySite(ctx context.Context, tenantID, siteID uuid.UUID, f ListPHPErrorsFilter) ([]PHPError, string, error) {
	if f.Limit <= 0 || f.Limit > 500 {
		f.Limit = 100
	}
	var out []PHPError
	var nextCursor string
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		// Build the query with optional WHERE clauses. Dynamic $-arg building so
		// the LIMIT placeholder index is computed last.
		args := []any{tenantID, siteID}
		sqlText := `SELECT id, tenant_id, site_id, md5, code, severity, message,
				file, line, request_path, first_seen_at, last_seen_at,
				occurrence_count, silenced, backtrace, created_at, updated_at
			 FROM agent_php_errors
			 WHERE tenant_id = $1 AND site_id = $2`
		if !f.Since.IsZero() {
			args = append(args, f.Since)
			sqlText += ` AND last_seen_at > $` + strFromInt(len(args))
		}
		if f.Silenced != nil {
			args = append(args, *f.Silenced)
			sqlText += ` AND silenced = $` + strFromInt(len(args))
		}
		// Keyset cursor: (last_seen_at, id) < ($ts, $id) for DESC order.
		if f.Cursor != "" {
			if cursorTs, cursorID, ok := decodePHPErrorCursor(f.Cursor); ok {
				args = append(args, cursorTs, cursorID)
				tsIdx := strFromInt(len(args) - 1)
				idIdx := strFromInt(len(args))
				sqlText += ` AND (last_seen_at, id) < ($` + tsIdx + `, $` + idIdx + `)`
			}
			// Malformed cursor: silently fall back to first page.
		}
		// Fetch limit+1 rows; extra row signals there is a next page.
		args = append(args, f.Limit+1)
		sqlText += ` ORDER BY last_seen_at DESC, id DESC LIMIT $` + strFromInt(len(args))
		rows, err := tx.Query(ctx, sqlText, args...)
		if err != nil {
			return domain.Internal("php_errors_list_failed", "failed to list php errors").WithCause(err)
		}
		defer rows.Close()
		for rows.Next() {
			var e PHPError
			// backtrace is stored as jsonb; pgx scans jsonb as []byte.
			var backtraceRaw []byte
			if err := rows.Scan(&e.ID, &e.TenantID, &e.SiteID, &e.MD5, &e.Code,
				&e.Severity, &e.Message, &e.File, &e.Line, &e.RequestPath,
				&e.FirstSeenAt, &e.LastSeenAt, &e.OccurrenceCount, &e.Silenced,
				&backtraceRaw,
				&e.CreatedAt, &e.UpdatedAt,
			); err != nil {
				return domain.Internal("php_errors_list_failed", "failed to read php errors").WithCause(err)
			}
			if len(backtraceRaw) > 0 {
				_ = json.Unmarshal(backtraceRaw, &e.Backtrace)
			}
			if e.Backtrace == nil {
				e.Backtrace = []ErrorFrame{}
			}
			out = append(out, e)
		}
		if err := rows.Err(); err != nil {
			return domain.Internal("php_errors_list_failed", "failed to iterate php errors").WithCause(err)
		}
		if len(out) > f.Limit {
			// Sentinel row confirms more pages; encode cursor from the LAST kept row.
			last := out[f.Limit-1]
			nextCursor = encodePHPErrorCursor(last.LastSeenAt, last.ID)
			out = out[:f.Limit]
		}
		return nil
	})
	return out, nextCursor, err
}

// SetSilenced flips the silenced flag on a (site, md5) row. NotFound when the
// row doesn't exist within the tenant scope.
func (r *Repo) SetSilenced(ctx context.Context, tenantID, siteID uuid.UUID, md5 string, silenced bool) error {
	return r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		ct, err := tx.Exec(ctx,
			`UPDATE agent_php_errors
			   SET silenced = $4, updated_at = now()
			 WHERE tenant_id = $1 AND site_id = $2 AND md5 = $3`,
			tenantID, siteID, md5, silenced,
		)
		if err != nil {
			return domain.Internal("php_error_silence_failed", "failed to toggle silence flag").WithCause(err)
		}
		if ct.RowsAffected() == 0 {
			return domain.NotFound("php_error_not_found", "php error not found")
		}
		return nil
	})
}

// GetErrorConfig returns the stored error config for (tenantID, siteID).
// found=false (and no error) when no row exists yet; callers should default
// to agentcmd.DefaultErrorLevel with an empty ignore list.
func (r *Repo) GetErrorConfig(ctx context.Context, tenantID, siteID uuid.UUID) (ErrorConfig, bool, error) {
	var out ErrorConfig
	var found bool
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		row := tx.QueryRow(ctx,
			`SELECT tenant_id, site_id, enabled, error_level, ignore_md5s, updated_at
			 FROM site_error_config
			 WHERE tenant_id = $1 AND site_id = $2`,
			tenantID, siteID,
		)
		var ignoreMD5s []string
		if err := row.Scan(&out.TenantID, &out.SiteID, &out.Enabled, &out.ErrorLevel, &ignoreMD5s, &out.UpdatedAt); err != nil {
			if errors.Is(err, pgx.ErrNoRows) {
				return nil
			}
			return domain.Internal("error_config_get_failed", "failed to get error config").WithCause(err)
		}
		if ignoreMD5s == nil {
			ignoreMD5s = []string{}
		}
		out.IgnoreMD5s = ignoreMD5s
		found = true
		return nil
	})
	return out, found, err
}

// UpsertErrorConfig inserts or replaces the error config for (tenantID, siteID).
// updated_at is refreshed on every upsert. Returns the stored config.
func (r *Repo) UpsertErrorConfig(ctx context.Context, cfg ErrorConfig) (ErrorConfig, error) {
	var out ErrorConfig
	ignoreMD5s := cfg.IgnoreMD5s
	if ignoreMD5s == nil {
		ignoreMD5s = []string{}
	}
	err := r.pool.InTenantTx(ctx, cfg.TenantID, func(tx pgx.Tx) error {
		row := tx.QueryRow(ctx,
			`INSERT INTO site_error_config
				(tenant_id, site_id, enabled, error_level, ignore_md5s, updated_at)
			 VALUES ($1, $2, $3, $4, $5, now())
			 ON CONFLICT (site_id) DO UPDATE
			   SET enabled      = EXCLUDED.enabled,
			       error_level  = EXCLUDED.error_level,
			       ignore_md5s  = EXCLUDED.ignore_md5s,
			       updated_at   = now()
			 RETURNING tenant_id, site_id, enabled, error_level, ignore_md5s, updated_at`,
			cfg.TenantID, cfg.SiteID, cfg.Enabled, cfg.ErrorLevel, ignoreMD5s,
		)
		var md5s []string
		if err := row.Scan(&out.TenantID, &out.SiteID, &out.Enabled, &out.ErrorLevel, &md5s, &out.UpdatedAt); err != nil {
			return domain.Internal("error_config_upsert_failed", "failed to upsert error config").WithCause(err)
		}
		if md5s == nil {
			md5s = []string{}
		}
		out.IgnoreMD5s = md5s
		return nil
	})
	return out, err
}

// DeleteStaleErrors removes agent_php_errors rows whose last_seen_at is
// older than the given retention window. It runs cross-tenant under
// app.agent = 'on' (the same GUC the backup retention GC uses) so a single
// pass sweeps the whole table without needing to enumerate tenant IDs.
// LIMIT 5000 per pass caps the blast radius and keeps each transaction short.
// Returns the number of rows deleted.
func (r *Repo) DeleteStaleErrors(ctx context.Context, retention time.Duration) (int64, error) {
	cutoff := time.Now().UTC().Add(-retention)
	var deleted int64
	err := r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		ct, err := tx.Exec(ctx,
			`DELETE FROM agent_php_errors
			 WHERE id IN (
			   SELECT id FROM agent_php_errors
			   WHERE last_seen_at < $1
			   LIMIT 5000
			 )`,
			cutoff,
		)
		if err != nil {
			return domain.Internal("php_errors_gc_failed", "failed to delete stale php errors").WithCause(err)
		}
		deleted = ct.RowsAffected()
		return nil
	})
	return deleted, err
}

// UpdateSiteTimezone writes the WordPress timezone fields onto the site row.
// It runs inside InTenantTx (tenant-scoped, no agent flag needed) so the
// standard tenant_isolation RLS policy permits the UPDATE.
//
// Both fields are optional — empty timezone or zero offset are stored as-is
// (the migration defaults are ” and 0 respectively). The caller must guard
// against a nil/malformed payload before calling this method.
func (r *Repo) UpdateSiteTimezone(ctx context.Context, tenantID, siteID uuid.UUID, wpTimezone string, wpGMTOffset float64) error {
	return r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		ct, err := tx.Exec(ctx,
			`UPDATE sites
			    SET wp_timezone   = $3,
			        wp_gmt_offset = $4
			  WHERE tenant_id = $1
			    AND id        = $2`,
			tenantID, siteID, wpTimezone, wpGMTOffset,
		)
		if err != nil {
			return domain.Internal("site_timezone_update_failed", "failed to update site timezone").WithCause(err)
		}
		// A zero rows-affected result means the site row doesn't exist (e.g. a
		// stale agent with a deleted site). Treat as a no-op rather than a hard
		// error so the diagnostics ingest still succeeds for the other categories.
		_ = ct
		return nil
	})
}

// SetSiteHostProvider records the CP-inferred hosting provider for a site,
// derived from the agent's observed public egress IP (M28). Mirrors
// UpdateSiteTimezone: a raw tenant-scoped UPDATE inside InTenantTx (RLS-safe).
// `provider` may be "" when the IP could not be attributed to a known provider;
// the observed IP and checked-at timestamp are still recorded so the inference
// is auditable and re-resolves naturally on the next diagnostics push.
func (r *Repo) SetSiteHostProvider(ctx context.Context, tenantID, siteID uuid.UUID, provider, org, ip string) error {
	return r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		if _, err := tx.Exec(ctx,
			`UPDATE sites
			    SET host_provider            = $3,
			        host_provider_org        = $4,
			        host_provider_ip         = $5,
			        host_provider_checked_at = now()
			  WHERE tenant_id = $1
			    AND id        = $2`,
			tenantID, siteID, provider, org, ip,
		); err != nil {
			return domain.Internal("site_host_provider_update_failed", "failed to update site host provider").WithCause(err)
		}
		return nil
	})
}

// strFromInt is a tiny helper for building $-arg numbers in the dynamic
// WHERE clauses above without pulling in fmt.Sprintf on the hot path.
func strFromInt(n int) string {
	if n == 0 {
		return "0"
	}
	digits := []byte{}
	neg := false
	if n < 0 {
		neg = true
		n = -n
	}
	for n > 0 {
		digits = append([]byte{byte('0' + n%10)}, digits...)
		n /= 10
	}
	if neg {
		digits = append([]byte{'-'}, digits...)
	}
	return string(digits)
}

// _ keeps the errors import honest (NotFound check upstream of repos).
var _ = errors.Is
