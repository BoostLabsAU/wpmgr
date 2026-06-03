// Integration test for the Performance Suite (m36) row-level-security policies.
// Proves the perf tables enforce tenant isolation (tenant_isolation policy) and
// allow the cross-tenant agent/worker path (agent policy), against a real
// Postgres with the production non-superuser role. Requires Docker; skips when
// unavailable (via startPostgres).
package tests

import (
	"context"
	"testing"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/mosamlife/wpmgr/apps/api/internal/db"
)

// seedSiteFor inserts a minimal sites row for a tenant via the superuser pool
// (sites has FORCE RLS; the superuser bypasses it for fixture setup).
func seedSiteFor(t *testing.T, admin *db.Pool, tenant uuid.UUID, url string) uuid.UUID {
	t.Helper()
	var id uuid.UUID
	if err := admin.QueryRow(context.Background(),
		`INSERT INTO sites (tenant_id, url, name) VALUES ($1, $2, 'seed') RETURNING id`,
		tenant, url).Scan(&id); err != nil {
		t.Fatalf("seed site: %v", err)
	}
	return id
}

// TestPerfConfigRLS proves site_perf_config (representative of all 5 m36 tables,
// which share the identical tenant_isolation + agent policy block) isolates
// tenants and exposes the agent escape exactly like the rest of the schema.
func TestPerfConfigRLS(t *testing.T) {
	app := startPostgres(t) // connected as the non-superuser wpmgr_app role
	admin := connectAdmin(t, app)
	defer admin.Close()
	ctx := context.Background()

	tenantA := seedTenant(t, app, "perf-a-"+uuid.NewString()[:8])
	tenantB := seedTenant(t, app, "perf-b-"+uuid.NewString()[:8])
	siteA := seedSiteFor(t, admin, tenantA, "https://"+uuid.NewString()+".example.com")
	siteA2 := seedSiteFor(t, admin, tenantA, "https://"+uuid.NewString()+".example.com")

	// Seed a perf config for tenant A's site via the superuser (bypasses RLS).
	if _, err := admin.Exec(ctx,
		`INSERT INTO site_perf_config (site_id, tenant_id, cache_enabled) VALUES ($1, $2, true)`,
		siteA, tenantA); err != nil {
		t.Fatalf("seed perf config: %v", err)
	}

	countUnder := func(run func(fn func(pgx.Tx) error) error) int {
		var n int
		if err := run(func(tx pgx.Tx) error {
			return tx.QueryRow(ctx, `SELECT count(*) FROM site_perf_config WHERE site_id = $1`, siteA).Scan(&n)
		}); err != nil {
			t.Fatalf("count query: %v", err)
		}
		return n
	}

	// 1. Tenant A (its own scope) sees its config.
	if got := countUnder(func(fn func(pgx.Tx) error) error { return app.InTenantTx(ctx, tenantA, fn) }); got != 1 {
		t.Fatalf("tenant A must see its own perf config, got %d", got)
	}
	// 2. Tenant B does NOT see tenant A's config (tenant_isolation).
	if got := countUnder(func(fn func(pgx.Tx) error) error { return app.InTenantTx(ctx, tenantB, fn) }); got != 0 {
		t.Fatalf("tenant B must NOT see tenant A's perf config (tenant_isolation), got %d", got)
	}
	// 3. The agent/worker scope sees it cross-tenant (agent policy — the path the
	//    heartbeat stats writer + RUCSS worker use via InAgentTx).
	if got := countUnder(func(fn func(pgx.Tx) error) error { return app.InAgentTx(ctx, fn) }); got != 1 {
		t.Fatalf("agent scope must see the perf config (agent policy), got %d", got)
	}

	// 4. WITH CHECK: tenant B cannot INSERT a perf config carrying tenant A's id
	//    even for a real site of A's (siteA2 exists, has no config). The FK is
	//    satisfied, so a success would be an RLS WITH CHECK failure.
	errWrite := app.InTenantTx(ctx, tenantB, func(tx pgx.Tx) error {
		_, e := tx.Exec(ctx,
			`INSERT INTO site_perf_config (site_id, tenant_id, cache_enabled) VALUES ($1, $2, true)`,
			siteA2, tenantA)
		return e
	})
	if errWrite == nil {
		t.Fatal("tenant B must NOT be able to write a perf config for tenant A (WITH CHECK)")
	}

	// 5. Sanity: the row really is still there and unique (only tenant A's).
	var total int
	if err := admin.QueryRow(ctx, `SELECT count(*) FROM site_perf_config`).Scan(&total); err != nil {
		t.Fatalf("total count: %v", err)
	}
	if total != 1 {
		t.Fatalf("expected exactly 1 perf config row overall, got %d", total)
	}
}
