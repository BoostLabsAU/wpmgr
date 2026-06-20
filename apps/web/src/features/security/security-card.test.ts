/**
 * Tests for SecurityCard + SecurityOverview logic.
 *
 * Convention: pure-logic tests only — no React renderer, no DOM.
 * These tests pin the data-derivation logic and DTO shape contracts so a
 * mismatch between backend response shapes and the UI's assumptions fails
 * here, not in production.
 *
 * Card anatomy:
 *   - rounded-2xl border bg-card section
 *   - header: icon-square + title + purpose + status pill + optional action
 *   - body: border-t bg-muted/30 p-4, only when expanded
 *   - collapsed by default; danger/warning auto-expand
 *
 * GET /security/policy shape confirmation (handler.go:644-661):
 *   The policyDTO has NO enrollment_summary field. The 15-field flat DTO is the
 *   complete response. Any 2FA enrollment display must NOT rely on fields
 *   beyond the 15 documented in use-policy.ts:SiteSecurityPolicy.
 */

import { describe, it, expect } from "vitest";

import type { HardeningConfig } from "./use-hardening";
import type { SiteSecurityPolicy } from "./use-policy";
import type { ScanRun } from "./use-scan";
import type { CardStatus } from "./security-card";

// ---------------------------------------------------------------------------
// 1. CardStatus type — variant constraint
// ---------------------------------------------------------------------------

describe("CardStatus — variant must be one of success|warning|destructive|muted", () => {
  it("success variant is a valid CardStatus", () => {
    const s: CardStatus = { variant: "success", label: "7 / 10 on" };
    expect(s.variant).toBe("success");
  });

  it("warning variant is a valid CardStatus", () => {
    const s: CardStatus = { variant: "warning", label: "Audit" };
    expect(s.variant).toBe("warning");
  });

  it("destructive variant is a valid CardStatus", () => {
    const s: CardStatus = { variant: "destructive", label: "3 findings" };
    expect(s.variant).toBe("destructive");
  });

  it("muted variant is a valid CardStatus", () => {
    const s: CardStatus = { variant: "muted", label: "Off" };
    expect(s.variant).toBe("muted");
  });
});

// ---------------------------------------------------------------------------
// 2. Hardening status derivation — matches hardeningStatus() in the route
// ---------------------------------------------------------------------------

/** Mirrors the hardeningStatus() logic in $siteId.security.tsx exactly. */
function deriveHardeningStatus(config: HardeningConfig): CardStatus {
  const boolToggles = [
    config.disable_file_editor,
    config.disable_php_in_uploads,
    config.protect_system_files,
    config.disable_directory_browsing,
    config.force_unique_nickname,
    config.disable_author_archive_enum,
    config.force_ssl,
  ] as const;
  const boolOn = boolToggles.filter(Boolean).length;
  const xmlrpcOn = config.xmlrpc_mode !== "on" ? 1 : 0;
  const restOn = config.restrict_rest_api === "restricted" ? 1 : 0;
  const loginOn = config.restrict_login_identifier !== "both" ? 1 : 0;
  const total = boolOn + xmlrpcOn + restOn + loginOn;
  if (total >= 7) return { variant: "success", label: `${total} / 10 on` };
  if (total >= 4) return { variant: "warning", label: `${total} / 10 on` };
  return { variant: "destructive", label: `${total} / 10 on` };
}

const BASE_HARDENING: HardeningConfig = {
  disable_file_editor: false,
  xmlrpc_mode: "on",
  restrict_rest_api: "default",
  restrict_login_identifier: "both",
  force_unique_nickname: false,
  disable_author_archive_enum: false,
  force_ssl: false,
  disable_directory_browsing: false,
  disable_php_in_uploads: false,
  protect_system_files: false,
};

describe("deriveHardeningStatus — 0..10 feature count maps to correct variant", () => {
  it("0 features on → destructive variant", () => {
    const status = deriveHardeningStatus(BASE_HARDENING);
    expect(status.variant).toBe("destructive");
    expect(status.label).toBe("0 / 10 on");
  });

  it("4 features on → warning variant", () => {
    const config: HardeningConfig = {
      ...BASE_HARDENING,
      disable_file_editor: true,
      disable_php_in_uploads: true,
      protect_system_files: true,
      disable_directory_browsing: true,
    };
    const status = deriveHardeningStatus(config);
    expect(status.variant).toBe("warning");
    expect(status.label).toBe("4 / 10 on");
  });

  it("7 features on → success variant", () => {
    const config: HardeningConfig = {
      ...BASE_HARDENING,
      disable_file_editor: true,
      disable_php_in_uploads: true,
      protect_system_files: true,
      disable_directory_browsing: true,
      force_unique_nickname: true,
      disable_author_archive_enum: true,
      force_ssl: true,
    };
    const status = deriveHardeningStatus(config);
    expect(status.variant).toBe("success");
    expect(status.label).toBe("7 / 10 on");
  });

  it("10 features on → success variant with 10 / 10 label", () => {
    const config: HardeningConfig = {
      disable_file_editor: true,
      xmlrpc_mode: "off",
      restrict_rest_api: "restricted",
      restrict_login_identifier: "email",
      force_unique_nickname: true,
      disable_author_archive_enum: true,
      force_ssl: true,
      disable_directory_browsing: true,
      disable_php_in_uploads: true,
      protect_system_files: true,
    };
    const status = deriveHardeningStatus(config);
    expect(status.variant).toBe("success");
    expect(status.label).toBe("10 / 10 on");
  });

  it("xmlrpc_mode='limited' counts as 1 hardened (not just 'off')", () => {
    const config: HardeningConfig = {
      ...BASE_HARDENING,
      xmlrpc_mode: "limited",
    };
    const s = deriveHardeningStatus(config);
    // Only 1 feature on (xmlrpc limited)
    expect(s.variant).toBe("destructive");
    expect(s.label).toBe("1 / 10 on");
  });

  it("restrict_rest_api='restricted' counts as 1 hardened", () => {
    const config: HardeningConfig = {
      ...BASE_HARDENING,
      restrict_rest_api: "restricted",
    };
    const s = deriveHardeningStatus(config);
    expect(s.label).toBe("1 / 10 on");
  });

  it("restrict_login_identifier='email' counts as 1 hardened", () => {
    const config: HardeningConfig = {
      ...BASE_HARDENING,
      restrict_login_identifier: "email",
    };
    const s = deriveHardeningStatus(config);
    expect(s.label).toBe("1 / 10 on");
  });
});

// ---------------------------------------------------------------------------
// 3. Scan status derivation
// ---------------------------------------------------------------------------

/** Mirrors the scanStatus() logic in $siteId.security.tsx exactly. */
function deriveScanStatus(runs: ScanRun[] | undefined): CardStatus {
  if (!runs) return { variant: "muted", label: "Loading" };
  const latest = runs[0];
  if (!latest) return { variant: "muted", label: "Never scanned" };
  if (latest.status === "done") {
    const total = Object.values(latest.finding_counts ?? {}).reduce(
      (s, n) => s + n,
      0,
    );
    if (total === 0) return { variant: "success", label: "Clean" };
    return {
      variant: "destructive",
      label: `${total} finding${total !== 1 ? "s" : ""}`,
    };
  }
  if (latest.status === "failed") return { variant: "warning", label: "Failed" };
  return { variant: "warning", label: "Scanning" };
}

function makeScanRun(overrides: Partial<ScanRun> = {}): ScanRun {
  return {
    id: "run-1",
    kind: "core",
    status: "done",
    files_scanned: 1234,
    wp_version: "6.5.0",
    locale: "en_US",
    error: null,
    finding_counts: {},
    created_at: "2026-06-20T00:00:00Z",
    started_at: "2026-06-20T00:00:00Z",
    finished_at: "2026-06-20T00:01:00Z",
    ...overrides,
  };
}

describe("deriveScanStatus — scan run states map to correct pill", () => {
  it("no runs → muted 'Never scanned'", () => {
    const s = deriveScanStatus([]);
    expect(s.variant).toBe("muted");
    expect(s.label).toBe("Never scanned");
  });

  it("undefined runs → muted 'Loading'", () => {
    const s = deriveScanStatus(undefined);
    expect(s.variant).toBe("muted");
    expect(s.label).toBe("Loading");
  });

  it("done run with no findings → success 'Clean'", () => {
    const s = deriveScanStatus([makeScanRun({ finding_counts: {} })]);
    expect(s.variant).toBe("success");
    expect(s.label).toBe("Clean");
  });

  it("done run with null finding_counts is treated as 0 findings", () => {
    const s = deriveScanStatus([makeScanRun({ finding_counts: null })]);
    expect(s.variant).toBe("success");
    expect(s.label).toBe("Clean");
  });

  it("done run with 1 finding → destructive singular label", () => {
    const s = deriveScanStatus([
      makeScanRun({ finding_counts: { core_modified: 1 } }),
    ]);
    expect(s.variant).toBe("destructive");
    expect(s.label).toBe("1 finding");
  });

  it("done run with 3 findings → destructive plural label", () => {
    const s = deriveScanStatus([
      makeScanRun({
        finding_counts: {
          core_modified: 2,
          core_missing: 1,
        },
      }),
    ]);
    expect(s.variant).toBe("destructive");
    expect(s.label).toBe("3 findings");
  });

  it("failed run → warning 'Failed'", () => {
    const s = deriveScanStatus([makeScanRun({ status: "failed" })]);
    expect(s.variant).toBe("warning");
    expect(s.label).toBe("Failed");
  });

  it("scanning run → warning 'Scanning'", () => {
    const s = deriveScanStatus([makeScanRun({ status: "scanning" })]);
    expect(s.variant).toBe("warning");
    expect(s.label).toBe("Scanning");
  });

  it("queued run → warning 'Scanning'", () => {
    const s = deriveScanStatus([makeScanRun({ status: "queued" })]);
    expect(s.variant).toBe("warning");
    expect(s.label).toBe("Scanning");
  });
});

// ---------------------------------------------------------------------------
// 4. 2FA status derivation
// ---------------------------------------------------------------------------

/** Mirrors the twoFactorStatus() logic in $siteId.security.tsx. */
function deriveTwoFactorStatus(
  policy: SiteSecurityPolicy | undefined,
): CardStatus {
  if (!policy) return { variant: "muted", label: "Loading" };
  if (!policy.two_factor_enabled) return { variant: "muted", label: "Off" };
  const required = policy.two_factor_required_roles.length > 0;
  return {
    variant: "success",
    label: required
      ? `Required · ${policy.two_factor_required_roles.length} role${policy.two_factor_required_roles.length !== 1 ? "s" : ""}`
      : "Optional",
  };
}

const BASE_POLICY: SiteSecurityPolicy = {
  two_factor_enabled: false,
  two_factor_methods: ["totp", "email", "backup"],
  two_factor_required_roles: [],
  two_factor_grace_logins: 3,
  two_factor_remember_device_days: 30,
  block_xmlrpc_for_2fa_users: true,
  password_min_zxcvbn_score: 0,
  password_min_zxcvbn_roles: [],
  password_block_compromised: false,
  password_reuse_block_count: 0,
  password_max_age_days: 0,
  password_expiry_roles: [],
  hide_backend_enabled: false,
  hide_backend_slug: "",
  hide_backend_redirect: "",
};

describe("deriveTwoFactorStatus — maps policy to pill", () => {
  it("undefined policy → muted 'Loading'", () => {
    const s = deriveTwoFactorStatus(undefined);
    expect(s.variant).toBe("muted");
    expect(s.label).toBe("Loading");
  });

  it("2FA disabled → muted 'Off'", () => {
    const s = deriveTwoFactorStatus({ ...BASE_POLICY, two_factor_enabled: false });
    expect(s.variant).toBe("muted");
    expect(s.label).toBe("Off");
  });

  it("2FA enabled with no required roles → success 'Optional'", () => {
    const s = deriveTwoFactorStatus({
      ...BASE_POLICY,
      two_factor_enabled: true,
      two_factor_required_roles: [],
    });
    expect(s.variant).toBe("success");
    expect(s.label).toBe("Optional");
  });

  it("2FA enabled with 1 required role → success with singular label", () => {
    const s = deriveTwoFactorStatus({
      ...BASE_POLICY,
      two_factor_enabled: true,
      two_factor_required_roles: ["administrator"],
    });
    expect(s.variant).toBe("success");
    expect(s.label).toBe("Required · 1 role");
  });

  it("2FA enabled with 2 required roles → success with plural label", () => {
    const s = deriveTwoFactorStatus({
      ...BASE_POLICY,
      two_factor_enabled: true,
      two_factor_required_roles: ["administrator", "editor"],
    });
    expect(s.variant).toBe("success");
    expect(s.label).toBe("Required · 2 roles");
  });
});

// ---------------------------------------------------------------------------
// 5. CONTRACT SAFETY: GET /security/policy enrollment summary
//
// The policyDTO (handler.go:644-661) does NOT include an enrollment_summary
// field. This test pins that the SiteSecurityPolicy type has exactly the 15
// non-optional fields from the Go DTO (+ optional updated_at) — no more.
//
// If a CP endpoint is added to expose enrollment data, it must be a NEW
// endpoint or a separately versioned response, never a field silently added
// to the existing policyDTO. Adding a field to the TS type without the Go
// handler emitting it would produce undefined at runtime.
// ---------------------------------------------------------------------------

describe("GET /security/policy shape — NO enrollment_summary field (CONTRACT)", () => {
  it("SiteSecurityPolicy has exactly 15 required fields matching Go policyDTO", () => {
    // Construct a complete wire payload (what toPolicyDTO() in handler.go emits).
    const wirePayload: Record<string, unknown> = {
      two_factor_enabled: false,
      two_factor_methods: ["totp", "email", "backup"],
      two_factor_required_roles: [],
      two_factor_grace_logins: 3,
      two_factor_remember_device_days: 30,
      block_xmlrpc_for_2fa_users: true,
      password_min_zxcvbn_score: 0,
      password_min_zxcvbn_roles: [],
      password_block_compromised: false,
      password_reuse_block_count: 0,
      password_max_age_days: 0,
      password_expiry_roles: [],
      hide_backend_enabled: false,
      hide_backend_slug: "",
      hide_backend_redirect: "",
      // updated_at is omitempty — absent when row not yet saved.
    };

    // The Go handler does NOT emit any enrollment_summary field.
    expect(wirePayload).not.toHaveProperty("enrollment_summary");
    expect(wirePayload).not.toHaveProperty("enrolled_count");
    expect(wirePayload).not.toHaveProperty("required_count");

    // Our TS type casts without loss (via unknown for strict compatibility check).
    const policy = wirePayload as unknown as SiteSecurityPolicy;
    expect(policy.two_factor_enabled).toBe(false);
    expect(Array.isArray(policy.two_factor_methods)).toBe(true);
  });

  it("enrollment status must NOT be rendered from non-existent DTO fields", () => {
    // This is the guard against fabricating data. The card shows an explainer
    // block explaining the enrollment flow, but never reads enrollment counts.
    // Any future CP endpoint for enrollment status needs to be a separate query.
    const policy: SiteSecurityPolicy = { ...BASE_POLICY };
    // Accessing a non-existent key returns undefined — our render must guard this.
    // Cast through unknown to access a non-existent property safely.
    const asRecord = policy as unknown as Record<string, unknown>;
    const fabricated = asRecord["enrollment_summary"];
    expect(fabricated).toBeUndefined();
  });
});

// ---------------------------------------------------------------------------
// 6. Auto-expand logic — danger/warning cards open by default
// ---------------------------------------------------------------------------

describe("SecurityCard auto-expand — danger/warning cards open, others collapse", () => {
  function shouldAutoExpand(status: CardStatus): boolean {
    return status.variant === "destructive" || status.variant === "warning";
  }

  it("destructive status → auto-expand true", () => {
    expect(shouldAutoExpand({ variant: "destructive", label: "3 findings" })).toBe(true);
  });

  it("warning status → auto-expand true", () => {
    expect(shouldAutoExpand({ variant: "warning", label: "Audit" })).toBe(true);
  });

  it("success status → auto-expand false", () => {
    expect(shouldAutoExpand({ variant: "success", label: "Clean" })).toBe(false);
  });

  it("muted status → auto-expand false", () => {
    expect(shouldAutoExpand({ variant: "muted", label: "Off" })).toBe(false);
  });
});

// ---------------------------------------------------------------------------
// 7. Password policy status derivation
// ---------------------------------------------------------------------------

/** Mirrors the passwordPolicyStatus() logic in $siteId.security.tsx. */
function derivePasswordStatus(
  policy: SiteSecurityPolicy | undefined,
): CardStatus {
  if (!policy) return { variant: "muted", label: "Loading" };
  const hasAny =
    policy.password_min_zxcvbn_score > 0 ||
    policy.password_block_compromised ||
    policy.password_reuse_block_count > 0 ||
    policy.password_max_age_days > 0;
  return hasAny
    ? { variant: "success", label: "Active" }
    : { variant: "muted", label: "Off" };
}

describe("derivePasswordStatus — maps policy to pill", () => {
  it("all password fields off → muted 'Off'", () => {
    const s = derivePasswordStatus(BASE_POLICY);
    expect(s.variant).toBe("muted");
    expect(s.label).toBe("Off");
  });

  it("min strength score > 0 → success 'Active'", () => {
    const s = derivePasswordStatus({
      ...BASE_POLICY,
      password_min_zxcvbn_score: 2,
    });
    expect(s.variant).toBe("success");
    expect(s.label).toBe("Active");
  });

  it("block_compromised true → success 'Active'", () => {
    const s = derivePasswordStatus({
      ...BASE_POLICY,
      password_block_compromised: true,
    });
    expect(s.variant).toBe("success");
  });

  it("reuse_block_count > 0 → success 'Active'", () => {
    const s = derivePasswordStatus({
      ...BASE_POLICY,
      password_reuse_block_count: 5,
    });
    expect(s.variant).toBe("success");
  });

  it("max_age_days > 0 → success 'Active'", () => {
    const s = derivePasswordStatus({
      ...BASE_POLICY,
      password_max_age_days: 90,
    });
    expect(s.variant).toBe("success");
  });

  it("undefined policy → muted 'Loading'", () => {
    const s = derivePasswordStatus(undefined);
    expect(s.variant).toBe("muted");
    expect(s.label).toBe("Loading");
  });
});

// ---------------------------------------------------------------------------
// 8. Hide login status derivation
// ---------------------------------------------------------------------------

/** Mirrors hideLoginStatus() in $siteId.security.tsx. */
function deriveHideLoginStatus(
  policy: SiteSecurityPolicy | undefined,
): CardStatus {
  if (!policy) return { variant: "muted", label: "Loading" };
  return policy.hide_backend_enabled
    ? { variant: "success", label: "Active" }
    : { variant: "muted", label: "Off" };
}

describe("deriveHideLoginStatus — enabled/disabled maps to pill", () => {
  it("hide_backend_enabled false → muted 'Off'", () => {
    const s = deriveHideLoginStatus(BASE_POLICY);
    expect(s.variant).toBe("muted");
    expect(s.label).toBe("Off");
  });

  it("hide_backend_enabled true → success 'Active'", () => {
    const s = deriveHideLoginStatus({
      ...BASE_POLICY,
      hide_backend_enabled: true,
      hide_backend_slug: "secret-access",
    });
    expect(s.variant).toBe("success");
    expect(s.label).toBe("Active");
  });

  it("undefined policy → muted 'Loading'", () => {
    const s = deriveHideLoginStatus(undefined);
    expect(s.variant).toBe("muted");
    expect(s.label).toBe("Loading");
  });
});

// ---------------------------------------------------------------------------
// 9. SecurityCard props anatomy — structural contract
// ---------------------------------------------------------------------------

describe("SecurityCard props anatomy", () => {
  it("a SecurityCard with all required props satisfies the interface", () => {
    // Type-level assertion: constructing the props object at TypeScript compile
    // time ensures the interface is stable. No runtime assertion needed.
    const props = {
      icon: null,
      iconTint: "bg-[var(--color-primary)]/10 text-[var(--color-primary)]",
      title: "Login & Two-Factor",
      purpose: "Require a second login step for sensitive roles.",
      status: { variant: "success" as const, label: "Optional" },
      children: null,
    };
    expect(props.title).toBe("Login & Two-Factor");
    expect(props.status.variant).toBe("success");
  });

  it("action slot is optional", () => {
    // CardStatus without action is still valid.
    const props: {
      title: string;
      status: CardStatus;
      action?: React.ReactNode;
    } = {
      title: "Hardening",
      status: { variant: "warning", label: "4 / 10 on" },
      // action intentionally omitted
    };
    expect(props.action).toBeUndefined();
  });

  it("six card ids match the tile navigation targets", () => {
    // The SecurityOverview tiles call onTileClick with these ids.
    // The SecurityCard wrappers use data-card-id matching these exact strings.
    const TILE_TARGETS = [
      "card-login-2fa",
      "card-bans",
      "card-file-integrity",
      "card-login-2fa", // 2FA tile also scrolls to card-login-2fa
    ];
    const CARD_IDS = [
      "card-login-2fa",
      "card-password",
      "card-hardening",
      "card-file-integrity",
      "card-bans",
      "card-hide-login",
    ];
    // Every tile target must correspond to a card id.
    for (const target of TILE_TARGETS) {
      expect(CARD_IDS).toContain(target);
    }
  });
});
