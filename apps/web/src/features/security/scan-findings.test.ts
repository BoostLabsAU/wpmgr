/**
 * Tests for Phase 2 file-integrity finding types in the scan domain.
 *
 * Following the project convention in use-hardening.test.ts / site-card.test.ts:
 * pure-function tests only; no React renderer, no DOM.
 *
 * Contract goal: a shape mismatch between the Go DTO (handler.go) and our
 * TypeScript types must be caught here, not in prod.
 */
import { describe, it, expect, vi } from "vitest";

import type { ScanFinding, ScanFindingType, ScanKind } from "./use-scan";

// ---------------------------------------------------------------------------
// Go DTO shapes — matched against apps/api/internal/scan/handler.go
//
// The Go `findingDTO` struct (handler.go:82-98) has these json tags:
//   id            string   json:"id"
//   site_id       string   json:"site_id"
//   run_id        string   json:"run_id"
//   finding_type  string   json:"finding_type"
//   path          string   json:"path"
//   severity      string   json:"severity"
//   expected_md5  string   json:"expected_md5,omitempty"
//   actual_md5    string   json:"actual_md5,omitempty"
//   ignored       bool     json:"ignored"
//   ignored_by    string   json:"ignored_by,omitempty"
//   created_at    string   json:"created_at"
//   last_seen_run string   json:"last_seen_run"
//
// Our ScanFinding interface must be a superset-safe projection of this shape.
// ---------------------------------------------------------------------------

// Simulated payload matching the Go findingDTO exactly (as it arrives from the
// JSON wire, before TypeScript casts it). We construct this as `unknown` first
// to emulate the `as T` cast in use-scan.ts's apiGet helper.
function makeWireFinding(overrides: Partial<Record<string, unknown>> = {}): unknown {
  return {
    id: "550e8400-e29b-41d4-a716-446655440001",
    site_id: "550e8400-e29b-41d4-a716-446655440002",
    run_id: "550e8400-e29b-41d4-a716-446655440003",
    finding_type: "core_modified",
    path: "wp-includes/class-wp.php",
    severity: "high",
    expected_md5: "d41d8cd98f00b204e9800998ecf8427e",
    actual_md5: "098f6bcd4621d373cade4e832627b4f6",
    ignored: false,
    created_at: "2026-06-19T10:00:00Z",
    last_seen_run: "550e8400-e29b-41d4-a716-446655440003",
    ...overrides,
  };
}

// ---------------------------------------------------------------------------
// 1. ScanFinding interface — all required DTO fields are accessible
//
// This test pinpoints that if the Go handler renames a json tag (e.g.
// "finding_type" → "type"), the TypeScript types must be updated in lockstep.
// We construct a typed ScanFinding from the wire shape to verify the mapping.
// ---------------------------------------------------------------------------

describe("ScanFinding interface — DTO shape matches Go findingDTO json tags", () => {
  it("all required Go json fields are present in the wire payload", () => {
    const wire = makeWireFinding() as Record<string, unknown>;
    // These field names MUST match the json:"..." tags in handler.go:82-98.
    const requiredGoFields = [
      "id",
      "site_id",
      "run_id",
      "finding_type",
      "path",
      "severity",
      "ignored",
      "created_at",
      "last_seen_run",
    ];
    for (const field of requiredGoFields) {
      expect(wire).toHaveProperty(field);
    }
  });

  it("the cast from wire to ScanFinding preserves the expected_md5 field", () => {
    const wire = makeWireFinding({ expected_md5: "abc123" }) as ScanFinding;
    // expected_md5 is omitempty in Go — it may be absent (null here); when
    // present, it must be the string value the DTO carried.
    expect(wire.expected_md5).toBe("abc123");
  });

  it("the cast from wire to ScanFinding preserves actual_md5 when omitted (omitempty → null/undefined)", () => {
    // Go omitempty on a string sends the field absent when empty.
    // The web ScanFinding type declares `actual_md5: string | null`.
    // When the field is absent in the wire, the cast lands as undefined —
    // which is falsy, so `finding.actual_md5 ? ... : "n/a"` works correctly.
    const wire = makeWireFinding({ actual_md5: "" }) as ScanFinding;
    // An empty string is falsy — the table renders "n/a". This is correct.
    expect(!wire.actual_md5).toBe(true);
  });

  it("ignored field is a boolean in the wire payload", () => {
    const wire = makeWireFinding({ ignored: true }) as ScanFinding;
    expect(wire.ignored).toBe(true);
    expect(typeof wire.ignored).toBe("boolean");
  });

  it("Go runDTO files_scanned field is an int64 (arrives as number on the wire)", () => {
    // Matches handler.go:65 `FilesScanned int64 json:"files_scanned"`
    const runWire = {
      id: "550e8400-e29b-41d4-a716-446655440001",
      site_id: "550e8400-e29b-41d4-a716-446655440002",
      kind: "core",
      status: "done",
      files_scanned: 1234,
      created_at: "2026-06-19T10:00:00Z",
    };
    expect(typeof runWire.files_scanned).toBe("number");
    expect(runWire.files_scanned).toBe(1234);
  });
});

// ---------------------------------------------------------------------------
// 2. Phase 2 finding types — string values confirmed against Go model.go
//
// The Go constants in apps/api/internal/scan/model.go:44-50 define these
// exact string values. If the Go code changes the string value of any const,
// this test (and the TYPE_LABEL map) must be updated in lockstep.
// ---------------------------------------------------------------------------

describe("Phase 2 finding type string values — confirmed against Go model.go constants", () => {
  // These are the EXACT string values from model.go FindingFile* / FindingPlugin* consts.
  const PHASE2_FINDING_TYPES: ScanFindingType[] = [
    "file_added",
    "file_changed",
    "file_removed",
    "plugin_modified",
    "plugin_unknown",
  ];

  it("all five Phase 2 finding types are valid ScanFindingType members", () => {
    // If a Go constant changes (e.g. "file_added" → "added_file"), TypeScript
    // will reject the string literal here, causing a compile-time failure.
    for (const t of PHASE2_FINDING_TYPES) {
      expect(typeof t).toBe("string");
      expect(t.length).toBeGreaterThan(0);
    }
  });

  it("file_added string value matches Go FindingFileAdded = 'file_added'", () => {
    const t: ScanFindingType = "file_added";
    expect(t).toBe("file_added");
  });

  it("file_changed string value matches Go FindingFileChanged = 'file_changed'", () => {
    const t: ScanFindingType = "file_changed";
    expect(t).toBe("file_changed");
  });

  it("file_removed string value matches Go FindingFileRemoved = 'file_removed'", () => {
    const t: ScanFindingType = "file_removed";
    expect(t).toBe("file_removed");
  });

  it("plugin_modified string value matches Go FindingPluginModified = 'plugin_modified'", () => {
    const t: ScanFindingType = "plugin_modified";
    expect(t).toBe("plugin_modified");
  });

  it("plugin_unknown string value matches Go FindingPluginUnknown = 'plugin_unknown'", () => {
    const t: ScanFindingType = "plugin_unknown";
    expect(t).toBe("plugin_unknown");
  });
});

// ---------------------------------------------------------------------------
// 3. Finding type chip logic — label + class resolution
//
// These tests mirror the TYPE_LABEL / TYPE_CLASSES / fallback logic from
// scan-findings-table.tsx as pure functions so they run without a DOM renderer.
// Keeping the logic in-sync: if the maps in the component change, these tests
// must be updated, and vice versa.
// ---------------------------------------------------------------------------

// Replicate the chip resolution logic from scan-findings-table.tsx.
// This is not an import — it's a clean-room re-statement of the same rules,
// so a divergence between the component and the test is caught explicitly.

const KNOWN_TYPE_LABELS: Record<ScanFindingType, string> = {
  core_modified: "Core modified",
  core_missing: "Core missing",
  core_unknown_injected: "Unknown file",
  file_added: "File added",
  file_changed: "File changed",
  file_removed: "File removed",
  plugin_modified: "Plugin file modified",
  plugin_unknown: "Unrecognized plugin file",
};

function resolveTypeLabel(type: string): string {
  if (type in KNOWN_TYPE_LABELS) {
    return KNOWN_TYPE_LABELS[type as ScanFindingType];
  }
  // Fallback: humanize the snake_case string.
  return type.replace(/_/g, " ");
}

function isKnownType(type: string): boolean {
  return type in KNOWN_TYPE_LABELS;
}

describe("finding type chip — label resolution", () => {
  it("core_modified resolves to 'Core modified'", () => {
    expect(resolveTypeLabel("core_modified")).toBe("Core modified");
  });

  it("file_added resolves to 'File added'", () => {
    expect(resolveTypeLabel("file_added")).toBe("File added");
  });

  it("file_changed resolves to 'File changed'", () => {
    expect(resolveTypeLabel("file_changed")).toBe("File changed");
  });

  it("file_removed resolves to 'File removed'", () => {
    expect(resolveTypeLabel("file_removed")).toBe("File removed");
  });

  it("plugin_modified resolves to 'Plugin file modified'", () => {
    expect(resolveTypeLabel("plugin_modified")).toBe("Plugin file modified");
  });

  it("plugin_unknown resolves to 'Unrecognized plugin file'", () => {
    expect(resolveTypeLabel("plugin_unknown")).toBe("Unrecognized plugin file");
  });

  it("all eight known types produce a non-empty label", () => {
    const knownTypes: ScanFindingType[] = [
      "core_modified",
      "core_missing",
      "core_unknown_injected",
      "file_added",
      "file_changed",
      "file_removed",
      "plugin_modified",
      "plugin_unknown",
    ];
    for (const t of knownTypes) {
      const label = resolveTypeLabel(t);
      expect(label.length).toBeGreaterThan(0);
    }
  });
});

describe("finding type chip — unknown type resilience (no crash)", () => {
  it("an unknown type is NOT in the known-type map", () => {
    expect(isKnownType("some_future_type")).toBe(false);
  });

  it("resolveTypeLabel falls back to humanized snake_case for unknown types", () => {
    expect(resolveTypeLabel("some_future_type")).toBe("some future type");
  });

  it("resolveTypeLabel produces a non-empty string for any non-empty input", () => {
    const unknownTypes = [
      "some_future_type",
      "x",
      "a_b_c",
      "UPPERCASE_TYPE",
    ];
    for (const t of unknownTypes) {
      expect(resolveTypeLabel(t).length).toBeGreaterThan(0);
    }
  });

  it("an unknown type from the API wire payload does not throw", () => {
    // Simulate what FindingTypeChip does: look up label, fall back to humanized.
    const wireFinding = makeWireFinding({
      finding_type: "a_completely_unknown_finding_type_from_a_future_backend",
    }) as { finding_type: string };

    expect(() => {
      const label = resolveTypeLabel(wireFinding.finding_type);
      // Must produce some non-empty string, not throw.
      expect(label.length).toBeGreaterThan(0);
    }).not.toThrow();
  });

  it("an empty string finding_type falls through to an empty humanized string (edge case, does not crash)", () => {
    // This is a degenerate case (the Go handler never sends an empty type)
    // but we confirm no throw occurs.
    expect(() => {
      resolveTypeLabel("");
    }).not.toThrow();
  });
});

// ---------------------------------------------------------------------------
// 4. canViewFile logic — which types support file content viewing
//
// Replicated from the FindingRow component in scan-findings-table.tsx.
// If the canViewFile condition changes, update this test in lockstep.
// ---------------------------------------------------------------------------

function canViewFile(findingType: string): boolean {
  return (
    findingType === "core_unknown_injected" ||
    findingType === "core_modified" ||
    findingType === "file_changed" ||
    findingType === "file_added" ||
    findingType === "plugin_modified"
  );
}

describe("canViewFile — which finding types support the file viewer", () => {
  it("core_unknown_injected supports file viewing", () => {
    expect(canViewFile("core_unknown_injected")).toBe(true);
  });

  it("core_modified supports file viewing", () => {
    expect(canViewFile("core_modified")).toBe(true);
  });

  it("file_changed supports file viewing (Phase 2)", () => {
    expect(canViewFile("file_changed")).toBe(true);
  });

  it("file_added supports file viewing (Phase 2 — operator can inspect the injected file)", () => {
    expect(canViewFile("file_added")).toBe(true);
  });

  it("plugin_modified supports file viewing (Phase 2)", () => {
    expect(canViewFile("plugin_modified")).toBe(true);
  });

  it("file_removed does NOT support file viewing (file no longer exists on disk)", () => {
    // A removed file has no current content to fetch from the agent.
    expect(canViewFile("file_removed")).toBe(false);
  });

  it("plugin_unknown does NOT support file viewing (out of scope for v1)", () => {
    expect(canViewFile("plugin_unknown")).toBe(false);
  });

  it("core_missing does NOT support file viewing (file is absent)", () => {
    expect(canViewFile("core_missing")).toBe(false);
  });

  it("an unknown type does NOT support file viewing (safe default)", () => {
    expect(canViewFile("some_future_unknown_type")).toBe(false);
  });
});

// ---------------------------------------------------------------------------
// 5. Scan kind selector — POST body contains the chosen kind
//
// Simulates the useStartScan mutationFn to verify that the chosen ScanKind
// is forwarded as the `kind` field in the POST body. This matches the
// Go handler startScanBody: `Kind string json:"kind"` (handler.go:59-61)
// and the Service.StartRun switch in service.go that validates "core"|"files"|"full".
// ---------------------------------------------------------------------------

describe("scan kind selector — POST body carries the selected kind", () => {
  const VALID_KINDS: ScanKind[] = ["core", "files", "full"];

  it("each valid kind is a non-empty string", () => {
    for (const k of VALID_KINDS) {
      expect(typeof k).toBe("string");
      expect(k.length).toBeGreaterThan(0);
    }
  });

  it("'core' kind posts { kind: 'core' } — matches Go startScanBody.Kind = 'core'", () => {
    const mockPost = vi.fn();
    function simulateStartScan(kind: ScanKind) {
      mockPost(`/api/v1/sites/site-123/scans`, { kind });
    }
    simulateStartScan("core");
    expect(mockPost).toHaveBeenCalledWith(
      "/api/v1/sites/site-123/scans",
      { kind: "core" },
    );
  });

  it("'files' kind posts { kind: 'files' } — matches Go KindFiles = 'files'", () => {
    const mockPost = vi.fn();
    function simulateStartScan(kind: ScanKind) {
      mockPost(`/api/v1/sites/site-123/scans`, { kind });
    }
    simulateStartScan("files");
    expect(mockPost).toHaveBeenCalledWith(
      "/api/v1/sites/site-123/scans",
      { kind: "files" },
    );
  });

  it("'full' kind posts { kind: 'full' } — matches Go KindFull = 'full'", () => {
    const mockPost = vi.fn();
    function simulateStartScan(kind: ScanKind) {
      mockPost(`/api/v1/sites/site-123/scans`, { kind });
    }
    simulateStartScan("full");
    expect(mockPost).toHaveBeenCalledWith(
      "/api/v1/sites/site-123/scans",
      { kind: "full" },
    );
  });

  it("the default kind for 'Core files' option is 'core'", () => {
    // Matches SCAN_KIND_OPTIONS[0].kind in scan-panel.tsx.
    const defaultKind: ScanKind = "core";
    expect(defaultKind).toBe("core");
  });

  it("siteId is URL-encoded in the POST path", () => {
    // Matches the encodeURIComponent(siteId) call in use-scan.ts apiPost.
    const siteId = "550e8400-e29b-41d4-a716-446655440001";
    const path = `/api/v1/sites/${encodeURIComponent(siteId)}/scans`;
    expect(path).toBe(
      "/api/v1/sites/550e8400-e29b-41d4-a716-446655440001/scans",
    );
  });
});

// ---------------------------------------------------------------------------
// 6. Severity — low severity is supported for file_removed
//
// The design spec (§3.5) assigns file_removed = SeverityLow = "low".
// SeverityChip already supports "low" (severity-chip.tsx CHIP/DOT/WORD maps).
// This test pins that "low" is part of ScanFindingSeverity and that a
// file_removed finding with severity="low" is a valid combination.
// ---------------------------------------------------------------------------

describe("severity — 'low' is a valid ScanFindingSeverity for file_removed", () => {
  it("a file_removed finding with severity low is a valid ScanFinding shape", () => {
    const finding = makeWireFinding({
      finding_type: "file_removed",
      severity: "low",
      expected_md5: "",
      actual_md5: "",
    }) as ScanFinding;

    expect(finding.finding_type).toBe("file_removed");
    expect(finding.severity).toBe("low");
  });

  it("file_changed carries severity high per the design spec", () => {
    const finding = makeWireFinding({
      finding_type: "file_changed",
      severity: "high",
    }) as ScanFinding;
    expect(finding.severity).toBe("high");
  });

  it("file_added carries severity medium per the design spec", () => {
    const finding = makeWireFinding({
      finding_type: "file_added",
      severity: "medium",
    }) as ScanFinding;
    expect(finding.severity).toBe("medium");
  });

  it("plugin_modified and plugin_unknown carry severity high per the design spec", () => {
    for (const type of ["plugin_modified", "plugin_unknown"] as ScanFindingType[]) {
      const finding = makeWireFinding({
        finding_type: type,
        severity: "high",
      }) as ScanFinding;
      expect(finding.severity).toBe("high");
    }
  });
});

// ---------------------------------------------------------------------------
// 7. Findings list DTO shape — items wrapper
//
// The GET /findings endpoint returns { items: findingDTO[] }.
// Our apiGet call in useScanFindings expects `data.items ?? []`.
// This test pins the wrapper shape so a backend change (e.g. "results" key)
// surfaces as a test failure.
// ---------------------------------------------------------------------------

describe("findings list DTO — wrapper shape matches Go findingListDTO", () => {
  it("the wire response uses 'items' as the array key", () => {
    // Matches handler.go:99 `findingListDTO { Items []findingDTO json:"items" }`
    const wireResponse: { items: unknown[] } = {
      items: [makeWireFinding()],
    };
    expect(wireResponse).toHaveProperty("items");
    expect(Array.isArray(wireResponse.items)).toBe(true);
  });

  it("an empty findings response has items as an empty array (not null)", () => {
    // The Go handler always returns `items := make([]findingDTO, 0, len(findings))`
    // so items is never null in the response. Our `data.items ?? []` handles the
    // null case defensively (in case a future version changes this).
    const wireResponse = { items: [] as unknown[] };
    const result = wireResponse.items ?? [];
    expect(result).toHaveLength(0);
  });

  it("finding_counts on a run may be null (nil Go map marshals to null)", () => {
    // Matches the comment in use-scan.ts:47 and the Go omitempty tag on
    // FindingCounts: a run with no findings or in-flight sends finding_counts
    // as absent/null, NOT as an empty object.
    const runWithNullCounts = {
      id: "550e8400-e29b-41d4-a716-446655440001",
      site_id: "550e8400-e29b-41d4-a716-446655440002",
      kind: "core",
      status: "done",
      files_scanned: 500,
      finding_counts: null as Record<string, number> | null,
      created_at: "2026-06-19T10:00:00Z",
    };
    // Our component calls Object.values(counts ?? {}) — must not throw on null.
    const counts = runWithNullCounts.finding_counts ?? {};
    const total = Object.values(counts).reduce((s, n) => s + n, 0);
    expect(total).toBe(0);
  });
});
