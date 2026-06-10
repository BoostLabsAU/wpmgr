import { useState } from "react";
import { createFileRoute } from "@tanstack/react-router";
import { Send } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";
import { EmailProviderConfig } from "@/features/email/email-provider-config";
import { EmailRoutingPanel } from "@/features/email/email-routing-panel";
import { EmailTestDialog } from "@/features/email/email-test-dialog";
import { EmailLogTable } from "@/features/email/email-log-table";
import { EmailDeliverability } from "@/features/email/email-deliverability";
import { EmailSuppressionList } from "@/features/email/email-suppression-list";
import { useEmailEvents } from "@/features/email/use-email-events";

// `/sites/$siteId/email` — per-site email management tab.
//
// Five sections (Radix-compatible Tabs):
//   1. Provider Config  — provider picker + dynamic field schema + sender
//                         identity + log settings
//   2. Routing         — default/fallback connections + per-FROM mappings
//   3. Email Log       — paginated log table with search/filter/export + detail
//                         dialog (Phase 4a: per-row Resend + multi-select bulk
//                         resend/delete)
//   4. Deliverability  — stats cards + per-day chart + per-provider breakdown
//   5. Suppressions    — suppressed address list + add/remove (Phase 4a)
//
// A "Send test email" button (always visible in the page header area) opens
// the test dialog regardless of which tab is active.
//
// Phase 4b: useEmailEvents wires up live SSE updates for this site's email
// data so the log, stats, and suppression list refresh automatically on new
// sends, bounces, and suppression changes.

export const Route = createFileRoute("/_authed/sites/$siteId/email")({
  component: EmailTab,
});

type TabValue = "config" | "routing" | "log" | "deliverability" | "suppressions";

function EmailTab() {
  const { siteId } = Route.useParams();
  const [tab, setTab] = useState<TabValue>("config");
  const [testOpen, setTestOpen] = useState(false);

  // Live updates via the shared SSE bus
  useEmailEvents(siteId);

  return (
    <section aria-labelledby="email-heading" className="px-4 pb-8 pt-6 sm:px-6">
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h2
          id="email-heading"
          className="text-lg font-semibold text-[var(--color-foreground)]"
        >
          Email
        </h2>
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={() => setTestOpen(true)}
          className="gap-1.5"
        >
          <Send aria-hidden="true" className="size-4" />
          Send test email
        </Button>
      </div>

      <Tabs value={tab} onValueChange={(v) => setTab(v as TabValue)}>
        <TabsList aria-label="Email sections">
          <TabsTrigger value="config">Provider</TabsTrigger>
          <TabsTrigger value="routing">Routing</TabsTrigger>
          <TabsTrigger value="log">Log</TabsTrigger>
          <TabsTrigger value="deliverability">Deliverability</TabsTrigger>
          <TabsTrigger value="suppressions">Suppressions</TabsTrigger>
        </TabsList>

        <div className="mt-6">
          <TabsContent value="config">
            <EmailProviderConfig siteId={siteId} />
          </TabsContent>

          <TabsContent value="routing">
            <EmailRoutingPanel siteId={siteId} />
          </TabsContent>

          <TabsContent value="log">
            <EmailLogTable siteId={siteId} />
          </TabsContent>

          <TabsContent value="deliverability">
            <EmailDeliverability siteId={siteId} />
          </TabsContent>

          <TabsContent value="suppressions">
            <EmailSuppressionList siteId={siteId} />
          </TabsContent>
        </div>
      </Tabs>

      {/* Test email dialog — mounted at the route level so it can be opened
          from any tab without unmounting when the active tab changes. */}
      <EmailTestDialog
        siteId={siteId}
        open={testOpen}
        onClose={() => setTestOpen(false)}
      />
    </section>
  );
}
