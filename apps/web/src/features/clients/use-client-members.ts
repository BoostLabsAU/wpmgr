// Client portal member management hooks (m66 Phase 3).
//
// Mirrors the sharing hooks pattern: typed key factory, useQuery + useMutation
// with typed error handling. Used by the "Portal access" tab on the client
// detail page.

import {
  useQuery,
  useMutation,
  useQueryClient,
  type UseQueryResult,
  type UseMutationResult,
} from "@tanstack/react-query";
import {
  listClientMembers,
  addClientMember,
  removeClientMember,
  listClientInvitations,
  revokeClientInvitation,
  regenerateClientInvitation,
  type ClientMember,
  type ClientMemberList,
  type ClientMemberCreateRequest,
  type ClientMemberInviteResult,
  type ClientInvitation,
  type ClientInvitationList,
} from "@wpmgr/api";

import { toError } from "@/features/auth/use-auth";
import { toast } from "@/components/toast";

// Re-export types for route files.
export type {
  ClientMember,
  ClientMemberList,
  ClientMemberCreateRequest,
  ClientMemberInviteResult,
  ClientInvitation,
  ClientInvitationList,
};

// ---------------------------------------------------------------------------
// Query key factory
// ---------------------------------------------------------------------------

export const clientMembersKeys = {
  all: (clientId: string) => ["clients", clientId, "members"] as const,
  list: (clientId: string) =>
    [...clientMembersKeys.all(clientId), "list"] as const,
  invitations: (clientId: string) =>
    [...clientMembersKeys.all(clientId), "invitations"] as const,
};

// ---------------------------------------------------------------------------
// Read hooks
// ---------------------------------------------------------------------------

export function useClientMembers(
  clientId: string,
): UseQueryResult<ClientMember[], Error> {
  return useQuery({
    queryKey: clientMembersKeys.list(clientId),
    queryFn: async () => {
      const { data, error } = await listClientMembers({
        path: { clientId },
      });
      if (error) throw toError(error);
      return data?.items ?? [];
    },
    enabled: !!clientId,
  });
}

export function useClientInvitations(
  clientId: string,
): UseQueryResult<ClientInvitation[], Error> {
  return useQuery({
    queryKey: clientMembersKeys.invitations(clientId),
    queryFn: async () => {
      const { data, error } = await listClientInvitations({
        path: { clientId },
      });
      if (error) throw toError(error);
      return data?.items ?? [];
    },
    enabled: !!clientId,
  });
}

// ---------------------------------------------------------------------------
// Mutation hooks
// ---------------------------------------------------------------------------

export function useAddClientMember(
  clientId: string,
): UseMutationResult<ClientMemberInviteResult, Error, ClientMemberCreateRequest> {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (body) => {
      const { data, error, response } = await addClientMember({
        path: { clientId },
        body,
      });
      if (response?.status === 409) {
        throw new Error("This person already has portal access.");
      }
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response");
      return data;
    },
    onSuccess: (result) => {
      void queryClient.invalidateQueries({
        queryKey: clientMembersKeys.list(clientId),
      });
      void queryClient.invalidateQueries({
        queryKey: clientMembersKeys.invitations(clientId),
      });
      if (result.invited) {
        toast.success(`Invitation sent to ${result.email}`);
      } else {
        toast.success(`Portal access granted to ${result.email}`);
      }
    },
  });
}

export function useRemoveClientMember(
  clientId: string,
): UseMutationResult<void, Error, string> {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (userId: string) => {
      const { error, response } = await removeClientMember({
        path: { clientId, userId },
      });
      if (response?.status === 404) throw new Error("Member not found.");
      if (error) throw toError(error);
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: clientMembersKeys.list(clientId),
      });
      toast.success("Portal access revoked");
    },
  });
}

export function useRevokeClientInvitation(
  clientId: string,
): UseMutationResult<void, Error, string> {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (invitationId: string) => {
      const { error, response } = await revokeClientInvitation({
        path: { clientId, invitationId },
      });
      if (response?.status === 404) throw new Error("Invitation not found.");
      if (error) throw toError(error);
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: clientMembersKeys.invitations(clientId),
      });
      toast.success("Invitation revoked");
    },
  });
}

export function useRegenerateClientInvitation(
  clientId: string,
): UseMutationResult<ClientMemberInviteResult, Error, string> {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (invitationId: string) => {
      const { data, error } = await regenerateClientInvitation({
        path: { clientId, invitationId },
      });
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response");
      return data;
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: clientMembersKeys.invitations(clientId),
      });
      toast.success("Invitation link refreshed");
    },
  });
}
