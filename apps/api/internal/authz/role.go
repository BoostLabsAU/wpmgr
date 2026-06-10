// Package authz defines the WPMgr role hierarchy, the permission matrix, and
// the helpers handlers/middleware use to enforce role minimums and discrete
// permissions. Roles are totally ordered: owner > admin > operator > viewer.
package authz

// Role is a principal's role within a tenant.
type Role string

const (
	// RoleViewer can read tenant-scoped resources but not mutate them.
	RoleViewer Role = "viewer"
	// RoleOperator can manage sites (create/update/delete) in addition to reads.
	RoleOperator Role = "operator"
	// RoleAdmin can manage members and API keys and read the audit log.
	RoleAdmin Role = "admin"
	// RoleOwner has full control of the tenant, including ownership transfer.
	RoleOwner Role = "owner"
)

// rank gives each role a comparable level; higher is more privileged.
var rank = map[Role]int{
	RoleViewer:   1,
	RoleOperator: 2,
	RoleAdmin:    3,
	RoleOwner:    4,
}

// Valid reports whether r is a known role.
func (r Role) Valid() bool {
	_, ok := rank[r]
	return ok
}

// AtLeast reports whether r is at least as privileged as min.
func (r Role) AtLeast(min Role) bool {
	return rank[r] >= rank[min]
}

// Permission is a discrete capability checked by RequirePermission.
type Permission string

const (
	// PermSiteRead lists/reads sites.
	PermSiteRead Permission = "site:read"
	// PermSiteWrite creates/updates/deletes sites.
	PermSiteWrite Permission = "site:write"
	// PermMemberRead lists tenant members.
	PermMemberRead Permission = "member:read"
	// PermMemberManage invites/updates/removes tenant members.
	PermMemberManage Permission = "member:manage"
	// PermAPIKeyRead lists API keys.
	PermAPIKeyRead Permission = "apikey:read"
	// PermAPIKeyManage creates/revokes API keys.
	PermAPIKeyManage Permission = "apikey:manage"
	// PermAuditRead reads the audit log.
	PermAuditRead Permission = "audit:read"
	// PermTenantManage manages tenant settings.
	PermTenantManage Permission = "tenant:manage"
	// PermSiteAutologin mints a one-time autologin URL into a managed WordPress
	// site. The minted JWT lets the receiving agent establish an authenticated
	// wp-admin session in the operator's browser. Reserved for owner+admin in V0
	// (operator/viewer are explicitly excluded; finer per-grant flows are out of
	// scope for V0).
	PermSiteAutologin Permission = "site:autologin"
	// PermMediaDeleteOriginals authorises the IRREVERSIBLE "delete originals"
	// media action (ADR-043 §6): once a site's archived originals are deleted,
	// an optimized attachment can never be restored. Gated at admin+ (above the
	// operator-level PermSiteWrite that guards sync/optimize/restore) and paired
	// with a type-the-hostname UI confirmation.
	PermMediaDeleteOriginals Permission = "media:delete_originals"
	// PermSMTPManage edits the instance-level SMTP relay (ADR-045): host/port/
	// credentials/From + the send-test. It writes a stored secret and is the
	// instance's mail transport, so it sits with PermTenantManage at owner-only.
	PermSMTPManage Permission = "smtp:manage"
	// PermSiteCacheManage enables/disables and reconfigures the agent-side page
	// cache for a site (Performance Suite, ADR-046). Operator+ — the same
	// site-management tier as PermSiteWrite; site-scoped (NOT in orgLevelPerms),
	// so a collaborator with access to a site can manage that site's cache.
	PermSiteCacheManage Permission = "site.cache.manage"
	// PermSiteCachePurge triggers a cache purge/preload for a site (ADR-046).
	// Operator+; site-scoped.
	PermSiteCachePurge Permission = "site.cache.purge"
	// PermSitePerfConfig saves the per-site performance configuration — minify,
	// RUCSS, lazy-load, CDN, DB-clean, bloat removal (ADR-046). Operator+;
	// site-scoped.
	PermSitePerfConfig Permission = "site.perf.config"
	// PermSiteCacheDeleteAll authorises the destructive "delete everything"
	// cache action — drop the on-disk cache directory, the advanced-cache
	// drop-in and the managed .htaccess block in one shot (ADR-046). Gated at
	// admin+ (above the operator-level cache perms), mirroring the
	// PermMediaDeleteOriginals destructive-action precedent.
	PermSiteCacheDeleteAll Permission = "site.cache.delete-everything"

	// PermMediaCleanScan authorises the read-only attachment reference scan
	// (#190). Viewer+ — no side effects.
	PermMediaCleanScan Permission = "site.media.clean.scan"
	// PermMediaCleanWrite authorises the reversible isolate and restore actions
	// (#190). Operator+ — data-mutation operations comparable to PermSiteWrite.
	PermMediaCleanWrite Permission = "site.media.clean.write"
	// PermMediaCleanDelete authorises the PERMANENT deletion of quarantined
	// attachments (#190). Admin+ — irreversible, mirrors PermMediaDeleteOriginals.
	PermMediaCleanDelete Permission = "site.media.clean.delete"

	// PermEmailManage configures and reads per-site outgoing email (SMTP /
	// provider config, secrets, test-send). Operator+ (same tier as
	// PermSiteCacheManage and PermSitePerfConfig) — site-write-class, NOT in
	// orgLevelPerms. A site collaborator with access to a site can manage
	// that site's email config.
	PermEmailManage Permission = "site.email.manage"

	// PermClientRead lists and reads agency clients (m63). Viewer+ — org-scoped
	// only; site-scoped collaborators never see the client roster.
	PermClientRead Permission = "client:read"
	// PermClientManage creates, updates, deletes, and assigns agency clients (m63).
	// Operator+ — same tier as PermSiteWrite; includes the assignment flow.
	PermClientManage Permission = "client:manage"
)

// minRoleFor maps each permission to the minimum role that holds it. The matrix
// is intentionally simple (role-rank based) for V0; finer-grained grants can be
// layered later without changing call sites.
var minRoleFor = map[Permission]Role{
	PermSiteRead:      RoleViewer,
	PermSiteWrite:     RoleOperator,
	PermMemberRead:    RoleViewer,
	PermMemberManage:  RoleAdmin,
	PermAPIKeyRead:    RoleAdmin,
	PermAPIKeyManage:  RoleAdmin,
	PermAuditRead:     RoleAdmin,
	PermTenantManage:  RoleOwner,
	PermSiteAutologin: RoleAdmin,
	// Irreversible media original-deletion: admin+ (ADR-043 §6).
	PermMediaDeleteOriginals: RoleAdmin,
	// Instance SMTP transport + stored secret: owner-only (ADR-045).
	PermSMTPManage: RoleOwner,
	// Performance Suite (ADR-046). Cache enable/purge + perf config are
	// site-management actions at operator+; the destructive delete-everything is
	// admin+ (mirrors PermMediaDeleteOriginals).
	PermSiteCacheManage:    RoleOperator,
	PermSiteCachePurge:     RoleOperator,
	PermSitePerfConfig:     RoleOperator,
	PermSiteCacheDeleteAll: RoleAdmin,
	// Media Cleaner (#190). Scan is read-only (viewer+); isolate/restore are
	// reversible mutations (operator+); permanent delete is admin+ (irreversible).
	PermMediaCleanScan:   RoleViewer,
	PermMediaCleanWrite:  RoleOperator,
	PermMediaCleanDelete: RoleAdmin,
	// Per-site Email Management (m59). Operator+ — site-write-class.
	PermEmailManage: RoleOperator,
	// Agency Clients (m63). Read: viewer+; manage: operator+; org-scoped only.
	PermClientRead:   RoleViewer,
	PermClientManage: RoleOperator,
}

// Allows reports whether role r is permitted to perform p.
func Allows(r Role, p Permission) bool {
	min, ok := minRoleFor[p]
	if !ok {
		return false
	}
	return r.AtLeast(min)
}
