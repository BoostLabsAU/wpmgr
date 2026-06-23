import { Container, Section, SectionHeading } from "@/components/ui/primitives";
import { cn } from "@/lib/utils";
import { Reveal } from "@/components/motion/reveal";

type SiteStatus = "up" | "degraded" | "down";

type StatusSite = {
  name: string;
  status: SiteStatus;
  latency: string;
  uptime: string;
};

const STATUS_LABEL: Record<SiteStatus, string> = {
  up: "Up",
  degraded: "Degraded",
  down: "Down",
};

const STATUS_DOT: Record<SiteStatus, string> = {
  up: "bg-[var(--success)]",
  degraded: "bg-[var(--warning-subtle-fg)]",
  down: "bg-[oklch(55%_0.16_22)]",
};

const STATUS_BADGE: Record<SiteStatus, string> = {
  up: "bg-[var(--success-subtle)] text-[var(--success-subtle-fg)]",
  degraded: "bg-[var(--warning-subtle)] text-[var(--warning-subtle-fg)]",
  down: "bg-[oklch(95%_0.03_22)] text-[oklch(40%_0.14_22)] dark:bg-[oklch(28%_0.08_22)] dark:text-[oklch(85%_0.10_22)]",
};

/** Animated status pulse for live sites. */
function StatusPulse({ status }: { status: SiteStatus }) {
  return (
    <span className="relative inline-flex h-2.5 w-2.5 shrink-0">
      <span
        className={cn(
          "absolute inline-flex h-full w-full rounded-full opacity-60",
          status === "up" && "animate-ping bg-[var(--success)]",
          status === "degraded" && "bg-[var(--warning-subtle-fg)]",
          status === "down" && "bg-[oklch(55%_0.16_22)]",
        )}
      />
      <span className={cn("relative inline-flex h-2.5 w-2.5 rounded-full", STATUS_DOT[status])} />
    </span>
  );
}

/**
 * OpsStatus: "Real-Time Operations Landing" archetype.
 * Shows a live-status fleet mini-matrix using the success/warning/info tokens.
 * Data is illustrative (no real fetch); structured so a Phase 2 ISR fetch
 * can slot in transparently.
 */
export function OpsStatus({
  heading,
  subhead,
  sites,
}: {
  heading: string;
  subhead: string;
  sites: readonly StatusSite[];
}) {
  const upCount = sites.filter((s) => s.status === "up").length;
  const degradedCount = sites.filter((s) => s.status === "degraded").length;

  return (
    <Section tone="muted" id="ops-status">
      <Container>
        <div className="mx-auto max-w-5xl">
          <Reveal>
            <SectionHeading
              eyebrow="Live fleet overview"
              title={heading}
              lead={subhead}
            />
          </Reveal>

          <Reveal delay={0.08} className="mt-12">
            {/* Mock dashboard chrome */}
            <div className="overflow-hidden rounded-2xl border border-[var(--border)] bg-card shadow-lg">
              {/* Chrome bar */}
              <div className="flex items-center justify-between gap-4 border-b border-[var(--border)] bg-[var(--muted)]/60 px-5 py-3">
                <div className="flex items-center gap-2">
                  <span className="flex gap-1.5">
                    <span className="h-3 w-3 rounded-full bg-[var(--muted-foreground)]/20" />
                    <span className="h-3 w-3 rounded-full bg-[var(--muted-foreground)]/20" />
                    <span className="h-3 w-3 rounded-full bg-[var(--muted-foreground)]/20" />
                  </span>
                  <span className="ml-2 font-mono text-xs text-[var(--muted-foreground)]">
                    manage.wpmgr.app / sites
                  </span>
                </div>
                <div className="flex items-center gap-3 text-xs text-[var(--muted-foreground)]">
                  <span className="inline-flex items-center gap-1.5 rounded-full bg-[var(--success-subtle)] px-2 py-0.5 font-medium text-[var(--success-subtle-fg)]">
                    <span className="h-1.5 w-1.5 rounded-full bg-[var(--success)] animate-ping-once" />
                    {upCount} up
                  </span>
                  {degradedCount > 0 && (
                    <span className="inline-flex items-center gap-1.5 rounded-full bg-[var(--warning-subtle)] px-2 py-0.5 font-medium text-[var(--warning-subtle-fg)]">
                      {degradedCount} degraded
                    </span>
                  )}
                </div>
              </div>

              {/* Table header */}
              <div className="grid grid-cols-4 gap-4 border-b border-[var(--border)] bg-[var(--muted)]/30 px-5 py-2.5 text-xs font-semibold uppercase tracking-[0.08em] text-[var(--muted-foreground)]">
                <span className="col-span-2">Site</span>
                <span className="text-right">Latency</span>
                <span className="text-right">Uptime 30d</span>
              </div>

              {/* Rows */}
              <div className="divide-y divide-[var(--border)]">
                {sites.map((site, i) => (
                  <div
                    key={site.name}
                    className={cn(
                      "grid grid-cols-4 items-center gap-4 px-5 py-3.5 text-sm",
                      i % 2 === 1 && "bg-[var(--muted)]/20",
                    )}
                  >
                    {/* Name + status */}
                    <div className="col-span-2 flex items-center gap-3 min-w-0">
                      <StatusPulse status={site.status} />
                      <span className="truncate font-medium text-foreground">{site.name}</span>
                      <span
                        className={cn(
                          "hidden shrink-0 rounded-full px-2 py-0.5 text-xs font-medium sm:inline-block",
                          STATUS_BADGE[site.status],
                        )}
                      >
                        {STATUS_LABEL[site.status]}
                      </span>
                    </div>
                    {/* Latency */}
                    <span
                      className="text-right font-mono text-[var(--muted-foreground)]"
                      style={{ fontVariantNumeric: "tabular-nums" }}
                    >
                      {site.latency}
                    </span>
                    {/* Uptime */}
                    <span
                      className="text-right font-mono text-[var(--muted-foreground)]"
                      style={{ fontVariantNumeric: "tabular-nums" }}
                    >
                      {site.uptime}
                    </span>
                  </div>
                ))}
              </div>

              {/* Footer note */}
              <div className="border-t border-[var(--border)] px-5 py-3 text-xs text-[var(--muted-foreground)]">
                Sample data only. Values shown are illustrative.
              </div>
            </div>
          </Reveal>
        </div>
      </Container>
    </Section>
  );
}
