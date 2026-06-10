import { createFileRoute } from "@tanstack/react-router";
import { Users } from "lucide-react";

import { PageHeader } from "@/components/shared/page-header";
import { ClientsList } from "@/features/clients/clients-list";

export const Route = createFileRoute("/_authed/clients/")({
  component: ClientsPage,
});

function ClientsPage() {
  return (
    <section aria-labelledby="clients-heading" className="space-y-6">
      <PageHeader
        title="Clients"
        subline="Group your sites by client and filter across the Sites page"
        badges={
          <span aria-hidden="true">
            <Users className="size-4 text-[var(--color-muted-foreground)]" />
          </span>
        }
      />
      <ClientsList />
    </section>
  );
}
