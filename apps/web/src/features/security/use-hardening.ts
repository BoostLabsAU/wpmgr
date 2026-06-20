import {
  useQuery,
  useMutation,
  useQueryClient,
  type UseQueryResult,
  type UseMutationResult,
} from "@tanstack/react-query";
import { client } from "@wpmgr/api";

import { toError } from "@/features/auth/use-auth";

// Security Suite Phase 1 — hardening config + ban list data hooks.
//
// The hardening and ban-list endpoints are hand-rolled Gin routes (NOT in the
// generated @wpmgr/api SDK). We call them via `client.get/put/post/delete`
// from the configured Hey API client exactly as `use-scan.ts` does for the
// integrity scan endpoints.
//
// The client is pre-configured (lib/api.ts):
//   - baseUrl: ""   (same-origin; Vite dev proxy / nginx in prod)
//   - credentials: "include"  (sends the HttpOnly wpmgr_session cookie)
//
// All paths begin with /api/v1 — the prefix the Vite proxy routes to the CP.

// ---------------------------------------------------------------------------
// Domain types
// ---------------------------------------------------------------------------

export type XmlrpcMode = "on" | "off" | "limited";
export type RestApiAccess = "default" | "restricted";
export type LoginIdentifier = "username" | "email" | "both";

export interface HardeningConfig {
  disable_file_editor: boolean;
  xmlrpc_mode: XmlrpcMode;
  restrict_rest_api: RestApiAccess;
  restrict_login_identifier: LoginIdentifier;
  force_unique_nickname: boolean;
  disable_author_archive_enum: boolean;
  force_ssl: boolean;
  disable_directory_browsing: boolean;
  disable_php_in_uploads: boolean;
  protect_system_files: boolean;
}

export interface HardeningResponse {
  config: HardeningConfig;
  /** Optional caveat returned by the server (e.g. "wp-config.php not writable"). */
  detail?: string;
}

export type BanType = "ip" | "range" | "user_agent";

export interface Ban {
  id: string;
  type: BanType;
  value: string;
  comment: string;
  created_at: string;
}

export interface BanList {
  items: Ban[];
}

export interface BanCreate {
  type: BanType;
  value: string;
  comment: string;
}

export interface BanCreateResponse {
  ban: Ban;
  /** Optional caveat (e.g. "server-level reload required for nginx"). */
  detail?: string;
}

// ---------------------------------------------------------------------------
// Cache key family
// ---------------------------------------------------------------------------

export const hardeningKeys = {
  all: ["hardening"] as const,
  config: (siteId: string) => ["hardening", siteId, "config"] as const,
  bans: (siteId: string) => ["hardening", siteId, "bans"] as const,
};

// ---------------------------------------------------------------------------
// Low-level authenticated helpers (mirrors use-scan.ts pattern)
// ---------------------------------------------------------------------------

async function apiGet<T>(url: string): Promise<T> {
  const result = await client.get({ url });
  if (result.error !== undefined) throw toError(result.error);
  return result.data as T;
}

async function apiPut<T>(url: string, body?: unknown): Promise<T> {
  const result = await client.put({
    url,
    body,
    headers: { "Content-Type": "application/json" },
  });
  if (result.error !== undefined) throw toError(result.error);
  return result.data as T;
}

async function apiPost<T>(url: string, body?: unknown): Promise<T> {
  const result = await client.post({
    url,
    body,
    headers: { "Content-Type": "application/json" },
  });
  if (result.error !== undefined) throw toError(result.error);
  return result.data as T;
}

async function apiDelete(url: string): Promise<void> {
  const result = await client.delete({ url });
  if (result.error !== undefined) throw toError(result.error);
}

// ---------------------------------------------------------------------------
// useHardeningConfig — GET /api/v1/sites/{siteId}/security/hardening
// ---------------------------------------------------------------------------

export function useHardeningConfig(
  siteId: string,
): UseQueryResult<HardeningResponse, Error> {
  return useQuery({
    queryKey: hardeningKeys.config(siteId),
    queryFn: async () =>
      apiGet<HardeningResponse>(
        `/api/v1/sites/${encodeURIComponent(siteId)}/security/hardening`,
      ),
    staleTime: 30_000,
  });
}

// ---------------------------------------------------------------------------
// useUpdateHardeningConfig — PUT /api/v1/sites/{siteId}/security/hardening
// ---------------------------------------------------------------------------

export function useUpdateHardeningConfig(
  siteId: string,
): UseMutationResult<HardeningResponse, Error, HardeningConfig> {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (config: HardeningConfig) =>
      apiPut<HardeningResponse>(
        `/api/v1/sites/${encodeURIComponent(siteId)}/security/hardening`,
        config,
      ),
    onSuccess: (updated) => {
      queryClient.setQueryData<HardeningResponse>(
        hardeningKeys.config(siteId),
        updated,
      );
    },
  });
}

// ---------------------------------------------------------------------------
// useBans — GET /api/v1/sites/{siteId}/security/bans
// ---------------------------------------------------------------------------

export function useBans(siteId: string): UseQueryResult<Ban[], Error> {
  return useQuery({
    queryKey: hardeningKeys.bans(siteId),
    queryFn: async () => {
      const data = await apiGet<BanList>(
        `/api/v1/sites/${encodeURIComponent(siteId)}/security/bans`,
      );
      return data.items ?? [];
    },
    staleTime: 30_000,
  });
}

// ---------------------------------------------------------------------------
// useCreateBan — POST /api/v1/sites/{siteId}/security/bans
// ---------------------------------------------------------------------------

export function useCreateBan(
  siteId: string,
): UseMutationResult<BanCreateResponse, Error, BanCreate> {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (body: BanCreate) =>
      apiPost<BanCreateResponse>(
        `/api/v1/sites/${encodeURIComponent(siteId)}/security/bans`,
        body,
      ),
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: hardeningKeys.bans(siteId),
      });
    },
  });
}

// ---------------------------------------------------------------------------
// useDeleteBan — DELETE /api/v1/sites/{siteId}/security/bans/{banId}
// ---------------------------------------------------------------------------

export function useDeleteBan(
  siteId: string,
): UseMutationResult<void, Error, string> {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (banId: string) =>
      apiDelete(
        `/api/v1/sites/${encodeURIComponent(siteId)}/security/bans/${encodeURIComponent(banId)}`,
      ),
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: hardeningKeys.bans(siteId),
      });
    },
  });
}

// ---------------------------------------------------------------------------
// Validation helpers (exported for tests and components)
// ---------------------------------------------------------------------------

/** Returns true for a valid IPv4 address. */
export function isValidIpv4(value: string): boolean {
  const trimmed = value.trim();
  return /^(\d{1,3}\.){3}\d{1,3}$/.test(trimmed) &&
    trimmed.split(".").every((octet) => {
      const n = parseInt(octet, 10);
      return n >= 0 && n <= 255;
    });
}

/** Returns true for a valid IPv4 CIDR block (e.g. 192.168.0.0/24). */
export function isValidCidr(value: string): boolean {
  const trimmed = value.trim();
  const match = /^(\d{1,3}\.){3}\d{1,3}\/(\d{1,2})$/.exec(trimmed);
  if (!match) return false;
  const prefix = parseInt(match[2]!, 10);
  const ip = trimmed.split("/")[0]!;
  return (
    prefix >= 0 &&
    prefix <= 32 &&
    ip.split(".").every((octet) => {
      const n = parseInt(octet, 10);
      return n >= 0 && n <= 255;
    })
  );
}

/** User-agent strings: non-empty, printable ASCII. */
export function isValidUserAgent(value: string): boolean {
  const trimmed = value.trim();
  return trimmed.length > 0 && trimmed.length <= 512;
}

/**
 * Validates a ban value against its declared type.
 * Returns null when valid, or a human-readable error string when invalid.
 */
export function validateBanValue(type: BanType, value: string): string | null {
  const trimmed = value.trim();
  if (!trimmed) return "Value is required.";
  if (type === "ip") {
    if (!isValidIpv4(trimmed)) {
      return "Enter a valid IPv4 address (e.g. 203.0.113.42).";
    }
  } else if (type === "range") {
    if (!isValidCidr(trimmed)) {
      return "Enter a valid IPv4 CIDR block (e.g. 203.0.113.0/24).";
    }
  } else if (type === "user_agent") {
    if (!isValidUserAgent(trimmed)) {
      return "Enter a non-empty user-agent string (max 512 characters).";
    }
  }
  return null;
}
