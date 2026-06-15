// Unit tests for pure fleet logic: status classification and tile filter.

import { describe, it, expect } from "vitest";

// ---------------------------------------------------------------------------
// BackupHealthStatus classification logic (pure, mirroring the Go server-side
// rule; expressed here so the frontend can test round-trips without hitting
// the API).
// ---------------------------------------------------------------------------

type BackupHealthStatus =
  | "protected"
  | "stale"
  | "failed"
  | "unprotected"
  | "in_flight";

interface ClassifyInput {
  last_completed_at: string | null;
  last_failed_at: string | null;
  in_flight_count: number;
  schedule_cadence: "daily" | "weekly" | "monthly" | null;
}

function classifyBackupStatus(input: ClassifyInput): BackupHealthStatus {
  const {
    last_completed_at,
    last_failed_at,
    in_flight_count,
    schedule_cadence,
  } = input;

  // No completed backup ever -> unprotected.
  if (!last_completed_at) {
    if (in_flight_count > 0) return "in_flight";
    return "unprotected";
  }

  const completedTs = Date.parse(last_completed_at);

  // last_failed_at newer than last_completed_at -> failed.
  if (last_failed_at) {
    const failedTs = Date.parse(last_failed_at);
    if (failedTs > completedTs) return "failed";
  }

  // In-flight with no recent completion -> in_flight.
  // (If there IS a recent completion and also in-flight count, it's protected.)
  // We only flag in_flight when the latest backup never completed.
  if (in_flight_count > 0 && !last_completed_at) return "in_flight";

  // last_completed_at older than 2x the schedule cadence (or >48h if no
  // schedule) -> stale.
  const cadenceMs: Record<NonNullable<typeof schedule_cadence>, number> = {
    daily: 24 * 3_600_000,
    weekly: 7 * 24 * 3_600_000,
    monthly: 30 * 24 * 3_600_000,
  };
  const thresholdMs = schedule_cadence
    ? 2 * cadenceMs[schedule_cadence]
    : 48 * 3_600_000;

  if (Date.now() - completedTs > thresholdMs) return "stale";

  return "protected";
}

// ---------------------------------------------------------------------------
// Tests: classifyBackupStatus
// ---------------------------------------------------------------------------

describe("classifyBackupStatus", () => {
  const hoursAgo = (h: number) =>
    new Date(Date.now() - h * 3_600_000).toISOString();
  const daysAgo = (d: number) => hoursAgo(d * 24);

  it("returns unprotected when no backup has ever completed", () => {
    expect(
      classifyBackupStatus({
        last_completed_at: null,
        last_failed_at: null,
        in_flight_count: 0,
        schedule_cadence: "daily",
      }),
    ).toBe("unprotected");
  });

  it("returns in_flight when in-flight and never completed", () => {
    expect(
      classifyBackupStatus({
        last_completed_at: null,
        last_failed_at: null,
        in_flight_count: 1,
        schedule_cadence: "daily",
      }),
    ).toBe("in_flight");
  });

  it("returns failed when last_failed_at is newer than last_completed_at", () => {
    expect(
      classifyBackupStatus({
        last_completed_at: hoursAgo(4),
        last_failed_at: hoursAgo(1),
        in_flight_count: 0,
        schedule_cadence: "daily",
      }),
    ).toBe("failed");
  });

  it("returns protected when last_failed_at is older than last_completed_at", () => {
    expect(
      classifyBackupStatus({
        last_completed_at: hoursAgo(1),
        last_failed_at: hoursAgo(4),
        in_flight_count: 0,
        schedule_cadence: "daily",
      }),
    ).toBe("protected");
  });

  it("returns stale when daily backup is older than 48h", () => {
    expect(
      classifyBackupStatus({
        last_completed_at: hoursAgo(50),
        last_failed_at: null,
        in_flight_count: 0,
        schedule_cadence: "daily",
      }),
    ).toBe("stale");
  });

  it("returns stale when weekly backup is older than 14 days", () => {
    expect(
      classifyBackupStatus({
        last_completed_at: daysAgo(15),
        last_failed_at: null,
        in_flight_count: 0,
        schedule_cadence: "weekly",
      }),
    ).toBe("stale");
  });

  it("returns stale when no schedule and backup older than 48h", () => {
    expect(
      classifyBackupStatus({
        last_completed_at: hoursAgo(52),
        last_failed_at: null,
        in_flight_count: 0,
        schedule_cadence: null,
      }),
    ).toBe("stale");
  });

  it("returns protected when recent daily backup exists", () => {
    expect(
      classifyBackupStatus({
        last_completed_at: hoursAgo(10),
        last_failed_at: null,
        in_flight_count: 0,
        schedule_cadence: "daily",
      }),
    ).toBe("protected");
  });

  it("returns protected when monthly backup is within 60 days", () => {
    expect(
      classifyBackupStatus({
        last_completed_at: daysAgo(45),
        last_failed_at: null,
        in_flight_count: 0,
        schedule_cadence: "monthly",
      }),
    ).toBe("protected");
  });

  it("ignores failed at older than completed when completed is recent", () => {
    expect(
      classifyBackupStatus({
        last_completed_at: hoursAgo(2),
        last_failed_at: hoursAgo(24),
        in_flight_count: 0,
        schedule_cadence: "daily",
      }),
    ).toBe("protected");
  });
});

// ---------------------------------------------------------------------------
// Tests: tile filter toggle logic
// ---------------------------------------------------------------------------

function makeFilterSet(initial: string[] = []) {
  let state = new Set<string>(initial);
  const toggle = (key: string) => {
    const next = new Set(state);
    if (next.has(key)) {
      next.delete(key);
    } else {
      next.add(key);
    }
    state = next;
    return state;
  };
  const getActive = () => state;
  return { toggle, getActive };
}

describe("tile filter toggle", () => {
  it("starts empty (no filter = show all)", () => {
    const { getActive } = makeFilterSet();
    expect(getActive().size).toBe(0);
  });

  it("adds a key on first toggle", () => {
    const { toggle, getActive } = makeFilterSet();
    toggle("down");
    expect(getActive().has("down")).toBe(true);
    expect(getActive().size).toBe(1);
  });

  it("removes a key on second toggle (deactivate)", () => {
    const { toggle, getActive } = makeFilterSet();
    toggle("down");
    toggle("down");
    expect(getActive().size).toBe(0);
  });

  it("supports multiple active keys simultaneously", () => {
    const { toggle, getActive } = makeFilterSet();
    toggle("down");
    toggle("degraded");
    expect(getActive().has("down")).toBe(true);
    expect(getActive().has("degraded")).toBe(true);
    expect(getActive().size).toBe(2);
  });

  it("filters items correctly when one key is active", () => {
    const items = [
      { id: 1, status: "protected" },
      { id: 2, status: "failed" },
      { id: 3, status: "failed" },
    ];
    const { toggle, getActive } = makeFilterSet();
    toggle("failed");
    const active = getActive();
    const filtered = items.filter((i) => active.has(i.status));
    expect(filtered).toHaveLength(2);
    expect(filtered.every((i) => i.status === "failed")).toBe(true);
  });

  it("shows all items when filter is empty", () => {
    const items = [
      { id: 1, status: "protected" },
      { id: 2, status: "failed" },
    ];
    const { getActive } = makeFilterSet();
    const active = getActive();
    const filtered = active.size === 0 ? items : items.filter((i) => active.has(i.status));
    expect(filtered).toHaveLength(2);
  });
});

// ---------------------------------------------------------------------------
// Tests: uptime status derivation (matching the Go contract)
// ---------------------------------------------------------------------------

type UptimeStatusKind = "up" | "degraded" | "down" | "unknown";

const SLOW_THRESHOLD_MS = 2000;

interface ProbeInput {
  up: boolean | null;
  avg_latency_ms: number | null;
  connection_state: string;
  last_probe_at: string | null;
}

function deriveUptimeStatus(input: ProbeInput): UptimeStatusKind {
  const { up, avg_latency_ms, connection_state } = input;
  if (up === null) return "unknown";
  if (!up) return "down";
  if (
    (avg_latency_ms !== null && avg_latency_ms > SLOW_THRESHOLD_MS) ||
    connection_state === "degraded"
  ) {
    return "degraded";
  }
  return "up";
}

describe("deriveUptimeStatus", () => {
  it("returns unknown when up is null (no probe yet)", () => {
    expect(
      deriveUptimeStatus({
        up: null,
        avg_latency_ms: null,
        connection_state: "connected",
        last_probe_at: null,
      }),
    ).toBe("unknown");
  });

  it("returns down when probe up=false", () => {
    expect(
      deriveUptimeStatus({
        up: false,
        avg_latency_ms: null,
        connection_state: "connected",
        last_probe_at: new Date().toISOString(),
      }),
    ).toBe("down");
  });

  it("returns up when probe up=true and latency is fast", () => {
    expect(
      deriveUptimeStatus({
        up: true,
        avg_latency_ms: 800,
        connection_state: "connected",
        last_probe_at: new Date().toISOString(),
      }),
    ).toBe("up");
  });

  it("returns degraded when latency exceeds threshold", () => {
    expect(
      deriveUptimeStatus({
        up: true,
        avg_latency_ms: 2500,
        connection_state: "connected",
        last_probe_at: new Date().toISOString(),
      }),
    ).toBe("degraded");
  });

  it("returns degraded when connection_state is degraded even with fast latency", () => {
    expect(
      deriveUptimeStatus({
        up: true,
        avg_latency_ms: 100,
        connection_state: "degraded",
        last_probe_at: new Date().toISOString(),
      }),
    ).toBe("degraded");
  });
});
