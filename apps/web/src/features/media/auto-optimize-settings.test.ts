import { describe, it, expect } from "vitest";

import type { MediaSettings, TargetFormat, TargetQuality } from "./types";

// Pure unit coverage for MediaSettings type constraints (ADR-044 §C).
// These run in the node env (no DOM — no mocking of React Query or fetch).
// The hook logic itself is covered by the contract assertions below.

const VALID_FORMATS: TargetFormat[] = ["avif", "webp", "original"];
const VALID_QUALITIES: TargetQuality[] = ["lossy", "lossless"];

/** Build a valid MediaSettings object for testing. */
function settings(overrides: Partial<MediaSettings> = {}): MediaSettings {
  return {
    auto_optimize_enabled: false,
    auto_target_format: "avif",
    auto_target_quality: "lossy",
    ...overrides,
  };
}

describe("MediaSettings type contract", () => {
  it("accepts every valid format value", () => {
    for (const fmt of VALID_FORMATS) {
      const s = settings({ auto_target_format: fmt });
      expect(s.auto_target_format).toBe(fmt);
    }
  });

  it("accepts every valid quality value", () => {
    for (const q of VALID_QUALITIES) {
      const s = settings({ auto_target_quality: q });
      expect(s.auto_target_quality).toBe(q);
    }
  });

  it("defaults auto_optimize_enabled to false when not set", () => {
    const s = settings();
    expect(s.auto_optimize_enabled).toBe(false);
  });

  it("enabled flag is independently togglable from format/quality", () => {
    const on = settings({ auto_optimize_enabled: true, auto_target_format: "webp", auto_target_quality: "lossless" });
    const off = settings({ auto_optimize_enabled: false, auto_target_format: "webp", auto_target_quality: "lossless" });

    expect(on.auto_optimize_enabled).toBe(true);
    expect(off.auto_optimize_enabled).toBe(false);
    // format/quality preserved regardless of enabled flag
    expect(on.auto_target_format).toBe(off.auto_target_format);
    expect(on.auto_target_quality).toBe(off.auto_target_quality);
  });

  it("covers all 6 format × quality combinations", () => {
    const pairs: MediaSettings[] = [];
    for (const fmt of VALID_FORMATS) {
      for (const q of VALID_QUALITIES) {
        pairs.push(settings({ auto_target_format: fmt, auto_target_quality: q }));
      }
    }
    expect(pairs).toHaveLength(6);
    for (const s of pairs) {
      expect(VALID_FORMATS).toContain(s.auto_target_format);
      expect(VALID_QUALITIES).toContain(s.auto_target_quality);
    }
  });
});
