import { Button } from "@/components/ui/button";
import {
  Container,
  Eyebrow,
  Reveal,
  Section,
  SectionHeading,
} from "@/components/ui/primitives";
import { Icon } from "@/components/icon";
import { FleetHubLogo, Logo, Wordmark } from "@/components/logo";
import { ThemeToggle } from "@/components/theme-toggle";
import { ClusterFeatureCard, IconChip, ProofChip, StatChip, StepCard } from "@/components/cards";
import { useActiveCluster } from "@/lib/use-active-cluster";
import { BeforeAfterCard } from "@/components/before-after";
import { CodeSnippet } from "@/components/code-snippet";
import { FAQItem } from "@/components/faq-item";
import { RumPreview } from "@/components/rum-preview";
import {
  ENROLL,
  FAQ,
  FEATURES,
  FINAL_CTA,
  FOOTER,
  HERO,
  MEDIA,
  MEDIA_STEPS,
  NAV,
  OPEN_SOURCE,
  PERFORMANCE,
  PERFORMANCE_STEPS,
  RUM,
  SECURITY,
  SITE,
  STATS,
  TECH_STACK,
  TRUST,
} from "@/data/content";

type Cta = { label: string; href: string; variant?: "primary" | "secondary" | "ghost"; icon?: string };

/** CTA link. Trailing icons (ArrowRight) sit after the label; brand icons
 *  (Github, Star) sit before it. External links open in a new tab. */
function CTAButton({ cta, size = "md" }: { cta: Cta; size?: "md" | "lg" }) {
  const trailing = cta.icon === "ArrowRight";
  const external = cta.href.startsWith("http");
  return (
    <Button
      href={cta.href}
      variant={cta.variant ?? "primary"}
      size={size}
      {...(external ? { target: "_blank", rel: "noreferrer noopener" } : {})}
    >
      {cta.icon && !trailing ? <Icon name={cta.icon} size={size === "lg" ? 18 : 16} /> : null}
      {cta.label}
      {cta.icon && trailing ? <Icon name={cta.icon} size={size === "lg" ? 18 : 16} /> : null}
    </Button>
  );
}

/* ----------------------------------------------------------------- Nav --- */

export function NavBar() {
  return (
    <header className="sticky top-0 z-50 border-b border-border bg-background/85 backdrop-blur-md">
      <Container className="flex h-16 items-center justify-between gap-4">
        <a href="#top" className="cursor-pointer" aria-label="WPMgr home">
          <Logo />
        </a>
        <nav className="hidden items-center gap-7 md:flex">
          {NAV.links.map((l) => (
            <a
              key={l.href}
              href={l.href}
              className="cursor-pointer text-sm font-medium text-muted-foreground transition-colors duration-[var(--duration-fast)] hover:text-foreground"
            >
              {l.label}
            </a>
          ))}
        </nav>
        <div className="flex items-center gap-2">
          <a
            href={SITE.github}
            target="_blank"
            rel="noreferrer noopener"
            aria-label="WPMgr on GitHub"
            className="hidden h-9 w-9 cursor-pointer items-center justify-center rounded-[var(--radius)] border border-border bg-card text-muted-foreground transition-colors duration-[var(--duration-fast)] hover:bg-accent hover:text-accent-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--ring)] sm:inline-flex"
          >
            <Icon name="Github" size={17} />
          </a>
          <ThemeToggle />
          <Button
            href={SITE.github}
            target="_blank"
            rel="noreferrer noopener"
            variant="secondary"
            className="hidden sm:inline-flex"
          >
            Self-host it
          </Button>
          <Button href={SITE.signup}>Get started free</Button>
        </div>
      </Container>
    </header>
  );
}

/* --------------------------------------------------------------- Hero ---- */

/** A div-built dashboard window. No screenshots: a stylized sites list that
 *  mirrors what the product actually shows, using sample data labelled as such. */
function HeroPreview() {
  const rows = [
    { dot: "var(--success)", site: "shop.example.com", chip: "Backed up 2h ago", chipTone: "success", meta: "v0.12.4", note: "3 updates", noteTone: "warning" },
    { dot: "var(--success)", site: "blog.example.org", chip: "Backed up 1h ago", chipTone: "success", meta: "v0.12.4", note: "Up to date", noteTone: "muted" },
    { dot: "var(--warning-subtle-fg)", site: "studio.example.net", chip: "Backing up", chipTone: "info", meta: "v0.12.4", note: "Online", noteTone: "muted" },
  ] as const;

  const toneBg: Record<string, string> = {
    success: "bg-[var(--success-subtle)] text-[var(--success-subtle-fg)]",
    warning: "bg-[var(--warning-subtle)] text-[var(--warning-subtle-fg)]",
    info: "bg-[var(--info-subtle)] text-[var(--info-subtle-fg)]",
    muted: "bg-muted text-muted-foreground",
  };

  return (
    <div className="mx-auto w-full max-w-4xl overflow-hidden rounded-xl border border-border bg-card shadow-lg">
      <div className="flex items-center gap-2 border-b border-border bg-muted/50 px-4 py-3">
        <span className="flex gap-1.5">
          <span className="h-2.5 w-2.5 rounded-full bg-muted-foreground/30" />
          <span className="h-2.5 w-2.5 rounded-full bg-muted-foreground/30" />
          <span className="h-2.5 w-2.5 rounded-full bg-muted-foreground/30" />
        </span>
        <span className="ml-2 inline-flex items-center gap-1.5 rounded-md bg-background px-2.5 py-1 font-mono text-xs text-muted-foreground">
          <Icon name="LayoutDashboard" size={12} />
          manage.wpmgr.app
        </span>
      </div>
      <div className="flex items-center justify-between px-5 py-3.5">
        <span className="text-sm font-semibold text-foreground">Sites</span>
        <span className="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
          <span className="h-1.5 w-1.5 rounded-full bg-[var(--success)]" />
          3 connected
        </span>
      </div>
      <div className="divide-y divide-border border-t border-border">
        {rows.map((r) => (
          <div key={r.site} className="flex items-center gap-3 px-5 py-3.5">
            <span className="h-2 w-2 shrink-0 rounded-full" style={{ background: r.dot }} />
            <span className="flex-1 truncate font-mono text-sm text-foreground">{r.site}</span>
            <span className={`hidden rounded-full px-2 py-0.5 text-2xs font-medium sm:inline ${toneBg[r.chipTone]}`}>
              {r.chip}
            </span>
            <span className="hidden font-mono text-xs text-muted-foreground md:inline">{r.meta}</span>
            <span className={`rounded-full px-2 py-0.5 text-2xs font-medium ${toneBg[r.noteTone]}`}>{r.note}</span>
          </div>
        ))}
      </div>
    </div>
  );
}

export function Hero() {
  return (
    <section id="top" className="relative overflow-hidden">
      <div aria-hidden className="dot-field pointer-events-none absolute inset-0 -z-10 opacity-70" />
      <Container className="flex flex-col items-center gap-7 pt-20 pb-16 text-center sm:pt-24 lg:pt-28">
        <Reveal>
          <span className="inline-flex items-center gap-2 rounded-full border border-border bg-card px-3 py-1 text-xs font-medium text-muted-foreground">
            <span className="h-1.5 w-1.5 rounded-full bg-[var(--success)]" />
            {HERO.badge}
          </span>
        </Reveal>
        <Reveal delay={0.05}>
          <h1 className="mx-auto max-w-3xl text-4xl font-semibold tracking-[-0.018em] text-foreground sm:text-5xl">
            {HERO.heading}
          </h1>
        </Reveal>
        <Reveal delay={0.1}>
          <p className="mx-auto max-w-2xl text-lg leading-relaxed text-muted-foreground">{HERO.subhead}</p>
        </Reveal>
        <Reveal delay={0.15}>
          <div className="flex flex-col items-center gap-3 sm:flex-row">
            {HERO.ctas.map((c) => (
              <CTAButton key={c.label} cta={c} size="lg" />
            ))}
          </div>
        </Reveal>
        <Reveal delay={0.2}>
          <ul className="flex flex-col gap-x-8 gap-y-3 pt-2 sm:flex-row sm:items-center">
            {HERO.trust.map((t) => (
              <li key={t.title} className="flex items-center gap-2.5 text-left">
                <Icon name={t.icon} size={18} className="shrink-0 text-primary" />
                <span className="text-sm">
                  <span className="font-medium text-foreground">{t.title}.</span>{" "}
                  <span className="text-muted-foreground">{t.desc}</span>
                </span>
              </li>
            ))}
          </ul>
        </Reveal>
        <Reveal delay={0.25} className="w-full pt-6">
          <HeroPreview />
        </Reveal>
      </Container>
    </section>
  );
}

/* ------------------------------------------------------------- Sections -- */

export function TrustStrip() {
  return (
    <Section id="trust" tone="muted">
      <Container className="flex flex-col gap-8">
        <Reveal>
          <SectionHeading align="left" eyebrow={TRUST.eyebrow} title={TRUST.heading} lead={TRUST.subhead} />
        </Reveal>
        <Reveal>
          <div className="grid max-w-3xl gap-4 text-base leading-relaxed text-muted-foreground">
            {TRUST.bodyLines.map((b) => (
              <p key={b}>{b}</p>
            ))}
          </div>
        </Reveal>
        <Reveal>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {TRUST.chips.map((c) => (
              <ProofChip key={c.value} icon={c.icon} value={c.value} label={c.label} />
            ))}
          </div>
        </Reveal>
        <Reveal>
          <div>
            <CTAButton cta={{ ...TRUST.cta, variant: "ghost" }} />
          </div>
        </Reveal>
      </Container>
    </Section>
  );
}

/** Sticky chip rail with one chip per cluster. Highlights the active cluster
 *  (scrollspy via useActiveCluster). Works as plain anchor links without JS. */
function ClusterChipRail({ clusters, active }: {
  clusters: typeof FEATURES.clusters;
  active: string | null;
}) {
  return (
    <div className="sticky top-16 z-30 -mx-5 bg-background/85 px-5 backdrop-blur-md sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8">
      <div className="flex gap-2 overflow-x-auto py-3 [scrollbar-width:none]">
        {clusters.map((c) => {
          const isActive = active === c.id;
          return (
            <a
              key={c.id}
              href={`#${c.id}`}
              className={
                isActive
                  ? "inline-flex shrink-0 items-center gap-1.5 rounded-full border border-transparent bg-[var(--primary-subtle)] px-3 py-1.5 text-xs font-medium whitespace-nowrap text-[var(--primary-pressed)] transition-colors duration-[var(--duration-fast)]"
                  : "inline-flex shrink-0 items-center gap-1.5 rounded-full border border-border bg-card px-3 py-1.5 text-xs font-medium whitespace-nowrap text-muted-foreground transition-colors duration-[var(--duration-fast)] hover:text-foreground"
              }
            >
              <Icon name={c.icon} size={14} />
              {c.name}
            </a>
          );
        })}
      </div>
    </div>
  );
}

export function PlatformSection() {
  const clusterIds = FEATURES.clusters.map((c) => c.id);
  const active = useActiveCluster(clusterIds);

  return (
    <Section id="features">
      <Container className="flex flex-col gap-10">
        <Reveal>
          <SectionHeading align="left" eyebrow={FEATURES.eyebrow} title={FEATURES.heading} lead={FEATURES.subhead} />
        </Reveal>

        <ClusterChipRail clusters={FEATURES.clusters} active={active} />

        {FEATURES.clusters.map((cluster) => (
          <div
            key={cluster.id}
            id={cluster.id}
            className="scroll-mt-36 flex flex-col gap-6 border-t border-border pt-12 first:border-t-0 first:pt-0"
          >
            {/* Cluster header */}
            <Reveal>
              <div className="flex flex-col gap-2">
                <div className="flex items-center gap-3">
                  <IconChip name={cluster.icon} />
                  <h3 className="text-xl font-semibold text-foreground">{cluster.name}</h3>
                </div>
                <p className="max-w-2xl text-sm text-muted-foreground">{cluster.tagline}</p>
              </div>
            </Reveal>

            {/* Card grid: auto-rows-fr equalizes row heights structurally */}
            <div className="grid auto-rows-fr gap-5 sm:grid-cols-2 lg:grid-cols-3">
              {cluster.features.map((f, i) => (
                // h-full is REQUIRED here: auto-rows-fr + the motion div must
                // pass height through to the card or equal heights break.
                <Reveal key={f.title} className="h-full" delay={(i % 3) * 0.05}>
                  <ClusterFeatureCard {...f} />
                </Reveal>
              ))}
            </div>
          </div>
        ))}
      </Container>
    </Section>
  );
}

/** @deprecated Use PlatformSection. Kept as an alias so any stale import
 *  continues to compile while the rename propagates. */
export { PlatformSection as FeatureGrid };

export function MediaSpotlight() {
  return (
    <Section id="media" tone="muted">
      <Container className="grid items-center gap-12 lg:grid-cols-2">
        <Reveal className="flex flex-col gap-6">
          <Eyebrow>{MEDIA.eyebrow}</Eyebrow>
          <h2 className="text-3xl font-semibold text-foreground sm:text-4xl">{MEDIA.heading}</h2>
          <p className="text-lg leading-relaxed text-muted-foreground">{MEDIA.subhead}</p>
          <div className="grid gap-3 text-sm leading-relaxed text-muted-foreground">
            {MEDIA.bodyLines.map((b) => (
              <p key={b}>{b}</p>
            ))}
          </div>
          <div className="grid gap-3 sm:grid-cols-2">
            {MEDIA.chips.map((c) => (
              <ProofChip key={c.value} icon={c.icon} value={c.value} label={c.label} />
            ))}
          </div>
          <div>
            <CTAButton cta={{ ...MEDIA.cta, variant: "secondary" }} />
          </div>
        </Reveal>
        <Reveal delay={0.1}>
          <BeforeAfterCard
            caption={MEDIA.demo.caption}
            originalLabel={MEDIA.demo.originalLabel}
            originalBytes={MEDIA.demo.originalBytes}
            optimizedLabel={MEDIA.demo.optimizedLabel}
            optimizedBytes={MEDIA.demo.optimizedBytes}
            library={MEDIA.demo.library}
          />
        </Reveal>
      </Container>
    </Section>
  );
}

export function MediaHow() {
  return (
    <Section id="media-how">
      <Container className="flex flex-col gap-10">
        <Reveal>
          <SectionHeading align="left" eyebrow={MEDIA_STEPS.eyebrow} title={MEDIA_STEPS.heading} lead={MEDIA_STEPS.subhead} />
        </Reveal>
        <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
          {MEDIA_STEPS.steps.map((s, i) => (
            <Reveal key={s.n} delay={(i % 4) * 0.05}>
              <StepCard n={s.n} icon={s.icon} title={s.title} desc={s.desc} />
            </Reveal>
          ))}
        </div>
      </Container>
    </Section>
  );
}

export function PerformanceSpotlight() {
  return (
    <Section id="performance" tone="muted">
      <Container className="grid items-center gap-12 lg:grid-cols-2">
        <Reveal className="flex flex-col gap-6">
          <Eyebrow>{PERFORMANCE.eyebrow}</Eyebrow>
          <h2 className="text-3xl font-semibold text-foreground sm:text-4xl">{PERFORMANCE.heading}</h2>
          <p className="text-lg leading-relaxed text-muted-foreground">{PERFORMANCE.subhead}</p>
          <div className="grid gap-3 text-sm leading-relaxed text-muted-foreground">
            {PERFORMANCE.bodyLines.map((b) => (
              <p key={b}>{b}</p>
            ))}
          </div>
          <div>
            <CTAButton cta={{ ...PERFORMANCE.cta, variant: "secondary" }} />
          </div>
        </Reveal>
        <Reveal delay={0.1}>
          <div className="grid gap-3 sm:grid-cols-2">
            {PERFORMANCE.chips.map((c) => (
              <ProofChip key={c.value} icon={c.icon} value={c.value} label={c.label} />
            ))}
          </div>
        </Reveal>
      </Container>
    </Section>
  );
}

export function PerformanceHow() {
  return (
    <Section id="performance-how">
      <Container className="flex flex-col gap-10">
        <Reveal>
          <SectionHeading align="left" eyebrow={PERFORMANCE_STEPS.eyebrow} title={PERFORMANCE_STEPS.heading} lead={PERFORMANCE_STEPS.subhead} />
        </Reveal>
        <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
          {PERFORMANCE_STEPS.steps.map((s, i) => (
            <Reveal key={s.n} delay={(i % 4) * 0.05}>
              <StepCard n={s.n} icon={s.icon} title={s.title} desc={s.desc} />
            </Reveal>
          ))}
        </div>
      </Container>
    </Section>
  );
}

export function RumSection() {
  const d = RUM.demo;
  return (
    <Section id="rum" tone="muted">
      <Container className="grid items-start gap-12 lg:grid-cols-2">
        {/* Left column: copy + capability list + privacy points */}
        <Reveal className="flex flex-col gap-6">
          <Eyebrow>{RUM.eyebrow}</Eyebrow>
          <h2 className="text-3xl font-semibold text-foreground sm:text-4xl">{RUM.heading}</h2>
          <p className="text-lg leading-relaxed text-muted-foreground">{RUM.subhead}</p>
          <div className="grid gap-3 text-sm leading-relaxed text-muted-foreground">
            {RUM.bodyLines.map((b) => (
              <p key={b}>{b}</p>
            ))}
          </div>

          {/* Capability list */}
          <ul className="flex flex-col gap-3">
            {RUM.capabilities.map((cap) => (
              <li key={cap.label} className="flex items-start gap-3">
                <span className="mt-0.5 shrink-0 text-primary">
                  <Icon name={cap.icon} size={17} />
                </span>
                <div className="flex flex-col gap-0.5">
                  <span className="text-sm font-semibold text-foreground">{cap.label}</span>
                  <span className="text-sm leading-relaxed text-muted-foreground">{cap.detail}</span>
                </div>
              </li>
            ))}
          </ul>

          {/* Privacy bullets */}
          <div className="rounded-xl border border-border bg-card p-5 shadow-sm">
            <div className="mb-3 flex items-center gap-2">
              <IconChip name="EyeOff" />
              <span className="text-sm font-semibold text-foreground">Privacy by default</span>
            </div>
            <ul className="flex flex-col gap-1.5">
              {RUM.privacy.map((line) => (
                <li key={line} className="flex items-start gap-2 text-sm text-muted-foreground">
                  <Icon name="Check" size={14} className="mt-0.5 shrink-0 text-[var(--success)]" />
                  {line}
                </li>
              ))}
            </ul>
          </div>

          <div>
            <CTAButton cta={{ ...RUM.cta, variant: "secondary" }} />
          </div>
        </Reveal>

        {/* Right column: visual mock of the RUM dashboard */}
        <Reveal delay={0.1}>
          <RumPreview
            metric={d.metric}
            p75={d.p75}
            rating={d.rating}
            distribution={d.distribution}
            trend={d.trend}
            threshold={d.threshold}
            metrics={d.metrics}
          />
        </Reveal>
      </Container>
    </Section>
  );
}

export function HowItWorks() {
  return (
    <Section id="how-it-works" tone="muted">
      <Container className="flex flex-col gap-10">
        <Reveal>
          <SectionHeading align="left" eyebrow={ENROLL.eyebrow} title={ENROLL.heading} lead={ENROLL.subhead} />
        </Reveal>
        <Reveal>
          <p className="max-w-3xl text-base leading-relaxed text-muted-foreground">{ENROLL.body}</p>
        </Reveal>
        <div className="grid gap-5 sm:grid-cols-3">
          {ENROLL.steps.map((s, i) => (
            <Reveal key={s.n} delay={i * 0.05}>
              <StepCard n={s.n} icon={s.icon} title={s.title} desc={s.desc} />
            </Reveal>
          ))}
        </div>
        <Reveal>
          <div>
            <CTAButton cta={ENROLL.cta} />
          </div>
        </Reveal>
      </Container>
    </Section>
  );
}

export function Security() {
  return (
    <Section id="security">
      <Container className="flex flex-col gap-10">
        <Reveal>
          <SectionHeading align="left" eyebrow={SECURITY.eyebrow} title={SECURITY.heading} lead={SECURITY.subhead} />
        </Reveal>
        <Reveal>
          <div className="grid max-w-3xl gap-3 text-base leading-relaxed text-muted-foreground">
            {SECURITY.bodyLines.map((b) => (
              <p key={b}>{b}</p>
            ))}
          </div>
        </Reveal>
        <div className="grid gap-x-8 gap-y-7 sm:grid-cols-2 lg:grid-cols-3">
          {SECURITY.items.map((it, i) => (
            <Reveal key={it.title} delay={(i % 3) * 0.05}>
              <div className="flex flex-col gap-2.5">
                <div className="flex items-center gap-3">
                  <IconChip name={it.icon} />
                  <h3 className="text-base font-semibold text-foreground">{it.title}</h3>
                </div>
                <p className="text-sm leading-relaxed text-muted-foreground">{it.desc}</p>
              </div>
            </Reveal>
          ))}
        </div>
      </Container>
    </Section>
  );
}

export function OpenSource() {
  return (
    <Section id="open-source" tone="muted">
      <Container className="grid items-start gap-12 lg:grid-cols-2">
        <Reveal className="flex flex-col gap-6">
          <Eyebrow>{OPEN_SOURCE.eyebrow}</Eyebrow>
          <h2 className="text-3xl font-semibold text-foreground sm:text-4xl">{OPEN_SOURCE.heading}</h2>
          <p className="text-lg leading-relaxed text-muted-foreground">{OPEN_SOURCE.subhead}</p>
          <div className="grid gap-3 text-base leading-relaxed text-muted-foreground">
            {OPEN_SOURCE.bodyLines.map((b) => (
              <p key={b}>{b}</p>
            ))}
          </div>
          <div className="flex flex-col gap-3 sm:flex-row">
            {OPEN_SOURCE.ctas.map((c) => (
              <CTAButton key={c.label} cta={c} />
            ))}
          </div>
        </Reveal>
        <Reveal delay={0.1} className="flex flex-col gap-5 rounded-xl border border-border bg-card p-6 shadow-sm">
          <div className="flex flex-col gap-2">
            <span className="text-sm font-semibold text-foreground">Get the stack running</span>
            <CodeSnippet command={OPEN_SOURCE.command} />
          </div>
          <div className="flex flex-col">
            {OPEN_SOURCE.chips.map((c) => (
              <div key={c.value} className="flex items-start gap-3 border-t border-border py-3.5 first:border-t-0 first:pt-0">
                <span className="mt-0.5 text-primary">
                  <Icon name={c.icon} size={18} />
                </span>
                <div className="flex flex-col gap-0.5">
                  <span className="font-mono text-sm font-medium text-foreground">{c.value}</span>
                  <span className="text-xs leading-relaxed text-muted-foreground">{c.label}</span>
                </div>
              </div>
            ))}
          </div>
        </Reveal>
      </Container>
    </Section>
  );
}

export function TechStack() {
  return (
    <Section id="stack">
      <Container className="flex flex-col gap-10">
        <Reveal>
          <SectionHeading
            align="left"
            eyebrow={TECH_STACK.eyebrow}
            title={TECH_STACK.heading}
            lead={TECH_STACK.subhead}
          />
        </Reveal>
        <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
          {TECH_STACK.items.map((item, i) => (
            <Reveal key={item.label} delay={(i % 3) * 0.05}>
              <div className="flex flex-col gap-3 rounded-xl border border-border bg-card p-5 shadow-sm">
                <div className="flex items-center gap-3">
                  <span className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-[var(--primary-subtle)] text-[var(--primary-pressed)]">
                    <Icon name={item.icon} size={18} />
                  </span>
                  <h3 className="text-base font-semibold text-foreground">{item.label}</h3>
                </div>
                <p className="text-sm leading-relaxed text-muted-foreground">{item.blurb}</p>
              </div>
            </Reveal>
          ))}
        </div>
      </Container>
    </Section>
  );
}

export function Stats() {
  return (
    <Section id="stats">
      <Container className="flex flex-col gap-10">
        <Reveal>
          <SectionHeading align="left" eyebrow={STATS.eyebrow} title={STATS.heading} lead={STATS.subhead} />
        </Reveal>
        <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
          {STATS.items.map((s, i) => (
            <Reveal key={s.value + s.label} delay={(i % 3) * 0.05}>
              <StatChip icon={s.icon} value={s.value} label={s.label} />
            </Reveal>
          ))}
        </div>
      </Container>
    </Section>
  );
}

export function Faq() {
  return (
    <Section id="faq" tone="muted">
      <Container className="flex max-w-3xl flex-col gap-8">
        <Reveal>
          <SectionHeading align="left" eyebrow={FAQ.eyebrow} title={FAQ.heading} lead={FAQ.subhead} />
        </Reveal>
        <Reveal>
          <div className="rounded-xl border border-border bg-card px-6 shadow-sm">
            {FAQ.items.map((f) => (
              <FAQItem key={f.q} q={f.q} a={f.a} />
            ))}
          </div>
        </Reveal>
      </Container>
    </Section>
  );
}

export function FinalCta() {
  return (
    <section id="get-started" className="relative overflow-hidden">
      <div aria-hidden className="dot-field pointer-events-none absolute inset-0 -z-10 opacity-70" />
      <Container className="flex flex-col items-center gap-6 py-20 text-center sm:py-24 lg:py-28">
        <Reveal>
          <h2 className="mx-auto max-w-2xl text-3xl font-semibold text-foreground sm:text-4xl">{FINAL_CTA.heading}</h2>
        </Reveal>
        <Reveal delay={0.05}>
          <p className="mx-auto max-w-2xl text-lg leading-relaxed text-muted-foreground">{FINAL_CTA.subhead}</p>
        </Reveal>
        <Reveal delay={0.1}>
          <div className="flex flex-col items-center gap-3 sm:flex-row">
            {FINAL_CTA.ctas.map((c) => (
              <CTAButton key={c.label} cta={c} size="lg" />
            ))}
          </div>
        </Reveal>
        <Reveal delay={0.15}>
          <p className="text-sm text-muted-foreground">{FINAL_CTA.body}</p>
        </Reveal>
      </Container>
    </section>
  );
}

export function Footer() {
  return (
    <footer className="border-t border-border bg-background">
      <Container className="flex flex-col gap-10 py-14">
        <div className="flex flex-col gap-8 lg:flex-row lg:items-start lg:justify-between">
          <div className="flex max-w-md flex-col gap-3">
            <span className="inline-flex items-center gap-2.5">
              <FleetHubLogo size={24} />
              <Wordmark />
            </span>
            <p className="text-sm text-muted-foreground">{FOOTER.tagline}</p>
          </div>
          <nav className="flex flex-wrap gap-x-10 gap-y-3">
            {FOOTER.links.map((l) => (
              <a
                key={l.label}
                href={l.href}
                target="_blank"
                rel="noreferrer noopener"
                className="inline-flex items-center gap-2 text-sm font-medium text-muted-foreground transition-colors duration-[var(--duration-fast)] hover:text-foreground"
              >
                <Icon name={l.icon} size={16} />
                {l.label}
              </a>
            ))}
          </nav>
        </div>
        <div className="flex flex-col gap-3 border-t border-border pt-6">
          {FOOTER.bodyLines.map((b) => (
            <p key={b} className="max-w-3xl text-xs leading-relaxed text-muted-foreground">
              {b}
            </p>
          ))}
        </div>
      </Container>
    </footer>
  );
}
