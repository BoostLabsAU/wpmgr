// Shared content types for the marketing site.
// All copy lives in typed modules under lib/content/. Pages consume these
// directly as Server Component props. Phase 2 feature pages extend FeaturePageData.

export type Cta = {
  label: string;
  href: string;
  variant?: "primary" | "secondary" | "ghost";
  icon?: string;
};

export type Chip = {
  icon: string;
  value: string;
  label: string;
};

export type Step = {
  n: string;
  icon: string;
  title: string;
  desc: string;
};

export type FaqItem = {
  q: string;
  a: string;
};

// Feature grid types (mirrors content.ts for compatibility)
export type FeatureVisual = "cache-trend" | "rum-distribution" | "media-compare";

export type ClusterFeature = {
  icon: string;
  title: string;
  summary: string;
  bullets: string[];
  link?: { href: `#${string}` };
  visual?: FeatureVisual;
};

export type FeatureCluster = {
  id: `platform-${string}`;
  icon: string;
  name: string;
  tagline: string;
  features: ClusterFeature[];
};

// Phase 2 extension: per-feature page data shape
export type FeaturePageData = {
  slug: string;
  title: string;
  metaTitle: string;
  metaDescription: string;
  hero: {
    eyebrow: string;
    heading: string;
    subhead: string;
    primaryCta: Cta;
    secondaryCta?: Cta;
  };
  problem: {
    heading: string;
    body: string;
  };
  steps: Step[];
  subFeatures: Array<{
    icon: string;
    title: string;
    desc: string;
  }>;
  faq: FaqItem[];
  siblingLinks: Array<{ label: string; href: string }>;
  solutionLinks: Array<{ label: string; href: string }>;
};

// Phase 3 extension: per-solution page data shape
export type SolutionStat = {
  icon: string;
  value: string;
  label: string;
};

export type SolutionFeatureCard = {
  featureSlug: string;
  icon: string;
  title: string;
  summary: string;
  href: string;
};

export type SolutionPageData = {
  slug: string;
  /** Short display title, used in hub cards and breadcrumb */
  title: string;
  /** H1: the primary keyword phrase for this solution */
  heading: string;
  metaTitle: string;
  metaDescription: string;
  hero: {
    /** Problem-framed subheading that precedes the H1 */
    eyebrow: string;
    subhead: string;
    primaryCta: Cta;
    secondaryCta?: Cta;
  };
  /** Outcome narrative block (2 to 4 sentences) */
  outcomes: {
    heading: string;
    body: string;
  };
  /** The 3 to 5 proving feature cards that link down to feature pages */
  provingFeatures: SolutionFeatureCard[];
  /** Stats strip (3 items, tabular figures) */
  stats: SolutionStat[];
  faq: FaqItem[];
  /** Optional layout hint so each solution page feels distinct */
  layoutVariant?: "default" | "split" | "compact";
};
