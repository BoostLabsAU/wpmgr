import { useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { Check, Copy, RefreshCw, Server } from "lucide-react";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { toast } from "@/components/toast";

import { perfKeys } from "../perf-keys";
import type { PerfConfig } from "../types";

// Server-status card: shows the agent-reported server software + three install
// badges (cache drop-in, WP_CACHE constant, managed .htaccess). For nginx —
// which can't be configured via .htaccess — it shows a copy-paste location
// snippet and a Verify action that re-reads the config + stats so the operator
// can confirm the manual config took effect.

export interface ServerStatusCardProps {
  siteId: string;
  config: PerfConfig;
}

function nginxSnippet(): string {
  return [
    "# WPMgr page cache — serve cached HTML when present",
    "set $wpmgr_cache_file '';",
    'if ($request_method = GET) {',
    '    set $wpmgr_cache_file "/wp-content/cache/wpmgr/$host$request_uri/index.html";',
    "}",
    'if (-f "$document_root$wpmgr_cache_file") {',
    "    rewrite .* $wpmgr_cache_file last;",
    "}",
  ].join("\n");
}

export function ServerStatusCard({ siteId, config }: ServerStatusCardProps) {
  const qc = useQueryClient();
  const [copied, setCopied] = useState(false);
  const [verifying, setVerifying] = useState(false);

  const software = config.server_software?.trim();
  const isNginx = (software ?? "").toLowerCase().includes("nginx");

  const badges: Array<{ label: string; ok: boolean }> = [
    { label: "Cache drop-in", ok: config.dropin_installed },
    { label: "WP_CACHE constant", ok: config.wp_cache_constant_set },
    { label: "Managed .htaccess", ok: config.htaccess_managed },
  ];

  function copySnippet() {
    void navigator.clipboard.writeText(nginxSnippet()).then(() => {
      setCopied(true);
      window.setTimeout(() => setCopied(false), 1500);
    });
  }

  async function verify() {
    setVerifying(true);
    try {
      await Promise.all([
        qc.invalidateQueries({ queryKey: perfKeys.config(siteId) }),
        qc.invalidateQueries({ queryKey: perfKeys.stats(siteId) }),
      ]);
      toast.info("Re-checked the server.", {
        description: "Refreshed the install state and cache stats.",
      });
    } finally {
      setVerifying(false);
    }
  }

  return (
    <section className="space-y-3 rounded-xl border border-border bg-card p-5 text-card-foreground shadow-sm">
      <div className="flex items-start justify-between gap-3">
        <div className="flex items-center gap-2">
          <Server aria-hidden="true" className="size-4 text-muted-foreground" />
          <div>
            <h3 className="text-sm font-semibold text-foreground">
              Server status
            </h3>
            <p className="mt-0.5 text-xs text-muted-foreground">
              {software ? (
                <>
                  Running <span className="font-mono">{software}</span>
                </>
              ) : (
                "Web server not reported yet"
              )}
            </p>
          </div>
        </div>
        <Button
          type="button"
          variant="ghost"
          size="sm"
          onClick={() => void verify()}
          disabled={verifying}
        >
          <RefreshCw
            aria-hidden="true"
            className={verifying ? "size-4 animate-spin" : "size-4"}
          />
          Verify
        </Button>
      </div>

      <div className="flex flex-wrap gap-2">
        {badges.map((b) => (
          <Badge key={b.label} variant={b.ok ? "success" : "muted"}>
            {b.ok ? (
              <Check aria-hidden="true" className="size-3" />
            ) : null}
            {b.label}
            {b.ok ? "" : " — not set"}
          </Badge>
        ))}
      </div>

      {isNginx ? (
        <div className="space-y-2 rounded-lg border border-border bg-background p-3">
          <div className="flex items-center justify-between gap-2">
            <p className="text-xs text-muted-foreground">
              nginx can't use .htaccess. Add this to your server block, then
              reload nginx and click Verify.
            </p>
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={copySnippet}
              aria-label="Copy the nginx cache snippet"
              className="shrink-0"
            >
              {copied ? (
                <Check aria-hidden="true" className="size-4" />
              ) : (
                <Copy aria-hidden="true" className="size-4" />
              )}
              {copied ? "Copied" : "Copy"}
            </Button>
          </div>
          <pre className="overflow-x-auto rounded bg-muted p-3 text-xs leading-relaxed text-foreground">
            <code>{nginxSnippet()}</code>
          </pre>
        </div>
      ) : null}
    </section>
  );
}
