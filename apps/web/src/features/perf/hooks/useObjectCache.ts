import {
  useMutation,
  useQuery,
  useQueryClient,
  type UseQueryResult,
  type UseMutationResult,
} from "@tanstack/react-query";
import {
  getObjectCacheConfig,
  putObjectCacheConfig,
  testObjectCache,
  enableObjectCache,
  disableObjectCache,
  flushObjectCache,
  getObjectCacheStatsHistory,
  type ObjectCacheConfig,
  type ObjectCacheConfigPut,
  type ObjectCacheTestResult,
  type ObjectCacheStatsHistory,
} from "@wpmgr/api";

import { toast } from "@/components/toast";
import { toError } from "@/features/auth/use-auth";

import { perfKeys } from "../perf-keys";

// ---------------------------------------------------------------------------
// GET config
// ---------------------------------------------------------------------------

/**
 * Fetch the per-site object cache config.
 * Returns the live status fields (oc_state, oc_latency_ms, etc.) alongside
 * the editable connection fields. The password is never returned (has_password
 * flag only).
 */
export function useObjectCacheConfig(
  siteId: string,
): UseQueryResult<ObjectCacheConfig, Error> {
  return useQuery({
    queryKey: perfKeys.objectCacheConfig(siteId),
    queryFn: async () => {
      const { data, error } = await getObjectCacheConfig({ path: { siteId } });
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response from object-cache config");
      return data;
    },
    enabled: Boolean(siteId),
  });
}

// ---------------------------------------------------------------------------
// PUT config (partial update — nil-sentinel preserves stored password)
// ---------------------------------------------------------------------------

/**
 * Update the object cache configuration.
 * An empty or absent `password` field preserves the stored secret (nil-sentinel).
 * On success, patches the config cache with the authoritative server response.
 */
export function useUpdateObjectCacheConfig(
  siteId: string,
): UseMutationResult<ObjectCacheConfig, Error, ObjectCacheConfigPut> {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (body: ObjectCacheConfigPut) => {
      const { data, error } = await putObjectCacheConfig({
        path: { siteId },
        body,
      });
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response from object-cache PUT");
      return data;
    },
    onSuccess: (saved) => {
      qc.setQueryData<ObjectCacheConfig>(
        perfKeys.objectCacheConfig(siteId),
        saved,
      );
    },
    onError: (err) => {
      toast.error("Could not save object cache configuration.", {
        description: err.message,
      });
    },
  });
}

// ---------------------------------------------------------------------------
// POST test
// ---------------------------------------------------------------------------

/**
 * Run the connection test probe. The result is returned directly to the caller
 * (not cached) so the test panel can update without a query invalidation.
 */
export function useTestObjectCache(
  siteId: string,
): UseMutationResult<ObjectCacheTestResult, Error, string | undefined> {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (password?: string) => {
      const { data, error } = await testObjectCache({
        path: { siteId },
        body: password ? { password } : undefined,
      });
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response from object-cache test");
      return data;
    },
    onSuccess: () => {
      // Re-read config so last_tested_at and last_test_config_hash update.
      void qc.invalidateQueries({
        queryKey: perfKeys.objectCacheConfig(siteId),
      });
    },
  });
}

// ---------------------------------------------------------------------------
// POST enable
// ---------------------------------------------------------------------------

/**
 * Install the object-cache drop-in. The CP enforces that a passing test must
 * exist for the current config hash; the API returns 400 with a descriptive
 * error when this gate is not satisfied.
 */
export function useEnableObjectCache(
  siteId: string,
): UseMutationResult<{ ok: boolean; detail?: string }, Error, void> {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async () => {
      const { data, error } = await enableObjectCache({ path: { siteId } });
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response from object-cache enable");
      if (data.ok === false) throw new Error(data.detail || "Enable failed");
      return data;
    },
    onSuccess: () => {
      void qc.invalidateQueries({
        queryKey: perfKeys.objectCacheConfig(siteId),
      });
      toast.success("Object cache enabled.");
    },
    onError: (err) => {
      toast.error("Could not enable object cache.", { description: err.message });
    },
  });
}

// ---------------------------------------------------------------------------
// POST disable
// ---------------------------------------------------------------------------

export function useDisableObjectCache(
  siteId: string,
): UseMutationResult<{ ok: boolean; detail?: string }, Error, void> {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async () => {
      const { data, error } = await disableObjectCache({ path: { siteId } });
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response from object-cache disable");
      if (data.ok === false) throw new Error(data.detail || "Disable failed");
      return data;
    },
    onSuccess: () => {
      void qc.invalidateQueries({
        queryKey: perfKeys.objectCacheConfig(siteId),
      });
      toast.success("Object cache disabled.");
    },
    onError: (err) => {
      toast.error("Could not disable object cache.", { description: err.message });
    },
  });
}

// ---------------------------------------------------------------------------
// POST flush
// ---------------------------------------------------------------------------

export interface FlushOptions {
  scope?: "all" | "site" | "group";
  group?: string;
}

export function useFlushObjectCache(
  siteId: string,
): UseMutationResult<{ ok: boolean; detail?: string }, Error, FlushOptions | undefined> {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (opts?: FlushOptions) => {
      const { data, error } = await flushObjectCache({
        path: { siteId },
        body: opts ?? {},
      });
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response from object-cache flush");
      if (data.ok === false) throw new Error(data.detail || "Flush failed");
      return data;
    },
    onSuccess: () => {
      void qc.invalidateQueries({
        queryKey: perfKeys.objectCacheConfig(siteId),
      });
      toast.success("Object cache flushed.");
    },
    onError: (err) => {
      toast.error("Could not flush object cache.", { description: err.message });
    },
  });
}

// ---------------------------------------------------------------------------
// GET stats-history
// ---------------------------------------------------------------------------

type StatsWindow = 7 | 30 | 90;

export function useObjectCacheStatsHistory(
  siteId: string,
  days: StatsWindow = 7,
): UseQueryResult<ObjectCacheStatsHistory, Error> {
  return useQuery({
    queryKey: [...perfKeys.objectCacheStats(siteId), days],
    queryFn: async () => {
      const { data, error } = await getObjectCacheStatsHistory({
        path: { siteId },
        query: { days },
      });
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response from object-cache stats-history");
      return data;
    },
    staleTime: 5 * 60 * 1000,
    enabled: Boolean(siteId),
  });
}
