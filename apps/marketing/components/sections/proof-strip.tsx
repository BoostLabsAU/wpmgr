import { Icon } from "@/components/ui/icon";
import { Container, Section } from "@/components/ui/primitives";
import { Stagger, StaggerItem } from "@/components/motion/stagger";
import { Reveal } from "@/components/motion/reveal";

type StatItem = {
  icon: string;
  value: string;
  label: string;
};

export function ProofStrip({
  eyebrow,
  heading,
  subhead,
  items,
}: {
  eyebrow?: string;
  heading: string;
  subhead: string;
  items: StatItem[];
}) {
  return (
    <Section tone="muted" id="proof">
      <Container>
        <Reveal>
          <div className="mx-auto max-w-2xl text-center">
            {eyebrow && (
              <span className="inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.14em] text-[var(--eyebrow)] mb-4">
                <span aria-hidden className="h-px w-6 bg-[var(--primary)]/60" />
                {eyebrow}
              </span>
            )}
            <h2 className="text-3xl font-semibold text-foreground sm:text-4xl">{heading}</h2>
            <p className="mt-4 text-lg leading-relaxed text-[var(--muted-foreground)]">{subhead}</p>
          </div>
        </Reveal>

        <Stagger className="mt-12 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {items.map((item) => (
            <StaggerItem key={item.value}>
              <div className="flex flex-col gap-2 rounded-xl border border-[var(--border)] bg-card p-6 shadow-sm">
                <Icon name={item.icon} size={20} className="text-[var(--primary)]" />
                <span
                  className="font-mono text-2xl font-medium text-foreground"
                  style={{ fontVariantNumeric: "tabular-nums" }}
                >
                  {item.value}
                </span>
                <span className="text-sm leading-relaxed text-[var(--muted-foreground)]">{item.label}</span>
              </div>
            </StaggerItem>
          ))}
        </Stagger>
      </Container>
    </Section>
  );
}
