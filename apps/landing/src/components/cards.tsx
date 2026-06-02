import { Icon } from "@/components/icon";
import { cn } from "@/lib/cn";

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

export function FeatureCard({
  icon,
  title,
  desc,
}: {
  icon: string;
  title: string;
  desc: string;
}) {
  return (
    <Card className="flex flex-col gap-3">
      <div className="flex items-center gap-3">
        <IconChip name={icon} />
        <h3 className="text-lg font-semibold text-foreground">{title}</h3>
      </div>
      <p className="text-sm leading-relaxed text-muted-foreground">{desc}</p>
    </Card>
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
