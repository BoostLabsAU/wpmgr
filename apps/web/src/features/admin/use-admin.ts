import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { client } from "@wpmgr/api";

import { toast } from "@/components/toast";
import { toError } from "@/features/auth/use-auth";

// Admin domain hooks — call the hand-rolled /api/v1/admin/* endpoints.
// These routes are NOT in the generated SDK (superadmin-only; not in the
// OpenAPI spec). They use the configured Hey API client directly.

export interface AdminUser {
  id: string;
  email: string;
  name: string;
  status: "active" | "pending" | "disabled";
  email_verified: boolean;
  created_at: string;
  last_login_at?: string;
  is_superadmin: boolean;
  org_count: number;
}

export interface AdminStats {
  users: number;
  organizations: number;
  sites: number;
}

export interface AdminUserSite {
  site_id: string;
  url: string;
  name: string;
  connection_state: string;
  enrolled_at: string | null;
  site_created_at: string;
  tenant_id: string;
  tenant_name: string;
  member_role: string;
}

export const adminKeys = {
  stats: ["admin", "stats"] as const,
  users: (search: string) => ["admin", "users", search] as const,
  userSites: (userId: string) => ["admin", "user-sites", userId] as const,
} as const;

export function useAdminUserSites(userId: string | null) {
  return useQuery({
    queryKey: userId !== null ? adminKeys.userSites(userId) : (["admin", "user-sites", null] as const),
    queryFn: async () => {
      const r = await client.get({ url: `/api/v1/admin/users/${userId}/sites` });
      if (r.error) throw toError(r.error);
      return (r.data as { sites: AdminUserSite[] }).sites;
    },
    enabled: userId !== null,
    staleTime: 30_000,
  });
}

export function useAdminStats() {
  return useQuery({
    queryKey: adminKeys.stats,
    queryFn: async () => {
      const r = await client.get({ url: "/api/v1/admin/stats" });
      if (r.error) throw toError(r.error);
      return r.data as AdminStats;
    },
    staleTime: 30_000,
  });
}

export function useAdminUsers(search: string) {
  return useQuery({
    queryKey: adminKeys.users(search),
    queryFn: async () => {
      const url = search
        ? `/api/v1/admin/users?search=${encodeURIComponent(search)}&limit=100`
        : `/api/v1/admin/users?limit=100`;
      const r = await client.get({ url });
      if (r.error) throw toError(r.error);
      return (r.data as { items: AdminUser[] }).items;
    },
    staleTime: 30_000,
  });
}

interface KeptOrg {
  id: string;
  name: string;
  site_count: number;
}

interface DeleteUserResult {
  deleted_orgs: number;
  kept_orgs_with_sites: KeptOrg[];
}

export function useAdminDeleteUser() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (userId: string) => {
      const r = await client.delete({ url: `/api/v1/admin/users/${userId}` });
      if (r.error) throw toError(r.error);
      return r.data as DeleteUserResult;
    },
    onSuccess: (res) => {
      void qc.invalidateQueries({ queryKey: ["admin", "users"] });
      void qc.invalidateQueries({ queryKey: adminKeys.stats });
      const kept = res?.kept_orgs_with_sites ?? [];
      if (kept.length > 0) {
        // Orphaned orgs that still own sites are kept, not auto-deleted — the
        // sites now belong to an org with no members. Use a sticky destructive
        // toast so the operator explicitly acknowledges and can reassign or
        // remove them.
        const names = kept
          .map((o) => `${o.name} (${o.site_count} site${o.site_count === 1 ? "" : "s"})`)
          .join(", ");
        toast.destructive("User deleted, but some orgs still own sites", {
          description: `Kept and now has no members: ${names}. Reassign or remove these orgs.`,
          action: { label: "Got it", onClick: () => {} },
        });
        return;
      }
      const removed = res?.deleted_orgs ?? 0;
      toast.success(
        removed > 0
          ? `User deleted; removed ${removed} empty org${removed === 1 ? "" : "s"}`
          : "User deleted",
      );
    },
    onError: (err: Error) =>
      toast.error("Delete failed", { description: err.message }),
  });
}

export function useAdminSetStatus() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({
      userId,
      status,
    }: {
      userId: string;
      status: "active" | "disabled";
    }) => {
      const r = await client.patch({
        url: `/api/v1/admin/users/${userId}`,
        body: { status },
        headers: { "Content-Type": "application/json" },
      });
      if (r.error) throw toError(r.error);
      return r.data as AdminUser;
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ["admin", "users"] });
    },
    onError: (err: Error) =>
      toast.error("Status update failed", { description: err.message }),
  });
}

export function useAdminResendVerification() {
  return useMutation({
    mutationFn: async (userId: string) => {
      const r = await client.post({
        url: `/api/v1/admin/users/${userId}/resend-verification`,
      });
      if (r.error) throw toError(r.error);
    },
    onSuccess: () => toast.success("Verification email sent"),
    onError: (err: Error) =>
      toast.error("Resend failed", { description: err.message }),
  });
}
