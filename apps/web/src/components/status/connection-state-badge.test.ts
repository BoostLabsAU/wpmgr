import { describe, it, expect } from "vitest";

import { resolveLabel } from "./connection-state-badge-helpers";

// Unit coverage for the `resolveLabel` function that maps disconnected_reason
// onto human-visible badge copy. The full badge render (React component) is
// not tested here because this project does not yet have @testing-library/react
// wired up; this file pins the pure-function contract that the badge delegates
// to, so the reason->copy mapping can be verified independently of the DOM.

describe("resolveLabel — disconnected state variants", () => {
  it('returns "Agent unreachable" for agent_unreachable reason', () => {
    expect(resolveLabel("disconnected", "agent_unreachable")).toBe(
      "Agent unreachable",
    );
  });

  it('returns "No heartbeat" for heartbeat_timeout reason', () => {
    expect(resolveLabel("disconnected", "heartbeat_timeout")).toBe(
      "No heartbeat",
    );
  });

  it('returns "No heartbeat" when reason is absent (legacy passive path)', () => {
    expect(resolveLabel("disconnected", null)).toBe("No heartbeat");
    expect(resolveLabel("disconnected", undefined)).toBe("No heartbeat");
    expect(resolveLabel("disconnected")).toBe("No heartbeat");
  });

  it('returns "No heartbeat" for any unrecognized reason string (forward-compat)', () => {
    expect(resolveLabel("disconnected", "some_future_reason")).toBe(
      "No heartbeat",
    );
  });
});

describe("resolveLabel — non-disconnected states pass through unchanged", () => {
  it('returns "Connected" for connected state regardless of reason', () => {
    expect(resolveLabel("connected", "agent_unreachable")).toBe("Connected");
    expect(resolveLabel("connected")).toBe("Connected");
  });

  it('returns "Degraded" for degraded state', () => {
    expect(resolveLabel("degraded")).toBe("Degraded");
  });

  it('returns "Awaiting agent" for pending_enrollment state', () => {
    expect(resolveLabel("pending_enrollment")).toBe("Awaiting agent");
  });

  it('returns "Revoked" for revoked state', () => {
    expect(resolveLabel("revoked")).toBe("Revoked");
  });

  it('returns "Archived" for archived state', () => {
    expect(resolveLabel("archived")).toBe("Archived");
  });
});
