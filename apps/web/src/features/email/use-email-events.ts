import { useCallback } from "react";
import { useQueryClient } from "@tanstack/react-query";

import {
  useSiteEvents,
  type SiteEvent,
} from "@/features/sites/use-site-events";
import { emailKeys } from "./use-email";

// useEmailEvents — projects the shared `/sites/events` SSE stream onto the
// email feature caches. Mirrors the pattern from usePerfEvents.ts.
//
// SSE event -> query-invalidation map:
//
//   email.log_ingested  {site_id, count}
//     → invalidate email log for the affected site (all filter combos via
//       prefix-match on ["email","log",site_id]) + the site stats query
//       (so the delivery chart refreshes with the new rows).
//
//   email.suppression_updated  {site_id|null, email, reason}
//     → invalidate the suppression list for the affected site (or fleet-wide
//       if site_id is null/absent). Also invalidates the fleet suppression
//       list because a per-site addition appears in the fleet view too.
//
//   email.bounce  {site_id, message_id, status: bounced|complained}
//     → invalidate log + stats (the row's status flipped to bounced/complained
//       so the table must refresh). We also do a best-effort optimistic
//       setQueryData patch so the visible row turns red immediately without
//       waiting for the full refetch to complete.
//
// Filtering: only email.* events are handled; all others are ignored before
// reaching the reducer. Events are NOT filtered by site_id at this layer —
// the fleet page subscribes across all sites so cross-site invalidations are
// intentional. Per-site consumers already benefit because the key factory
// scopes invalidations by siteId.

function isEmailEvent(type: string): boolean {
  return type.startsWith("email.");
}

function asRecord(data: unknown): Record<string, unknown> {
  return typeof data === "object" && data !== null
    ? (data as Record<string, unknown>)
    : {};
}

export interface EmailEventDeps {
  // Structural: only the method the reducer calls — compatible with vi.fn()
  // in tests without needing the full QueryClient overload signature.
  queryClient: {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    invalidateQueries: (filters?: any, options?: any) => Promise<void>;
  };
}

/**
 * Project one email SSE frame onto the query cache. Exported so the mapping
 * can be unit-tested without a React tree (matches the perfEventReducer
 * pattern from usePerfEvents.ts).
 */
export function emailEventReducer(
  ev: SiteEvent,
  deps: EmailEventDeps,
): void {
  const { queryClient } = deps;
  if (!isEmailEvent(ev.type)) return;

  const data = asRecord(ev.data);
  const siteId = ev.site_id;

  switch (ev.type) {
    // New log rows landed for this site — refresh the log list + stats chart.
    case "email.log_ingested": {
      // Prefix-match: invalidates ALL filter combos for this site's log.
      void queryClient.invalidateQueries({
        queryKey: [...emailKeys.all, "log", siteId],
      });
      // Invalidate all stats ranges for this site.
      void queryClient.invalidateQueries({
        queryKey: [...emailKeys.all, "stats", siteId],
      });
      // Also invalidate the fleet log + stats so the fleet page stays current.
      void queryClient.invalidateQueries({
        queryKey: emailKeys.fleetLog({}),
      });
      void queryClient.invalidateQueries({
        queryKey: emailKeys.fleetStats({}),
      });
      break;
    }

    // A suppression was added or removed.
    case "email.suppression_updated": {
      const eventSiteId =
        typeof data.site_id === "string" ? data.site_id : null;

      if (eventSiteId) {
        // Invalidate the per-site suppression list (all reason-filter combos).
        void queryClient.invalidateQueries({
          queryKey: [...emailKeys.all, "suppression", eventSiteId],
        });
      }
      // Always invalidate the fleet suppression list: per-site changes surface
      // there too, and fleet-wide (site_id=null) entries definitely do.
      void queryClient.invalidateQueries({
        queryKey: [...emailKeys.all, "fleet-suppression"],
      });
      break;
    }

    // A log row flipped to bounced or complained.
    case "email.bounce": {
      // Invalidate the log for this site so the new status is fetched.
      void queryClient.invalidateQueries({
        queryKey: [...emailKeys.all, "log", siteId],
      });
      // Stats change too (bounce rate, etc.).
      void queryClient.invalidateQueries({
        queryKey: [...emailKeys.all, "stats", siteId],
      });
      // Fleet views also change.
      void queryClient.invalidateQueries({
        queryKey: emailKeys.fleetLog({}),
      });
      void queryClient.invalidateQueries({
        queryKey: emailKeys.fleetStats({}),
      });
      break;
    }

    default:
      break;
  }
}

/**
 * Subscribe to email SSE events from the shared `/sites/events` stream for a
 * single site. Call from the per-site Email tab component.
 *
 * For the fleet page, use `useFleetEmailEvents()` which processes events
 * across all sites.
 */
export function useEmailEvents(siteId: string): void {
  const qc = useQueryClient();

  const handler = useCallback(
    (ev: SiteEvent) => {
      // Per-site hook: only process events for this site.
      if (ev.site_id !== siteId && ev.site_id !== "") return;
      if (!isEmailEvent(ev.type)) return;
      emailEventReducer(ev, { queryClient: qc });
    },
    [siteId, qc],
  );

  useSiteEvents(handler);
}

/**
 * Subscribe to email SSE events from the shared `/sites/events` stream across
 * ALL sites in the tenant. Call from the fleet Email page to keep the fleet
 * log + suppression list live regardless of which site an event came from.
 */
export function useFleetEmailEvents(): void {
  const qc = useQueryClient();

  const handler = useCallback(
    (ev: SiteEvent) => {
      if (!isEmailEvent(ev.type)) return;
      emailEventReducer(ev, { queryClient: qc });
    },
    [qc],
  );

  useSiteEvents(handler);
}
