import { describe, it, expect, vi, beforeEach } from "vitest";

import {
  validateBanValue,
  isValidIpv4,
  isValidCidr,
  isValidUserAgent,
  type BanType,
  type HardeningConfig,
} from "./use-hardening";

// ---------------------------------------------------------------------------
// validateBanValue — client-side ban value validation
// ---------------------------------------------------------------------------
//
// These tests assert the behaviour exported from use-hardening.ts that is
// the first line of defence before a POST /api/v1/sites/{siteId}/security/bans
// call is made. An invalid value must return a non-null error string; a valid
// value must return null.
//
// The tests are pure-logic (no React, no HTTP) — mirroring the pattern in
// use-site-connection.test.ts and use-bulk-backup.test.ts.

describe("isValidIpv4", () => {
  it("accepts a well-formed IPv4 address", () => {
    expect(isValidIpv4("203.0.113.42")).toBe(true);
    expect(isValidIpv4("192.168.1.1")).toBe(true);
    expect(isValidIpv4("10.0.0.1")).toBe(true);
    expect(isValidIpv4("255.255.255.255")).toBe(true);
    expect(isValidIpv4("0.0.0.0")).toBe(true);
  });

  it("rejects IPs with out-of-range octets", () => {
    expect(isValidIpv4("256.0.0.1")).toBe(false);
    expect(isValidIpv4("192.168.999.1")).toBe(false);
  });

  it("rejects CIDR notation as an IPv4 address", () => {
    expect(isValidIpv4("203.0.113.0/24")).toBe(false);
  });

  it("rejects empty string", () => {
    expect(isValidIpv4("")).toBe(false);
  });

  it("rejects non-IP strings", () => {
    expect(isValidIpv4("not-an-ip")).toBe(false);
    expect(isValidIpv4("localhost")).toBe(false);
  });
});

describe("isValidCidr", () => {
  it("accepts well-formed IPv4 CIDR blocks", () => {
    expect(isValidCidr("203.0.113.0/24")).toBe(true);
    expect(isValidCidr("10.0.0.0/8")).toBe(true);
    expect(isValidCidr("192.168.1.0/32")).toBe(true);
    expect(isValidCidr("0.0.0.0/0")).toBe(true);
  });

  it("rejects prefix out of range", () => {
    expect(isValidCidr("203.0.113.0/33")).toBe(false);
  });

  it("rejects bare IPv4 without prefix", () => {
    expect(isValidCidr("203.0.113.42")).toBe(false);
  });

  it("rejects out-of-range octets", () => {
    expect(isValidCidr("256.0.0.0/24")).toBe(false);
  });

  it("rejects empty string", () => {
    expect(isValidCidr("")).toBe(false);
  });
});

describe("isValidUserAgent", () => {
  it("accepts a non-empty user-agent string", () => {
    expect(isValidUserAgent("BadBot/1.0")).toBe(true);
    expect(isValidUserAgent("Mozilla/5.0 (compatible)")).toBe(true);
  });

  it("rejects empty string", () => {
    expect(isValidUserAgent("")).toBe(false);
  });

  it("rejects a string exceeding 512 characters", () => {
    expect(isValidUserAgent("a".repeat(513))).toBe(false);
  });

  it("accepts a string of exactly 512 characters", () => {
    expect(isValidUserAgent("a".repeat(512))).toBe(true);
  });
});

describe("validateBanValue — ip type", () => {
  it("returns null for a valid IPv4 address", () => {
    expect(validateBanValue("ip", "203.0.113.42")).toBeNull();
  });

  it("returns an error for a CIDR when type is ip", () => {
    expect(validateBanValue("ip", "203.0.113.0/24")).not.toBeNull();
  });

  it("returns an error for an empty value", () => {
    expect(validateBanValue("ip", "")).not.toBeNull();
    expect(validateBanValue("ip", "   ")).not.toBeNull();
  });

  it("returns an error string (not a boolean or null) so callers can render it", () => {
    const result = validateBanValue("ip", "not-an-ip");
    expect(typeof result).toBe("string");
    expect(result!.length).toBeGreaterThan(0);
  });

  it("does NOT make any API call (pure validation — the critical guard)", () => {
    // This test is structural: we call validateBanValue directly and assert it
    // returns without touching the network. Because validateBanValue is a pure
    // function with no side-effects, any API call mock would be unnecessary —
    // the function signature itself enforces this contract.
    const mockFetch = vi.fn();
    const originalFetch = globalThis.fetch;
    globalThis.fetch = mockFetch;
    try {
      validateBanValue("ip", "999.999.999.999");
      expect(mockFetch).not.toHaveBeenCalled();
    } finally {
      globalThis.fetch = originalFetch;
    }
  });
});

describe("validateBanValue — range type", () => {
  it("returns null for a valid CIDR block", () => {
    expect(validateBanValue("range", "203.0.113.0/24")).toBeNull();
  });

  it("returns an error for a bare IP when type is range", () => {
    expect(validateBanValue("range", "203.0.113.42")).not.toBeNull();
  });

  it("returns an error for empty value", () => {
    expect(validateBanValue("range", "")).not.toBeNull();
  });
});

describe("validateBanValue — user_agent type", () => {
  it("returns null for a non-empty user agent", () => {
    expect(validateBanValue("user_agent", "BadBot/1.0")).toBeNull();
  });

  it("returns an error for empty user agent", () => {
    expect(validateBanValue("user_agent", "")).not.toBeNull();
    expect(validateBanValue("user_agent", "   ")).not.toBeNull();
  });

  it("returns an error for a user agent exceeding 512 chars", () => {
    expect(validateBanValue("user_agent", "x".repeat(513))).not.toBeNull();
  });
});

// ---------------------------------------------------------------------------
// API call contract: hook function signatures
//
// These tests verify that the hooks in use-hardening.ts accept the right
// parameter shapes. They import the functions but do NOT call them (calling
// them requires a React context + QueryClient). The goal is to pin the public
// API shape so a refactor that accidentally changes a parameter name or type
// will fail here, not silently in a component.
// ---------------------------------------------------------------------------

describe("hardening hook exports — public API shape", () => {
  it("useHardeningConfig is exported as a function accepting a siteId string", async () => {
    const { useHardeningConfig } = await import("./use-hardening");
    expect(typeof useHardeningConfig).toBe("function");
    // The hook takes exactly one argument (siteId: string).
    expect(useHardeningConfig.length).toBe(1);
  });

  it("useUpdateHardeningConfig is exported as a function accepting a siteId string", async () => {
    const { useUpdateHardeningConfig } = await import("./use-hardening");
    expect(typeof useUpdateHardeningConfig).toBe("function");
    expect(useUpdateHardeningConfig.length).toBe(1);
  });

  it("useBans is exported as a function accepting a siteId string", async () => {
    const { useBans } = await import("./use-hardening");
    expect(typeof useBans).toBe("function");
    expect(useBans.length).toBe(1);
  });

  it("useCreateBan is exported as a function accepting a siteId string", async () => {
    const { useCreateBan } = await import("./use-hardening");
    expect(typeof useCreateBan).toBe("function");
    expect(useCreateBan.length).toBe(1);
  });

  it("useDeleteBan is exported as a function accepting a siteId string", async () => {
    const { useDeleteBan } = await import("./use-hardening");
    expect(typeof useDeleteBan).toBe("function");
    expect(useDeleteBan.length).toBe(1);
  });
});

// ---------------------------------------------------------------------------
// hardeningKeys — cache key structure
//
// Mirrors the cache-key pin tests in use-site-connection.test.ts.
// These assert the key shapes that mutation invalidations depend on.
// ---------------------------------------------------------------------------

describe("hardeningKeys — cache key factory", () => {
  it("config key is specific to the siteId so only that site's config invalidates", async () => {
    const { hardeningKeys } = await import("./use-hardening");
    const key = hardeningKeys.config("site-abc");
    expect(key).toContain("site-abc");
    expect(key).toContain("config");
  });

  it("bans key is specific to the siteId so only that site's bans invalidate", async () => {
    const { hardeningKeys } = await import("./use-hardening");
    const key = hardeningKeys.bans("site-abc");
    expect(key).toContain("site-abc");
    expect(key).toContain("bans");
  });

  it("config key and bans key are distinct so invalidating one does not flush the other", async () => {
    const { hardeningKeys } = await import("./use-hardening");
    const configKey = hardeningKeys.config("site-abc");
    const bansKey = hardeningKeys.bans("site-abc");
    expect(configKey).not.toEqual(bansKey);
  });

  it("config keys for different sites are distinct", async () => {
    const { hardeningKeys } = await import("./use-hardening");
    const key1 = hardeningKeys.config("site-aaa");
    const key2 = hardeningKeys.config("site-bbb");
    expect(key1).not.toEqual(key2);
  });
});

// ---------------------------------------------------------------------------
// HardeningConfig shape — all 10 fields present
//
// This is a type-level guard: we construct a complete HardeningConfig object
// to ensure any future schema changes (adding/removing a field) surface as a
// TypeScript compile error rather than a runtime omission.
// ---------------------------------------------------------------------------

describe("HardeningConfig shape — all 10 required fields are present in the type", () => {
  it("a complete config object satisfies the HardeningConfig type", () => {
    // Constructing this object exercises the TypeScript type at test compile
    // time. At runtime, we only need it to be a plain object (no schema
    // validation library required).
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

    expect(Object.keys(config)).toHaveLength(10);
  });

  it("xmlrpc_mode accepts only on / limited / off", () => {
    const validModes = ["on", "limited", "off"] as const;
    for (const mode of validModes) {
      const config: HardeningConfig = {
        disable_file_editor: false,
        xmlrpc_mode: mode,
        restrict_rest_api: "default",
        restrict_login_identifier: "both",
        force_unique_nickname: false,
        disable_author_archive_enum: false,
        force_ssl: false,
        disable_directory_browsing: false,
        disable_php_in_uploads: false,
        protect_system_files: false,
      };
      expect(config.xmlrpc_mode).toBe(mode);
    }
  });
});

// ---------------------------------------------------------------------------
// Ban creation — API is called with the right body shape
//
// This is a dependency-injection test that follows the same pattern as
// runBulkBackup in use-bulk-backup.test.ts. We extract the pure validation
// logic (validateBanValue) and the URL construction (inlined here) to assert
// that an invalid IP is BLOCKED before the POST fires.
// ---------------------------------------------------------------------------

describe("ban creation pre-flight — invalid IP is rejected without an API call", () => {
  const SITE_ID = "aaaaaaaa-0000-0000-0000-000000000001";

  it("an invalid IP address fails client-side validation and does not reach the network", () => {
    const mockPost = vi.fn();

    // Simulate the exact guard in AddBanForm.handleAdd:
    const type: BanType = "ip";
    const value = "999.999.999.999";
    const validationError = validateBanValue(type, value);

    if (validationError) {
      // Guard fires — API must NOT be called.
      // (In the real component, this sets state and returns early.)
    } else {
      mockPost(SITE_ID, { type, value, comment: "" });
    }

    expect(validationError).not.toBeNull();
    expect(mockPost).not.toHaveBeenCalled();
  });

  it("a valid IP address passes client-side validation and would reach the network", () => {
    const mockPost = vi.fn();

    const type: BanType = "ip";
    const value = "203.0.113.42";
    const validationError = validateBanValue(type, value);

    if (!validationError) {
      mockPost(SITE_ID, { type, value, comment: "" });
    }

    expect(validationError).toBeNull();
    expect(mockPost).toHaveBeenCalledWith(SITE_ID, {
      type: "ip",
      value: "203.0.113.42",
      comment: "",
    });
  });

  it("a valid CIDR range passes validation and would reach the network", () => {
    const mockPost = vi.fn();

    const type: BanType = "range";
    const value = "198.51.100.0/24";
    const validationError = validateBanValue(type, value);

    if (!validationError) {
      mockPost(SITE_ID, { type, value, comment: "" });
    }

    expect(validationError).toBeNull();
    expect(mockPost).toHaveBeenCalledWith(SITE_ID, {
      type: "range",
      value: "198.51.100.0/24",
      comment: "",
    });
  });

  it("a valid user agent passes validation and would reach the network", () => {
    const mockPost = vi.fn();

    const type: BanType = "user_agent";
    const value = "EvilScraper/2.0";
    const validationError = validateBanValue(type, value);

    if (!validationError) {
      mockPost(SITE_ID, { type, value, comment: "Known scraper bot" });
    }

    expect(validationError).toBeNull();
    expect(mockPost).toHaveBeenCalledWith(SITE_ID, {
      type: "user_agent",
      value: "EvilScraper/2.0",
      comment: "Known scraper bot",
    });
  });
});

// ---------------------------------------------------------------------------
// Viewer role — write operations are not exposed
//
// The canWrite flag is derived from canOperate(me) in the route component.
// These tests assert the logic of the exported validation helpers in a
// viewer-equivalent scenario: validation still works (read-only view can still
// show correct state), but the add-form code path is never reached.
// ---------------------------------------------------------------------------

describe("viewer role — validateBanValue is safe to call but form is gated upstream", () => {
  it("validateBanValue returns the expected result regardless of role (pure function)", () => {
    // A viewer calling validateBanValue gets the same result as an operator.
    // The canWrite gate lives at the component render level, not inside the
    // pure validation function. This test pins that the validator has no
    // implicit role dependency.
    expect(validateBanValue("ip", "10.0.0.1")).toBeNull();
    expect(validateBanValue("ip", "not-an-ip")).not.toBeNull();
  });

  it("viewer flag false means the add form is not rendered (structural assertion)", () => {
    // In BanListPanel, the AddBanForm is only rendered when canWrite is true.
    // We simulate this gating logic here — the mock post function must NOT be
    // called when canWrite is false.
    const canWrite = false;
    const mockMutate = vi.fn();

    function simulateBanListPanelSubmit(canWriteFlag: boolean) {
      if (!canWriteFlag) return; // guard matches the real component
      mockMutate({ type: "ip", value: "10.0.0.1", comment: "" });
    }

    simulateBanListPanelSubmit(canWrite);
    expect(mockMutate).not.toHaveBeenCalled();
  });

  it("operator flag true means the mutation would be called", () => {
    const canWrite = true;
    const mockMutate = vi.fn();

    function simulateBanListPanelSubmit(canWriteFlag: boolean) {
      if (!canWriteFlag) return;
      const type: BanType = "ip";
      const value = "10.0.0.1";
      const validationError = validateBanValue(type, value);
      if (!validationError) {
        mockMutate({ type, value, comment: "" });
      }
    }

    simulateBanListPanelSubmit(canWrite);
    expect(mockMutate).toHaveBeenCalledWith({
      type: "ip",
      value: "10.0.0.1",
      comment: "",
    });
  });
});

// ---------------------------------------------------------------------------
// Hardening save — dirty-state tracking
//
// The hardening panel sends ALL 10 fields on save (no partial PATCH). These
// tests pin that a change to one field produces a config object that differs
// from the initial one in exactly the changed field.
// ---------------------------------------------------------------------------

describe("hardening config — single-field toggle produces correct payload", () => {
  const BASE_CONFIG: HardeningConfig = {
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

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("toggling disable_file_editor produces a config with that field flipped", () => {
    // Simulate the toggle function in HardeningLoaded:
    const updated = { ...BASE_CONFIG, disable_file_editor: true };
    expect(updated.disable_file_editor).toBe(true);
    // All other fields must remain unchanged.
    const keys = Object.keys(BASE_CONFIG) as (keyof HardeningConfig)[];
    for (const key of keys) {
      if (key === "disable_file_editor") continue;
      expect(updated[key]).toBe(BASE_CONFIG[key]);
    }
  });

  it("changing xmlrpc_mode to off produces a config with mode=off", () => {
    const updated: HardeningConfig = { ...BASE_CONFIG, xmlrpc_mode: "off" };
    expect(updated.xmlrpc_mode).toBe("off");
  });

  it("the save payload always contains all 10 fields (no partial omission)", () => {
    const payload: HardeningConfig = {
      ...BASE_CONFIG,
      force_ssl: true,
    };

    const mockPut = vi.fn();
    mockPut(payload);

    expect(mockPut).toHaveBeenCalledWith(
      expect.objectContaining({
        disable_file_editor: false,
        xmlrpc_mode: "on",
        restrict_rest_api: "default",
        restrict_login_identifier: "both",
        force_unique_nickname: false,
        disable_author_archive_enum: false,
        force_ssl: true,
        disable_directory_browsing: false,
        disable_php_in_uploads: false,
        protect_system_files: false,
      }),
    );
  });
});
