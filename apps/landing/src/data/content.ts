// Single source of truth for every word on the landing page. Keeping the copy
// here (not inline in JSX) makes the no-em-dash and no-competitor sweeps a
// one-file grep, and keeps section components purely about layout.
//
// House rules baked into this copy: no em dashes, no en dashes, no competitor
// names. Use "to" for ranges. Verified by scripts/check-copy at build time.

export const SITE = {
  name: "WPMgr",
  tagline: "All your WordPress sites, one dashboard you own.",
  github: "https://github.com/mosamlife/wpmgr",
  dashboard: "https://manage.wpmgr.app",
  metaTitle: "WPMgr: Open-Source, Self-Hosted WordPress Fleet Management",
  metaDescription:
    "Manage all your WordPress sites from one dashboard you own. Open source and self-hostable: scheduled backups, safe fleet updates, AVIF and WebP image optimization, uptime, and security, with a signed agent you can audit.",
} as const;

export const NAV = {
  links: [
    { label: "Features", href: "#features" },
    { label: "Media", href: "#media" },
    { label: "How it works", href: "#how-it-works" },
    { label: "Open source", href: "#open-source" },
    { label: "API", href: "/docs/" },
    { label: "FAQ", href: "#faq" },
  ],
};

export const HERO = {
  badge: "First public release, v0.12",
  heading: "Run your whole WordPress fleet from one dashboard you actually own",
  subhead:
    "WPMgr is the open-source, self-hostable control panel for everyone managing one site or a hundred. Enroll, monitor, update, back up, optimize images, and lock down every site from a single screen, on infrastructure you control, with a signed agent you can audit line by line.",
  bodyLines: [
    "Add a site by URL, paste a one-time code into the plugin, and watch it flip from Awaiting to Connected with no page refresh.",
    "Open source under AGPL with an MIT-licensed agent. Every message it exchanges is Ed25519-signed, so nothing happens to your sites you cannot verify.",
  ],
  trust: [
    { icon: "BadgeCheck", title: "No per-site fees", desc: "Free to self-host, no paid tier" },
    { icon: "ServerCog", title: "Your infrastructure", desc: "Fleet data never leaves your server" },
    { icon: "FileSearch", title: "Auditable agent", desc: "Read every line before you run it" },
  ],
  ctas: [
    { label: "Self-host it", href: SITE.github, variant: "primary" as const, icon: "Github" },
    { label: "See the live dashboard", href: SITE.dashboard, variant: "secondary" as const, icon: "ArrowRight" },
  ],
};

export const TRUST = {
  eyebrow: "Why trust it",
  heading: "Trust earned the honest way, not borrowed from logos",
  subhead:
    "No customer logos to show yet, because WPMgr just shipped its first public release. So we will earn your trust the open way instead.",
  bodyLines: [
    "Everything is open source under the AGPL, so you can read every line before you run it. The WordPress agent is MIT-licensed and every message it exchanges with the control plane is Ed25519-signed.",
    "Self-host it on your own server and your fleet data, backups, and media never leave infrastructure you control. Browse the live dashboard to see exactly what you get, then read the code on GitHub and judge it for yourself.",
  ],
  chips: [
    { icon: "Scale", value: "AGPL-3.0", label: "Read the whole control plane in the open" },
    { icon: "FileBadge", value: "MIT", label: "The WordPress agent is permissively licensed" },
    { icon: "KeyRound", value: "Ed25519", label: "Every agent message is cryptographically signed" },
    { icon: "HardDrive", value: "Self-hosted", label: "Your data stays on infrastructure you run" },
  ],
  cta: { label: "Read the code on GitHub", href: SITE.github, icon: "ArrowRight" },
};

export const FEATURES = {
  eyebrow: "The platform",
  heading: "Everything you need to run a fleet, included",
  subhead:
    "Connect sites, back them up, update them safely, watch their health, and lock them down. One dashboard, no add-on sprawl.",
  body:
    "Each capability ships in the open release. Turn on what you need, leave the rest off, and manage one site or hundreds from the same screen.",
  cards: [
    {
      icon: "Network",
      title: "Fleet connection",
      desc:
        "Add a site by URL, paste a one-time code into the plugin, and it goes live with no refresh. One-click login into wp-admin with no shared passwords, plus live status dots that flip the moment a site goes up, slow, or offline.",
    },
    {
      icon: "DatabaseBackup",
      title: "Backups and restore",
      desc:
        "Schedule backups of your database and files, then restore the whole site, just the database, just files, or a single plugin, theme, or upload. Only changed files re-upload, the site stays live during a restore, and a failed restore never leaves it half-broken.",
    },
    {
      icon: "RefreshCw",
      title: "Fleet updates",
      desc:
        "Preview exactly which versions will change before anything updates. A snapshot is taken before each update and reverted automatically if the site fails its health check. Push to a group or a tag in one bulk run with live per-site progress.",
    },
    {
      icon: "Activity",
      title: "Monitoring and health",
      desc:
        "Uptime and response-time charts over 7, 30, and 90 days, plus a fleet-wide status overview. One alert when a site goes down and one when it recovers, by email or webhook, with TLS expiry warnings and PHP fatal-error tracking.",
    },
    {
      icon: "ShieldCheck",
      title: "Security",
      desc:
        "Scan core files against the official WordPress checksums and flag anything modified, missing, or injected. Block brute-force logins in escalating steps without locking out real admins, allow or deny by IP with a safety rail for your own IP, and whitelabel the login page.",
    },
    {
      icon: "Users",
      title: "Team and access",
      desc:
        "Four roles from owner to viewer, so each person gets exactly the access they need. Share one site with a collaborator who can never see the rest of your fleet, sign in with email, or with your company's single sign-on (OIDC), and keep a tamper-evident audit log of every action.",
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
  heading: "Open source, self-hosted, and yours to keep",
  subhead:
    "Bring up the full stack with a few commands and your fleet runs entirely on infrastructure you own.",
  bodyLines: [
    "WPMgr is free to self-host with no paid tier or per-site fee. The control plane and dashboard are AGPL-3.0 and the WordPress agent plugin is MIT-licensed.",
    "The bundled Docker Compose stack brings up the control plane, dashboard, database, and storage in a few commands, and prebuilt container images are published for production setups.",
  ],
  command: "docker compose up -d",
  chips: [
    { icon: "Scale", value: "AGPL-3.0", label: "Control plane and dashboard, open to read and audit" },
    { icon: "FileBadge", value: "MIT", label: "The WordPress agent carries a permissive license" },
    { icon: "ContainerIcon", value: "Compose", label: "Full system up in a few commands" },
    { icon: "Infinity", value: "Unlimited", label: "Manage one site or hundreds, no per-site fee" },
  ],
  ctas: [
    { label: "Star on GitHub", href: SITE.github, variant: "primary" as const, icon: "Star" },
    { label: "See the live dashboard", href: SITE.dashboard, variant: "secondary" as const, icon: "ArrowRight" },
  ],
};

export const STATS = {
  eyebrow: "Day one",
  heading: "What you get on day one",
  subhead: "Concrete capabilities, not a roadmap.",
  items: [
    { icon: "ImageDown", value: "AVIF + WebP", label: "Modern formats delivered to browsers that support them, originals as fallback" },
    { icon: "Undo2", value: "100%", label: "Reversible by design, restore reverts every variant" },
    { icon: "ShieldOff", value: "0 bytes", label: "Image bytes on the control plane, presigned URLs only" },
    { icon: "Activity", value: "7 / 30 / 90 days", label: "Response-time and uptime history with fleet-wide status" },
    { icon: "Users", value: "4 roles", label: "From owner to viewer, plus single-site sharing" },
    { icon: "KeyRound", value: "Ed25519", label: "Signed on every control-plane message between agent and control plane" },
  ],
};

export const FAQ = {
  eyebrow: "FAQ",
  heading: "Questions, answered straight",
  subhead: "The things people ask before they self-host.",
  items: [
    {
      q: "Is WPMgr free?",
      a: "Yes. WPMgr is open source and free to self-host. The control plane and dashboard are AGPL-3.0, and the WordPress agent plugin is MIT-licensed. There is no paid tier or per-site fee to run it yourself.",
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
      q: "How do backups work?",
      a: "You set a schedule (hourly, daily, weekly, or monthly), and each backup streams a database dump and file archive, chunks and deduplicates them so only changed data re-uploads, and stores them in your chosen destination. Restore the whole site or a single plugin, theme, upload, or table, atomically, while the site stays online.",
    },
  ],
};

export const FINAL_CTA = {
  heading: "Own your WordPress management. Self-host it in minutes.",
  subhead:
    "Bring up the full stack with a few commands, enroll your first site with a one-time code, and run your whole fleet from a dashboard that lives on infrastructure you control.",
  body: "Free, open source, and no per-site fee. Read every line before you run it.",
  ctas: [
    { label: "Self-host it", href: SITE.github, variant: "primary" as const, icon: "Github" },
    { label: "See the live dashboard", href: SITE.dashboard, variant: "secondary" as const, icon: "ArrowRight" },
  ],
};

export const FOOTER = {
  tagline: SITE.tagline,
  bodyLines: [
    "Open source under AGPL-3.0 (control plane and dashboard) and MIT (WordPress agent).",
    "WordPress is a trademark of the WordPress Foundation. WPMgr is an independent, self-hostable project and is not endorsed by, affiliated with, or sponsored by the WordPress Foundation or Automattic.",
  ],
  links: [
    { label: "GitHub", href: SITE.github, icon: "Github" },
    { label: "API reference", href: "/docs/", icon: "FileSearch" },
    { label: "Live dashboard", href: SITE.dashboard, icon: "LayoutDashboard" },
    { label: "License", href: SITE.github + "/blob/main/LICENSE", icon: "Scale" },
  ],
};
