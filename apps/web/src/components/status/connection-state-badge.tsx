import { useMemo, useEffect, useState } from "react";

import { useNow } from "@/lib/use-now";
import { cn, relativeTime } from "@/lib/utils";

import { StatusDot, type StatusTone } from "./status-dot";

// Phase 5.5 — the single source of truth for rendering a site's connection
// lifecycle state. Reads `connection_state` (plus last-seen / expiry context)
// and renders dot + label + relative time. Used on the sites table and the
// site-detail header so the vocabulary stays identical everywhere.
//
//   pending_enrollment → ◐ Awaiting agent · {N}m left   (info)
//   connected          → ● Connected · last seen {t}     (success)
//   degraded           → ● Degraded · last seen {t}      (warning)
//   disconnected       → ● Disconnected · {t}            (destructive)
//   revoked            → ● Revoked                        (destructive)
//   archived           → ◯ Archived                       (muted)
//
// Relative time auto-updates via `useNow()` — a 1s tick while connected or
// degraded (operators watch these closely), 30s otherwise (cheap; the others
// move slowly). The dot one-shot-pulses on tone change (`pulseOnChange`) so a
// pending→connected or connected→degraded flip registers without becoming a
// perpetual attention magnet.

import type { ConnectionState } from "@/features/sites/connection-state";
import { resolveLabel } from "./connection-state-badge-helpers";

export interface ConnectionStateBadgeProps {
  state: ConnectionState;
  /** ISO last-heartbeat timestamp; drives the "last seen {t}" tail. */
  lastSeenAt?: string | null;
  /** ISO enrollment-code expiry; drives the "{N}m left" pending tail. */
  expiresAt?: string | null;
  /**
   * CP-written disconnect reason. Distinguishes an actively-verified failure
   * ("agent_unreachable") from a passive heartbeat gap ("heartbeat_timeout").
   * Only meaningful when `state === "disconnected"`.
   */
  disconnectedReason?: string | null;
  /** Pulse the dot once when the state changes. Defaults to true. */
  pulseOnChange?: boolean;
  className?: string;
}

interface BadgeShape {
  tone: StatusTone;
  /** ◐ / ● / ◯ glyph carried by the dot shape — we render via StatusDot. */
  glyph: "half" | "filled" | "hollow";
  label: string;
}

const SHAPE: Record<ConnectionState, BadgeShape> = {
  pending_enrollment: { tone: "info", glyph: "half", label: "Awaiting agent" },
  connected: { tone: "success", glyph: "filled", label: "Connected" },
  degraded: { tone: "warning", glyph: "filled", label: "Degraded" },
  disconnected: {
    tone: "destructive",
    glyph: "filled",
    label: "Disconnected",
  },
  revoked: { tone: "destructive", glyph: "filled", label: "Revoked" },
  archived: { tone: "muted", glyph: "hollow", label: "Archived" },
};

/** States that benefit from a 1s clock (everything else updates slowly). */
function tickFor(state: ConnectionState): number {
  return state === "connected" || state === "degraded" ? 1000 : 30000;
}

/**
 * Calm-down debounce: suppress a connected→degraded flip until it has
 * persisted for `DEGRADED_DEBOUNCE_MS`.
 *
 * Low-traffic sites often miss one heartbeat window (60s) and flip
 * connected→degraded for a few seconds before the next heartbeat arrives
 * and flips them back. Without this, the badge flaps noticeably in operator
 * dashboards. The debounce is purely presentational:
 *   - It does NOT affect the underlying `connection_state` value in the cache.
 *   - It does NOT suppress a genuinely sustained degraded state (after
 *     `DEGRADED_DEBOUNCE_MS` the badge shows "Degraded" correctly).
 *   - Any state other than connected→degraded passes through immediately
 *     (e.g., connected→disconnected, connected→revoked are always shown at
 *     once because they are high-signal events operators must not miss).
 *
 * 10 s is long enough to absorb a single missed heartbeat (CP threshold is
 * ~60–90 s) while still being short enough to show genuine degradation well
 * before the CP promotes the site to "disconnected".
 */
const DEGRADED_DEBOUNCE_MS = 10_000;

/**
 * Returns the display-state to render. When the incoming `state` flips from
 * "connected" to "degraded", we keep showing "connected" for
 * `DEGRADED_DEBOUNCE_MS` before switching. All other transitions are instant.
 *
 * Implementation: we use the functional-updater form of `setDisplayed` so we
 * can read the *current* displayed value inside the effect without adding it
 * to the dependency array (which would loop). The timer callback is the only
 * place that calls setDisplayed, satisfying the react-hooks ESLint rules.
 */
function useDebouncedState(state: ConnectionState): ConnectionState {
  // `displayed` is the state we are currently rendering to the operator.
  const [displayed, setDisplayed] = useState<ConnectionState>(state);

  useEffect(() => {
    // Use the functional updater to read current displayed without a dep.
    // This schedules the update; the callback runs after render so it is NOT
    // a synchronous setState-in-effect call.
    const id = window.setTimeout(() => {
      setDisplayed((prev) => {
        // Only debounce the specific connected→degraded transition.
        // The timer for the debounce case fires after DEGRADED_DEBOUNCE_MS;
        // for other transitions the timeout is 0 (next microtask).
        if (prev === "connected" && state === "degraded") {
          // Still in the debounce window — don't flip yet. The deferred timer
          // below handles the actual flip after the hold window.
          return prev;
        }
        return state;
      });
    }, 0);

    // For the connected→degraded transition, ALSO schedule the real flip after
    // the hold window so the badge does eventually show "Degraded" if the state
    // has truly persisted.
    if (state === "degraded") {
      const debounceId = window.setTimeout(() => {
        setDisplayed(state);
      }, DEGRADED_DEBOUNCE_MS);
      return () => {
        clearTimeout(id);
        clearTimeout(debounceId);
      };
    }

    return () => clearTimeout(id);
  }, [state]);

  return displayed;
}

export function ConnectionStateBadge({
  state,
  lastSeenAt,
  expiresAt,
  disconnectedReason,
  pulseOnChange = true,
  className,
}: ConnectionStateBadgeProps) {
  // Apply the connected→degraded calm-down debounce (see `useDebouncedState`
  // above). All other transitions are unaffected and update immediately.
  const displayState = useDebouncedState(state);
  const now = useNow(tickFor(displayState));
  const shape = SHAPE[displayState];

  // For disconnected state, override the label based on the disconnect reason
  // so operators see an accurate, actionable description rather than the generic
  // "Disconnected" label which conflates two different scenarios.
  const displayLabel = resolveLabel(displayState, disconnectedReason);

  const tail = useMemo(
    () => computeTail(displayState, now, lastSeenAt, expiresAt, disconnectedReason),
    [displayState, now, lastSeenAt, expiresAt, disconnectedReason],
  );

  const a11yLabel = tail.text
    ? `${displayLabel}, ${tail.aria ?? tail.text}`
    : displayLabel;

  return (
    <span
      className={cn(
        "inline-flex items-center gap-1.5 text-xs font-medium",
        className,
      )}
      aria-label={a11yLabel}
      title={tail.tooltip ?? undefined}
    >
      {/* The dot is decorative here; the wrapper span carries the a11y label
          (and an aria-live region announces pending countdowns, see below). */}
      <ConnectionDot
        tone={shape.tone}
        glyph={shape.glyph}
        pulseOnChange={pulseOnChange}
      />
      <span aria-hidden="true">{displayLabel}</span>
      {tail.text ? (
        <>
          <span aria-hidden="true" className="text-muted-foreground">
            {"·"}
          </span>
          <span
            aria-hidden="true"
            className="font-mono tabular-nums text-muted-foreground"
            // Pending countdowns are announced politely so a screen-reader user
            // hears "2m left" tick toward expiry without a barrage.
            {...(state === "pending_enrollment"
              ? { "aria-live": "polite" as const }
              : {})}
          >
            {tail.text}
          </span>
        </>
      ) : null}
    </span>
  );
}

/**
 * StatusDot renders a filled circle; we adapt its glyph by overlaying a ring
 * (half / hollow). For `filled` we use the dot as-is. For `half` (◐ awaiting)
 * and `hollow` (◯ archived) we render a ring + partial/empty fill via border
 * utilities so the three lifecycle "shapes" read distinctly at a glance.
 */
function ConnectionDot({
  tone,
  glyph,
  pulseOnChange,
}: {
  tone: StatusTone;
  glyph: BadgeShape["glyph"];
  pulseOnChange: boolean;
}) {
  if (glyph === "filled") {
    return <StatusDot tone={tone} pulseOnChange={pulseOnChange} />;
  }
  if (glyph === "hollow") {
    // ◯ — a hollow ring in the muted tone.
    return (
      <span
        aria-hidden="true"
        className="inline-block size-2 shrink-0 rounded-full border-2 border-muted-foreground"
      />
    );
  }
  // ◐ — half-filled: a ringed dot with a half-fill via a clipped inner.
  return (
    <span
      aria-hidden="true"
      className="relative inline-flex size-2 shrink-0 items-center justify-center rounded-full border-2 border-info"
    >
      <span className="absolute inset-y-0 left-0 w-1/2 rounded-l-full bg-info" />
    </span>
  );
}

interface Tail {
  text: string | null;
  /** Optional fuller phrasing for the accessible label. */
  aria?: string;
  /** Optional tooltip text surfaced on the badge wrapper. */
  tooltip?: string | null;
}

function computeTail(
  state: ConnectionState,
  now: number,
  lastSeenAt?: string | null,
  expiresAt?: string | null,
  disconnectedReason?: string | null,
): Tail {
  switch (state) {
    case "pending_enrollment": {
      const left = minutesLeft(expiresAt, now);
      if (left === null) return { text: null };
      if (left <= 0)
        return { text: "code expired", aria: "enrollment code expired" };
      return { text: `${left}m left`, aria: `${left} minutes left to enroll` };
    }
    case "connected":
    case "degraded": {
      const t = shortRelative(lastSeenAt);
      if (!t) return { text: null };
      const tooltip =
        state === "degraded"
          ? "Agent quiet; the control plane is verifying reachability."
          : null;
      return { text: `last seen ${t}`, aria: `last seen ${t}`, tooltip };
    }
    case "disconnected": {
      const t = shortRelative(lastSeenAt);
      const isActiveVerify = disconnectedReason === "agent_unreachable";
      const tooltip = isActiveVerify
        ? "The control plane dialed the agent directly and got no answer."
        : "The agent stopped reporting; the site itself may still be up. Check the uptime pill.";
      if (!t) return { text: null, tooltip };
      return { text: t, aria: `since ${t}`, tooltip };
    }
    case "revoked":
    case "archived":
      return { text: null };
  }
}

function minutesLeft(expiresAt: string | null | undefined, now: number): number | null {
  if (!expiresAt) return null;
  const expiry = Date.parse(expiresAt);
  if (Number.isNaN(expiry)) return null;
  return Math.max(0, Math.ceil((expiry - now) / 60000));
}

/** "4m" / "2h" — chip-format relative time with the trailing " ago" stripped. */
function shortRelative(iso: string | null | undefined): string | null {
  const full = relativeTime(iso);
  if (!full) return null;
  if (full === "just now") return "now";
  return full.replace(/ ago$/, "");
}
