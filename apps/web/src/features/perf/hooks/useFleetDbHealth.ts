// useFleetDbHealth — fetches the tenant-level DB health aggregate from
// GET /api/v1/perf/db/fleet-health (P3.7).
//
// This is a TENANT-LEVEL query — no siteId in the path. The endpoint
// aggregates size, orphan counts, and growth data across ALL of the tenant's
// sites that have at least one scan result.
//
// The endpoint always returns HTTP 200 with a zero-value struct when no sites
// have been scanned yet (total_sites_scanned === 0). The panel detects this
// condition and renders the empty state directly — no 404 branch needed.
// staleTime is 5 minutes — the data only changes after a new per-site scan
// is run, so there is no value in aggressive re-fetching.

import { useQuery, type UseQueryResult } from "@tanstack/react-query";
import { client } from "@wpmgr/api";

import { toError } from "@/features/auth/use-auth";

import { perfKeys } from "../perf-keys";
import type { FleetDbHealth } from "../types";

const STALE_MS = 5 * 60 * 1000; // 5 minutes

/**
 * Fetch the fleet-wide database health aggregate for the current tenant.
 *
 * Always resolves to a FleetDbHealth value. When no sites have been scanned
 * yet the control plane returns a zero-value struct (total_sites_scanned === 0)
 * with HTTP 200; callers should check that field to render an empty state.
 */
export function useFleetDbHealth(): UseQueryResult<FleetDbHealth, Error> {
  return useQuery({
    queryKey: perfKeys.fleetDbHealth(),
    queryFn: async (): Promise<FleetDbHealth> => {
      const { data, error } = await client.get<FleetDbHealth, false>({
        url: "/api/v1/perf/db/fleet-health",
        credentials: "include",
      });
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response from perf/db/fleet-health");
      return data;
    },
    staleTime: STALE_MS,
  });
}
