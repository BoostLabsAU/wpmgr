package security

import (
	"context"
	"errors"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/mosamlife/wpmgr/apps/api/internal/agentcmd"
	"github.com/mosamlife/wpmgr/apps/api/internal/db"
	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
)

// Repo is the tenant-scoped persistence layer for the security domain.
//
// Operator reads/writes use InTenantTx (app.tenant_id GUC).
// Agent ingest uses InTenantTx as well — the agent authenticator already
// resolved the (tenantID, siteID) pair, so we rely on the same RLS policy.
type Repo struct {
	pool *db.Pool
}

// NewRepo wires a Repo with the shared pgx pool.
func NewRepo(pool *db.Pool) *Repo {
	return &Repo{pool: pool}
}

// GetConfig returns the stored security config for (tenantID, siteID).
// found=false (and no error) when no row exists yet; callers should return
// the built-in default config.
func (r *Repo) GetConfig(ctx context.Context, tenantID, siteID uuid.UUID) (SecurityConfig, bool, error) {
	var out SecurityConfig
	var found bool
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		row := tx.QueryRow(ctx,
			`SELECT tenant_id, site_id, mode, thresholds, ip_header,
			        allow_cidrs, deny_cidrs, updated_at
			 FROM site_security_config
			 WHERE tenant_id = $1 AND site_id = $2`,
			tenantID, siteID,
		)
		var thresholds agentcmd.SecurityThresholds
		var allowCIDRs, denyCIDRs []string
		if err := row.Scan(
			&out.TenantID, &out.SiteID, &out.Mode,
			&thresholds,
			&out.IPHeader, &allowCIDRs, &denyCIDRs, &out.UpdatedAt,
		); err != nil {
			if errors.Is(err, pgx.ErrNoRows) {
				return nil
			}
			return domain.Internal("security_config_get_failed", "failed to get security config").WithCause(err)
		}
		out.Thresholds = thresholds
		if allowCIDRs == nil {
			allowCIDRs = []string{}
		}
		if denyCIDRs == nil {
			denyCIDRs = []string{}
		}
		out.AllowCIDRs = allowCIDRs
		out.DenyCIDRs = denyCIDRs
		found = true
		return nil
	})
	return out, found, err
}

// UpsertConfig inserts or replaces the security config for (tenantID, siteID).
// updated_at is refreshed on every upsert. Returns the stored config.
func (r *Repo) UpsertConfig(ctx context.Context, cfg SecurityConfig) (SecurityConfig, error) {
	var out SecurityConfig
	allowCIDRs := cfg.AllowCIDRs
	if allowCIDRs == nil {
		allowCIDRs = []string{}
	}
	denyCIDRs := cfg.DenyCIDRs
	if denyCIDRs == nil {
		denyCIDRs = []string{}
	}
	err := r.pool.InTenantTx(ctx, cfg.TenantID, func(tx pgx.Tx) error {
		row := tx.QueryRow(ctx,
			`INSERT INTO site_security_config
				(tenant_id, site_id, mode, thresholds, ip_header, allow_cidrs, deny_cidrs, updated_at)
			 VALUES ($1, $2, $3, $4, $5, $6, $7, now())
			 ON CONFLICT (site_id) DO UPDATE
			   SET mode        = EXCLUDED.mode,
			       thresholds  = EXCLUDED.thresholds,
			       ip_header   = EXCLUDED.ip_header,
			       allow_cidrs = EXCLUDED.allow_cidrs,
			       deny_cidrs  = EXCLUDED.deny_cidrs,
			       updated_at  = now()
			 RETURNING tenant_id, site_id, mode, thresholds, ip_header,
			           allow_cidrs, deny_cidrs, updated_at`,
			cfg.TenantID, cfg.SiteID, cfg.Mode, cfg.Thresholds,
			cfg.IPHeader, allowCIDRs, denyCIDRs,
		)
		var thresholds agentcmd.SecurityThresholds
		var ac, dc []string
		if err := row.Scan(
			&out.TenantID, &out.SiteID, &out.Mode,
			&thresholds,
			&out.IPHeader, &ac, &dc, &out.UpdatedAt,
		); err != nil {
			return domain.Internal("security_config_upsert_failed", "failed to upsert security config").WithCause(err)
		}
		out.Thresholds = thresholds
		if ac == nil {
			ac = []string{}
		}
		if dc == nil {
			dc = []string{}
		}
		out.AllowCIDRs = ac
		out.DenyCIDRs = dc
		return nil
	})
	return out, err
}

// InsertLoginEventsBatch bulk-inserts the agent-shipped login events, ignoring
// duplicates (ON CONFLICT DO NOTHING on the dedup index). Returns the highest
// agent_event_id in the batch so the handler can return it to the agent for
// cursor advancement.
func (r *Repo) InsertLoginEventsBatch(ctx context.Context, tenantID, siteID uuid.UUID, events []LoginEvent) (int64, error) {
	if len(events) == 0 {
		return 0, nil
	}
	var highest int64
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		for _, e := range events {
			if _, err := tx.Exec(ctx,
				`INSERT INTO agent_login_events
					(tenant_id, site_id, agent_event_id, ip, status, category,
					 username, request_id, occurred_at, ingested_at)
				 VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, now())
				 ON CONFLICT (tenant_id, site_id, agent_event_id) DO NOTHING`,
				tenantID, siteID, e.AgentEventID, e.IP, e.Status, e.Category,
				e.Username, e.RequestID, e.OccurredAt,
			); err != nil {
				return domain.Internal("login_events_insert_failed", "failed to insert login event").WithCause(err)
			}
			if e.AgentEventID > highest {
				highest = e.AgentEventID
			}
		}
		return nil
	})
	return highest, err
}

// ListLoginEvents returns login events for a site, ordered by occurred_at DESC.
// limit is clamped to [1, 500]. statusFilter is a tri-state: nil = all statuses.
func (r *Repo) ListLoginEvents(ctx context.Context, tenantID, siteID uuid.UUID, limit int, statusFilter *LoginEventStatus) ([]LoginEvent, error) {
	if limit <= 0 || limit > 500 {
		limit = 100
	}
	var out []LoginEvent
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		args := []any{tenantID, siteID, limit}
		sqlText := `SELECT id, tenant_id, site_id, agent_event_id, ip, status,
				        category, username, request_id, occurred_at, ingested_at
				 FROM agent_login_events
				 WHERE tenant_id = $1 AND site_id = $2`
		if statusFilter != nil {
			args = append(args, int16(*statusFilter))
			sqlText += ` AND status = $` + itoa(len(args))
		}
		sqlText += ` ORDER BY occurred_at DESC LIMIT $3`

		rows, err := tx.Query(ctx, sqlText, args...)
		if err != nil {
			return domain.Internal("login_events_list_failed", "failed to list login events").WithCause(err)
		}
		defer rows.Close()
		for rows.Next() {
			var ev LoginEvent
			var occurredAt *time.Time
			if err := rows.Scan(
				&ev.ID, &ev.TenantID, &ev.SiteID, &ev.AgentEventID,
				&ev.IP, &ev.Status, &ev.Category, &ev.Username,
				&ev.RequestID, &occurredAt, &ev.IngestedAt,
			); err != nil {
				return domain.Internal("login_events_list_failed", "failed to read login event").WithCause(err)
			}
			if occurredAt != nil {
				ev.OccurredAt = *occurredAt
			}
			out = append(out, ev)
		}
		if err := rows.Err(); err != nil {
			return domain.Internal("login_events_list_failed", "failed to iterate login events").WithCause(err)
		}
		return nil
	})
	return out, err
}

// itoa is a tiny helper for building $-arg numbers in dynamic WHERE clauses.
func itoa(n int) string {
	if n <= 0 {
		return "0"
	}
	digits := []byte{}
	for n > 0 {
		digits = append([]byte{byte('0' + n%10)}, digits...)
		n /= 10
	}
	return string(digits)
}

// keep errors import honest.
var _ = errors.Is

// ---------------------------------------------------------------------------
// Hardening config (m76 — site_security_hardening_config)
// ---------------------------------------------------------------------------

// GetHardeningConfig returns the stored hardening config for (tenantID, siteID).
// found=false (and no error) when no row exists yet; callers return the default.
func (r *Repo) GetHardeningConfig(ctx context.Context, tenantID, siteID uuid.UUID) (HardeningConfig, bool, error) {
	var out HardeningConfig
	var found bool
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		row := tx.QueryRow(ctx,
			`SELECT tenant_id, site_id,
			        disable_file_editor, xmlrpc_mode, restrict_rest_api,
			        restrict_login_identifier, force_unique_nickname,
			        disable_author_archive_enum, force_ssl,
			        disable_directory_browsing, disable_php_in_uploads,
			        protect_system_files, updated_at, actor_type, actor_id
			   FROM site_security_hardening_config
			  WHERE tenant_id = $1 AND site_id = $2`,
			tenantID, siteID,
		)
		var actorType, actorID *string
		var xmlrpcMode, restrictREST, loginID string
		if err := row.Scan(
			&out.TenantID, &out.SiteID,
			&out.DisableFileEditor, &xmlrpcMode, &restrictREST,
			&loginID, &out.ForceUniqueNickname,
			&out.DisableAuthorArchiveEnum, &out.ForceSSL,
			&out.DisableDirectoryBrowsing, &out.DisablePHPInUploads,
			&out.ProtectSystemFiles, &out.UpdatedAt, &actorType, &actorID,
		); err != nil {
			if errors.Is(err, pgx.ErrNoRows) {
				return nil
			}
			return domain.Internal("security_hardening_get_failed",
				"failed to get hardening config").WithCause(err)
		}
		out.XMLRPCMode = XMLRPCMode(xmlrpcMode)
		out.RestrictRESTAPI = RESTAPIMode(restrictREST)
		out.RestrictLoginIdentifier = LoginIdentifierMode(loginID)
		if actorType != nil {
			out.ActorType = *actorType
		}
		if actorID != nil {
			out.ActorID = *actorID
		}
		found = true
		return nil
	})
	return out, found, err
}

// UpsertHardeningConfig inserts or replaces the hardening config for
// (tenantID, siteID). updated_at is refreshed on every upsert. Returns the
// stored config.
func (r *Repo) UpsertHardeningConfig(ctx context.Context, cfg HardeningConfig) (HardeningConfig, error) {
	var out HardeningConfig
	err := r.pool.InTenantTx(ctx, cfg.TenantID, func(tx pgx.Tx) error {
		row := tx.QueryRow(ctx,
			`INSERT INTO site_security_hardening_config
				(site_id, tenant_id, disable_file_editor, xmlrpc_mode,
				 restrict_rest_api, restrict_login_identifier, force_unique_nickname,
				 disable_author_archive_enum, force_ssl, disable_directory_browsing,
				 disable_php_in_uploads, protect_system_files, updated_at,
				 actor_type, actor_id)
			 VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,now(),$13,$14)
			 ON CONFLICT (site_id) DO UPDATE
			   SET disable_file_editor        = EXCLUDED.disable_file_editor,
			       xmlrpc_mode                = EXCLUDED.xmlrpc_mode,
			       restrict_rest_api          = EXCLUDED.restrict_rest_api,
			       restrict_login_identifier  = EXCLUDED.restrict_login_identifier,
			       force_unique_nickname       = EXCLUDED.force_unique_nickname,
			       disable_author_archive_enum = EXCLUDED.disable_author_archive_enum,
			       force_ssl                  = EXCLUDED.force_ssl,
			       disable_directory_browsing = EXCLUDED.disable_directory_browsing,
			       disable_php_in_uploads     = EXCLUDED.disable_php_in_uploads,
			       protect_system_files       = EXCLUDED.protect_system_files,
			       updated_at                 = now(),
			       actor_type                 = EXCLUDED.actor_type,
			       actor_id                   = EXCLUDED.actor_id
			 RETURNING tenant_id, site_id,
			           disable_file_editor, xmlrpc_mode, restrict_rest_api,
			           restrict_login_identifier, force_unique_nickname,
			           disable_author_archive_enum, force_ssl,
			           disable_directory_browsing, disable_php_in_uploads,
			           protect_system_files, updated_at, actor_type, actor_id`,
			cfg.SiteID, cfg.TenantID,
			cfg.DisableFileEditor, string(cfg.XMLRPCMode),
			string(cfg.RestrictRESTAPI), string(cfg.RestrictLoginIdentifier),
			cfg.ForceUniqueNickname, cfg.DisableAuthorArchiveEnum,
			cfg.ForceSSL, cfg.DisableDirectoryBrowsing,
			cfg.DisablePHPInUploads, cfg.ProtectSystemFiles,
			cfg.ActorType, cfg.ActorID,
		)
		var actorType, actorID *string
		var xmlrpcMode, restrictREST, loginID string
		if err := row.Scan(
			&out.TenantID, &out.SiteID,
			&out.DisableFileEditor, &xmlrpcMode, &restrictREST,
			&loginID, &out.ForceUniqueNickname,
			&out.DisableAuthorArchiveEnum, &out.ForceSSL,
			&out.DisableDirectoryBrowsing, &out.DisablePHPInUploads,
			&out.ProtectSystemFiles, &out.UpdatedAt, &actorType, &actorID,
		); err != nil {
			return domain.Internal("security_hardening_upsert_failed",
				"failed to upsert hardening config").WithCause(err)
		}
		out.XMLRPCMode = XMLRPCMode(xmlrpcMode)
		out.RestrictRESTAPI = RESTAPIMode(restrictREST)
		out.RestrictLoginIdentifier = LoginIdentifierMode(loginID)
		if actorType != nil {
			out.ActorType = *actorType
		}
		if actorID != nil {
			out.ActorID = *actorID
		}
		return nil
	})
	return out, err
}

// ---------------------------------------------------------------------------
// Ban list (m76 — site_security_bans)
// ---------------------------------------------------------------------------

// ListBans returns all bans for a site, ordered by created_at DESC, id DESC.
func (r *Repo) ListBans(ctx context.Context, tenantID, siteID uuid.UUID) ([]Ban, error) {
	var out []Ban
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		rows, err := tx.Query(ctx,
			`SELECT id, tenant_id, site_id, type, value, comment,
			        actor_type, actor_id, created_at
			   FROM site_security_bans
			  WHERE tenant_id = $1 AND site_id = $2
			  ORDER BY created_at DESC, id DESC`,
			tenantID, siteID,
		)
		if err != nil {
			return domain.Internal("security_bans_list_failed",
				"failed to list security bans").WithCause(err)
		}
		defer rows.Close()
		for rows.Next() {
			var b Ban
			var banType string
			if err := rows.Scan(
				&b.ID, &b.TenantID, &b.SiteID, &banType, &b.Value,
				&b.Comment, &b.ActorType, &b.ActorID, &b.CreatedAt,
			); err != nil {
				return domain.Internal("security_bans_list_failed",
					"failed to read security ban").WithCause(err)
			}
			b.Type = BanType(banType)
			out = append(out, b)
		}
		return rows.Err()
	})
	return out, err
}

// InsertBan inserts a new ban entry. Returns domain.Conflict when the
// (site_id, type, value) tuple already exists.
func (r *Repo) InsertBan(ctx context.Context, ban Ban) (Ban, error) {
	var out Ban
	err := r.pool.InTenantTx(ctx, ban.TenantID, func(tx pgx.Tx) error {
		row := tx.QueryRow(ctx,
			`INSERT INTO site_security_bans
				(tenant_id, site_id, type, value, comment, actor_type, actor_id)
			 VALUES ($1,$2,$3,$4,$5,$6,$7)
			 RETURNING id, tenant_id, site_id, type, value, comment,
			           actor_type, actor_id, created_at`,
			ban.TenantID, ban.SiteID, string(ban.Type), ban.Value,
			ban.Comment, ban.ActorType, ban.ActorID,
		)
		var banType string
		if err := row.Scan(
			&out.ID, &out.TenantID, &out.SiteID, &banType, &out.Value,
			&out.Comment, &out.ActorType, &out.ActorID, &out.CreatedAt,
		); err != nil {
			// Postgres unique-violation code 23505.
			if isUniqueViolation(err) {
				return domain.Conflict("ban_already_exists",
					"a ban for this type/value already exists on this site")
			}
			return domain.Internal("security_ban_insert_failed",
				"failed to insert security ban").WithCause(err)
		}
		out.Type = BanType(banType)
		return nil
	})
	return out, err
}

// DeleteBan removes the ban with the given id, scoped to (tenantID, siteID).
// Returns domain.NotFound when no such row exists.
func (r *Repo) DeleteBan(ctx context.Context, tenantID, siteID, banID uuid.UUID) error {
	return r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		tag, err := tx.Exec(ctx,
			`DELETE FROM site_security_bans
			  WHERE tenant_id = $1 AND site_id = $2 AND id = $3`,
			tenantID, siteID, banID,
		)
		if err != nil {
			return domain.Internal("security_ban_delete_failed",
				"failed to delete security ban").WithCause(err)
		}
		if tag.RowsAffected() == 0 {
			return domain.NotFound("ban_not_found", "security ban not found")
		}
		return nil
	})
}

// isUniqueViolation reports whether the error is a Postgres unique-constraint
// violation (SQLSTATE 23505). Avoids importing pgconn directly in service code.
func isUniqueViolation(err error) bool {
	type pgErr interface{ SQLState() string }
	var pe pgErr
	if errors.As(err, &pe) {
		return pe.SQLState() == "23505"
	}
	return false
}
