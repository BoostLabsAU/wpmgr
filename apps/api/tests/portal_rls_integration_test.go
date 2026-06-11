// portal_rls_integration_test.go — m66 client-portal isolation against the
// REAL schema (migrations + RLS) as a non-superuser role.
//
// These tests exist because the portal's auth-time site expansion depends on
// policy-in-policy visibility that no unit test can exercise: tables
// referenced inside a policy expression are subject to their own RLS, so
// sites_client_read (which JOINs clients) only works because of
// clients_member_read. The first cut of m66 shipped without that policy and
// every portal principal resolved zero sites; only a live-Postgres probe as a
// non-superuser caught it. This file pins that behavior plus the two gates
// the contract calls out: the client_id = ANY predicate on generated_reports
// (the table has no site_scope restrictive policy, so the predicate is the
// only cross-client gate) and the archived-client revoke chokepoint.
package tests

import (
	"context"
	"testing"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgtype"

	"github.com/mosamlife/wpmgr/apps/api/internal/db"
	"github.com/mosamlife/wpmgr/apps/api/internal/db/sqlc"
)

// portalFixture seeds (via the superuser pool — seeding is out-of-band setup,
// the assertions run as the app role): one tenant, two clients each with one
// site, one portal user per client, and one completed report per client.
type portalFixture struct {
	tenant             uuid.UUID
	clientA, clientB   uuid.UUID
	siteA, siteB       uuid.UUID
	userA, userB       uuid.UUID
	reportA, reportB   uuid.UUID
}

func seedPortalFixture(t *testing.T, app *db.Pool) portalFixture {
	t.Helper()
	ctx := context.Background()
	admin := connectAdmin(t, app)
	defer admin.Close()

	f := portalFixture{
		clientA: uuid.New(), clientB: uuid.New(),
		siteA: uuid.New(), siteB: uuid.New(),
		reportA: uuid.New(), reportB: uuid.New(),
	}

	if err := admin.QueryRow(ctx,
		"INSERT INTO tenants (name, slug) VALUES ('portal-t', 'portal-t') RETURNING id").Scan(&f.tenant); err != nil {
		t.Fatalf("seed tenant: %v", err)
	}
	for _, u := range []struct {
		dst   *uuid.UUID
		email string
	}{{&f.userA, "portal-a@example.com"}, {&f.userB, "portal-b@example.com"}} {
		if err := admin.QueryRow(ctx,
			"INSERT INTO users (email) VALUES ($1) RETURNING id", u.email).Scan(u.dst); err != nil {
			t.Fatalf("seed user %s: %v", u.email, err)
		}
	}
	for _, c := range []struct {
		id   uuid.UUID
		name string
	}{{f.clientA, "Client A"}, {f.clientB, "Client B"}} {
		if _, err := admin.Exec(ctx,
			"INSERT INTO clients (id, tenant_id, name) VALUES ($1, $2, $3)",
			c.id, f.tenant, c.name); err != nil {
			t.Fatalf("seed client %s: %v", c.name, err)
		}
	}
	for _, s := range []struct {
		id, client uuid.UUID
		url        string
	}{{f.siteA, f.clientA, "https://a.portal.example"}, {f.siteB, f.clientB, "https://b.portal.example"}} {
		if _, err := admin.Exec(ctx,
			"INSERT INTO sites (id, tenant_id, url, name, client_id) VALUES ($1, $2, $3, $3, $4)",
			s.id, f.tenant, s.url, s.client); err != nil {
			t.Fatalf("seed site: %v", err)
		}
	}
	for _, m := range []struct{ client, user uuid.UUID }{
		{f.clientA, f.userA}, {f.clientB, f.userB},
	} {
		if _, err := admin.Exec(ctx,
			"INSERT INTO client_members (tenant_id, client_id, user_id) VALUES ($1, $2, $3)",
			f.tenant, m.client, m.user); err != nil {
			t.Fatalf("seed client_member: %v", err)
		}
	}
	for _, r := range []struct{ id, client uuid.UUID }{
		{f.reportA, f.clientA}, {f.reportB, f.clientB},
	} {
		if _, err := admin.Exec(ctx,
			`INSERT INTO generated_reports (id, tenant_id, client_id, period_start, period_end, status, html_blob_key, pdf_blob_key)
			 VALUES ($1, $2, $3, now() - interval '30 days', now(), 'completed', 'k.html', 'k.pdf')`,
			r.id, f.tenant, r.client); err != nil {
			t.Fatalf("seed report: %v", err)
		}
	}
	return f
}

// authSiteIDs runs the exact auth-time expansion (resolveClientAccess's query
// under InUserTx) and returns the resolved client and site IDs.
func authSiteIDs(t *testing.T, pool *db.Pool, userID, tenantID uuid.UUID) (clients, sites []uuid.UUID) {
	t.Helper()
	err := pool.InUserTx(context.Background(), userID, func(tx pgx.Tx) error {
		rows, qerr := sqlc.New(tx).GetClientAccessForUserTenant(context.Background(),
			sqlc.GetClientAccessForUserTenantParams{UserID: userID, TenantID: tenantID})
		if qerr != nil {
			return qerr
		}
		seen := map[uuid.UUID]bool{}
		for _, r := range rows {
			if !seen[r.ClientID] {
				seen[r.ClientID] = true
				clients = append(clients, r.ClientID)
			}
			if r.SiteID.Valid {
				sites = append(sites, uuid.UUID(r.SiteID.Bytes))
			}
		}
		return nil
	})
	if err != nil {
		t.Fatalf("auth expansion: %v", err)
	}
	return clients, sites
}

// TestPortalAuthExpansionResolvesOwnClientSitesOnly proves the policy-in-policy
// chain (client_members_self_read + clients_member_read + sites_client_read)
// resolves a portal user's sites under InUserTx, and only their own.
func TestPortalAuthExpansionResolvesOwnClientSitesOnly(t *testing.T) {
	pool := startPostgres(t)
	f := seedPortalFixture(t, pool)

	clients, sites := authSiteIDs(t, pool, f.userA, f.tenant)
	if len(clients) != 1 || clients[0] != f.clientA {
		t.Fatalf("user A clients = %v, want exactly [%s]", clients, f.clientA)
	}
	if len(sites) != 1 || sites[0] != f.siteA {
		t.Fatalf("user A sites = %v, want exactly [%s] (zero sites means the clients_member_read policy chain is broken)", sites, f.siteA)
	}

	// Policy-level isolation: under user A's InUserTx, a bare SELECT on sites
	// must surface ONLY client A's site (sites_client_read), never B's.
	var visible []uuid.UUID
	err := pool.InUserTx(context.Background(), f.userA, func(tx pgx.Tx) error {
		rows, qerr := tx.Query(context.Background(), "SELECT id FROM sites")
		if qerr != nil {
			return qerr
		}
		defer rows.Close()
		for rows.Next() {
			var id uuid.UUID
			if err := rows.Scan(&id); err != nil {
				return err
			}
			visible = append(visible, id)
		}
		return rows.Err()
	})
	if err != nil {
		t.Fatalf("bare sites select: %v", err)
	}
	if len(visible) != 1 || visible[0] != f.siteA {
		t.Fatalf("sites visible to user A = %v, want exactly [%s]", visible, f.siteA)
	}
}

// TestPortalReportsClientIDPredicateIsTheGate proves the portal reports
// listing returns only the caller's clients' reports. generated_reports
// carries no site_scope restrictive policy, so under the portal principal's
// scoped tenant tx the WHERE client_id = ANY(client_ids) predicate is the
// sole cross-client gate; this test pins that it actually filters.
func TestPortalReportsClientIDPredicateIsTheGate(t *testing.T) {
	pool := startPostgres(t)
	f := seedPortalFixture(t, pool)
	ctx := context.Background()

	// Mirror the portal handler: InScopedTenantTx with the portal principal's
	// allowlist (client A's sites only).
	var got []uuid.UUID
	err := pool.InScopedTenantTx(ctx, f.tenant, f.userA, []uuid.UUID{f.siteA}, func(tx pgx.Tx) error {
		rows, qerr := sqlc.New(tx).ListCompletedReportsForClients(ctx, sqlc.ListCompletedReportsForClientsParams{
			TenantID:        f.tenant,
			ClientIds:       []uuid.UUID{f.clientA},
			CursorCreatedAt: pgtype.Timestamptz{Time: time.Now().Add(time.Hour), Valid: true},
			CursorID:        uuid.Max,
			RowLimit:        50,
		})
		if qerr != nil {
			return qerr
		}
		for _, r := range rows {
			got = append(got, r.ID)
		}
		return nil
	})
	if err != nil {
		t.Fatalf("list reports: %v", err)
	}
	if len(got) != 1 || got[0] != f.reportA {
		t.Fatalf("reports for client A principal = %v, want exactly [%s] (client B's report must be filtered by the ANY predicate)", got, f.reportA)
	}
}

// TestPortalArchivedClientRevokesAccess proves the archived-client chokepoint:
// archiving a client makes the auth expansion return zero rows (fail-closed
// 403 on the next request) and drops site visibility via sites_client_read.
func TestPortalArchivedClientRevokesAccess(t *testing.T) {
	pool := startPostgres(t)
	f := seedPortalFixture(t, pool)
	ctx := context.Background()

	admin := connectAdmin(t, pool)
	defer admin.Close()
	if _, err := admin.Exec(ctx, "UPDATE clients SET archived_at = now() WHERE id = $1", f.clientA); err != nil {
		t.Fatalf("archive client A: %v", err)
	}

	clients, sites := authSiteIDs(t, pool, f.userA, f.tenant)
	if len(clients) != 0 || len(sites) != 0 {
		t.Fatalf("archived client A still resolves clients=%v sites=%v, want none", clients, sites)
	}

	// User B's access is untouched.
	clientsB, sitesB := authSiteIDs(t, pool, f.userB, f.tenant)
	if len(clientsB) != 1 || len(sitesB) != 1 {
		t.Fatalf("user B affected by archiving client A: clients=%v sites=%v", clientsB, sitesB)
	}

	// Hard delete of a client WITH an assigned site: the membership CASCADEs
	// and the site survives unassigned. Pins the m66 FK repair — m63's bare
	// composite SET NULL nulled sites.tenant_id too, so this exact DELETE
	// failed with 23502.
	if _, err := admin.Exec(ctx, "DELETE FROM clients WHERE id = $1", f.clientB); err != nil {
		t.Fatalf("delete client B (with assigned site): %v", err)
	}
	var n int
	if err := admin.QueryRow(ctx, "SELECT count(*) FROM client_members WHERE client_id = $1", f.clientB).Scan(&n); err != nil {
		t.Fatalf("count members: %v", err)
	}
	if n != 0 {
		t.Fatalf("client_members not CASCADEd on client delete: %d rows remain", n)
	}
	var siteTenant uuid.UUID
	var siteClient *uuid.UUID
	if err := admin.QueryRow(ctx,
		"SELECT tenant_id, client_id FROM sites WHERE id = $1", f.siteB).Scan(&siteTenant, &siteClient); err != nil {
		t.Fatalf("site B must survive client deletion: %v", err)
	}
	if siteTenant != f.tenant || siteClient != nil {
		t.Fatalf("site B after client delete: tenant=%s client=%v, want tenant kept and client NULL", siteTenant, siteClient)
	}
}
