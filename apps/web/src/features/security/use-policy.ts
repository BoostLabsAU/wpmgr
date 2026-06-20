import {
  useQuery,
  useMutation,
  useQueryClient,
  type UseQueryResult,
  type UseMutationResult,
} from "@tanstack/react-query";
import { client } from "@wpmgr/api";

import { toError } from "@/features/auth/use-auth";

// Security Suite Phase 3 — site-user auth policy data hooks.
//
// The policy endpoints are hand-rolled Gin routes (NOT in the generated @wpmgr/api
// SDK). We call them via `client.get/put/delete` from the configured Hey API
// client exactly as `use-hardening.ts` does for hardening/ban endpoints.
//
// Routes (handler.go:68-73):
//   GET    /api/v1/sites/{siteId}/security/policy
//   PUT    /api/v1/sites/{siteId}/security/policy
//   GET    /api/v1/sites/{siteId}/security/policy/groups
//   PUT    /api/v1/sites/{siteId}/security/policy/groups/:role
//   DELETE /api/v1/sites/{siteId}/security/policy/groups/:role
//
// The GET /policy response is FLAT (all fields at the top level, no sub-object
// wrapper). See policyDTO in handler.go:644-661.
// The PUT response is also flat; the X-Agent-Push-Warning header surfaces any
// agent-push caveat (mirroring the hardening endpoint pattern).
//
// policyGroupDTO (handler.go:735-743) uses pointer fields for optional overrides
// (Require2FA *bool, AllowedMethods []string, MinZxcvbnScore *int,
// BlockCompromised *bool, MaxAgeDays *int) so absent fields arrive as
// undefined/omitted, not as zero values.

// ---------------------------------------------------------------------------
// Domain types — matched EXACTLY to Go policyDTO json tags (handler.go:644-661)
// ---------------------------------------------------------------------------

/** Flat policy shape returned by GET /security/policy and sent by PUT. */
export interface SiteSecurityPolicy {
  two_factor_enabled: boolean;
  two_factor_methods: string[];
  two_factor_required_roles: string[];
  two_factor_grace_logins: number;
  two_factor_remember_device_days: number;
  block_xmlrpc_for_2fa_users: boolean;
  password_min_zxcvbn_score: number;
  password_min_zxcvbn_roles: string[];
  password_block_compromised: boolean;
  password_reuse_block_count: number;
  password_max_age_days: number;
  password_expiry_roles: string[];
  hide_backend_enabled: boolean;
  hide_backend_slug: string;
  hide_backend_redirect: string;
  updated_at?: string;
}

/** The default policy a newly-created site gets (all features off). */
export const DEFAULT_POLICY: SiteSecurityPolicy = {
  two_factor_enabled: false,
  two_factor_methods: ["totp", "email", "backup"],
  two_factor_required_roles: [],
  two_factor_grace_logins: 3,
  two_factor_remember_device_days: 30,
  block_xmlrpc_for_2fa_users: true,
  password_min_zxcvbn_score: 0,
  password_min_zxcvbn_roles: [],
  password_block_compromised: false,
  password_reuse_block_count: 0,
  password_max_age_days: 0,
  password_expiry_roles: [],
  hide_backend_enabled: false,
  hide_backend_slug: "",
  hide_backend_redirect: "",
};

/**
 * When an operator first enables 2FA, nudge them toward a sensible default:
 * administrators-only, TOTP + backup codes.
 * This is the suggested setup — fully editable before saving.
 */
export const TFA_ENABLE_NUDGE = {
  two_factor_required_roles: ["administrator"],
  two_factor_methods: ["totp", "backup"],
} as const;

/** Per-role group override. Maps to policyGroupDTO in handler.go:735-743. */
export interface PolicyGroup {
  role: string;
  require_2fa?: boolean | null;
  allowed_methods?: string[] | null;
  min_zxcvbn_score?: number | null;
  block_compromised?: boolean | null;
  max_age_days?: number | null;
  created_at?: string;
}

/** Groups list response: { items: policyGroupDTO[] } (handler.go:745-747) */
export interface PolicyGroupList {
  items: PolicyGroup[];
}

/** Payload for PUT /security/policy, and the response body. */
export interface PolicySaveResponse {
  policy: SiteSecurityPolicy;
  /** Optional caveat from the X-Agent-Push-Warning header. */
  detail?: string;
}

/** Payload for PUT /security/policy/groups/:role */
export interface PolicyGroupSaveResponse {
  group: PolicyGroup;
  detail?: string;
}

// ---------------------------------------------------------------------------
// Slug validation — client-side, matching the design spec (§1.1):
// ^[a-z0-9-]{4,64}$  and must not be a reserved path
// ---------------------------------------------------------------------------

const SLUG_REGEX = /^[a-z0-9-]{4,64}$/;

const RESERVED_SLUGS = new Set([
  "wp-login",
  "wp-admin",
  "wp-content",
  "wp-includes",
  "xmlrpc",
  "feed",
  "wp-json",
  "admin",
  "login",
]);

/**
 * Validates a hide-backend slug.
 * Returns null when valid, or a human-readable error string when invalid.
 */
export function validateHideBackendSlug(slug: string): string | null {
  const trimmed = slug.trim();
  if (!trimmed) return "Slug is required when hide login is enabled.";
  if (!SLUG_REGEX.test(trimmed)) {
    return "Slug must be 4-64 characters and contain only lowercase letters, digits, and hyphens.";
  }
  if (RESERVED_SLUGS.has(trimmed)) {
    return `"${trimmed}" is a reserved WordPress path and cannot be used as the login slug.`;
  }
  return null;
}

/**
 * Validates a hide-backend redirect URL (optional field).
 * Returns null when valid (or empty), or an error string.
 */
export function validateHideBackendRedirect(redirect: string): string | null {
  const trimmed = redirect.trim();
  if (!trimmed) return null; // empty means 404 — allowed
  // Must be an absolute or root-relative URL.
  if (!trimmed.startsWith("/") && !trimmed.startsWith("http://") && !trimmed.startsWith("https://")) {
    return "Redirect must be a root-relative path (e.g. /home) or an absolute URL.";
  }
  return null;
}

// ---------------------------------------------------------------------------
// Cache key family
// ---------------------------------------------------------------------------

export const policyKeys = {
  all: ["security-policy"] as const,
  policy: (siteId: string) => ["security-policy", siteId, "policy"] as const,
  groups: (siteId: string) => ["security-policy", siteId, "groups"] as const,
};

// ---------------------------------------------------------------------------
// Low-level helpers (mirrors use-hardening.ts pattern)
// ---------------------------------------------------------------------------

async function apiGet<T>(url: string): Promise<T> {
  const result = await client.get({ url });
  if (result.error !== undefined) throw toError(result.error);
  return result.data as T;
}

async function apiPut<T>(url: string, body: unknown): Promise<{ data: T; detail?: string }> {
  const result = await client.put({
    url,
    body,
    headers: { "Content-Type": "application/json" },
  });
  if (result.error !== undefined) throw toError(result.error);
  const detail = result.response?.headers?.get("X-Agent-Push-Warning") ?? undefined;
  return { data: result.data as T, detail: detail || undefined };
}

async function apiDelete(url: string): Promise<void> {
  const result = await client.delete({ url });
  if (result.error !== undefined) throw toError(result.error);
}

// ---------------------------------------------------------------------------
// useSiteSecurityPolicy — GET /api/v1/sites/{siteId}/security/policy
// ---------------------------------------------------------------------------

export function useSiteSecurityPolicy(
  siteId: string,
): UseQueryResult<SiteSecurityPolicy, Error> {
  return useQuery({
    queryKey: policyKeys.policy(siteId),
    queryFn: async () =>
      apiGet<SiteSecurityPolicy>(
        `/api/v1/sites/${encodeURIComponent(siteId)}/security/policy`,
      ),
    staleTime: 30_000,
  });
}

// ---------------------------------------------------------------------------
// useUpdateSiteSecurityPolicy — PUT /api/v1/sites/{siteId}/security/policy
// ---------------------------------------------------------------------------

export function useUpdateSiteSecurityPolicy(
  siteId: string,
): UseMutationResult<PolicySaveResponse, Error, SiteSecurityPolicy> {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (policy: SiteSecurityPolicy): Promise<PolicySaveResponse> => {
      const { data, detail } = await apiPut<SiteSecurityPolicy>(
        `/api/v1/sites/${encodeURIComponent(siteId)}/security/policy`,
        policy,
      );
      return { policy: data, detail };
    },
    onSuccess: (saved) => {
      queryClient.setQueryData<SiteSecurityPolicy>(
        policyKeys.policy(siteId),
        saved.policy,
      );
    },
  });
}

// ---------------------------------------------------------------------------
// usePolicyGroups — GET /api/v1/sites/{siteId}/security/policy/groups
// ---------------------------------------------------------------------------

export function usePolicyGroups(
  siteId: string,
): UseQueryResult<PolicyGroup[], Error> {
  return useQuery({
    queryKey: policyKeys.groups(siteId),
    queryFn: async () => {
      const data = await apiGet<PolicyGroupList>(
        `/api/v1/sites/${encodeURIComponent(siteId)}/security/policy/groups`,
      );
      return data.items ?? [];
    },
    staleTime: 30_000,
  });
}

// ---------------------------------------------------------------------------
// useUpsertPolicyGroup — PUT /api/v1/sites/{siteId}/security/policy/groups/:role
// ---------------------------------------------------------------------------

export function useUpsertPolicyGroup(
  siteId: string,
): UseMutationResult<PolicyGroupSaveResponse, Error, PolicyGroup> {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (group: PolicyGroup): Promise<PolicyGroupSaveResponse> => {
      const { data, detail } = await apiPut<PolicyGroup>(
        `/api/v1/sites/${encodeURIComponent(siteId)}/security/policy/groups/${encodeURIComponent(group.role)}`,
        group,
      );
      return { group: data, detail };
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: policyKeys.groups(siteId),
      });
    },
  });
}

// ---------------------------------------------------------------------------
// useDeletePolicyGroup — DELETE /api/v1/sites/{siteId}/security/policy/groups/:role
// ---------------------------------------------------------------------------

export function useDeletePolicyGroup(
  siteId: string,
): UseMutationResult<void, Error, string> {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (role: string) =>
      apiDelete(
        `/api/v1/sites/${encodeURIComponent(siteId)}/security/policy/groups/${encodeURIComponent(role)}`,
      ),
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: policyKeys.groups(siteId),
      });
    },
  });
}
