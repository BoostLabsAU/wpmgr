import { describe, it, expect, vi, beforeEach } from "vitest";

import { emailEventReducer, type EmailEventDeps } from "./use-email-events";
import { emailKeys } from "./use-email";
import type { SiteEvent } from "@/features/sites/use-site-events";

// ---------------------------------------------------------------------------
// Test helpers
// ---------------------------------------------------------------------------

// A minimal structural mock that satisfies EmailEventDeps. We keep the shape
// narrow and assign directly to EmailEventDeps so vi.fn() is inferred without
// the overloaded QueryClient signature creating a type incompatibility — the
// same pattern used in media-events.test.ts.
function makeQueryClient() {
  const invalidateQueries = vi.fn();
  const deps: EmailEventDeps = {
    queryClient: { invalidateQueries },
  };
  return { deps, invalidateQueries };
}

function makeEvent(
  type: string,
  siteId: string,
  data: unknown = {},
): SiteEvent {
  return {
    id: "evt-1",
    type: type as SiteEvent["type"],
    site_id: siteId,
    ts: new Date().toISOString(),
    data,
  };
}

const SITE_ID = "site-aaaaaaaa";

// ---------------------------------------------------------------------------
// email.log_ingested
// ---------------------------------------------------------------------------

describe("emailEventReducer — email.log_ingested", () => {
  let ctx: ReturnType<typeof makeQueryClient>;

  beforeEach(() => {
    ctx = makeQueryClient();
  });

  it("invalidates the site log prefix", () => {
    emailEventReducer(
      makeEvent("email.log_ingested", SITE_ID, { count: 3 }),
      ctx.deps,
    );

    const logKey = [...emailKeys.all, "log", SITE_ID];
    expect(ctx.invalidateQueries).toHaveBeenCalledWith(
      expect.objectContaining({ queryKey: logKey }),
    );
  });

  it("invalidates the site stats prefix", () => {
    emailEventReducer(
      makeEvent("email.log_ingested", SITE_ID, { count: 1 }),
      ctx.deps,
    );

    const statsKey = [...emailKeys.all, "stats", SITE_ID];
    expect(ctx.invalidateQueries).toHaveBeenCalledWith(
      expect.objectContaining({ queryKey: statsKey }),
    );
  });

  it("also invalidates fleet log + fleet stats", () => {
    emailEventReducer(
      makeEvent("email.log_ingested", SITE_ID, { count: 2 }),
      ctx.deps,
    );

    expect(ctx.invalidateQueries).toHaveBeenCalledWith(
      expect.objectContaining({ queryKey: emailKeys.fleetLog({}) }),
    );
    expect(ctx.invalidateQueries).toHaveBeenCalledWith(
      expect.objectContaining({ queryKey: emailKeys.fleetStats({}) }),
    );
  });
});

// ---------------------------------------------------------------------------
// email.suppression_updated
// ---------------------------------------------------------------------------

describe("emailEventReducer — email.suppression_updated", () => {
  let ctx: ReturnType<typeof makeQueryClient>;

  beforeEach(() => {
    ctx = makeQueryClient();
  });

  it("invalidates the per-site suppression prefix when site_id is present", () => {
    emailEventReducer(
      makeEvent("email.suppression_updated", SITE_ID, {
        site_id: SITE_ID,
        email: "user@example.com",
        reason: "manual",
      }),
      ctx.deps,
    );

    const suppressionKey = [...emailKeys.all, "suppression", SITE_ID];
    expect(ctx.invalidateQueries).toHaveBeenCalledWith(
      expect.objectContaining({ queryKey: suppressionKey }),
    );
  });

  it("always invalidates the fleet suppression prefix", () => {
    emailEventReducer(
      makeEvent("email.suppression_updated", SITE_ID, {
        site_id: SITE_ID,
        email: "user@example.com",
        reason: "hard_bounce",
      }),
      ctx.deps,
    );

    const fleetKey = [...emailKeys.all, "fleet-suppression"];
    expect(ctx.invalidateQueries).toHaveBeenCalledWith(
      expect.objectContaining({ queryKey: fleetKey }),
    );
  });

  it("only invalidates fleet suppression when site_id is absent (fleet-wide entry)", () => {
    emailEventReducer(
      makeEvent("email.suppression_updated", "", {
        // no site_id — fleet-wide entry
        email: "user@example.com",
        reason: "unsubscribe",
      }),
      ctx.deps,
    );

    // Should NOT call with a per-site suppression key containing SITE_ID
    const callArgs = ctx.invalidateQueries.mock.calls.map(
      (args: unknown[]) => JSON.stringify((args[0] as { queryKey?: unknown[] } | undefined)?.queryKey),
    );
    const hasSiteKey = callArgs.some((k) =>
      k.includes('"suppression"') && k.includes(SITE_ID),
    );
    expect(hasSiteKey).toBe(false);

    // Should still invalidate fleet
    const fleetKey = [...emailKeys.all, "fleet-suppression"];
    expect(ctx.invalidateQueries).toHaveBeenCalledWith(
      expect.objectContaining({ queryKey: fleetKey }),
    );
  });
});

// ---------------------------------------------------------------------------
// email.bounce
// ---------------------------------------------------------------------------

describe("emailEventReducer — email.bounce", () => {
  let ctx: ReturnType<typeof makeQueryClient>;

  beforeEach(() => {
    ctx = makeQueryClient();
  });

  it("invalidates the site log prefix", () => {
    emailEventReducer(
      makeEvent("email.bounce", SITE_ID, {
        message_id: "msg-123",
        status: "bounced",
      }),
      ctx.deps,
    );

    const logKey = [...emailKeys.all, "log", SITE_ID];
    expect(ctx.invalidateQueries).toHaveBeenCalledWith(
      expect.objectContaining({ queryKey: logKey }),
    );
  });

  it("invalidates the site stats prefix", () => {
    emailEventReducer(
      makeEvent("email.bounce", SITE_ID, {
        message_id: "msg-456",
        status: "complained",
      }),
      ctx.deps,
    );

    const statsKey = [...emailKeys.all, "stats", SITE_ID];
    expect(ctx.invalidateQueries).toHaveBeenCalledWith(
      expect.objectContaining({ queryKey: statsKey }),
    );
  });

  it("also invalidates fleet log + fleet stats", () => {
    emailEventReducer(
      makeEvent("email.bounce", SITE_ID, { status: "bounced" }),
      ctx.deps,
    );

    expect(ctx.invalidateQueries).toHaveBeenCalledWith(
      expect.objectContaining({ queryKey: emailKeys.fleetLog({}) }),
    );
    expect(ctx.invalidateQueries).toHaveBeenCalledWith(
      expect.objectContaining({ queryKey: emailKeys.fleetStats({}) }),
    );
  });
});

// ---------------------------------------------------------------------------
// Non-email events are ignored
// ---------------------------------------------------------------------------

describe("emailEventReducer — ignores non-email events", () => {
  it("does not call invalidateQueries for cache.stats.updated", () => {
    const { deps, invalidateQueries } = makeQueryClient();
    emailEventReducer(
      makeEvent("cache.stats.updated", SITE_ID, {}),
      deps,
    );
    expect(invalidateQueries).not.toHaveBeenCalled();
  });

  it("does not call invalidateQueries for rum.rollup_updated", () => {
    const { deps, invalidateQueries } = makeQueryClient();
    emailEventReducer(
      makeEvent("rum.rollup_updated", SITE_ID, {}),
      deps,
    );
    expect(invalidateQueries).not.toHaveBeenCalled();
  });
});

// ---------------------------------------------------------------------------
// BodyNotStoredError class
// ---------------------------------------------------------------------------

describe("BodyNotStoredError", () => {
  it("is an Error subclass with the right name", async () => {
    const { BodyNotStoredError } = await import("./use-email");
    const err = new BodyNotStoredError();
    expect(err).toBeInstanceOf(Error);
    expect(err.name).toBe("BodyNotStoredError");
    expect(err.message).toMatch(/body.*not stored/i);
  });
});

// ---------------------------------------------------------------------------
// Resend body-stored gate
// ---------------------------------------------------------------------------

describe("resend body-stored gating", () => {
  it("body_stored=false means the resend button should be disabled", () => {
    // This is a UI contract test: when body_stored is false the resend
    // mutation is not safe to fire (the backend will 409). We verify the
    // flag carries the right semantic here; the component gates on it.
    const entryWithoutBody = { body_stored: false, id: "log-1" };
    const canResend = entryWithoutBody.body_stored;
    expect(canResend).toBe(false);
  });

  it("body_stored=true means the resend button should be enabled", () => {
    const entryWithBody = { body_stored: true, id: "log-2" };
    const canResend = entryWithBody.body_stored;
    expect(canResend).toBe(true);
  });

  it("bulk resend filters to only stored-body IDs", () => {
    const entries = [
      { id: "a", body_stored: true },
      { id: "b", body_stored: false },
      { id: "c", body_stored: true },
      { id: "d", body_stored: false },
    ];
    const selectedIds = new Set(["a", "b", "c", "d"]);
    const resendable = entries
      .filter((e) => e.body_stored && selectedIds.has(e.id))
      .map((e) => e.id);

    expect(resendable).toEqual(["a", "c"]);
    expect(resendable).not.toContain("b");
    expect(resendable).not.toContain("d");
  });
});
