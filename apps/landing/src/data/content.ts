// Single source of truth for every word on the landing page. Keeping the copy
// here (not inline in JSX) makes the no-em-dash and no-competitor sweeps a
// one-file grep, and keeps section components purely about layout.
//
// House rules baked into this copy: no em dashes, no en dashes, no competitor
// names. Use "to" for ranges. Verified by scripts/check-copy at build time.

export const SITE = {
  name: "WPMgr",
  tagline: "Open-source WordPress fleet management you can run, read, and improve.",
  github: "https://github.com/mosamlife/wpmgr",
  dashboard: "https://manage.wpmgr.app",
  metaTitle: "WPMgr: Open-Source, Self-Hosted WordPress Fleet Management",
  metaDescription:
    "Open-source, self-hostable WordPress fleet manager. Backups and restore, safe updates, Media Optimizer (AVIF and WebP), full-page caching, Database Cleaner, uptime, and security scanning, with a signed MIT-licensed agent you can audit and contribute to.",
} as const;

export const NAV = {
  links: [
    { label: "Features", href: "#features" },
    { label: "Performance", href: "#performance" },
    { label: "RUM", href: "#rum" },
    { label: "Media", href: "#media" },
    { label: "DB Cleaner", href: "#platform-clean" },
    { label: "Clients", href: "#platform-clients" },
    { label: "How it works", href: "#how-it-works" },
    { label: "Stack", href: "#stack" },
    { label: "Contribute", href: "#open-source" },
    { label: "API", href: "/docs/" },
    { label: "FAQ", href: "#faq" },
  ],
};

export const HERO = {
  badge: "v0.31.1 / open source",
  heading: "The open-source WordPress fleet manager you can run, read, and contribute to",
  subhead:
    "WPMgr is a self-hostable control plane for managing one WordPress site or a whole portfolio. Back up, restore, update, monitor uptime, optimize images with the Media Optimizer, clean the database, and lock down every site from a single dashboard, all on infrastructure you own, built from code you can read and improve.",
  bodyLines: [
    "Add a site by URL, paste a one-time code into the plugin, and watch it flip from Awaiting to Connected with no page refresh.",
    "Open source under AGPL with an MIT-licensed agent. Every message it exchanges is Ed25519-signed, so nothing happens to your sites you cannot verify.",
  ],
  trust: [
    { icon: "GitFork", title: "Fork and contribute", desc: "AGPL control plane, MIT agent, PRs welcome" },
    { icon: "ServerCog", title: "Your infrastructure", desc: "Fleet data never leaves your server" },
    { icon: "FileSearch", title: "Auditable agent", desc: "Read every line before you run it" },
  ],
  ctas: [
    { label: "Star on GitHub", href: SITE.github, variant: "primary" as const, icon: "Github" },
    { label: "See the live dashboard", href: SITE.dashboard, variant: "secondary" as const, icon: "ArrowRight" },
  ],
};

export const TRUST = {
  eyebrow: "Why trust it",
  heading: "Every claim is verifiable in the repository",
  subhead:
    "No marketing logos. WPMgr earns trust by being readable: open source, self-hostable, and built so contributors can follow every decision from issue to merged code.",
  bodyLines: [
    "Everything is open source under the AGPL, so you can read every line before you run it. The WordPress agent is MIT-licensed and every message it exchanges with the control plane is Ed25519-signed.",
    "Self-host it on your own server and your fleet data, backups, and media never leave infrastructure you control. Browse the live dashboard to see exactly what you get, then read the code on GitHub and contribute what you need.",
  ],
  chips: [
    { icon: "Scale", value: "AGPL-3.0", label: "Read the whole control plane in the open" },
    { icon: "FileBadge", value: "MIT", label: "The WordPress agent is permissively licensed" },
    { icon: "KeyRound", value: "Ed25519", label: "Every agent message is cryptographically signed" },
    { icon: "GitPullRequest", value: "PRs open", label: "Contributions welcome, good-first-issue labels maintained" },
  ],
  cta: { label: "Read the code on GitHub", href: SITE.github, icon: "ArrowRight" },
};

// ---------------------------------------------------------------------------
// PLATFORM INDEX. HARD COPY BUDGETS, enforced by scripts/check-copy.mjs
// (build-failing). Formatting contract for the budget parser: every budgeted
// field below is a single-line, double-quoted string literal.
//
//   cluster.name      <= 16 chars. Clusters: min 4, max 6.
//   cluster.tagline   <= 90 chars, exactly one sentence.
//   feature.title     <= 26 chars, no terminal period.
//   feature.summary   <= 120 chars, exactly one sentence.
//   feature.bullets   2 to 4 items, each <= 64 chars, no terminal period.
//   features/cluster  <= 6. At 7 you split the cluster or promote a feature
//                     to a deep-dive section.
//   feature.link      optional; href MUST start with "#". The rendered label
//                     is always "See it in depth" (not in the data).
//   feature.visual    optional; ONLY allowed when link is present. Must be a
//                     key of VISUALS in components/feature-visuals.tsx.
//
// PROMOTION RULE: the grid is the index. A feature that deserves more than
// 4 bullets of story gets its own deep-dive Section (the Performance/RUM/
// Media pattern) and its card gains a link. The card itself never grows.
// ---------------------------------------------------------------------------

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

export const FEATURES: {
  eyebrow: string;
  heading: string;
  subhead: string;
  clusters: FeatureCluster[];
} = {
  eyebrow: "The platform",
  heading: "Everything you need to run a fleet, all in the open release",
  subhead: "One dashboard, no add-on sprawl. Five capability areas, every line of code open to read and extend.",
  clusters: [
    {
      id: "platform-operate",
      icon: "ServerCog",
      name: "Operate",
      tagline: "Connect a site in under a minute, then run the whole fleet from one screen.",
      features: [
        {
          icon: "Network",
          title: "Fleet connection",
          summary: "Sites enroll with a one-time code and stay verifiably connected.",
          bullets: [
            "Live flip from Awaiting to Connected, no refresh",
            "One-click wp-admin login, no shared passwords",
            "Stable status badges plus a manual Re-check",
          ],
        },
        {
          icon: "DatabaseBackup",
          title: "Backups and restore",
          summary: "Scheduled full and incremental backups with point-in-time restore.",
          bullets: [
            "Increments pack only files that changed",
            "Base plus increments in one expandable chain",
            "Restore to any snapshot, site stays online",
          ],
        },
        {
          icon: "RefreshCw",
          title: "Fleet updates",
          summary: "Preview version changes, then update with an automatic safety net.",
          bullets: [
            "Snapshot first, auto-revert on failed health check",
            "Bulk runs by group or tag",
            "Live per-site progress",
          ],
        },
        {
          icon: "Activity",
          title: "Monitoring and health",
          summary: "Uptime, response time, and fleet-wide status at a glance.",
          bullets: [
            "7, 30, and 90 day charts",
            "Down and recovery alerts by email or webhook",
            "TLS expiry warnings and PHP fatal tracking",
          ],
        },
      ],
    },
    {
      id: "platform-accelerate",
      icon: "Gauge",
      name: "Accelerate",
      tagline: "Make every page faster, then prove it with real-visitor data.",
      features: [
        {
          icon: "Zap",
          title: "Performance and caching",
          summary: "Full-page caching and asset optimization, per site or fleet-wide.",
          bullets: [
            "Pre-gzipped pages served straight from disk",
            "Unused CSS removal, minify, defer, lazy-load",
            "WOFF2 font transcoding and subsetting",
            "WooCommerce cart-session caching",
          ],
          link: { href: "#performance" },
          visual: "cache-trend",
        },
        {
          icon: "BarChart2",
          title: "Real User Monitoring",
          summary: "Core Web Vitals from real visitors at the p75 Google uses.",
          bullets: [
            "LCP, INP, CLS, FCP, TTFB distributions",
            "28-day trends with threshold lines drawn on",
            "Per-URL and per-device breakdowns",
            "Anonymous, off by default, no cookies",
          ],
          link: { href: "#rum" },
          visual: "rum-distribution",
        },
        {
          icon: "ImageDown",
          title: "Media Optimizer",
          summary: "Re-encode the media library to AVIF and WebP, fully reversible.",
          bullets: [
            "Originals archived on the site",
            "Right format per browser, automatic fallback",
            "No image bytes touch the control plane",
          ],
          link: { href: "#media" },
          visual: "media-compare",
        },
        {
          icon: "HardDrive",
          title: "Redis Object Cache",
          summary: "Per-site persistent object cache that accelerates logged-in users, admin, and every uncacheable database round-trip.",
          bullets: [
            "phpredis connection, TLS, ACL, and per-site key prefix",
            "Degrades safely to in-memory cache if Redis is unreachable",
            "Hit ratio, memory, and latency history in the dashboard",
            "Debug header verifies cache state per request",
          ],
        },
      ],
    },
    {
      id: "platform-clean",
      icon: "Eraser",
      name: "Clean up",
      tagline: "Database and media hygiene that previews first and reverses cleanly.",
      features: [
        {
          icon: "DatabaseZap",
          title: "Database Cleaner",
          summary: "Scan first, then clean revisions, transients, and orphans in batches.",
          bullets: [
            "Per-table inventory with owner labels",
            "Orphans classified against a signature corpus",
            "90-day health trend and a fleet-wide view",
          ],
        },
        {
          icon: "RotateCcw",
          title: "Database Snapshots",
          summary: "A quick local snapshot before a risky change, instant revert after.",
          bullets: [
            "Faster and lighter than a full backup",
            "No remote storage required",
          ],
        },
        {
          icon: "Replace",
          title: "Search and Replace",
          summary: "Serialization-safe find and replace across the whole database.",
          bullets: [
            "PHP-serialized data survives intact",
            "Preview matches before committing",
          ],
        },
        {
          icon: "ImageOff",
          title: "Unused Image Cleaner",
          summary: "Finds media nothing references, with proof of where used images appear.",
          bullets: [
            "Reversible quarantine before any delete",
            "Ambiguous references count as in-use",
            "Per-image usage report",
          ],
        },
      ],
    },
    {
      id: "platform-clients",
      icon: "Handshake",
      name: "Serve clients",
      tagline: "Group sites by customer and put your brand on everything they see.",
      features: [
        {
          icon: "Briefcase",
          title: "Client management",
          summary: "Group any number of sites under named client records.",
          bullets: [
            "Brand color, logo, contacts, and notes",
            "Bulk-assign sites from the fleet view",
            "Filter the fleet or jump to a client page",
          ],
        },
        {
          icon: "ScrollText",
          title: "White-label reports",
          summary: "Branded maintenance reports on a schedule or on demand.",
          bullets: [
            "Uptime, backups, updates, vitals, email health",
            "HTML email, print page, and vector-chart PDF",
            "Per-section toggles, custom intro and closing",
            "Powered-by footer removable on any plan",
          ],
        },
        {
          icon: "LayoutDashboard",
          title: "Client portal",
          summary: "A read-only branded portal where clients see their own sites.",
          bullets: [
            "Email invites on the same login page",
            "Uptime, backups, vitals, report downloads",
            "Access revoked instantly on removal",
          ],
        },
        {
          icon: "MailCheck",
          title: "Per-site email and log",
          summary: "Per-site outgoing email with a central, searchable delivery log.",
          bullets: [
            "SES, SendGrid, Mailgun, Postmark, any SMTP",
            "Named connections with automatic failover",
            "Webhook bounce and complaint suppression",
            "Fleet-wide deliverability view and digests",
          ],
        },
      ],
    },
    {
      id: "platform-protect",
      icon: "LockKeyhole",
      name: "Protect",
      tagline: "Hardening, access control, and account flows that cannot lock you out.",
      features: [
        {
          icon: "ShieldCheck",
          title: "Security",
          summary: "Integrity scanning, brute-force protection, and an IP firewall.",
          bullets: [
            "Core files checked against official checksums",
            "Escalating login blocks, admins never locked out",
            "IP rules with a safety rail for your own IP",
          ],
        },
        {
          icon: "Users",
          title: "Team and access",
          summary: "Four roles, per-site sharing, SSO, and a tamper-evident audit log.",
          bullets: [
            "Owner to viewer, least privilege by default",
            "Share one site without exposing the fleet",
            "Email sign-in or company OIDC",
          ],
        },
        {
          icon: "Mail",
          title: "Email in minutes",
          summary: "Point the control plane at your own SMTP server in Settings.",
          bullets: [
            "Send a test message before saving",
            "Credentials encrypted at rest",
            "All transactional mail routes through it",
          ],
        },
        {
          icon: "KeyRound",
          title: "Account recovery",
          summary: "Self-serve password reset and change, no support ticket.",
          bullets: [
            "Works from any device, no admin involved",
            "A change signs out every other session",
          ],
        },
        {
          icon: "UserPlus",
          title: "Open sign up",
          summary: "Email-verified self-registration with a configurable gate.",
          bullets: [
            "Verification link before any access",
            "No manual provisioning",
            "Lock it down when onboarding is done",
          ],
        },
      ],
    },
  ],
};

export const MEDIA = {
  eyebrow: "Flagship feature",
  heading: "Lighter images, no quality loss, fully reversible",
  subhead:
    "Turn on Media Optimization and WPMgr re-encodes your library to AVIF and WebP in the cloud. Every browser gets the format it supports, your originals stay safely archived on the site, and you can revert any image with one click.",
  bodyLines: [
    "WPMgr totals the bytes saved across every variant, including all the thumbnail sizes WordPress generates, plus a running count of images optimized, so the number on your dashboard reflects real files, not one hero image per upload.",
    "No image bytes ever pass through WPMgr's control plane. Source files move from your site to your storage, and optimized files move from the encoder to your storage, using short-lived presigned URLs while WPMgr keeps only metadata.",
    "The Unused Image Cleaner finds attachments that are not referenced anywhere, shows exactly where each image in use appears, and moves unwanted images to a reversible quarantine. Permanent deletion requires explicit confirmation. Ambiguous references are always treated as in-use, so the cleaner never flags a genuinely used image.",
  ],
  chips: [
    { icon: "ImageDown", value: "AVIF + WebP", label: "Modern formats served automatically, GIFs to animated WebP" },
    { icon: "Undo2", value: "100%", label: "Reversible: originals archived on the site" },
    { icon: "ShieldOff", value: "0 bytes", label: "Image data on the control plane, presigned URLs only" },
    { icon: "ToggleLeft", value: "Opt-in", label: "Disabled until you turn it on, per site" },
  ],
  cta: { label: "See Media Optimization live", href: SITE.dashboard, icon: "ArrowRight" },
  // BeforeAfterCard demo data. Byte figures are illustrative of a typical
  // photo plus its thumbnail set, framed as "a sample upload" so nothing is
  // overstated as a guaranteed result.
  demo: {
    caption: "A sample upload, full image plus its thumbnail set",
    originalLabel: "Original JPEG",
    originalBytes: 2_480_000,
    optimizedLabel: "Optimized AVIF",
    optimizedBytes: 712_000,
    library: [
      { label: "Optimized", pct: 72, tone: "success" as const },
      { label: "Pending", pct: 18, tone: "warning" as const },
      { label: "Unsupported", pct: 10, tone: "muted" as const },
    ],
  },
};

export const MEDIA_STEPS = {
  eyebrow: "Under the hood",
  heading: "How Media Optimization works, end to end",
  subhead:
    "Four steps, every one of them reversible. Auto-optimize runs on upload without slowing the editor.",
  steps: [
    {
      n: "1",
      icon: "Upload",
      title: "Upload or pick",
      desc:
        "Flip on auto-optimize and every new upload gets queued the moment WordPress finishes generating its sizes. Already have a full library? Select existing images and send them in batches. No reformatting your workflow.",
    },
    {
      n: "2",
      icon: "Cpu",
      title: "We re-encode safely",
      desc:
        "A dedicated cloud encoder reads each image by its real bytes, not a guessed file extension, and re-encodes the full image plus every thumbnail to AVIF or WebP. Animated GIFs become animated WebP. Your originals are renamed and archived on the site, never deleted.",
    },
    {
      n: "3",
      icon: "Globe",
      title: "Browsers get the modern format",
      desc:
        "A small .htaccess rule checks what each visitor's browser actually supports and serves the modern format only when it will display, falling back to the original everywhere else. Pages get lighter with no broken images and no front-end plugin bloat.",
    },
    {
      n: "4",
      icon: "RotateCcw",
      title: "Revert anytime",
      desc:
        "Changed your mind, or a single image looks off? Restore puts every archived original back, full image and all thumbnails, and rewrites the URLs in your content. Nothing about optimization is a one-way door.",
    },
  ],
};

export const PERFORMANCE = {
  eyebrow: "Performance Suite",
  heading: "Faster pages, on by the toggle, never at the cost of the page",
  subhead:
    "Turn on full-page caching and asset optimization and WPMgr serves your anonymous pages from disk and ships only the assets each page needs. Every step is per site or across your whole portfolio, and a failed optimization always falls back to the original.",
  bodyLines: [
    "Page caching serves pre-gzipped HTML straight from disk, with logged-in, per-role, mobile, and per-query variants, bypass rules for cart and checkout, scheduled refresh, automatic purge on content changes, and a preload warmer. The server fast-path installs itself on Apache and ships a paste-in snippet for nginx.",
    "Remove Unused CSS is computed by WPMgr's own engine with no headless browser and no third-party service. Interactive states stay intact, a per-site safelist covers anything added by scripts, and on a cache miss the full CSS is served so rendering is never blocked.",
  ],
  chips: [
    { icon: "Gauge", value: "From disk", label: "Anonymous pages served as pre-gzipped HTML, no PHP on a hit" },
    { icon: "Scissors", value: "Unused CSS", label: "Stripped and inlined, full CSS served on any miss" },
    { icon: "Type", value: "WOFF2 + subset", label: "Fonts transcoded to WOFF2 and optionally subsetted to latin-ext, 60 to 90 percent smaller" },
    { icon: "ShieldOff", value: "No browser", label: "Pure-Go engine, no headless Chrome and no third-party service" },
    { icon: "ToggleLeft", value: "Per site", label: "Off until you turn it on, with safe, balanced, and aggressive presets" },
  ],
  cta: { label: "See the dashboard", href: SITE.dashboard, icon: "ArrowRight" },
};

export const PERFORMANCE_STEPS = {
  eyebrow: "Under the hood",
  heading: "What the Performance Suite turns on",
  subhead:
    "Four layers, each on its own toggle, all degrading safely. Turn on what you need and leave the rest off.",
  steps: [
    {
      n: "1",
      icon: "Zap",
      title: "Cache pages to disk",
      desc:
        "Anonymous pages are stored as pre-gzipped HTML and served straight from disk on a hit, with variants for logged-in users, roles, mobile, and query strings, plus bypass rules so cart and checkout pages stay dynamic.",
    },
    {
      n: "2",
      icon: "Scissors",
      title: "Trim CSS and JS",
      desc:
        "Minify CSS and JS, delay scripts until interaction, and strip the CSS a page does not use. Remove Unused CSS runs on WPMgr's own engine and always serves full CSS when a result is not ready yet.",
    },
    {
      n: "3",
      icon: "ImageDown",
      title: "Lighten the front end",
      desc:
        "Lazy-load images with width, height, and srcset preserved, swap in fonts without blocking text, convert self-hosted fonts to WOFF2 for smaller and faster font loads (50 to 65 percent smaller for TTF and OTF), and optionally subset each font to the latin-ext unicode range for a further 60 to 90 percent reduction. Subsetting is experimental and off by default; icon and variable fonts are detected and skipped automatically. Remove front-end bloat and rewrite asset URLs to your CDN with credentials encrypted at rest.",
    },
    {
      n: "4",
      icon: "Gauge",
      title: "Manage it like a fleet",
      desc:
        "Save the config for one site, purge the cache across many at once, or apply a safe, balanced, or aggressive preset to a whole group in one run. Live status and stats stream to the dashboard with no refresh.",
    },
  ],
};

export const RUM = {
  eyebrow: "Real User Monitoring",
  heading: "Complete Core Web Vitals tracking from real visitors",
  subhead:
    "See how your pages actually perform in the field. All five Core Web Vitals, at the p75 percentile Google uses for ranking, sourced from real browsers on your live site.",
  bodyLines: [
    "WPMgr collects LCP, INP, CLS, FCP, and TTFB from real visitor sessions and surfaces them at the p75 percentile: the same threshold Google PageSpeed Insights and Search Console use for field data. No lab simulation, no synthetic crawler.",
    "Every metric shows a PageSpeed Insights-style distribution bar and a 28-day p75 trend with the passing threshold marked, so you can see at a glance whether a recent change moved the needle in the field. Per-URL and per-device breakdowns let you pinpoint exactly where a score comes from.",
  ],
  capabilities: [
    { icon: "BarChart2", label: "All five Core Web Vitals", detail: "LCP, INP, CLS, FCP, and TTFB at p75, the percentile Google uses for field data" },
    { icon: "TrendingUp", label: "28-day p75 trend per metric", detail: "The passing threshold line is drawn on every trend so you can see the moment a change crossed it" },
    { icon: "Gauge", label: "Good / needs improvement / poor distribution", detail: "A PageSpeed Insights-style histogram bar built from the same rating buckets Google uses" },
    { icon: "Monitor", label: "Per-URL and per-device breakdowns", detail: "Narrow to a specific page or separate desktop from mobile without leaving the dashboard" },
    { icon: "Activity", label: "Live updates over SSE", detail: "New beacons stream to the dashboard in real time with no manual refresh" },
    { icon: "EyeOff", label: "Privacy-first by design", detail: "Anonymous, off by default, no cookies, no cross-site identifiers, query strings stripped, visitor IP never stored" },
  ],
  privacy: [
    "Off by default. You turn it on per site when you want it.",
    "No cookies and no cross-site identifiers of any kind.",
    "Page paths are stored with the query string stripped.",
    "Visitor IP addresses are never stored.",
    "On a self-hosted control plane every measurement stays on your own infrastructure.",
  ],
  cta: { label: "See it in the dashboard", href: "https://manage.wpmgr.app", icon: "ArrowRight" },
  // Mock data for the RUM preview widget. These are illustrative figures only,
  // labelled as sample data in the component.
  demo: {
    metric: "LCP",
    p75: "2.1s",
    rating: "good" as const,
    distribution: [
      { label: "Good", pct: 68, tone: "good" as const },
      { label: "Needs improvement", pct: 22, tone: "needs-improvement" as const },
      { label: "Poor", pct: 10, tone: "poor" as const },
    ],
    trend: [2.6, 2.4, 2.3, 2.5, 2.2, 2.0, 2.1, 2.3, 2.0, 1.9, 2.1, 2.0, 2.1],
    threshold: 2.5,
    metrics: [
      { name: "LCP", p75: "2.1s", rating: "good" as const },
      { name: "INP", p75: "148ms", rating: "good" as const },
      { name: "CLS", p75: "0.05", rating: "good" as const },
      { name: "FCP", p75: "1.4s", rating: "good" as const },
      { name: "TTFB", p75: "310ms", rating: "needs-improvement" as const },
    ],
  },
};

export const ENROLL = {
  eyebrow: "Getting started",
  heading: "From zero to managed in under a minute",
  subhead:
    "No SSH, no FTP, no shared admin passwords. Three steps and the site is live on your dashboard.",
  body:
    "The agent is a lightweight PHP plugin that needs only PHP 8.1+ and WordPress 6.0+. Backups use a pure-PHP streaming dump and archiver, so they work even on managed hosts that lock down shell access and the mysqldump binary.",
  steps: [
    {
      n: "1",
      icon: "PlusCircle",
      title: "Add the site by URL",
      desc:
        "Paste a site URL into the dashboard. WPMgr generates a one-time enrollment code and marks the site as Awaiting connection.",
    },
    {
      n: "2",
      icon: "KeyRound",
      title: "Push the one-time code",
      desc:
        "Install the MIT-licensed agent plugin and paste the one-time code. The agent registers its signing key and the site flips from Awaiting to Connected with no page refresh.",
    },
    {
      n: "3",
      icon: "LayoutDashboard",
      title: "Manage everything",
      desc:
        "Back up, update, monitor uptime, optimize images, and lock the site down, all from the single dashboard. Disconnect cleanly anytime, and reconnecting later keeps the full history.",
    },
  ],
  cta: { label: "Self-host it", href: SITE.github, icon: "Github" },
};

export const SECURITY = {
  eyebrow: "Security and privacy",
  heading: "Built so a mistake can never lock you out of your own sites",
  subhead:
    "Integrity scanning, brute-force protection, and an IP firewall across the fleet, with sensitive controls fenced off and a tamper-evident record of every action.",
  bodyLines: [
    "Your data lives on the infrastructure you run WPMgr on, not ours. Backups can be client-side encrypted so the control plane only ever stores ciphertext and never holds a decryption key.",
    "Image bytes move directly between your site and your storage, and the agent redacts emails, passwords, secrets, tokens, and salts before any diagnostics ever leave a site.",
  ],
  items: [
    { icon: "FileScan", title: "Core file-integrity scanning", desc: "Compares WordPress core files against the official checksums and flags anything modified, missing, or injected." },
    { icon: "LockKeyhole", title: "Brute-force protection", desc: "Blocks repeated failed logins in escalating steps across the fleet, without locking out legitimate admins." },
    { icon: "ShieldAlert", title: "IP firewall with a safety rail", desc: "Allow or deny visitors by IP range, with a guard that keeps your own IP from ever being blocked." },
    { icon: "FileLock2", title: "Client-side encrypted backups", desc: "Optional end-to-end encryption means the control plane stores only ciphertext and never holds a key to decrypt it." },
    { icon: "EyeOff", title: "Redacted diagnostics", desc: "Emails, passwords, secrets, tokens, and salts are stripped before any diagnostics leave a site." },
    { icon: "ScrollText", title: "Tamper-evident audit log", desc: "Every login, role change, and site action is recorded in an audit log you can review." },
  ],
};

export const OPEN_SOURCE = {
  eyebrow: "Open source",
  heading: "Built to be forked, run, and improved by anyone",
  subhead:
    "WPMgr is open source first. Self-host it in minutes, read every line, open issues, submit PRs, and shape where it goes next.",
  bodyLines: [
    "The control plane and dashboard are AGPL-3.0. The WordPress agent is MIT-licensed. Both licenses are chosen so you can audit, fork, and deploy without restriction. There is no paid tier or per-site fee to run it yourself.",
    "The bundled Docker Compose stack brings up the control plane, dashboard, database, and storage in a few commands. Prebuilt container images are published for production setups. A built-in startup check (`validate-env`) lists every misconfigured environment variable at once, and the control plane stays up in a degraded mode with a clear /readyz 503 if a setting is wrong, so you can diagnose the problem without watching a crash loop. Good-first-issue labels are kept current on GitHub so contributors have a clear on-ramp.",
  ],
  command: "docker compose up -d",
  chips: [
    { icon: "Scale", value: "AGPL-3.0", label: "Control plane and dashboard, open to read, fork, and deploy" },
    { icon: "FileBadge", value: "MIT", label: "The WordPress agent carries a permissive license" },
    { icon: "GitPullRequest", value: "PRs welcome", label: "Good-first-issue labels maintained for new contributors" },
    { icon: "Infinity", value: "Unlimited sites", label: "No per-site fee, no artificial cap" },
  ],
  ctas: [
    { label: "Star on GitHub", href: SITE.github, variant: "primary" as const, icon: "Star" },
    { label: "See the live dashboard", href: SITE.dashboard, variant: "secondary" as const, icon: "ArrowRight" },
  ],
};

export const TECH_STACK = {
  eyebrow: "Under the hood",
  heading: "A readable, contributor-friendly stack top to bottom",
  subhead:
    "Every layer was chosen for clarity and long-term maintainability. One static binary for the control plane, typed contracts shared across the wire, and a WordPress agent you can read before you run it.",
  items: [
    {
      icon: "ServerCog",
      label: "Go control plane",
      blurb:
        "A single self-contained binary. Compiles in seconds, ships as a tiny container, and reads like straightforward imperative code. No JVM, no interpreter, no runtime surprises.",
    },
    {
      icon: "LayoutDashboard",
      label: "React 19 + TypeScript + Vite",
      blurb:
        "React 19 with TanStack Query for server state, Zustand for local UI state, shadcn/ui primitives, and Tailwind v4. Strict TypeScript throughout, fast hot reload with Vite.",
    },
    {
      icon: "FileBadge",
      label: "MIT-licensed PHP agent",
      blurb:
        "A lightweight WordPress plugin that needs only PHP 8.1 and WordPress 6.0. MIT-licensed so you can read, audit, fork, and embed it without restriction.",
    },
    {
      icon: "FileScan",
      label: "OpenAPI contract layer",
      blurb:
        "Types are generated from a single OpenAPI spec and shared by the Go server and the TypeScript client. Change the spec, regenerate, and both ends stay in sync automatically.",
    },
    {
      icon: "HardDrive",
      label: "PostgreSQL + Redis",
      blurb:
        "Row-level security handles multi-tenancy in the database itself, not in application logic. Redis backs the job queue and short-lived state so the control plane stays stateless.",
    },
    {
      icon: "Zap",
      label: "River job queue + Ed25519 + SSE",
      blurb:
        "A Go-native job queue (River) drives async work. Every command the control plane sends to an agent is Ed25519-signed. Live progress streams to the browser over Server-Sent Events.",
    },
    {
      icon: "Globe",
      label: "Run anywhere",
      blurb:
        "A bundled Docker Compose file brings up the full stack in one command. S3-compatible object storage means MinIO works locally and any cloud provider works in production.",
    },
  ],
} as const;

export const STATS = {
  eyebrow: "Day one",
  heading: "What you get on day one",
  subhead: "Concrete capabilities, not a roadmap.",
  items: [
    { icon: "ImageDown", value: "AVIF + WebP", label: "Media Optimizer delivers modern formats to browsers that support them, originals as fallback" },
    { icon: "Undo2", value: "100%", label: "Reversible by design: media revert, backup restore, and db clean all roll back cleanly" },
    { icon: "DatabaseZap", value: "DB Cleaner", label: "Scan, classify orphans, trend 90 days of health, and act on the whole fleet at once" },
    { icon: "Activity", value: "7 / 30 / 90 days", label: "Response-time and uptime history with fleet-wide status" },
    { icon: "Users", value: "4 roles", label: "From owner to viewer, plus single-site sharing" },
    { icon: "GitFork", value: "AGPL + MIT", label: "Fork, self-host, and contribute, no paid tier required" },
  ],
};

export const FAQ = {
  eyebrow: "FAQ",
  heading: "Questions, answered straight",
  subhead: "The things people ask before they self-host or contribute.",
  items: [
    {
      q: "Is WPMgr free?",
      a: "Yes. WPMgr is open source and free to self-host. The control plane and dashboard are AGPL-3.0, and the WordPress agent plugin is MIT-licensed. There is no paid tier or per-site fee to run it yourself.",
    },
    {
      q: "How do I contribute?",
      a: "Fork the repository on GitHub, pick a good-first-issue, and open a pull request. The control plane is Go with a generated OpenAPI layer, the frontend is React 19, and the agent is PHP. Each area has its own README with a dev-setup walkthrough. Architecture decisions are tracked in docs/adr/ so you can understand why things are built the way they are before you change them.",
    },
    {
      q: "Do I have to self-host it?",
      a: "Self-hosting is the way WPMgr is built to run today, and it is what keeps your data on infrastructure you own. The bundled Docker Compose stack brings up the full system (control plane, dashboard, database, and storage) with a few commands, and prebuilt container images are published for production setups.",
    },
    {
      q: "Does it work on managed or locked-down WordPress hosts?",
      a: "Yes. The agent is a lightweight PHP plugin that needs PHP 8.1+ and WordPress 6.0+, nothing more. Backups use a pure-PHP streaming dump and archiver with no shell access and no mysqldump binary, so they work even on hosts that lock those down.",
    },
    {
      q: "Is my data private?",
      a: "Your data lives on the infrastructure you run WPMgr on, not ours. Backups can be client-side encrypted so the control plane only ever stores ciphertext and never holds a decryption key, image bytes move directly between your site and your storage, and the agent redacts emails, passwords, secrets, tokens, and salts before any diagnostics ever leave a site.",
    },
    {
      q: "How many sites can I manage?",
      a: "There is no built-in site limit. WPMgr is designed as fleet management for one site or many, with a real-time sites list, multi-tenant organizations, role-based access, and per-site sharing so you can hand a collaborator exactly one site without exposing the rest.",
    },
    {
      q: "How does the Database Cleaner work?",
      a: "It scans before it deletes. A read-only scan shows row counts and space savings per category, a full per-table inventory with engine and overhead, and a 'belongs to' label identifying WordPress core tables, active plugin or theme tables, and orphans left by removed plugins. Orphaned options and cron events are matched against a corpus of plugin signatures so you know what is safe to remove before you remove it. Cleanup runs in batches, streams live progress, never locks a busy database, and supports a scheduled automatic clean the control plane drives.",
    },
    {
      q: "How do backups work?",
      a: "You set a schedule (hourly, daily, weekly, or monthly) and choose full or incremental. A full backup streams a database dump and file archive, encrypts each chunk client-side, and uploads only what is not already stored. An incremental backup compares the live file tree against the previous snapshot by size and modified time, packs only the files that changed, and records deletions as tombstones. The database is dumped in full on every run. Restore the whole site or a single plugin, theme, upload, or table to any point in the incremental chain, while the site stays online.",
    },
    {
      q: "What do client reports and the portal include?",
      a: "Each report covers uptime and response time, backups completed, updates applied, Core Web Vitals real-user p75, and email deliverability. Per-section on/off toggles and custom intro and closing text let you tailor every report. Delivery formats are a branded HTML email digest, a print-optimized page, and a downloadable PDF with vector charts. The client brand color and logo appear throughout; the powered-by footer is removable on any plan. The portal is read-only: a sites overview with softened status wording, per-site uptime and incident history, backup inventory, applied updates, Core Web Vitals field data, and completed report downloads. Portal access is revoked instantly when a member is removed, the client is archived, or the client is deleted. Clients are tenant-isolated; site-scoped collaborators cannot see the client roster.",
    },
    {
      q: "How does per-site email work?",
      a: "Connect Amazon SES, SendGrid, Mailgun, Postmark, or any SMTP server per site, or set an org-wide default that saves once and propagates to every inheriting site automatically. Define multiple named connections per site and map FROM addresses to a specific connection; a fallback connection retries automatically when the primary fails. Provider credentials are encrypted at rest and never returned by the API. Every email is logged centrally with full header detail and attachment metadata, searchable and filterable, with CSV and JSON export. Connect a provider webhook and hard bounces and complaints are suppressed automatically, per-site or fleet-wide. Email bodies are not stored by default; logs prune after 14 days.",
    },
  ],
};

export const FINAL_CTA = {
  heading: "Self-host it, contribute to it, or just use it. Your call.",
  subhead:
    "Bring up the full stack with a few commands, enroll your first site with a one-time code, and run your whole fleet from a dashboard that lives on infrastructure you control. Or fork it and build what you need.",
  body: "Free, open source, no per-site fee. Read every line before you run it.",
  ctas: [
    { label: "Star on GitHub", href: SITE.github, variant: "primary" as const, icon: "Github" },
    { label: "See the live dashboard", href: SITE.dashboard, variant: "secondary" as const, icon: "ArrowRight" },
  ],
};

export const FOOTER = {
  tagline: SITE.tagline,
  bodyLines: [
    "Open source under AGPL-3.0 (control plane and dashboard) and MIT (WordPress agent). Contributions welcome.",
    "WordPress is a trademark of the WordPress Foundation. WPMgr is an independent, self-hostable project and is not endorsed by, affiliated with, or sponsored by the WordPress Foundation or Automattic.",
  ],
  links: [
    { label: "GitHub", href: SITE.github, icon: "Github" },
    { label: "Contributing", href: SITE.github + "/blob/main/docs/contributing.md", icon: "GitPullRequest" },
    { label: "API reference", href: "/docs/", icon: "FileSearch" },
    { label: "Live dashboard", href: SITE.dashboard, icon: "LayoutDashboard" },
    { label: "License", href: SITE.github + "/blob/main/LICENSE", icon: "Scale" },
    { label: "Terms", href: SITE.dashboard + "/terms", icon: "ScrollText" },
    { label: "Privacy", href: SITE.dashboard + "/privacy", icon: "FileLock2" },
  ],
};
