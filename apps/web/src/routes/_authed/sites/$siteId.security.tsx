import { createFileRoute } from "@tanstack/react-router";

import { useMe, canOperate } from "@/features/auth/use-auth";
import { LoginProtectionPanel } from "@/features/security/login-protection-panel";
import { LoginEventsTable } from "@/features/security/login-events-table";
import { ScanPanel } from "@/features/security/scan-panel";
import { HardeningPanel } from "@/features/security/hardening-panel";
import { BanListPanel } from "@/features/security/ban-list-panel";
import { PolicyPanel } from "@/features/security/policy-panel";

// `/sites/$siteId/security` — six sections:
//
//   1. Hardening (Phase 1) — 10 server-hardening toggles grouped into
//      sub-sections (file/content, XML-RPC, REST API, login, transport).
//      Backed by hand-rolled Gin: GET/PUT /api/v1/sites/{siteId}/security/hardening.
//
//   2. Ban list (Phase 1) — per-site IP / CIDR / user-agent ban table
//      with inline add form and delete action.
//      Backed by hand-rolled Gin: GET/POST/DELETE /api/v1/sites/{siteId}/security/bans.
//
//   3. Login Protection (S2) — config panel + recent events table.
//
//   4. Vulnerabilities — WPScan-based vuln scan (future sprint stub).
//      Retained from prior batch. When the scan endpoint lands:
//        - swap the empty state for an ErrorsTable-style table pattern
//        - use VulnSeverityChip for severity cells
//
//   5. Integrity scan (Phase 2) — core file integrity scan using WordPress.org
//      checksums. Powered by hand-rolled Gin endpoints (not in ogen client).
//
//   6. Authentication policy (Phase 3, ADR-059) — site-user 2FA, password
//      policy, and hide-login-page config. Flat DTO from hand-rolled Gin:
//      GET/PUT /api/v1/sites/{siteId}/security/policy.
//      Groups: GET/PUT/DELETE /api/v1/sites/{siteId}/security/policy/groups/:role.
//
// Write access gates (PermSecurityManage = operator+):
//   `canWrite` is derived from `canOperate(me)` which maps to owner/admin/operator.
//   Viewer-role users see all panels in read-only mode (controls disabled or
//   hidden, no mutation calls possible).

export const Route = createFileRoute("/_authed/sites/$siteId/security")({
  component: SecurityTab,
});

function SecurityTab() {
  const { siteId } = Route.useParams();
  const { data: me } = useMe();
  const canWrite = canOperate(me);

  return (
    <div className="divide-y divide-[var(--color-border)]">
      {/* ── Section 1: Hardening ── */}
      <section
        aria-labelledby="hardening-heading"
        className="space-y-6 px-4 pb-8 pt-6 sm:px-6"
      >
        <h2
          id="hardening-heading"
          className="text-xs font-medium uppercase tracking-wide text-muted-foreground"
        >
          Hardening
        </h2>

        <HardeningPanel siteId={siteId} canWrite={canWrite} />
      </section>

      {/* ── Section 2: Ban list ── */}
      <section
        aria-labelledby="ban-list-heading"
        className="space-y-4 px-4 pb-8 pt-6 sm:px-6"
      >
        <div>
          <h2
            id="ban-list-heading"
            className="text-xs font-medium uppercase tracking-wide text-muted-foreground"
          >
            Bans
          </h2>
          <p className="mt-1 text-xs text-[var(--color-muted-foreground)]">
            Block specific IPs, CIDR ranges, or user agents from reaching the
            site. Rules are applied at the application layer.
          </p>
        </div>

        <BanListPanel siteId={siteId} canWrite={canWrite} />
      </section>

      {/* ── Section 3: Login protection ── */}
      <section
        aria-labelledby="login-protection-heading"
        className="space-y-6 px-4 pb-8 pt-6 sm:px-6"
      >
        <h2
          id="login-protection-heading"
          className="text-xs font-medium uppercase tracking-wide text-muted-foreground"
        >
          Login protection
        </h2>

        <LoginProtectionPanel siteId={siteId} />

        {/* Recent events table sits below the config panel in the same section */}
        <div className="pt-2">
          <LoginEventsTable siteId={siteId} />
        </div>
      </section>

      {/* ── Section 4: Vulnerabilities (future-sprint stub) ── */}
      <section
        aria-labelledby="vulnerabilities-heading"
        className="px-4 pb-8 pt-6 sm:px-6"
      >
        <h2
          id="vulnerabilities-heading"
          className="mb-4 text-xs font-medium uppercase tracking-wide text-muted-foreground"
        >
          Vulnerabilities
        </h2>

        {/* Scan-backend-pending: no card wrapper per DESIGN rule "never nest cards" */}
        <div
          role="status"
          aria-label="No scan results yet"
          className="flex flex-col items-center gap-3 py-12 text-center"
        >
          <p className="text-balance text-sm text-[var(--color-muted-foreground)]">
            Run a scan to check plugins, themes, and WordPress core against the
            WPScan database.
          </p>
        </div>
      </section>

      {/* ── Section 5: File integrity (Phase 2) ── */}
      <section
        aria-labelledby="integrity-scan-heading"
        className="space-y-4 px-4 pb-8 pt-6 sm:px-6"
      >
        <h2
          id="integrity-scan-heading"
          className="text-xs font-medium uppercase tracking-wide text-muted-foreground"
        >
          File integrity
        </h2>

        <ScanPanel siteId={siteId} canWrite={canWrite} />
      </section>

      {/* ── Section 6: Authentication policy (Phase 3, ADR-059) ── */}
      <section
        aria-labelledby="auth-policy-heading"
        className="space-y-6 px-4 pb-8 pt-6 sm:px-6"
      >
        <div>
          <h2
            id="auth-policy-heading"
            className="text-xs font-medium uppercase tracking-wide text-muted-foreground"
          >
            Authentication policy
          </h2>
          <p className="mt-1 text-xs text-[var(--color-muted-foreground)]">
            Configure two-factor authentication, password requirements, and
            login-page visibility for WordPress users on this site.
          </p>
        </div>

        <PolicyPanel siteId={siteId} canWrite={canWrite} />
      </section>
    </div>
  );
}
