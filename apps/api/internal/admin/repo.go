package admin

import (
	"context"
	"errors"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/mosamlife/wpmgr/apps/api/internal/db"
	"github.com/mosamlife/wpmgr/apps/api/internal/db/sqlc"
	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
)

// Repo provides superadmin data access. All queries run on the bare pool without
// RLS tenant scope — the users table has no RLS, and the admin area is gated by
// the requireSuperadmin middleware.
type Repo struct {
	pool *db.Pool
	q    *sqlc.Queries
}

// NewRepo builds an admin Repo over the pgx pool.
func NewRepo(pool *db.Pool) *Repo {
	return &Repo{pool: pool, q: sqlc.New(pool.Pool)}
}

// AdminUser is the view model for the superadmin user list.
type AdminUser struct {
	ID            uuid.UUID
	Email         string
	Name          string
	Status        string
	EmailVerified bool
	CreatedAt     time.Time
	LastLoginAt   *time.Time
	IsSuperadmin  bool
	OrgCount      int64
}

// AdminStats holds instance-wide counts.
type AdminStats struct {
	Users int64
	Orgs  int64
	Sites int64
}

// asBool safely converts an interface{} column from a computed boolean expression
// (e.g. `email_verified_at IS NOT NULL AS email_verified`) to a Go bool.
func asBool(v interface{}) bool {
	if v == nil {
		return false
	}
	if b, ok := v.(bool); ok {
		return b
	}
	return false
}

// ListUsers returns all users across the instance, optionally filtered by search.
// An empty search string matches all users. The org_count column is a LEFT JOIN
// onto memberships, which has FORCE row-level security, so the query MUST run
// under app.agent='on' (the memberships_agent SELECT policy) via InAgentTx — on
// the bare pool the unset app.tenant_id GUC would hide every membership and make
// org_count always 0.
func (r *Repo) ListUsers(ctx context.Context, search string, limit, offset int32) ([]AdminUser, error) {
	var out []AdminUser
	err := r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		rows, err := sqlc.New(tx).AdminListUsers(ctx, sqlc.AdminListUsersParams{
			Column1: search,
			Limit:   limit,
			Offset:  offset,
		})
		if err != nil {
			return err
		}
		out = make([]AdminUser, 0, len(rows))
		for _, row := range rows {
			u := AdminUser{
				ID:            row.ID,
				Email:         row.Email,
				Name:          row.Name,
				Status:        row.Status,
				EmailVerified: asBool(row.EmailVerified),
				CreatedAt:     row.CreatedAt,
				IsSuperadmin:  row.IsSuperadmin,
				OrgCount:      row.OrgCount,
			}
			if row.LastLoginAt.Valid {
				t := row.LastLoginAt.Time
				u.LastLoginAt = &t
			}
			out = append(out, u)
		}
		return nil
	})
	if err != nil {
		return nil, domain.Internal("admin_list_users_failed", "failed to list users").WithCause(err)
	}
	return out, nil
}

// GetUser loads a single user by ID for the superadmin view.
func (r *Repo) GetUser(ctx context.Context, id uuid.UUID) (AdminUser, error) {
	row, err := r.q.AdminGetUserByID(ctx, id)
	if err != nil {
		if errors.Is(err, pgx.ErrNoRows) {
			return AdminUser{}, domain.NotFound("user_not_found", "user not found")
		}
		return AdminUser{}, domain.Internal("admin_get_user_failed", "failed to load user").WithCause(err)
	}
	u := AdminUser{
		ID:            row.ID,
		Email:         row.Email,
		Name:          row.Name,
		Status:        row.Status,
		EmailVerified: asBool(row.EmailVerified),
		CreatedAt:     row.CreatedAt,
		IsSuperadmin:  row.IsSuperadmin,
	}
	if row.LastLoginAt.Valid {
		t := row.LastLoginAt.Time
		u.LastLoginAt = &t
	}
	return u, nil
}

// DeleteUser permanently deletes a user by ID. Returns NotFound when no row
// was deleted.
func (r *Repo) DeleteUser(ctx context.Context, id uuid.UUID) error {
	n, err := r.q.AdminDeleteUser(ctx, id)
	if err != nil {
		return domain.Internal("admin_delete_user_failed", "failed to delete user").WithCause(err)
	}
	if n == 0 {
		return domain.NotFound("user_not_found", "user not found")
	}
	return nil
}

// OrphanTenant is a tenant that a user is the sole member of — deleting that
// user would leave the org memberless. SiteCount distinguishes a truly empty
// org (safe to remove) from one that still owns sites (kept + flagged).
type OrphanTenant struct {
	ID        uuid.UUID
	Name      string
	SiteCount int64
}

// SoleTenants returns the tenants in which userID is the ONLY member, with each
// tenant's name + site count. It runs under InAgentTx (memberships_agent +
// sites_agent) so the cross-tenant counts are visible. Call this BEFORE deleting
// the user — afterwards the membership rows are gone and the orphans cannot be
// reconstructed.
func (r *Repo) SoleTenants(ctx context.Context, userID uuid.UUID) ([]OrphanTenant, error) {
	var out []OrphanTenant
	err := r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		rows, err := sqlc.New(tx).AdminUserSoleTenants(ctx, userID)
		if err != nil {
			return err
		}
		out = make([]OrphanTenant, 0, len(rows))
		for _, row := range rows {
			out = append(out, OrphanTenant{ID: row.TenantID, Name: row.TenantName, SiteCount: row.SiteCount})
		}
		return nil
	})
	if err != nil {
		return nil, domain.Internal("admin_sole_tenants_failed", "failed to inspect orphaned orgs").WithCause(err)
	}
	return out, nil
}

// DeleteEmptyTenant removes a tenant only if it has no memberships and no sites,
// returning true when a row was actually deleted. It delegates to the
// admin_delete_empty_tenant SECURITY DEFINER function (owner privileges) because
// a tenant's ON DELETE CASCADE reaches audit_log, which wpmgr_app may not delete
// (the trail is insert-only) — a direct DELETE fails 42501. The function re-checks
// emptiness inside the statement and pins app.agent='on' itself, so a tenant that
// gained a member or site between SoleTenants and this call is left intact. The
// InAgentTx wrapper is retained for transactional consistency with the caller.
func (r *Repo) DeleteEmptyTenant(ctx context.Context, tenantID uuid.UUID) (bool, error) {
	var deleted bool
	err := r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		ok, err := sqlc.New(tx).AdminDeleteEmptyTenant(ctx, tenantID)
		if err != nil {
			return err
		}
		deleted = ok
		return nil
	})
	if err != nil {
		return false, domain.Internal("admin_delete_tenant_failed", "failed to delete orphaned org").WithCause(err)
	}
	return deleted, nil
}

// TenancyRef is one tenant reference in the site-tenancy diagnostic. Role is the
// membership/share role or the source table label; Count is the row count.
type TenancyRef struct {
	TenantID   uuid.UUID `json:"tenant_id"`
	TenantName string    `json:"tenant_name"`
	Role       string    `json:"role,omitempty"`
	Count      int64     `json:"count,omitempty"`
}

// SiteTenancyReport compares where a site + its perf data live against a user's
// org memberships — for diagnosing a tenant/ownership split.
type SiteTenancyReport struct {
	SiteID         uuid.UUID    `json:"site_id"`
	SiteFound      bool         `json:"site_found"`
	SiteTenantID   uuid.UUID    `json:"site_tenant_id"`
	SiteTenantName string       `json:"site_tenant_name"`
	SiteURL        string       `json:"site_url"`
	DataTenants    []TenancyRef `json:"data_tenants"`
	Memberships    []TenancyRef `json:"your_memberships"`
	SiteShares     []TenancyRef `json:"site_shares"`
}

// SiteTenancy returns where a site (and its rucss/cache-stats/config rows) live vs
// the requesting user's orgs. Runs under InAgentTx so the *_agent RLS policies
// expose rows cross-tenant. Read-only.
func (r *Repo) SiteTenancy(ctx context.Context, userID, siteID uuid.UUID) (SiteTenancyReport, error) {
	rep := SiteTenancyReport{SiteID: siteID}
	err := r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		// site owner
		serr := tx.QueryRow(ctx,
			`SELECT s.tenant_id, t.name, s.url FROM sites s JOIN tenants t ON t.id = s.tenant_id WHERE s.id = $1`,
			siteID,
		).Scan(&rep.SiteTenantID, &rep.SiteTenantName, &rep.SiteURL)
		if serr == nil {
			rep.SiteFound = true
		} else if !errors.Is(serr, pgx.ErrNoRows) {
			return serr
		}

		// which tenant owns each perf table's rows for this site
		dataQ := []struct{ label, sql string }{
			{"rucss_results", `SELECT r.tenant_id, t.name, count(*) FROM rucss_results r JOIN tenants t ON t.id = r.tenant_id WHERE r.site_id = $1 GROUP BY r.tenant_id, t.name`},
			{"site_cache_stats", `SELECT c.tenant_id, t.name, count(*) FROM site_cache_stats c JOIN tenants t ON t.id = c.tenant_id WHERE c.site_id = $1 GROUP BY c.tenant_id, t.name`},
			{"site_perf_config", `SELECT pc.tenant_id, t.name, count(*) FROM site_perf_config pc JOIN tenants t ON t.id = pc.tenant_id WHERE pc.site_id = $1 GROUP BY pc.tenant_id, t.name`},
		}
		for _, dq := range dataQ {
			rows, qerr := tx.Query(ctx, dq.sql, siteID)
			if qerr != nil {
				return qerr
			}
			for rows.Next() {
				ref := TenancyRef{Role: dq.label}
				if scanErr := rows.Scan(&ref.TenantID, &ref.TenantName, &ref.Count); scanErr != nil {
					rows.Close()
					return scanErr
				}
				rep.DataTenants = append(rep.DataTenants, ref)
			}
			rows.Close()
			if rows.Err() != nil {
				return rows.Err()
			}
		}

		// the requesting user's org memberships
		mem, merr := collectRefs(ctx, tx,
			`SELECT m.tenant_id, t.name, m.role FROM memberships m JOIN tenants t ON t.id = m.tenant_id WHERE m.user_id = $1`,
			userID)
		if merr != nil {
			return merr
		}
		rep.Memberships = mem
		// any per-site share of this site
		shares, sherr := collectRefs(ctx, tx,
			`SELECT sh.tenant_id, t.name, sh.role FROM site_shares sh JOIN tenants t ON t.id = sh.tenant_id WHERE sh.site_id = $1`,
			siteID)
		if sherr != nil {
			return sherr
		}
		rep.SiteShares = shares
		return nil
	})
	if err != nil {
		return SiteTenancyReport{}, domain.Internal("admin_site_tenancy_failed", "failed to inspect site tenancy").WithCause(err)
	}
	return rep, nil
}

// collectRefs runs a (tenant_id, name, role) query and returns the refs.
func collectRefs(ctx context.Context, tx pgx.Tx, sql string, arg uuid.UUID) ([]TenancyRef, error) {
	rows, err := tx.Query(ctx, sql, arg)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []TenancyRef
	for rows.Next() {
		var ref TenancyRef
		if err := rows.Scan(&ref.TenantID, &ref.TenantName, &ref.Role); err != nil {
			return nil, err
		}
		out = append(out, ref)
	}
	return out, rows.Err()
}

// SetStatus updates a user's status and returns the updated view model.
func (r *Repo) SetStatus(ctx context.Context, id uuid.UUID, status string) (AdminUser, error) {
	row, err := r.q.AdminSetUserStatus(ctx, sqlc.AdminSetUserStatusParams{ID: id, Status: status})
	if err != nil {
		if errors.Is(err, pgx.ErrNoRows) {
			return AdminUser{}, domain.NotFound("user_not_found", "user not found")
		}
		return AdminUser{}, domain.Internal("admin_set_status_failed", "failed to update status").WithCause(err)
	}
	u := AdminUser{
		ID:            row.ID,
		Email:         row.Email,
		Name:          row.Name,
		Status:        row.Status,
		EmailVerified: asBool(row.EmailVerified),
		CreatedAt:     row.CreatedAt,
		IsSuperadmin:  row.IsSuperadmin,
	}
	if row.LastLoginAt.Valid {
		t := row.LastLoginAt.Time
		u.LastLoginAt = &t
	}
	return u, nil
}

// Stats returns instance-wide counts for users, orgs, and sites. The sites
// table has FORCE row-level security, so the count must run under app.agent='on'
// (the sites_agent policy) via InAgentTx — on the bare pool the unset
// app.tenant_id GUC would make the sites count always 0. users + tenants have no
// RLS, so their counts are unaffected by the agent scope.
func (r *Repo) Stats(ctx context.Context) (AdminStats, error) {
	var out AdminStats
	err := r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		row, err := sqlc.New(tx).AdminInstanceStats(ctx)
		if err != nil {
			return err
		}
		out = AdminStats{Users: row.UserCount, Orgs: row.OrgCount, Sites: row.SiteCount}
		return nil
	})
	if err != nil {
		return AdminStats{}, domain.Internal("admin_stats_failed", "failed to load stats").WithCause(err)
	}
	return out, nil
}
