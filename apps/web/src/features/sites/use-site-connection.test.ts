import { describe, it, expect } from "vitest";

import {
  AgentUnreachableError,
  SiteUrlExistsError,
} from "./use-site-connection";
import { sitesKeys } from "./use-sites";

// Unit coverage for the exported error classes and query-key shapes used by
// `useRecheckConnection`. The full mutation lifecycle (HTTP call → invalidate
// → toast) is integration-level and requires @testing-library/react +
// @tanstack/react-query wrappers that are not yet in this project's test
// stack; this file pins the contracts that the mutation's call site branches on.

// ---------------------------------------------------------------------------
// AgentUnreachableError — the 502/agent_unreachable typed error
// ---------------------------------------------------------------------------

describe("AgentUnreachableError", () => {
  it("has code 'agent_unreachable' so callers can instanceof-branch without string comparison", () => {
    const err = new AgentUnreachableError();
    expect(err.code).toBe("agent_unreachable");
  });

  it("is an instance of Error so it propagates naturally through TanStack Query's onError", () => {
    const err = new AgentUnreachableError();
    expect(err).toBeInstanceOf(Error);
  });

  it("carries a non-alarming message (no 'disconnected'/'down' vocabulary) appropriate for a calm toast", () => {
    const err = new AgentUnreachableError();
    // The message must NOT use alarming terminology — the badge must NOT flip.
    expect(err.message).not.toMatch(/disconnected|down|failed|error/i);
    // It SHOULD describe the quiet-agent scenario so the operator understands.
    expect(err.message).toMatch(/quiet|monitor/i);
  });

  it("has a predictable name for log filtering", () => {
    const err = new AgentUnreachableError();
    expect(err.name).toBe("AgentUnreachableError");
  });

  it("is NOT an instance of SiteUrlExistsError — distinct error lineages", () => {
    const err = new AgentUnreachableError();
    expect(err).not.toBeInstanceOf(SiteUrlExistsError);
  });
});

// ---------------------------------------------------------------------------
// sitesKeys — the exact cache keys useRecheckConnection invalidates
// ---------------------------------------------------------------------------

describe("sitesKeys — keys invalidated by useRecheckConnection on 200", () => {
  const SITE_ID = "aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee";

  it("detail key is specific to the site so only the affected badge invalidates", () => {
    const key = sitesKeys.detail(SITE_ID);
    expect(key).toContain(SITE_ID);
    expect(key).toContain("detail");
  });

  it("lists key is under the 'sites' namespace so the table row reconciles", () => {
    const key = sitesKeys.lists();
    expect(key[0]).toBe("sites");
    expect(key).toContain("list");
  });

  it("detail key is distinct from the lists key so a recheck only refetches what it needs", () => {
    const detail = sitesKeys.detail(SITE_ID);
    const lists = sitesKeys.lists();
    // They share the root "sites" namespace but diverge immediately after.
    expect(detail).not.toEqual(lists);
  });
});
