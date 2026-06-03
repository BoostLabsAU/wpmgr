// Integration tests for the superadmin orphaned-org cleanup (#151a) against a
// real Postgres with the production role topology: a superuser owner owns the
// tables and the app connects as the NON-superuser wpmgr_app, which has
// UPDATE/DELETE/TRUNCATE revoked on the append-only audit_log.
//
// These reproduce the prod failure mode and prove the m35
// admin_delete_empty_tenant SECURITY DEFINER fix. They require Docker and skip
// when it is unavailable (via startPostgres).
package tests

import (
	"context"
	"os"
	"strings"
	"testing"

	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/db"
)

// seedAuditRow inserts one audit_log row for a tenant via the superuser pool
// (audit_log has FORCE RLS + INSERT WITH CHECK on app.tenant_id; the superuser
// bypasses it). This mirrors the register event every real signup writes.
func seedAuditRow(t *testing.T, admin *db.Pool, tenant uuid.UUID) {
	t.Helper()
	_, err := admin.Exec(context.Background(),
		`INSERT INTO audit_log (tenant_id, actor_type, action, hash)
		 VALUES ($1, 'user', 'register', 'seed-hash')`, tenant)
	if err != nil {
		t.Fatalf("seed audit row: %v", err)
	}
}

func countAuditRows(t *testing.T, admin *db.Pool, tenant uuid.UUID) int {
	t.Helper()
	var n int
	if err := admin.QueryRow(context.Background(),
		`SELECT count(*) FROM audit_log WHERE tenant_id = $1`, tenant).Scan(&n); err != nil {
		t.Fatalf("count audit rows: %v", err)
	}
	return n
}

func tenantExists(t *testing.T, admin *db.Pool, tenant uuid.UUID) bool {
	t.Helper()
	var ok bool
	if err := admin.QueryRow(context.Background(),
		`SELECT EXISTS (SELECT 1 FROM tenants WHERE id = $1)`, tenant).Scan(&ok); err != nil {
		t.Fatalf("tenant exists: %v", err)
	}
	return ok
}

func seedUserRow(t *testing.T, admin *db.Pool, email string) uuid.UUID {
	t.Helper()
	var id uuid.UUID
	if err := admin.QueryRow(context.Background(),
		`INSERT INTO users (email) VALUES ($1) RETURNING id`, email).Scan(&id); err != nil {
		t.Fatalf("seed user: %v", err)
	}
	return id
}

func seedMembershipRow(t *testing.T, admin *db.Pool, user, tenant uuid.UUID) {
	t.Helper()
	if _, err := admin.Exec(context.Background(),
		`INSERT INTO memberships (tenant_id, user_id, role) VALUES ($1, $2, 'owner')`,
		tenant, user); err != nil {
		t.Fatalf("seed membership: %v", err)
	}
}

func seedSiteRow(t *testing.T, admin *db.Pool, tenant uuid.UUID) {
	t.Helper()
	if _, err := admin.Exec(context.Background(),
		`INSERT INTO sites (tenant_id, url, name) VALUES ($1, $2, 'seed')`,
		tenant, "https://"+uuid.NewString()+".example.com"); err != nil {
		t.Fatalf("seed site: %v", err)
	}
}

// callDeleteEmptyTenant invokes the SECURITY DEFINER function as the app role
// (wpmgr_app) via the EXECUTE grant — exactly how admin.Repo.DeleteEmptyTenant
// reaches it in production.
func callDeleteEmptyTenant(t *testing.T, app *db.Pool, tenant uuid.UUID) bool {
	t.Helper()
	var deleted bool
	if err := app.QueryRow(context.Background(),
		`SELECT admin_delete_empty_tenant($1)`, tenant).Scan(&deleted); err != nil {
		t.Fatalf("call admin_delete_empty_tenant: %v", err)
	}
	return deleted
}

func TestAdminOrphanCleanup(t *testing.T) {
	app := startPostgres(t) // connected as wpmgr_app (non-superuser)
	admin := connectAdmin(t, app)
	defer admin.Close()
	ctx := context.Background()
	_ = ctx

	// Diagnostic: the OLD path. A direct DELETE FROM tenants by wpmgr_app on a
	// tenant that holds an audit_log row. In prod (Cloud SQL) this fails 42501
	// because the FK cascade's audit_log delete is privilege-checked against
	// wpmgr_app, which lacks DELETE on audit_log. We log the outcome rather than
	// hard-assert, since the RI-cascade privilege behavior can differ by Postgres
	// build; the fix is robust either way.
	t.Run("old_direct_delete_diagnostic", func(t *testing.T) {
		tid := seedTenant(t, app, "orphan-old-"+uuid.NewString()[:8])
		seedAuditRow(t, admin, tid)
		_, err := app.Exec(context.Background(), `DELETE FROM tenants WHERE id = $1`, tid)
		switch {
		case err == nil:
			t.Logf("OLD direct DELETE FROM tenants SUCCEEDED as wpmgr_app in this container "+
				"(RI cascade did not privilege-check audit_log here; prod Cloud SQL DID fail 42501). "+
				"tenant gone=%v — the m35 explicit-delete fix is robust to both behaviors", !tenantExists(t, admin, tid))
		case strings.Contains(err.Error(), "audit_log"):
			t.Logf("OLD direct DELETE FROM tenants failed as expected (reproduces prod 42501): %v", err)
			if !tenantExists(t, admin, tid) {
				t.Fatalf("tenant should survive a failed cascade delete")
			}
		default:
			t.Fatalf("unexpected error on old direct delete: %v", err)
		}
	})

	// The fix: an empty orphaned tenant (no members, no sites) WITH audit history
	// is deleted, and its audit rows go with it.
	t.Run("empty_tenant_deleted_with_audit", func(t *testing.T) {
		tid := seedTenant(t, app, "orphan-empty-"+uuid.NewString()[:8])
		seedAuditRow(t, admin, tid)
		seedAuditRow(t, admin, tid)
		if got := countAuditRows(t, admin, tid); got != 2 {
			t.Fatalf("precondition: expected 2 audit rows, got %d", got)
		}
		if deleted := callDeleteEmptyTenant(t, app, tid); !deleted {
			t.Fatal("expected the empty tenant to be deleted (true)")
		}
		if tenantExists(t, admin, tid) {
			t.Fatal("tenant should be gone")
		}
		if got := countAuditRows(t, admin, tid); got != 0 {
			t.Fatalf("audit rows should be gone, got %d", got)
		}
	})

	// A tenant that still has a membership is NOT deleted.
	t.Run("tenant_with_membership_kept", func(t *testing.T) {
		tid := seedTenant(t, app, "orphan-mem-"+uuid.NewString()[:8])
		seedAuditRow(t, admin, tid)
		uid := seedUserRow(t, admin, "member-"+uuid.NewString()[:8]+"@example.com")
		seedMembershipRow(t, admin, uid, tid)
		if deleted := callDeleteEmptyTenant(t, app, tid); deleted {
			t.Fatal("must NOT delete a tenant that still has a membership")
		}
		if !tenantExists(t, admin, tid) {
			t.Fatal("tenant with a membership must survive")
		}
	})

	// A tenant that still owns a site is NOT deleted.
	t.Run("tenant_with_site_kept", func(t *testing.T) {
		tid := seedTenant(t, app, "orphan-site-"+uuid.NewString()[:8])
		seedAuditRow(t, admin, tid)
		seedSiteRow(t, admin, tid)
		if deleted := callDeleteEmptyTenant(t, app, tid); deleted {
			t.Fatal("must NOT delete a tenant that still owns a site")
		}
		if !tenantExists(t, admin, tid) {
			t.Fatal("tenant with a site must survive")
		}
	})
}

// TestAdminOrphanCleanup_NonSuperuserOwner recreates admin_delete_empty_tenant
// owned by a NOSUPERUSER NOBYPASSRLS role, replicating prod's Cloud SQL owner
// (cloudsqlsuperuser is NOT a true superuser, so FORCE RLS applies to it). The
// shared harness owns everything as the container's TRUE superuser, which both
// accepts a function-level SET on the custom app.agent GUC that a non-superuser
// owner rejects AND bypasses FORCE RLS on the explicit audit_log delete — so it
// cannot exercise the prod condition. This test does:
//   (a) runs the REAL m35 migration file as the non-superuser owner, proving the
//       CREATE FUNCTION is accepted (a function-level `SET "app.agent"` clause
//       would abort here with "permission denied to set parameter app.agent");
//   (b) deletes an empty tenant + its audit rows when the owner is itself subject
//       to FORCE RLS (so the in-function app.tenant_id scoping is load-bearing).
func TestAdminOrphanCleanup_NonSuperuserOwner(t *testing.T) {
	app := startPostgres(t)
	admin := connectAdmin(t, app)
	defer admin.Close()
	ctx := context.Background()

	for _, stmt := range []string{
		// Drop the harness-created (superuser-owned) function so the non-superuser
		// owner can create it.
		"DROP FUNCTION IF EXISTS admin_delete_empty_tenant(uuid)",
		"CREATE ROLE m35owner NOSUPERUSER NOBYPASSRLS",
		"GRANT USAGE, CREATE ON SCHEMA public TO m35owner",
		// Same table privileges the prod owner has (incl. DELETE on audit_log,
		// which is what wpmgr_app lacks). m35owner stays subject to FORCE RLS.
		"GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO m35owner",
	} {
		if _, err := admin.Exec(ctx, stmt); err != nil {
			t.Fatalf("setup (%q): %v", stmt, err)
		}
	}

	mig, err := os.ReadFile("../migrations/20260603070000_m35_delete_empty_tenant_func.sql")
	if err != nil {
		t.Fatalf("read m35 migration: %v", err)
	}
	// Execute the actual migration body as the non-superuser owner. A failure here
	// is the must-fix the prior review caught: a function-level SET on app.agent
	// aborts CREATE FUNCTION under a non-superuser owner.
	if _, err := admin.Exec(ctx, "SET ROLE m35owner;\n"+string(mig)+"\nRESET ROLE;"); err != nil {
		t.Fatalf("CREATE FUNCTION as non-superuser owner failed (prod owner condition): %v", err)
	}

	// (b) empty tenant with audit history is deleted under the FORCE-RLS owner.
	tid := seedTenant(t, app, "nso-empty-"+uuid.NewString()[:8])
	seedAuditRow(t, admin, tid)
	seedAuditRow(t, admin, tid)
	if deleted := callDeleteEmptyTenant(t, app, tid); !deleted {
		t.Fatal("expected empty tenant deleted under non-superuser owner")
	}
	if tenantExists(t, admin, tid) {
		t.Fatal("tenant should be gone")
	}
	if n := countAuditRows(t, admin, tid); n != 0 {
		t.Fatalf("audit rows should be gone under a FORCE-RLS owner, got %d", n)
	}

	// And a tenant that owns a site is still refused.
	tid2 := seedTenant(t, app, "nso-site-"+uuid.NewString()[:8])
	seedAuditRow(t, admin, tid2)
	seedSiteRow(t, admin, tid2)
	if deleted := callDeleteEmptyTenant(t, app, tid2); deleted {
		t.Fatal("must NOT delete a tenant that still owns a site (non-superuser owner)")
	}
	if !tenantExists(t, admin, tid2) {
		t.Fatal("tenant with a site must survive")
	}
}
