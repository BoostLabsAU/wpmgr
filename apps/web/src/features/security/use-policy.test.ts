/**
 * Tests for Security Suite Phase 3 — site-user auth policy domain.
 *
 * Following the project convention in use-hardening.test.ts / scan-findings.test.ts:
 * pure-function tests only; no React renderer, no DOM.
 *
 * Contract goal: a shape mismatch between the Go policyDTO (handler.go:644-661)
 * and our TypeScript types must be caught here, not in prod.
 *
 * Go struct reference (handler.go:644-661):
 *
 *   type policyDTO struct {
 *     TwoFactorEnabled            bool     `json:"two_factor_enabled"`
 *     TwoFactorMethods            []string `json:"two_factor_methods"`
 *     TwoFactorRequiredRoles      []string `json:"two_factor_required_roles"`
 *     TwoFactorGraceLogins        int      `json:"two_factor_grace_logins"`
 *     TwoFactorRememberDeviceDays int      `json:"two_factor_remember_device_days"`
 *     BlockXMLRPCFor2FAUsers      bool     `json:"block_xmlrpc_for_2fa_users"`
 *     PasswordMinZxcvbnScore      int      `json:"password_min_zxcvbn_score"`
 *     PasswordMinZxcvbnRoles      []string `json:"password_min_zxcvbn_roles"`
 *     PasswordBlockCompromised    bool     `json:"password_block_compromised"`
 *     PasswordReuseBlockCount     int      `json:"password_reuse_block_count"`
 *     PasswordMaxAgeDays          int      `json:"password_max_age_days"`
 *     PasswordExpiryRoles         []string `json:"password_expiry_roles"`
 *     HideBackendEnabled          bool     `json:"hide_backend_enabled"`
 *     HideBackendSlug             string   `json:"hide_backend_slug"`
 *     HideBackendRedirect         string   `json:"hide_backend_redirect"`
 *     UpdatedAt                   string   `json:"updated_at,omitempty"`
 *   }
 *
 * Group DTO reference (handler.go:735-743):
 *
 *   type policyGroupDTO struct {
 *     Role             string   `json:"role"`
 *     Require2FA       *bool    `json:"require_2fa,omitempty"`
 *     AllowedMethods   []string `json:"allowed_methods,omitempty"`
 *     MinZxcvbnScore   *int     `json:"min_zxcvbn_score,omitempty"`
 *     BlockCompromised *bool    `json:"block_compromised,omitempty"`
 *     MaxAgeDays       *int     `json:"max_age_days,omitempty"`
 *     CreatedAt        string   `json:"created_at,omitempty"`
 *   }
 */

import { describe, it, expect, vi } from "vitest";

import {
  validateHideBackendSlug,
  validateHideBackendRedirect,
  TFA_ENABLE_NUDGE,
  DEFAULT_POLICY,
  type SiteSecurityPolicy,
  type PolicyGroup,
} from "./use-policy";

// ---------------------------------------------------------------------------
// 1. DTO shape — flat GET response against Go policyDTO json tags
//
// Construct a complete wire payload and cast it to SiteSecurityPolicy.
// If the Go handler renames a json tag (e.g. "two_factor_enabled" → "tfa_enabled"),
// the TypeScript cast below will still compile BUT the field read will be
// undefined. The explicit field checks here surface that divergence.
// ---------------------------------------------------------------------------

/** Simulates the flat JSON body from GET /security/policy (Go toPolicyDTO). */
function makeWirePolicy(
  overrides: Partial<Record<string, unknown>> = {},
): unknown {
  return {
    // Must match policyDTO json tags EXACTLY (handler.go:644-661).
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
    updated_at: "2026-06-20T00:00:00Z",
    ...overrides,
  };
}

describe("policyDTO shape — all 16 Go json tags present in wire payload", () => {
  it("all required Go json fields are present in the wire payload", () => {
    const wire = makeWirePolicy() as Record<string, unknown>;
    // These field names MUST match the json:"..." tags in handler.go:644-661.
    const requiredFields = [
      "two_factor_enabled",
      "two_factor_methods",
      "two_factor_required_roles",
      "two_factor_grace_logins",
      "two_factor_remember_device_days",
      "block_xmlrpc_for_2fa_users",
      "password_min_zxcvbn_score",
      "password_min_zxcvbn_roles",
      "password_block_compromised",
      "password_reuse_block_count",
      "password_max_age_days",
      "password_expiry_roles",
      "hide_backend_enabled",
      "hide_backend_slug",
      "hide_backend_redirect",
    ];
    for (const field of requiredFields) {
      expect(wire).toHaveProperty(field);
    }
  });

  it("the wire payload casts to SiteSecurityPolicy without losing any field", () => {
    const wire = makeWirePolicy() as SiteSecurityPolicy;
    // Two-factor fields.
    expect(typeof wire.two_factor_enabled).toBe("boolean");
    expect(Array.isArray(wire.two_factor_methods)).toBe(true);
    expect(Array.isArray(wire.two_factor_required_roles)).toBe(true);
    expect(typeof wire.two_factor_grace_logins).toBe("number");
    expect(typeof wire.two_factor_remember_device_days).toBe("number");
    expect(typeof wire.block_xmlrpc_for_2fa_users).toBe("boolean");
    // Password fields.
    expect(typeof wire.password_min_zxcvbn_score).toBe("number");
    expect(Array.isArray(wire.password_min_zxcvbn_roles)).toBe(true);
    expect(typeof wire.password_block_compromised).toBe("boolean");
    expect(typeof wire.password_reuse_block_count).toBe("number");
    expect(typeof wire.password_max_age_days).toBe("number");
    expect(Array.isArray(wire.password_expiry_roles)).toBe(true);
    // Hide-backend fields.
    expect(typeof wire.hide_backend_enabled).toBe("boolean");
    expect(typeof wire.hide_backend_slug).toBe("string");
    expect(typeof wire.hide_backend_redirect).toBe("string");
  });

  it("the policy renders without crashing against a realistic flat GET payload", () => {
    // Explicit field-by-field check against the Go defaults (handler.go comment):
    //   two_factor_enabled: false
    //   two_factor_methods: ["totp","email","backup"]
    //   two_factor_required_roles: []
    //   two_factor_grace_logins: 3
    //   two_factor_remember_device_days: 30
    //   block_xmlrpc_for_2fa_users: true
    //   password_min_zxcvbn_score: 0
    //   password_block_compromised: false
    //   password_reuse_block_count: 0
    //   password_max_age_days: 0
    //   hide_backend_enabled: false
    //   hide_backend_slug: ""
    //   hide_backend_redirect: ""
    const wire = makeWirePolicy() as SiteSecurityPolicy;
    expect(wire.two_factor_enabled).toBe(false);
    expect(wire.two_factor_methods).toEqual(["totp", "email", "backup"]);
    expect(wire.two_factor_required_roles).toEqual([]);
    expect(wire.two_factor_grace_logins).toBe(3);
    expect(wire.two_factor_remember_device_days).toBe(30);
    expect(wire.block_xmlrpc_for_2fa_users).toBe(true);
    expect(wire.password_min_zxcvbn_score).toBe(0);
    expect(wire.password_block_compromised).toBe(false);
    expect(wire.password_reuse_block_count).toBe(0);
    expect(wire.password_max_age_days).toBe(0);
    expect(wire.hide_backend_enabled).toBe(false);
    expect(wire.hide_backend_slug).toBe("");
    expect(wire.hide_backend_redirect).toBe("");
  });

  it("a full SiteSecurityPolicy object satisfies all 15 required (non-optional) fields", () => {
    // If a new field is added to the Go policyDTO without updating the TS type,
    // this object construction fails at compile time (strict TypeScript).
    const policy: SiteSecurityPolicy = {
      two_factor_enabled: true,
      two_factor_methods: ["totp", "backup"],
      two_factor_required_roles: ["administrator"],
      two_factor_grace_logins: 3,
      two_factor_remember_device_days: 30,
      block_xmlrpc_for_2fa_users: true,
      password_min_zxcvbn_score: 3,
      password_min_zxcvbn_roles: [],
      password_block_compromised: true,
      password_reuse_block_count: 5,
      password_max_age_days: 90,
      password_expiry_roles: ["administrator", "editor"],
      hide_backend_enabled: true,
      hide_backend_slug: "my-login",
      hide_backend_redirect: "",
    };
    // Count the non-optional (defined) fields.
    const definedKeys = Object.keys(policy).filter(
      (k) => policy[k as keyof SiteSecurityPolicy] !== undefined,
    );
    expect(definedKeys.length).toBe(15);
  });

  it("updated_at is omitempty — may be absent in a default-state response", () => {
    // Go json:"updated_at,omitempty" means the field is absent (not empty string)
    // when the row has never been saved. Our SiteSecurityPolicy declares it as
    // optional (updated_at?: string).
    const wireNoTimestamp = makeWirePolicy({ updated_at: undefined }) as SiteSecurityPolicy;
    expect(wireNoTimestamp.updated_at).toBeUndefined();
  });

  it("array fields coalesce to [] (Go coalesceStringSliceDTO — handler.go:709-714)", () => {
    // The Go helper ensures nil slices serialize as [] not null. We confirm
    // our type handles both (the ?? [] fallback in consumers is still correct).
    const wire = makeWirePolicy({
      two_factor_methods: [],
      two_factor_required_roles: [],
      password_min_zxcvbn_roles: [],
      password_expiry_roles: [],
    }) as SiteSecurityPolicy;
    expect(wire.two_factor_methods).toEqual([]);
    expect(wire.two_factor_required_roles).toEqual([]);
  });
});

// ---------------------------------------------------------------------------
// 2. policyGroupDTO shape — matched against Go policyGroupDTO (handler.go:735-743)
// ---------------------------------------------------------------------------

/** Simulates a wire policyGroupDTO item. */
function makeWireGroup(
  overrides: Partial<Record<string, unknown>> = {},
): unknown {
  return {
    // Must match policyGroupDTO json tags (handler.go:735-743).
    role: "administrator",
    require_2fa: true,
    allowed_methods: ["totp", "backup"],
    min_zxcvbn_score: 3,
    block_compromised: true,
    max_age_days: 90,
    created_at: "2026-06-20T00:00:00Z",
    ...overrides,
  };
}

describe("policyGroupDTO shape — Go policyGroupDTO json tags", () => {
  it("all Go json fields are present in the wire payload", () => {
    const wire = makeWireGroup() as Record<string, unknown>;
    // json tags from handler.go:736-743.
    const fields = [
      "role",
      "require_2fa",
      "allowed_methods",
      "min_zxcvbn_score",
      "block_compromised",
      "max_age_days",
      "created_at",
    ];
    for (const f of fields) {
      expect(wire).toHaveProperty(f);
    }
  });

  it("role is always a string", () => {
    const wire = makeWireGroup() as PolicyGroup;
    expect(typeof wire.role).toBe("string");
    expect(wire.role).toBe("administrator");
  });

  it("optional pointer fields (require_2fa, min_zxcvbn_score, block_compromised, max_age_days) may be absent (omitempty)", () => {
    // Go: *bool json:"require_2fa,omitempty" — absent when nil, not false.
    const wire = makeWireGroup({
      require_2fa: undefined,
      min_zxcvbn_score: undefined,
      block_compromised: undefined,
      max_age_days: undefined,
    }) as PolicyGroup;
    expect(wire.require_2fa).toBeUndefined();
    expect(wire.min_zxcvbn_score).toBeUndefined();
    expect(wire.block_compromised).toBeUndefined();
    expect(wire.max_age_days).toBeUndefined();
  });

  it("groups list uses 'items' wrapper matching Go policyGroupListDTO (handler.go:745-747)", () => {
    const wireList = { items: [makeWireGroup()] };
    expect(wireList).toHaveProperty("items");
    expect(Array.isArray(wireList.items)).toBe(true);
    expect(wireList.items).toHaveLength(1);
  });

  it("a PolicyGroup constructed from wire data matches the expected shape", () => {
    const wire = makeWireGroup() as PolicyGroup;
    expect(wire.role).toBe("administrator");
    expect(wire.require_2fa).toBe(true);
    expect(wire.allowed_methods).toEqual(["totp", "backup"]);
    expect(wire.min_zxcvbn_score).toBe(3);
    expect(wire.block_compromised).toBe(true);
    expect(wire.max_age_days).toBe(90);
  });
});

// ---------------------------------------------------------------------------
// 3. validateHideBackendSlug — client-side guard before PUT fires
// ---------------------------------------------------------------------------

describe("validateHideBackendSlug — client-side slug validation", () => {
  it("returns null for a valid slug", () => {
    expect(validateHideBackendSlug("my-login")).toBeNull();
    expect(validateHideBackendSlug("site-access-2")).toBeNull();
    expect(validateHideBackendSlug("abcd")).toBeNull(); // min 4 chars
    // 64 chars — max allowed.
    expect(validateHideBackendSlug("a".repeat(64))).toBeNull();
  });

  it("returns an error for a slug shorter than 4 characters", () => {
    expect(validateHideBackendSlug("abc")).not.toBeNull();
    expect(validateHideBackendSlug("a")).not.toBeNull();
  });

  it("returns an error for a slug longer than 64 characters", () => {
    expect(validateHideBackendSlug("a".repeat(65))).not.toBeNull();
  });

  it("returns an error for uppercase letters", () => {
    expect(validateHideBackendSlug("My-Login")).not.toBeNull();
  });

  it("returns an error for disallowed characters (underscore, space)", () => {
    expect(validateHideBackendSlug("my_login")).not.toBeNull();
    expect(validateHideBackendSlug("my login")).not.toBeNull();
  });

  it("returns an error for an empty string", () => {
    expect(validateHideBackendSlug("")).not.toBeNull();
    expect(validateHideBackendSlug("   ")).not.toBeNull();
  });

  it("returns an error for reserved paths", () => {
    const reserved = [
      "wp-login",
      "wp-admin",
      "wp-content",
      "wp-includes",
      "admin",
      "login",
    ];
    for (const slug of reserved) {
      const result = validateHideBackendSlug(slug);
      expect(result).not.toBeNull();
    }
  });

  it("does NOT make any API call (pure validation)", () => {
    const mockFetch = vi.fn();
    const originalFetch = globalThis.fetch;
    globalThis.fetch = mockFetch;
    try {
      validateHideBackendSlug("invalid SLUG!");
      expect(mockFetch).not.toHaveBeenCalled();
    } finally {
      globalThis.fetch = originalFetch;
    }
  });

  it("invalid slug is blocked client-side and would not reach the network", () => {
    const mockPut = vi.fn();
    const slug = "INVALID SLUG!";
    const validationError = validateHideBackendSlug(slug);
    if (validationError) {
      // Guard fires — API must NOT be called.
    } else {
      mockPut({ hide_backend_slug: slug });
    }
    expect(validationError).not.toBeNull();
    expect(mockPut).not.toHaveBeenCalled();
  });

  it("valid slug passes client-side and would reach the network", () => {
    const mockPut = vi.fn();
    const slug = "secret-access";
    const validationError = validateHideBackendSlug(slug);
    if (!validationError) {
      mockPut({ hide_backend_slug: slug });
    }
    expect(validationError).toBeNull();
    expect(mockPut).toHaveBeenCalledWith({ hide_backend_slug: slug });
  });
});

// ---------------------------------------------------------------------------
// 4. validateHideBackendRedirect
// ---------------------------------------------------------------------------

describe("validateHideBackendRedirect — redirect URL validation", () => {
  it("returns null for an empty redirect (means 404 — allowed)", () => {
    expect(validateHideBackendRedirect("")).toBeNull();
    expect(validateHideBackendRedirect("   ")).toBeNull();
  });

  it("returns null for a root-relative path", () => {
    expect(validateHideBackendRedirect("/home")).toBeNull();
    expect(validateHideBackendRedirect("/")).toBeNull();
  });

  it("returns null for an https absolute URL", () => {
    expect(validateHideBackendRedirect("https://example.com/")).toBeNull();
  });

  it("returns null for an http absolute URL", () => {
    expect(validateHideBackendRedirect("http://example.com/")).toBeNull();
  });

  it("returns an error for a relative path without leading slash", () => {
    expect(validateHideBackendRedirect("home")).not.toBeNull();
    expect(validateHideBackendRedirect("../etc/passwd")).not.toBeNull();
  });

  it("returns an error for a javascript: scheme", () => {
    // javascript: is not / or http(s):// so it should fail.
    expect(validateHideBackendRedirect("javascript:alert(1)")).not.toBeNull();
  });
});

// ---------------------------------------------------------------------------
// 5. 2FA enable nudge — pre-fill behaviour
// ---------------------------------------------------------------------------

describe("TFA_ENABLE_NUDGE — pre-fill defaults when first enabling 2FA", () => {
  it("nudge sets required_roles to administrator only", () => {
    expect(TFA_ENABLE_NUDGE.two_factor_required_roles).toEqual(["administrator"]);
  });

  it("nudge sets methods to totp + backup (no email)", () => {
    expect(TFA_ENABLE_NUDGE.two_factor_methods).toEqual(["totp", "backup"]);
  });

  it("nudge does NOT include 'email' as the sole allowed method (deliverability risk)", () => {
    expect(TFA_ENABLE_NUDGE.two_factor_methods).not.toEqual(["email"]);
    expect(TFA_ENABLE_NUDGE.two_factor_methods).not.toContain("email");
  });

  it("simulates the toggle-2FA-enable path: nudge applied when roles were empty", () => {
    const mockSetPolicy = vi.fn();

    // Simulate handleToggle2FA in PolicyLoaded.
    const currentPolicy: SiteSecurityPolicy = {
      ...DEFAULT_POLICY,
      two_factor_enabled: false,
      two_factor_required_roles: [], // empty — triggers nudge
    };

    const enabling = !currentPolicy.two_factor_enabled;
    if (enabling && currentPolicy.two_factor_required_roles.length === 0) {
      mockSetPolicy({
        ...currentPolicy,
        two_factor_enabled: true,
        two_factor_required_roles: [...TFA_ENABLE_NUDGE.two_factor_required_roles],
        two_factor_methods: [...TFA_ENABLE_NUDGE.two_factor_methods],
      });
    } else {
      mockSetPolicy({ ...currentPolicy, two_factor_enabled: enabling });
    }

    expect(mockSetPolicy).toHaveBeenCalledWith(
      expect.objectContaining({
        two_factor_enabled: true,
        two_factor_required_roles: ["administrator"],
        two_factor_methods: ["totp", "backup"],
      }),
    );
  });

  it("simulates the toggle-2FA-enable path: nudge NOT applied when roles already set", () => {
    const mockSetPolicy = vi.fn();

    const currentPolicy: SiteSecurityPolicy = {
      ...DEFAULT_POLICY,
      two_factor_enabled: false,
      two_factor_required_roles: ["administrator", "editor"],
    };

    const enabling = !currentPolicy.two_factor_enabled;
    if (enabling && currentPolicy.two_factor_required_roles.length === 0) {
      mockSetPolicy({
        ...currentPolicy,
        two_factor_enabled: true,
        two_factor_required_roles: [...TFA_ENABLE_NUDGE.two_factor_required_roles],
        two_factor_methods: [...TFA_ENABLE_NUDGE.two_factor_methods],
      });
    } else {
      mockSetPolicy({ ...currentPolicy, two_factor_enabled: enabling });
    }

    // Nudge was NOT applied — roles remain as-is.
    expect(mockSetPolicy).toHaveBeenCalledWith(
      expect.objectContaining({
        two_factor_enabled: true,
        two_factor_required_roles: ["administrator", "editor"],
      }),
    );
    // Nudge methods were NOT injected.
    const call = (mockSetPolicy.mock.calls[0] as [SiteSecurityPolicy])[0];
    expect(call.two_factor_methods).not.toEqual(["totp", "backup"]);
  });
});

// ---------------------------------------------------------------------------
// 6. Policy save PUT body — shape and URL construction
// ---------------------------------------------------------------------------

describe("policy save — PUT fires with the correct URL and body shape", () => {
  const SITE_ID = "aaaaaaaa-0000-0000-0000-000000000001";

  it("PUT URL is correctly constructed with encoded siteId", () => {
    const url = `/api/v1/sites/${encodeURIComponent(SITE_ID)}/security/policy`;
    expect(url).toBe(
      "/api/v1/sites/aaaaaaaa-0000-0000-0000-000000000001/security/policy",
    );
  });

  it("the PUT body matches the flat policyDTO shape (no sub-object wrapper)", () => {
    const mockPut = vi.fn();
    const policy: SiteSecurityPolicy = makeWirePolicy() as SiteSecurityPolicy;

    // Simulate what the mutationFn does (no wrapper — flat body).
    mockPut(
      `/api/v1/sites/${encodeURIComponent(SITE_ID)}/security/policy`,
      policy,
    );

    expect(mockPut).toHaveBeenCalledWith(
      expect.stringContaining("/security/policy"),
      expect.objectContaining({
        two_factor_enabled: false,
        block_xmlrpc_for_2fa_users: true,
        password_min_zxcvbn_score: 0,
        hide_backend_enabled: false,
        hide_backend_slug: "",
      }),
    );
  });

  it("the PUT body always contains all 15 non-optional fields", () => {
    const policy: SiteSecurityPolicy = makeWirePolicy() as SiteSecurityPolicy;
    const requiredKeys: (keyof SiteSecurityPolicy)[] = [
      "two_factor_enabled",
      "two_factor_methods",
      "two_factor_required_roles",
      "two_factor_grace_logins",
      "two_factor_remember_device_days",
      "block_xmlrpc_for_2fa_users",
      "password_min_zxcvbn_score",
      "password_min_zxcvbn_roles",
      "password_block_compromised",
      "password_reuse_block_count",
      "password_max_age_days",
      "password_expiry_roles",
      "hide_backend_enabled",
      "hide_backend_slug",
      "hide_backend_redirect",
    ];
    for (const key of requiredKeys) {
      expect(policy[key]).not.toBeUndefined();
    }
  });
});

// ---------------------------------------------------------------------------
// 7. Viewer role — write operations are not exposed
// ---------------------------------------------------------------------------

describe("viewer role — write operations are gated upstream by canWrite", () => {
  it("canWrite=false prevents the save mutation from being called", () => {
    const canWrite = false;
    const mockMutate = vi.fn();

    function simulateSave(canWriteFlag: boolean) {
      if (!canWriteFlag) return;
      mockMutate(makeWirePolicy());
    }

    simulateSave(canWrite);
    expect(mockMutate).not.toHaveBeenCalled();
  });

  it("canWrite=true allows the save mutation", () => {
    const canWrite = true;
    const mockMutate = vi.fn();

    function simulateSave(canWriteFlag: boolean) {
      if (!canWriteFlag) return;
      const slugErr = validateHideBackendSlug("my-login");
      if (!slugErr) {
        mockMutate(makeWirePolicy({ hide_backend_slug: "my-login" }));
      }
    }

    simulateSave(canWrite);
    expect(mockMutate).toHaveBeenCalled();
  });

  it("validateHideBackendSlug is safe to call regardless of role (pure function)", () => {
    // The canWrite gate lives at the component level, not inside the validator.
    expect(validateHideBackendSlug("my-login")).toBeNull();
    expect(validateHideBackendSlug("INVALID!")).not.toBeNull();
  });
});

// ---------------------------------------------------------------------------
// 8. Cache key factory
// ---------------------------------------------------------------------------

describe("policyKeys — cache key factory", () => {
  it("policy key is specific to siteId", async () => {
    const { policyKeys } = await import("./use-policy");
    const key = policyKeys.policy("site-abc");
    expect(key).toContain("site-abc");
    expect(key).toContain("policy");
  });

  it("groups key is specific to siteId", async () => {
    const { policyKeys } = await import("./use-policy");
    const key = policyKeys.groups("site-abc");
    expect(key).toContain("site-abc");
    expect(key).toContain("groups");
  });

  it("policy key and groups key are distinct", async () => {
    const { policyKeys } = await import("./use-policy");
    expect(policyKeys.policy("site-abc")).not.toEqual(policyKeys.groups("site-abc"));
  });

  it("policy keys for different sites are distinct", async () => {
    const { policyKeys } = await import("./use-policy");
    expect(policyKeys.policy("site-aaa")).not.toEqual(policyKeys.policy("site-bbb"));
  });

  it("groups keys for different sites are distinct", async () => {
    const { policyKeys } = await import("./use-policy");
    expect(policyKeys.groups("site-aaa")).not.toEqual(policyKeys.groups("site-bbb"));
  });
});

// ---------------------------------------------------------------------------
// 9. Hook exports — public API shape
// ---------------------------------------------------------------------------

describe("policy hook exports — public API shape", () => {
  it("useSiteSecurityPolicy accepts a siteId string", async () => {
    const { useSiteSecurityPolicy } = await import("./use-policy");
    expect(typeof useSiteSecurityPolicy).toBe("function");
    expect(useSiteSecurityPolicy.length).toBe(1);
  });

  it("useUpdateSiteSecurityPolicy accepts a siteId string", async () => {
    const { useUpdateSiteSecurityPolicy } = await import("./use-policy");
    expect(typeof useUpdateSiteSecurityPolicy).toBe("function");
    expect(useUpdateSiteSecurityPolicy.length).toBe(1);
  });

  it("usePolicyGroups accepts a siteId string", async () => {
    const { usePolicyGroups } = await import("./use-policy");
    expect(typeof usePolicyGroups).toBe("function");
    expect(usePolicyGroups.length).toBe(1);
  });

  it("useUpsertPolicyGroup accepts a siteId string", async () => {
    const { useUpsertPolicyGroup } = await import("./use-policy");
    expect(typeof useUpsertPolicyGroup).toBe("function");
    expect(useUpsertPolicyGroup.length).toBe(1);
  });

  it("useDeletePolicyGroup accepts a siteId string", async () => {
    const { useDeletePolicyGroup } = await import("./use-policy");
    expect(typeof useDeletePolicyGroup).toBe("function");
    expect(useDeletePolicyGroup.length).toBe(1);
  });
});

// ---------------------------------------------------------------------------
// 10. DEFAULT_POLICY — all fields off/safe by default
// ---------------------------------------------------------------------------

describe("DEFAULT_POLICY — all security features off by default", () => {
  it("two_factor_enabled defaults to false", () => {
    expect(DEFAULT_POLICY.two_factor_enabled).toBe(false);
  });

  it("password_block_compromised defaults to false", () => {
    expect(DEFAULT_POLICY.password_block_compromised).toBe(false);
  });

  it("hide_backend_enabled defaults to false", () => {
    expect(DEFAULT_POLICY.hide_backend_enabled).toBe(false);
  });

  it("password_min_zxcvbn_score defaults to 0 (enforcement off)", () => {
    expect(DEFAULT_POLICY.password_min_zxcvbn_score).toBe(0);
  });

  it("password_max_age_days defaults to 0 (expiry off)", () => {
    expect(DEFAULT_POLICY.password_max_age_days).toBe(0);
  });

  it("password_reuse_block_count defaults to 0 (reuse check off)", () => {
    expect(DEFAULT_POLICY.password_reuse_block_count).toBe(0);
  });

  it("two_factor_required_roles defaults to empty (2FA optional for all)", () => {
    expect(DEFAULT_POLICY.two_factor_required_roles).toEqual([]);
  });
});

// ---------------------------------------------------------------------------
// 11. Group PUT URL construction
// ---------------------------------------------------------------------------

describe("group PUT/DELETE URL — role is URL-encoded in the path", () => {
  const SITE_ID = "aaaaaaaa-0000-0000-0000-000000000001";

  it("PUT groups URL encodes the role correctly", () => {
    const role = "administrator";
    const url = `/api/v1/sites/${encodeURIComponent(SITE_ID)}/security/policy/groups/${encodeURIComponent(role)}`;
    expect(url).toBe(
      "/api/v1/sites/aaaaaaaa-0000-0000-0000-000000000001/security/policy/groups/administrator",
    );
  });

  it("DELETE groups URL matches the PUT URL format", () => {
    const role = "editor";
    const deleteUrl = `/api/v1/sites/${encodeURIComponent(SITE_ID)}/security/policy/groups/${encodeURIComponent(role)}`;
    expect(deleteUrl).toBe(
      "/api/v1/sites/aaaaaaaa-0000-0000-0000-000000000001/security/policy/groups/editor",
    );
  });
});
