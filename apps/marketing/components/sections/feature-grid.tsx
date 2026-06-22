import { Icon } from "@/components/ui/icon";
import { Container, Section, SectionHeading } from "@/components/ui/primitives";
import { cn } from "@/lib/utils";
import { Reveal } from "@/components/motion/reveal";
import { Stagger, StaggerItem } from "@/components/motion/stagger";
import type { FeatureCluster } from "@/lib/content/types";

/** Small teal-tinted icon holder. */
function IconChip({ name }: { name: string }) {
  return (
    <span className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-[var(--primary-subtle)] text-[var(--primary-pressed)]">
      <Icon name={name} size={18} />
    </span>
  );
}

/** Single feature card within a cluster. */
function FeatureCard({
  icon,
  title,
  summary,
  bullets,
  link,
}: {
  icon: string;
  title: string;
  summary: string;
  bullets: string[];
  link?: { href: `#${string}` };
}) {
  return (
    <div
      className={cn(
        "flex h-full flex-col gap-3 rounded-xl border border-[var(--border)] bg-card p-5 shadow-sm",
        "transition-shadow duration-[var(--duration-base)] hover:shadow-md",
      )}
    >
      {/* Header */}
      <div className="flex items-center gap-3">
        <IconChip name={icon} />
        <h4 className="truncate text-base font-semibold text-foreground">{title}</h4>
      </div>

      {/* Summary */}
      <p className="line-clamp-2 text-sm leading-relaxed text-[var(--muted-foreground)]">{summary}</p>

      {/* Bullets */}
      <ul className="flex flex-1 flex-col gap-1.5">
        {bullets.map((b) => (
          <li key={b} className="flex items-start gap-2 text-sm text-[var(--muted-foreground)]">
            <Icon name="Check" size={14} className="mt-0.5 shrink-0 text-[var(--success)]" />
            <span>{b}</span>
          </li>
        ))}
      </ul>

      {/* Footer link */}
      {link && (
        <a
          href={link.href}
          className="mt-auto inline-flex items-center gap-1.5 pt-2 text-sm font-medium text-[var(--primary)] transition-colors duration-[var(--duration-fast)] hover:text-[var(--primary-hover)]"
        >
          See it in depth
          <Icon name="ArrowRight" size={14} />
        </a>
      )}
    </div>
  );
}

/** Cluster tab/accordion header. */
function ClusterHeader({ icon, name, tagline }: { icon: string; name: string; tagline: string }) {
  return (
    <div className="flex items-start gap-3 mb-6">
      <span className="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-[var(--primary)] text-[var(--primary-foreground)]">
        <Icon name={icon} size={20} />
      </span>
      <div>
        <h3 className="text-lg font-semibold text-foreground">{name}</h3>
        <p className="text-sm text-[var(--muted-foreground)]">{tagline}</p>
      </div>
    </div>
  );
}

/**
 * FeatureGrid: "Bento Grid Showcase" archetype.
 * Renders all 5 clusters stacked vertically, each with a header + card grid.
 * Cards stagger-reveal on scroll.
 */
export function FeatureGrid({
  eyebrow,
  heading,
  subhead,
  clusters,
}: {
  eyebrow: string;
  heading: string;
  subhead: string;
  clusters: FeatureCluster[];
}) {
  return (
    <Section id="features">
      <Container>
        <Reveal>
          <SectionHeading eyebrow={eyebrow} title={heading} lead={subhead} />
        </Reveal>

        <div className="mt-16 flex flex-col gap-20">
          {clusters.map((cluster) => (
            <div key={cluster.id} id={cluster.id}>
              <Reveal>
                <ClusterHeader
                  icon={cluster.icon}
                  name={cluster.name}
                  tagline={cluster.tagline}
                />
              </Reveal>
              <Stagger
                className={cn(
                  "grid gap-4",
                  cluster.features.length <= 3
                    ? "sm:grid-cols-2 lg:grid-cols-3"
                    : "sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4",
                  "auto-rows-fr",
                )}
              >
                {cluster.features.map((f) => (
                  <StaggerItem key={f.title} className="h-full">
                    <FeatureCard
                      icon={f.icon}
                      title={f.title}
                      summary={f.summary}
                      bullets={f.bullets}
                      link={f.link}
                    />
                  </StaggerItem>
                ))}
              </Stagger>
            </div>
          ))}
        </div>
      </Container>
    </Section>
  );
}
