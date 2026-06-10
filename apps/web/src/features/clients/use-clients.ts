// Agency Clients domain hooks (m63 Foundation).
//
// Pattern mirrors use-destinations.ts + use-email.ts: typed key factory,
// useQuery hooks that unwrap {data, error} and throw toError(error), and
// useMutation hooks that invalidate affected keys in onSuccess + toast outcomes.

import {
  useQuery,
  useMutation,
  useQueryClient,
  type UseQueryResult,
  type UseMutationResult,
} from "@tanstack/react-query";
import {
  listClients,
  getClient,
  createClient,
  updateClient,
  deleteClient,
  assignSitesToClient,
  type AgencyClient,
  type AgencyClientList,
  type CreateAgencyClientRequest,
  type UpdateAgencyClientRequest,
  type AssignSitesRequest,
  type AssignSitesResponse,
} from "@wpmgr/api";

import { toError } from "@/features/auth/use-auth";
import { toast } from "@/components/toast";
import { sitesKeys } from "@/features/sites/use-sites";

export const clientsKeys = {
  all: ["clients"] as const,
  lists: () => [...clientsKeys.all, "list"] as const,
  list: () => [...clientsKeys.lists()] as const,
  detail: (id: string) => [...clientsKeys.all, "detail", id] as const,
};

// Re-export the AgencyClient type so feature components can import from this
// module without going through @wpmgr/api directly.
export type { AgencyClient, AgencyClientList };

export function useClients(): UseQueryResult<AgencyClient[], Error> {
  return useQuery({
    queryKey: clientsKeys.list(),
    queryFn: async () => {
      const { data, error } = await listClients({});
      if (error) throw toError(error);
      return data?.items ?? [];
    },
  });
}

export function useClient(clientId: string): UseQueryResult<AgencyClient, Error> {
  return useQuery({
    queryKey: clientsKeys.detail(clientId),
    queryFn: async () => {
      const { data, error, response } = await getClient({
        path: { clientId },
      });
      if (response?.status === 404)
        throw new Error("Client not found");
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response");
      return data;
    },
    enabled: !!clientId,
  });
}

export function useCreateClient(): UseMutationResult<
  AgencyClient,
  Error,
  CreateAgencyClientRequest
> {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (body) => {
      const { data, error } = await createClient({ body });
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response");
      return data;
    },
    onSuccess: (client) => {
      void queryClient.invalidateQueries({ queryKey: clientsKeys.lists() });
      toast.success(`Client "${client.name}" created`);
    },
  });
}

export function useUpdateClient(): UseMutationResult<
  AgencyClient,
  Error,
  { clientId: string; body: UpdateAgencyClientRequest }
> {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async ({ clientId, body }) => {
      const { data, error } = await updateClient({
        path: { clientId },
        body,
      });
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response");
      return data;
    },
    onSuccess: (client) => {
      void queryClient.invalidateQueries({ queryKey: clientsKeys.lists() });
      void queryClient.invalidateQueries({
        queryKey: clientsKeys.detail(client.id),
      });
      toast.success(`Client "${client.name}" updated`);
    },
  });
}

export function useDeleteClient(): UseMutationResult<void, Error, string> {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (clientId: string) => {
      const { error, response } = await deleteClient({
        path: { clientId },
      });
      if (response?.status === 404) throw new Error("Client not found");
      if (error) throw toError(error);
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: clientsKeys.lists() });
      // Sites may have had their client_id cleared (ON DELETE SET NULL) so
      // invalidate the sites lists too so the badge column updates.
      void queryClient.invalidateQueries({ queryKey: sitesKeys.lists() });
    },
  });
}

/**
 * Assign (or unassign) a batch of sites to a client.
 *
 * Pass `clientId: null` to unassign. Pass `clientId: <id>` to assign.
 * The backend enforces the 500-site batch cap; the UI limits selection too.
 */
export function useAssignSites(): UseMutationResult<
  AssignSitesResponse,
  Error,
  AssignSitesRequest
> {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (body: AssignSitesRequest) => {
      const { data, error } = await assignSitesToClient({ body });
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response");
      return data;
    },
    onSuccess: (_data, vars) => {
      // Sites list must re-fetch so client_name badges update.
      void queryClient.invalidateQueries({ queryKey: sitesKeys.lists() });
      // The affected client's site_count changes too.
      if (vars.client_id) {
        void queryClient.invalidateQueries({
          queryKey: clientsKeys.detail(vars.client_id),
        });
      }
      void queryClient.invalidateQueries({ queryKey: clientsKeys.lists() });
    },
  });
}
