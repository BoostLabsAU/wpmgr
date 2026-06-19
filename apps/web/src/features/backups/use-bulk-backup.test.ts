import { describe, it, expect, vi, beforeEach } from "vitest";

import {
  runBulkBackup,
  isInFlightError,
  type BulkBackupDeps,
} from "./use-bulk-backup";
import type { BackupSnapshot } from "@wpmgr/api";

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

const SITE_A = "aaaaaaaa-0000-0000-0000-000000000001";
const SITE_B = "aaaaaaaa-0000-0000-0000-000000000002";
const SITE_C = "aaaaaaaa-0000-0000-0000-000000000003";

function makeSnapshot(siteId: string): BackupSnapshot {
  return {
    id: `snap-${siteId}`,
    tenant_id: "tenant-1",
    site_id: siteId,
    kind: "full",
    status: "pending",
    created_at: new Date().toISOString(),
    updated_at: new Date().toISOString(),
  };
}

/** Builds a BulkBackupDeps with per-site control. */
function makeDeps(
  siteOutcomes: Record<string, "ok" | "in_flight" | "error">,
): {
  deps: BulkBackupDeps;
  createBackupFn: ReturnType<typeof vi.fn<(siteId: string) => Promise<BackupSnapshot>>>;
  invalidateBackupList: ReturnType<typeof vi.fn>;
} {
  const createBackupFn = vi.fn((siteId: string): Promise<BackupSnapshot> => {
    const outcome = siteOutcomes[siteId] ?? "ok";
    if (outcome === "in_flight") {
      return Promise.reject(new Error("backup_already_in_flight"));
    }
    if (outcome === "error") {
      return Promise.reject(new Error("Internal server error"));
    }
    return Promise.resolve(makeSnapshot(siteId));
  });

  const invalidateBackupList = vi.fn();

  const deps: BulkBackupDeps = { createBackupFn, invalidateBackupList };
  return { deps, createBackupFn, invalidateBackupList };
}

// ---------------------------------------------------------------------------
// isInFlightError
// ---------------------------------------------------------------------------

describe("isInFlightError", () => {
  it("returns true for an error whose message includes the in-flight code", () => {
    expect(isInFlightError(new Error("backup_already_in_flight"))).toBe(true);
  });

  it("returns false for a generic error", () => {
    expect(isInFlightError(new Error("Internal server error"))).toBe(false);
  });

  it("returns false for non-Error values", () => {
    expect(isInFlightError("backup_already_in_flight")).toBe(false);
    expect(isInFlightError(null)).toBe(false);
    expect(isInFlightError(undefined)).toBe(false);
  });
});

// ---------------------------------------------------------------------------
// runBulkBackup — call count and ID routing (fix for issue #76 stubs)
// ---------------------------------------------------------------------------

describe("runBulkBackup — create-backup is called once per site id", () => {
  it("calls createBackupFn for every site id in the list", async () => {
    const { deps, createBackupFn } = makeDeps({
      [SITE_A]: "ok",
      [SITE_B]: "ok",
      [SITE_C]: "ok",
    });

    await runBulkBackup([SITE_A, SITE_B, SITE_C], deps);

    // The critical assertion: the API must be called N times with the correct
    // site ids — this is the stub behaviour that issue #76 fixes.
    expect(createBackupFn).toHaveBeenCalledTimes(3);
    expect(createBackupFn).toHaveBeenCalledWith(SITE_A);
    expect(createBackupFn).toHaveBeenCalledWith(SITE_B);
    expect(createBackupFn).toHaveBeenCalledWith(SITE_C);
  });

  it("returns all three ids in enqueued when all calls succeed", async () => {
    const { deps } = makeDeps({
      [SITE_A]: "ok",
      [SITE_B]: "ok",
      [SITE_C]: "ok",
    });

    const result = await runBulkBackup([SITE_A, SITE_B, SITE_C], deps);

    expect(result.enqueued).toHaveLength(3);
    expect(result.skipped).toHaveLength(0);
    expect(result.failed).toHaveLength(0);
  });

  it("invalidates the backup list cache for each successfully enqueued site", async () => {
    const { deps, invalidateBackupList } = makeDeps({
      [SITE_A]: "ok",
      [SITE_B]: "ok",
    });

    await runBulkBackup([SITE_A, SITE_B], deps);

    expect(invalidateBackupList).toHaveBeenCalledTimes(2);
    expect(invalidateBackupList).toHaveBeenCalledWith(SITE_A);
    expect(invalidateBackupList).toHaveBeenCalledWith(SITE_B);
  });
});

// ---------------------------------------------------------------------------
// runBulkBackup — 422 in-flight classification (skipped, not failed)
// ---------------------------------------------------------------------------

describe("runBulkBackup — 422 backup_already_in_flight is classified as skipped", () => {
  it("classifies an in-flight site as skipped, not failed", async () => {
    const { deps } = makeDeps({
      [SITE_A]: "in_flight",
    });

    const result = await runBulkBackup([SITE_A], deps);

    expect(result.skipped).toContain(SITE_A);
    expect(result.failed).toHaveLength(0);
    expect(result.enqueued).toHaveLength(0);
  });

  it("does NOT invalidate the backup list for an in-flight skip", async () => {
    const { deps, invalidateBackupList } = makeDeps({
      [SITE_A]: "in_flight",
    });

    await runBulkBackup([SITE_A], deps);

    expect(invalidateBackupList).not.toHaveBeenCalled();
  });
});

// ---------------------------------------------------------------------------
// runBulkBackup — partial failure with accurate counts (the test from spec §4)
// ---------------------------------------------------------------------------

describe("runBulkBackup — partial results report accurate enqueued/skipped/failed counts", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("correctly classifies one in-flight, one hard error, and one success", async () => {
    const { deps, createBackupFn } = makeDeps({
      [SITE_A]: "ok",
      [SITE_B]: "in_flight",   // 422 — should be skipped
      [SITE_C]: "error",       // 500 — should be failed
    });

    const result = await runBulkBackup([SITE_A, SITE_B, SITE_C], deps);

    // All three sites must have received a call — not blanket success/failure.
    expect(createBackupFn).toHaveBeenCalledTimes(3);
    expect(result.enqueued).toEqual([SITE_A]);
    expect(result.skipped).toEqual([SITE_B]);
    expect(result.failed).toEqual([SITE_C]);
  });

  it("never calls createBackupFn zero times for a non-empty list (guards against stub-only behaviour)", async () => {
    const { deps, createBackupFn } = makeDeps({ [SITE_A]: "ok" });

    await runBulkBackup([SITE_A], deps);

    // The key guard: a stub that just toasts without calling the API would
    // fail here because createBackupFn.mock.calls.length would be 0.
    expect(createBackupFn.mock.calls.length).toBeGreaterThan(0);
  });
});

// ---------------------------------------------------------------------------
// runBulkBackup — empty list is a no-op
// ---------------------------------------------------------------------------

describe("runBulkBackup — empty site list", () => {
  it("returns all-empty counts and makes no API calls", async () => {
    const { deps, createBackupFn } = makeDeps({});

    const result = await runBulkBackup([], deps);

    expect(createBackupFn).not.toHaveBeenCalled();
    expect(result.enqueued).toHaveLength(0);
    expect(result.skipped).toHaveLength(0);
    expect(result.failed).toHaveLength(0);
  });
});

// ---------------------------------------------------------------------------
// Bounded concurrency — chunks of CHUNK_SIZE (6) are processed sequentially
// ---------------------------------------------------------------------------

describe("runBulkBackup — bounded concurrency (max 6 parallel)", () => {
  it("calls all sites even when the list exceeds one chunk", async () => {
    // 10 sites spans two chunks of 6 and 4.
    const ids = Array.from({ length: 10 }, (_, i) => `site-${i}`);
    const outcomes: Record<string, "ok"> = {};
    for (const id of ids) outcomes[id] = "ok";

    const { deps, createBackupFn } = makeDeps(outcomes);

    const result = await runBulkBackup(ids, deps);

    expect(createBackupFn).toHaveBeenCalledTimes(10);
    expect(result.enqueued).toHaveLength(10);
  });
});
