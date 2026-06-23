// PricingPage: C3-compliant.
// Leads with free/open-source/self-host. Hosted tiers shown as coming soon
// with NO price numbers. SoftwareApplication JSON-LD with NO Offer price.
import type { Metadata } from "next";
import Link from "next/link";
import { Icon } from "@/components/ui/icon";
import { Container, Section, SectionHeading, Card } from "@/components/ui/primitives";
import { Reveal } from "@/components/motion/reveal";
import { Stagger, StaggerItem } from "@/components/motion/stagger";
import { FAQ } from "@/components/sections/faq";
import { CTABand } from "@/components/sections/cta-band";
import { buildMetadata, buildFAQPageLd, buildBreadcrumbLd, buildSoftwareApplicationLd } from "@/lib/seo";
import { JsonLd } from "@/lib/json-ld";
import { SITE_CONFIG } from "@/lib/site";
import { cn } from "@/lib/utils";

export const metadata: Metadata = buildMetadata({
  title: "WordPress Management Pricing | WPMgr",
  description:
    "WPMgr is free, open-source, and self-hostable with no per-site fee. Run the full control plane on your own infrastructure. A hosted cloud version is coming soon.",
  canonical: "/pricing/",
});

// Self-hosted feature checklist
const SELF_HOST_FEATURES = [
  { icon: "DatabaseBackup", label: "Incremental backups and point-in-time restore" },
  { icon: "RefreshCw", label: "Safe bulk updates with auto-revert" },
  { icon: "Activity", label: "Uptime and health monitoring" },
  { icon: "ImageDown", label: "Media Optimizer (AVIF and WebP)" },
  { icon: "Zap", label: "Full-page caching and unused CSS removal" },
  { icon: "HardDrive", label: "Redis object cache integration" },
  { icon: "BarChart2", label: "Real User Monitoring (Core Web Vitals)" },
  { icon: "DatabaseZap", label: "Database cleaner with orphan classification" },
  { icon: "ShieldCheck", label: "Security hardening suite and vulnerability scanner" },
  { icon: "Smartphone", label: "Two-factor authentication for site users" },
  { icon: "ScrollText", label: "White-label client reports" },
  { icon: "MailCheck", label: "Per-site email and delivery log" },
  { icon: "Users", label: "Team access control and audit log" },
  { icon: "ServerCog", label: "Unlimited sites, no per-site fee" },
];

// Coming-soon hosted tiers: named by audience, no price numbers
const HOSTED_TIERS = [
  {
    name: "Free",
    audience: "Personal projects and evaluation",
    description: "Everything in the self-hosted release, hosted by us with a generous site limit. Get started instantly without running your own server.",
    highlight: false,
  },
  {
    name: "Starter",
    audience: "Freelancers and small portfolios",
    description: "The full feature set with a higher site limit, email alerts, and priority queue for backup and update jobs.",
    highlight: false,
  },
  {
    name: "Agency",
    audience: "Agencies and growing teams",
    description: "Everything in Starter plus white-label reports, per-site client portals, team member seats, and OIDC SSO.",
    highlight: true,
  },
  {
    name: "Scale",
    audience: "Large fleets and hosting providers",
    description: "Everything in Agency with an expanded site limit, dedicated backup storage, priority support, and API rate limit increases.",
    highlight: false,
  },
];

const PRICING_FAQ = [
  {
    q: "Is the self-hosted version really free?",
    a: "Yes. WPMgr is open-source under the AGPL. You can run the full control plane on your own infrastructure at no cost, with no per-site fee and no feature gating. The agent plugin is MIT-licensed. You pay only for the server you choose to run it on.",
  },
  {
    q: "When will the hosted cloud version launch?",
    a: "We are building the hosted version now. You can follow progress on GitHub or star the repository to get notified when it launches. The hosted version will have a free tier so you can evaluate it without a credit card.",
  },
  {
    q: "Will the hosted tiers have a per-site fee?",
    a: "No. Hosted plans will be flat-rate tiers based on site count limits, not a per-site fee that grows with every client you add.",
  },
  {
    q: "Is there a difference in features between self-hosted and hosted?",
    a: "Every feature in the self-hosted release is also available in the hosted version. The hosted version adds managed infrastructure, automatic updates, and backup storage so you do not have to operate the server yourself.",
  },
  {
    q: "Can I migrate from self-hosted to the hosted cloud version later?",
    a: "Yes. The data model is the same. We will provide a migration path so your connected sites, backup history, and settings transfer cleanly to the hosted version.",
  },
  {
    q: "What license governs the control plane and the agent?",
    a: "The control plane is AGPL-3.0. The WordPress agent plugin is MIT. The AGPL means you can read, fork, and self-host the control plane, but if you distribute a modified version as a network service you must publish the source. The MIT agent is fully permissive.",
  },
];

export default function PricingPage() {
  const breadcrumbLd = buildBreadcrumbLd([
    { name: "Home", href: "/" },
    { name: "Pricing", href: "/pricing/" },
  ]);

  // SoftwareApplication JSON-LD with NO Offer price (C3: no published numbers)
  const appLd = {
    ...buildSoftwareApplicationLd(),
    // Override offers: show free self-hosted only, no hosted pricing
    offers: {
      "@type": "Offer",
      price: "0",
      priceCurrency: "USD",
      description: "Free, open-source, self-hostable. No per-site fee.",
    },
  };

  const faqLd = buildFAQPageLd(PRICING_FAQ);

  return (
    <>
      <nav aria-label="Breadcrumb" className="py-4">
        <Container>
          <ol className="flex flex-wrap items-center gap-1.5 text-sm text-[var(--muted-foreground)]">
            <li>
              <Link href="/" className="transition-colors hover:text-foreground">
                Home
              </Link>
            </li>
            <li aria-hidden className="select-none">/</li>
            <li className="text-foreground font-medium" aria-current="page">
              Pricing
            </li>
          </ol>
        </Container>
      </nav>

      {/* Hero: free/open-source first */}
      <section
        className="relative overflow-hidden py-20 sm:py-24 lg:py-28"
        aria-label="Pricing hero"
      >
        <div aria-hidden className="dot-field pointer-events-none absolute inset-0" />
        <Container className="relative">
          <div className="mx-auto max-w-3xl text-center">
            {/* H1: not JS-animated (LCP) */}
            <h1 className="text-4xl font-semibold tracking-tight text-foreground sm:text-5xl leading-[1.1]">
              Free, open-source, self-host forever
            </h1>
            <p className="mt-6 text-lg leading-relaxed text-[var(--muted-foreground)]">
              The full WPMgr control plane is open-source under the AGPL. Run it on your own infrastructure with no per-site fee, no feature gating, and no expiry. A hosted cloud version is on the way.
            </p>
            <div className="mt-8 flex flex-wrap items-center justify-center gap-3">
              <Link
                href={SITE_CONFIG.signup}
                target="_blank"
                rel="noreferrer noopener"
                className="inline-flex items-center gap-2 rounded-[var(--radius)] bg-primary px-6 py-3 text-base font-medium text-[var(--primary-foreground)] shadow-sm transition-colors hover:bg-[var(--primary-hover)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--ring)]"
              >
                Get started for free
                <Icon name="ArrowRight" size={18} />
              </Link>
              <Link
                href={SITE_CONFIG.github}
                target="_blank"
                rel="noreferrer noopener"
                className="inline-flex items-center gap-2 rounded-[var(--radius)] border border-[var(--border)] bg-card px-6 py-3 text-base font-medium text-foreground transition-colors hover:bg-[var(--accent)] hover:text-[var(--accent-foreground)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--ring)]"
              >
                <Icon name="Github" size={18} />
                Star on GitHub
              </Link>
            </div>
          </div>
        </Container>
      </section>

      {/* Self-hosted: everything included */}
      <Section tone="muted" id="self-hosted">
        <Container>
          <Reveal>
            <div className="mx-auto max-w-2xl text-center">
              <span className="inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.14em] text-[var(--eyebrow)] mb-4">
                <span aria-hidden className="h-px w-6 bg-[var(--primary)]/60" />
                Self-hosted
              </span>
              <h2 className="text-3xl font-semibold text-foreground sm:text-4xl">
                Everything included. No per-site fee.
              </h2>
              <p className="mt-4 text-lg leading-relaxed text-[var(--muted-foreground)]">
                Every feature in the list below ships in the open-source release. Fork it, extend it, or contribute back.
              </p>
            </div>
          </Reveal>
          <Stagger className="mt-12 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 max-w-5xl mx-auto">
            {SELF_HOST_FEATURES.map((f) => (
              <StaggerItem key={f.label}>
                <div className="flex items-center gap-3 rounded-xl border border-[var(--border)] bg-card px-4 py-3 shadow-sm">
                  <Icon name={f.icon} size={16} className="shrink-0 text-[var(--primary)]" />
                  <span className="text-sm text-foreground">{f.label}</span>
                </div>
              </StaggerItem>
            ))}
          </Stagger>
          <Reveal delay={0.1}>
            <div className="mt-10 flex flex-col items-center gap-3 text-center">
              <p className="text-sm text-[var(--muted-foreground)]">
                AGPL-3.0 control plane + MIT agent. Read every line before you run it.
              </p>
              <Link
                href={SITE_CONFIG.github}
                target="_blank"
                rel="noreferrer noopener"
                className="inline-flex items-center gap-2 text-sm font-medium text-[var(--primary)] hover:underline"
              >
                <Icon name="Github" size={14} />
                Read the source on GitHub
                <Icon name="ArrowRight" size={13} />
              </Link>
            </div>
          </Reveal>
        </Container>
      </Section>

      {/* Hosted: coming soon */}
      <Section id="hosted">
        <Container>
          <Reveal>
            <div className="mx-auto max-w-2xl text-center">
              <span className="inline-flex items-center gap-1.5 rounded-full bg-[var(--primary-subtle)] px-3 py-1 text-xs font-semibold text-[var(--primary-pressed)] mb-4">
                Coming soon
              </span>
              <h2 className="text-3xl font-semibold text-foreground sm:text-4xl">
                Hosted cloud version on the way
              </h2>
              <p className="mt-4 text-lg leading-relaxed text-[var(--muted-foreground)]">
                The same open-source capability, hosted and maintained by us. No server to run, no updates to apply, backups to managed storage. Notify-me below to get early access.
              </p>
            </div>
          </Reveal>

          <Stagger className="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            {HOSTED_TIERS.map((tier) => (
              <StaggerItem key={tier.name}>
                <Card
                  className={cn(
                    "flex h-full flex-col gap-4",
                    tier.highlight && "border-[var(--primary)]/50 ring-1 ring-[var(--primary)]/20",
                  )}
                >
                  {tier.highlight && (
                    <span className="inline-flex self-start rounded-full bg-[var(--primary-subtle)] px-2.5 py-0.5 text-xs font-semibold text-[var(--primary-pressed)]">
                      Most popular
                    </span>
                  )}
                  <div>
                    <h3 className="text-lg font-semibold text-foreground">{tier.name}</h3>
                    <p className="mt-0.5 text-xs font-medium text-[var(--primary-pressed)] uppercase tracking-wide">
                      {tier.audience}
                    </p>
                  </div>
                  <p className="flex-1 text-sm leading-relaxed text-[var(--muted-foreground)]">
                    {tier.description}
                  </p>
                  <div className="mt-auto rounded-lg border border-[var(--border)] bg-[var(--muted)]/30 px-3 py-2 text-center">
                    <span className="text-xs font-semibold text-[var(--muted-foreground)] uppercase tracking-wide">
                      Pricing announced at launch
                    </span>
                  </div>
                </Card>
              </StaggerItem>
            ))}
          </Stagger>

          <Reveal delay={0.1}>
            <div className="mx-auto mt-10 max-w-sm rounded-xl border border-[var(--border)] bg-card p-6 text-center shadow-sm">
              <h3 className="text-base font-semibold text-foreground">Get notified at launch</h3>
              <p className="mt-2 text-sm text-[var(--muted-foreground)]">
                Star the repository to follow development and be the first to know when hosted plans are available.
              </p>
              <Link
                href={SITE_CONFIG.github}
                target="_blank"
                rel="noreferrer noopener"
                className="mt-4 inline-flex items-center gap-2 rounded-[var(--radius)] bg-primary px-5 py-2.5 text-sm font-medium text-[var(--primary-foreground)] shadow-sm transition-colors hover:bg-[var(--primary-hover)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--ring)]"
              >
                <Icon name="Github" size={16} />
                Star on GitHub
              </Link>
            </div>
          </Reveal>
        </Container>
      </Section>

      {/* Cross-links */}
      <Section tone="muted" id="explore">
        <Container>
          <Reveal>
            <SectionHeading
              title="Dig into what you are getting"
              lead="The self-hosted release is complete. Explore the feature detail or find the solution that matches your use case."
            />
          </Reveal>
          <Stagger className="mt-10 flex flex-wrap justify-center gap-4">
            {[
              { label: "All 13 features", href: "/features/" },
              { label: "For agencies", href: "/solutions/agencies/" },
              { label: "For freelancers", href: "/solutions/freelancers/" },
              { label: "WordPress security", href: "/solutions/wordpress-security/" },
              { label: "WordPress backups", href: "/solutions/wordpress-backups/" },
              { label: "Speed up WordPress", href: "/solutions/wordpress-performance/" },
            ].map((link) => (
              <StaggerItem key={link.href}>
                <Link
                  href={link.href}
                  className="inline-flex items-center gap-1.5 rounded-lg border border-[var(--border)] bg-card px-4 py-2 text-sm font-medium text-foreground transition-colors hover:bg-[var(--accent)] hover:text-[var(--accent-foreground)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--ring)]"
                >
                  {link.label}
                  <Icon name="ArrowRight" size={13} className="text-[var(--muted-foreground)]" />
                </Link>
              </StaggerItem>
            ))}
          </Stagger>
        </Container>
      </Section>

      <FAQ
        eyebrow="FAQ"
        heading="Pricing questions answered"
        subhead="Common questions about the free self-hosted release and the upcoming hosted plans."
        items={PRICING_FAQ}
      />

      <CTABand
        heading="Start for free today. No credit card, no expiry."
        subhead="The complete open-source release is on GitHub. Self-host it on any server in minutes."
        ctas={[
          { label: "Get started for free", href: SITE_CONFIG.signup, variant: "primary", icon: "ArrowRight" },
          { label: "Star on GitHub", href: SITE_CONFIG.github, variant: "secondary", icon: "Github" },
        ]}
      />

      <JsonLd data={breadcrumbLd} />
      <JsonLd data={appLd} />
      <JsonLd data={faqLd} />
    </>
  );
}
