import { useState, useId } from "react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  CardDescription,
} from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { PageError } from "@/components/feedback";
import type { PutEmailConfigRequest } from "@wpmgr/api";
import { useEmailConfig, usePutEmailConfig } from "./use-email";

// ---------------------------------------------------------------------------
// Routing & Fallback panel
//
// Phase 1: simple default_connection / fallback_connection text inputs + a
// basic per-FROM mapping editor. The v1 data model stores these as opaque
// strings/jsonb; the UI exposes them with minimal chrome so they're usable
// without over-designing for a Phase 2 multi-connection model.
//
// Resend + bulk-delete are Phase 4 and are intentionally absent here.
// ---------------------------------------------------------------------------

export interface EmailRoutingPanelProps {
  siteId: string;
}

export function EmailRoutingPanel({ siteId }: EmailRoutingPanelProps) {
  const configQuery = useEmailConfig(siteId);
  const save = usePutEmailConfig(siteId);

  const [defaultConnection, setDefaultConnection] = useState("");
  const [fallbackConnection, setFallbackConnection] = useState("");
  const [mappingsRaw, setMappingsRaw] = useState("{}");
  const [initialized, setInitialized] = useState(false);

  const serverConfig = configQuery.data;
  if (serverConfig && !initialized) {
    setDefaultConnection(serverConfig.default_connection ?? "");
    setFallbackConnection(serverConfig.fallback_connection ?? "");
    setMappingsRaw(
      JSON.stringify(serverConfig.mappings ?? {}, null, 2),
    );
    setInitialized(true);
  }

  const defaultId = useId();
  const fallbackId = useId();
  const mappingsId = useId();

  let mappingsParseError = "";
  let parsedMappings: Record<string, unknown> = {};
  try {
    const parsed: unknown = JSON.parse(mappingsRaw || "{}");
    if (typeof parsed === "object" && parsed !== null && !Array.isArray(parsed)) {
      parsedMappings = parsed as Record<string, unknown>;
    } else {
      mappingsParseError = "Mappings must be a JSON object.";
    }
  } catch {
    mappingsParseError = "Invalid JSON.";
  }

  function buildPayload(): PutEmailConfigRequest {
    return {
      ...(defaultConnection.trim()
        ? { default_connection: defaultConnection.trim() }
        : {}),
      ...(fallbackConnection.trim()
        ? { fallback_connection: fallbackConnection.trim() }
        : {}),
      mappings: parsedMappings,
    };
  }

  if (configQuery.isPending) {
    return (
      <div className="space-y-3">
        <Skeleton className="h-5 w-48" />
        <Skeleton className="h-9 w-full" />
        <Skeleton className="h-9 w-full" />
      </div>
    );
  }

  if (configQuery.isError) {
    return (
      <PageError
        what="Could not load routing configuration."
        why={configQuery.error?.message}
        onRetry={() => void configQuery.refetch()}
      />
    );
  }

  return (
    <div className="space-y-6">
      {/* Connection routing */}
      <Card>
        <CardHeader>
          <CardTitle>Connection routing</CardTitle>
          <CardDescription>
            Default and fallback connection identifiers (Phase 2 multi-connection
            support will expose a picker here).
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="flex flex-col gap-1.5">
            <Label htmlFor={defaultId}>Default connection</Label>
            <Input
              id={defaultId}
              type="text"
              value={defaultConnection}
              onChange={(e) => setDefaultConnection(e.target.value)}
              placeholder="default"
              disabled={save.isPending}
            />
          </div>
          <div className="flex flex-col gap-1.5">
            <Label htmlFor={fallbackId}>Fallback connection</Label>
            <Input
              id={fallbackId}
              type="text"
              value={fallbackConnection}
              onChange={(e) => setFallbackConnection(e.target.value)}
              placeholder="Leave blank for no fallback"
              disabled={save.isPending}
            />
            <p className="text-xs text-[var(--color-muted-foreground)]">
              Used when the default connection fails. Leave blank to disable
              fallback routing.
            </p>
          </div>
        </CardContent>
      </Card>

      {/* Per-FROM mappings */}
      <Card>
        <CardHeader>
          <CardTitle>Per-FROM mappings</CardTitle>
          <CardDescription>
            Map sender addresses to specific connections as a JSON object
            (e.g. {`{"alerts@example.com":"ses-primary"}`}).
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-2">
          <Label htmlFor={mappingsId}>Mappings (JSON)</Label>
          <textarea
            id={mappingsId}
            value={mappingsRaw}
            onChange={(e) => setMappingsRaw(e.target.value)}
            rows={6}
            spellCheck={false}
            className="w-full resize-y rounded-md border border-[var(--color-input)] bg-transparent px-3 py-2 font-mono text-sm text-[var(--color-foreground)] placeholder-[var(--color-muted-foreground)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-ring)] disabled:opacity-50"
            disabled={save.isPending}
            aria-invalid={mappingsParseError !== ""}
            aria-describedby={
              mappingsParseError ? `${mappingsId}-error` : undefined
            }
          />
          {mappingsParseError ? (
            <p
              id={`${mappingsId}-error`}
              role="alert"
              className="text-xs text-[var(--color-destructive)]"
            >
              {mappingsParseError}
            </p>
          ) : null}
        </CardContent>
      </Card>

      {/* Save */}
      <div className="flex items-center justify-end gap-3">
        {save.isError ? (
          <p
            role="alert"
            className="text-sm text-[var(--color-destructive)]"
          >
            {save.error.message}
          </p>
        ) : null}
        <Button
          type="button"
          disabled={save.isPending || mappingsParseError !== ""}
          onClick={() => save.mutate(buildPayload())}
        >
          {save.isPending ? "Saving…" : "Save routing"}
        </Button>
      </div>
    </div>
  );
}
