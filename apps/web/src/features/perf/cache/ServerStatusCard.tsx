import { useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { AlertTriangle, Check, Copy, Minus, RefreshCw, Server, X } from "lucide-react";

import { Button } from "@/components/ui/button";
import { toast } from "@/components/toast";

import { perfKeys } from "../perf-keys";
import type { PerfConfig } from "../types";

// Server-status card: shows the agent-reported server software + three install
// state rows (cache drop-in, WP_CACHE constant, managed .htaccess).
//
// NGINX-AWARENESS: when server_software matches /nginx|openresty/i the
// .htaccess row renders as NEUTRAL — .htaccess is an Apache concept; nginx
// serves cache pages through the PHP drop-in, so "not managed" is expected and
// correct, not an error.
//
// WP_CACHE REMEDIATION: when wp_cache_constant_set is false, a prominent copy-
// able code block shows the exact define() the operator must paste into
// wp-config.php (the agent may have been unable to write wp-config.php itself).

export interface ServerStatusCardProps {
  siteId: string;
  config: PerfConfig;
}

const WP_CACHE_DEFINE = "define('WP_CACHE', true);";

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

type RowStatus = "ok" | "error" | "neutral";

interface StatusRowProps {
  label: string;
  status: RowStatus;
  detail?: string;
}

function StatusRow({ label, status, detail }: StatusRowProps) {
  return (
    <div className="flex items-start gap-3 py-2">
      <span className="mt-0.5 shrink-0">
        {status === "ok" ? (
          <Check aria-hidden="true" className="size-4 text-green-600 dark:text-green-400" />
        ) : status === "neutral" ? (
          <Minus aria-hidden="true" className="size-4 text-muted-foreground" />
        ) : (
          <X aria-hidden="true" className="size-4 text-red-600 dark:text-red-400" />
        )}
      </span>
      <div className="min-w-0">
        <p className="text-sm font-medium text-foreground">{label}</p>
        {detail ? (
          <p className="mt-0.5 text-xs text-muted-foreground">{detail}</p>
        ) : null}
      </div>
    </div>
  );
}

export function ServerStatusCard({ siteId, config }: ServerStatusCardProps) {
  const qc = useQueryClient();
  const [copiedSnippet, setCopiedSnippet] = useState(false);
  const [copiedDefine, setCopiedDefine] = useState(false);
  const [verifying, setVerifying] = useState(false);

  const software = config.server_software?.trim();
  const isNginx = /nginx|openresty/i.test(software ?? "");

  // .htaccess is only relevant on Apache. On nginx it is neutral (not an error).
  const htaccessStatus: RowStatus = isNginx
    ? "neutral"
    : config.htaccess_managed
      ? "ok"
      : "error";
  const htaccessDetail = isNginx
    ? "Not required on nginx — pages are served by the PHP drop-in."
    : config.htaccess_managed
      ? undefined
      : "The .htaccess rules for Apache are not in place.";

  function copySnippet() {
    void navigator.clipboard.writeText(nginxSnippet()).then(() => {
      setCopiedSnippet(true);
      window.setTimeout(() => setCopiedSnippet(false), 1500);
    });
  }

  function copyDefine() {
    void navigator.clipboard.writeText(WP_CACHE_DEFINE).then(() => {
      setCopiedDefine(true);
      window.setTimeout(() => setCopiedDefine(false), 1500);
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
      {/* Header */}
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

      {/* Install-state rows */}
      <div className="divide-y divide-border rounded-lg border border-border bg-background px-4">
        {/* Web server */}
        <StatusRow
          label="Web server"
          status={software ? "ok" : "neutral"}
          detail={software ? undefined : "Not reported yet — connect the agent and click Verify."}
        />

        {/* Cache drop-in */}
        <StatusRow
          label="Cache drop-in"
          status={config.dropin_installed ? "ok" : "error"}
          detail={
            config.dropin_installed
              ? undefined
              : "The drop-in (advanced-cache.php) is not installed. Re-enable caching to restore it."
          }
        />

        {/* WP_CACHE constant */}
        <StatusRow
          label="WP_CACHE constant"
          status={config.wp_cache_constant_set ? "ok" : "error"}
          detail={
            config.wp_cache_constant_set
              ? undefined
              : "Not set — the cache cannot serve responses without it. See the fix below."
          }
        />

        {/* Managed .htaccess */}
        <StatusRow
          label="Managed .htaccess"
          status={htaccessStatus}
          detail={htaccessDetail}
        />
      </div>

      {/* WP_CACHE remediation block */}
      {!config.wp_cache_constant_set ? (
        <div className="space-y-2 rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950/40">
          <div className="flex items-start gap-2">
            <AlertTriangle
              aria-hidden="true"
              className="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-400"
            />
            <div className="min-w-0 space-y-1">
              <p className="text-sm font-medium text-amber-900 dark:text-amber-200">
                WP_CACHE must be defined in wp-config.php
              </p>
              <p className="text-xs text-amber-800 dark:text-amber-300">
                Without it the cache drop-in is never loaded, so no pages are
                served from cache. The agent may not have write access to
                wp-config.php — add this line manually just above{" "}
                <span className="font-mono">
                  /* That&apos;s all, stop editing! */
                </span>
                :
              </p>
            </div>
          </div>
          <div className="flex items-center gap-2">
            <pre className="flex-1 overflow-x-auto rounded bg-amber-100 px-3 py-2 text-xs font-mono text-amber-900 dark:bg-amber-900/50 dark:text-amber-200">
              <code>{WP_CACHE_DEFINE}</code>
            </pre>
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={copyDefine}
              aria-label="Copy the WP_CACHE define"
              className="shrink-0 border-amber-300 bg-amber-50 text-amber-800 hover:bg-amber-100 dark:border-amber-700 dark:bg-amber-950/60 dark:text-amber-200 dark:hover:bg-amber-900/60"
            >
              {copiedDefine ? (
                <Check aria-hidden="true" className="size-4" />
              ) : (
                <Copy aria-hidden="true" className="size-4" />
              )}
              {copiedDefine ? "Copied" : "Copy"}
            </Button>
          </div>
        </div>
      ) : null}

      {/* nginx server-block snippet */}
      {isNginx ? (
        <div className="space-y-2 rounded-lg border border-border bg-background p-3">
          <div className="flex items-center justify-between gap-2">
            <p className="text-xs text-muted-foreground">
              nginx/openresty serves cache pages via the PHP drop-in — no
              .htaccess needed. Optionally add this to your server block for
              static-file bypass (faster), then reload nginx and click Verify.
            </p>
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={copySnippet}
              aria-label="Copy the nginx cache snippet"
              className="shrink-0"
            >
              {copiedSnippet ? (
                <Check aria-hidden="true" className="size-4" />
              ) : (
                <Copy aria-hidden="true" className="size-4" />
              )}
              {copiedSnippet ? "Copied" : "Copy"}
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
