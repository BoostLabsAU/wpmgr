// TanStack Query hook for the fleet-wide RUM aggregate.
// Endpoint: GET /api/v1/perf/rum/fleet?window_days=<>&device=<>

import { useQuery, type UseQueryResult } from "@tanstack/react-query";
import { client } from "@wpmgr/api";

import { toError } from "@/features/auth/use-auth";

import { fleetKeys } from "./fleet-keys";
import type { FleetRumResponse } from "./fleet-types";

export type DeviceFilter = "all" | "desktop" | "mobile" | "tablet";

const DEFAULT_WINDOW_DAYS = 28;
const STALE_MS = 5 * 60 * 1000; // 5 min — aggregates are expensive

export function useFleetRum(
  windowDays: number = DEFAULT_WINDOW_DAYS,
  device: DeviceFilter = "all",
): UseQueryResult<FleetRumResponse, Error> {
  return useQuery({
    queryKey: fleetKeys.rumFleet(windowDays, device),
    queryFn: async (): Promise<FleetRumResponse> => {
      const url = new URL(
        "/api/v1/perf/rum/fleet",
        window.location.origin,
      );
      url.searchParams.set("window_days", String(windowDays));
      if (device !== "all") url.searchParams.set("device", device);
      const { data, error } = await client.get<FleetRumResponse, false>({
        url: url.pathname + url.search,
        credentials: "include",
      });
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response from perf/rum/fleet");
      return data;
    },
    staleTime: STALE_MS,
  });
}

export { DEFAULT_WINDOW_DAYS };
