import { Icon } from "@/components/icon";
import { cn } from "@/lib/cn";
import type { ClusterFeature, FeatureVisual } from "@/data/content";
import { VISUALS } from "@/components/feature-visuals";

/** Soft hairline card. The ONLY bordered/rounded surface in a block; inner
 *  content groups with spacing and dividers, never a second nested card. */
export function Card({
  className,
  children,
}: {
  className?: string;
  children: React.ReactNode;
}) {
  return (
    <div className={cn("rounded-xl border border-border bg-card p-6 shadow-sm", className)}>
      {children}
    </div>
  );
}

/** Small teal-tinted icon holder. Sits beside a heading, never a big tile
 *  stacked above it (impeccable boxed-icon-tile guard). Teal icon on a same-hue
 *  teal-subtle fill, at --primary-pressed for a comfortable contrast margin. */
export function IconChip({ name }: { name: string }) {
  return (
    <span className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-[var(--primary-subtle)] text-[var(--primary-pressed)]">
      <Icon name={name} size={18} />
    </span>
  );
}

export function StepCard({
  n,
  icon,
  title,
  desc,
}: {
  n: string;
  icon: string;
  title: string;
  desc: string;
}) {
  return (
    <Card className="flex flex-col gap-3">
      <div className="flex items-center justify-between">
        <span
          className="font-mono text-2xl font-medium text-primary"
          style={{ fontVariantNumeric: "tabular-nums" }}
        >
          {n}
        </span>
        <Icon name={icon} size={20} className="text-muted-foreground" />
      </div>
      <h3 className="text-base font-semibold text-foreground">{title}</h3>
      <p className="text-sm leading-relaxed text-muted-foreground">{desc}</p>
    </Card>
  );
}

/** Icon + mono value + supporting label. Used in trust, media, and open-source
 *  proof rows. Single hairline surface. */
export function ProofChip({
  icon,
  value,
  label,
}: {
  icon: string;
  value: string;
  label: string;
}) {
  return (
    <div className="flex items-start gap-3 rounded-[var(--radius)] border border-border bg-card p-4">
      <span className="mt-0.5 text-primary">
        <Icon name={icon} size={18} />
      </span>
      <div className="flex flex-col gap-0.5">
        <span
          className="font-mono text-sm font-medium text-foreground"
          style={{ fontVariantNumeric: "tabular-nums" }}
        >
          {value}
        </span>
        <span className="text-xs leading-relaxed text-muted-foreground">{label}</span>
      </div>
    </div>
  );
}

/** Bigger numeric proof for the day-one stats band. */
export function StatChip({
  icon,
  value,
  label,
}: {
  icon: string;
  value: string;
  label: string;
}) {
  return (
    <Card className="flex flex-col gap-2">
      <Icon name={icon} size={20} className="text-primary" />
      <span
        className="font-mono text-2xl font-medium text-foreground"
        style={{ fontVariantNumeric: "tabular-nums" }}
      >
        {value}
      </span>
      <span className="text-sm leading-relaxed text-muted-foreground">{label}</span>
    </Card>
  );
}

/** Uniform platform-index card used in PlatformSection cluster grids.
 *
 *  Structure:
 *    - Header: IconChip + h4 title (single line, truncate seatbelt)
 *    - Summary: p.line-clamp-2 seatbelt
 *    - Bullets: flex-1 ul so footers align regardless of 2 vs 4 bullets
 *    - Visual (only when visual prop set): block from VISUALS registry
 *    - Footer link (only when link prop set): "See it in depth" + ArrowRight
 *
 *  The Reveal wrapper in PlatformSection MUST carry className="h-full" or
 *  equal-height rows silently break (auto-rows-fr requires the motion div to
 *  pass height through to the card). */
export function ClusterFeatureCard({
  icon,
  title,
  summary,
  bullets,
  link,
  visual,
}: ClusterFeature) {
  const Visual = visual ? VISUALS[visual as FeatureVisual] : null;
  return (
    <Card className="flex h-full flex-col gap-3 p-5">
      {/* Header */}
      <div className="flex items-center gap-3">
        <IconChip name={icon} />
        <h4 className="truncate text-base font-semibold text-foreground">{title}</h4>
      </div>

      {/* Summary */}
      <p className="line-clamp-2 text-sm leading-relaxed text-muted-foreground">{summary}</p>

      {/* Bullets: flex-1 absorbs height differences so footer links align */}
      <ul className="flex flex-1 flex-col gap-1.5">
        {bullets.map((b) => (
          <li key={b} className="flex items-start gap-2 text-sm text-muted-foreground">
            <Icon name="Check" size={14} className="mt-0.5 shrink-0 text-[var(--success)]" />
            <span>{b}</span>
          </li>
        ))}
      </ul>

      {/* Mini data visual (only on cards with a deep-dive link) */}
      {Visual && (
        <div className="pt-1">
          <Visual />
        </div>
      )}

      {/* Footer deep-dive link */}
      {link && (
        <a
          href={link.href}
          className="mt-auto inline-flex items-center gap-1.5 pt-2 text-sm font-medium text-primary transition-colors duration-[var(--duration-fast)] hover:text-[var(--primary-hover)]"
        >
          See it in depth
          <Icon name="ArrowRight" size={14} />
        </a>
      )}
    </Card>
  );
}
