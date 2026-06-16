/**
 * SiteCardThumbnail — 4-state screenshot hero for the site card grid view.
 *
 * States:
 *   ready      — screenshot_url present: lazy-loaded img from the CDN.
 *   capturing  — screenshot_status "pending": muted panel + slow Camera pulse.
 *   failed     — screenshot_status "failed": muted panel + ImageOff icon.
 *   never      — no screenshot fields at all (the default until the API
 *                surfaces screenshot_url): branded placeholder = site favicon
 *                onError->monogram.
 *
 * The real screenshot fields (`screenshot_url`, `screenshot_status`,
 * `screenshot_captured_at`, `screenshot_url_2x`) live directly on the
 * generated `Site` type (M72). No local widening needed.
 *
 * Aspect ratio: 16/10 (comfortable) or 16/9 (compact, passed via className).
 * Border radius: top corners only (card body provides the full card radius).
 */
import { useState } from "react";
import { Camera, ImageOff } from "lucide-react";
import type { Site } from "@wpmgr/api";

import { cn } from "@/lib/utils";

// ─── Deterministic hue from hostname ─────────────────────────────────────────

/**
 * Fold a hostname string into a 0-359 hue value so each domain gets its own
 * branded muted tint. Pure function; the same hostname always returns the same
 * hue so the tint is stable across re-renders and SSR.
 */
function hostnameToHue(hostname: string): number {
  let h = 0;
  for (let i = 0; i < hostname.length; i++) {
    h = (h * 31 + hostname.charCodeAt(i)) & 0xffff;
  }
  return h % 360;
}

// ─── Sub-components ───────────────────────────────────────────────────────────

function MutedPanel({
  children,
  className,
}: {
  children: React.ReactNode;
  className?: string;
}) {
  return (
    <div
      className={cn(
        "flex h-full w-full items-center justify-center bg-muted",
        className,
      )}
    >
      {children}
    </div>
  );
}

/**
 * "never" state: try the site favicon; fall back to a first-initial monogram
 * over a deterministic per-host muted hue tint.
 */
function NeverPlaceholder({
  hostname,
  siteName,
}: {
  hostname: string;
  siteName: string;
}) {
  const [faviconFailed, setFaviconFailed] = useState(false);
  const hue = hostnameToHue(hostname);

  const initial = (siteName || hostname).charAt(0).toUpperCase() || "?";

  const bgStyle: React.CSSProperties = {
    backgroundColor: `oklch(25% 0.04 ${hue})`,
  };

  return (
    <div
      className="flex h-full w-full items-center justify-center"
      style={bgStyle}
    >
      {!faviconFailed ? (
        <img
          src={`https://${hostname}/favicon.ico`}
          alt=""
          aria-hidden="true"
          loading="lazy"
          onError={() => setFaviconFailed(true)}
          className="size-8 object-contain"
        />
      ) : (
        <span
          aria-hidden="true"
          className="select-none font-mono text-2xl font-semibold"
          style={{ color: `oklch(75% 0.06 ${hue})` }}
        >
          {initial}
        </span>
      )}
    </div>
  );
}

// ─── Main component ───────────────────────────────────────────────────────────

export interface SiteCardThumbnailProps {
  site: Site;
  /** Override aspect ratio + other layout classes (default: aspect-[16/10]). */
  className?: string;
  /** When true, the "captured {time}" caption is hidden. */
  hideCaption?: boolean;
}

export function SiteCardThumbnail({
  site,
  className,
  // hideCaption retained in the prop interface; caption was moved to the card
  // footer as a labeled metadata row — the overlay itself no longer exists.
  hideCaption: _hideCaption = false,
}: SiteCardThumbnailProps) {
  const [imgError, setImgError] = useState(false);

  // Derive the thumbnail state from the screenshot fields (M72).
  const state = (() => {
    if (site.screenshot_url && !imgError) return "ready" as const;
    if (site.screenshot_status === "pending") return "capturing" as const;
    if (site.screenshot_status === "failed") return "failed" as const;
    if (imgError && site.screenshot_url) return "failed" as const;
    return "never" as const;
  })();

  let hostname = "";
  try {
    hostname = new URL(site.url).hostname || site.url;
  } catch {
    hostname = site.url.replace(/^https?:\/\//i, "").replace(/\/$/, "");
  }

  return (
    <div
      className={cn(
        "relative w-full overflow-hidden rounded-t-lg",
        "aspect-[16/10]",
        className,
      )}
    >
      {state === "ready" && site.screenshot_url ? (
        <img
          src={site.screenshot_url}
          srcSet={
            site.screenshot_url_2x
              ? `${site.screenshot_url} 1x, ${site.screenshot_url_2x} 2x`
              : undefined
          }
          alt={`Screenshot of ${site.name || hostname}`}
          loading="lazy"
          onError={() => setImgError(true)}
          className="h-full w-full object-cover object-top"
        />
      ) : state === "capturing" ? (
        <MutedPanel>
          {/* Slow pulse: Camera icon, motion-safe only. */}
          <Camera
            aria-label="Screenshot capturing"
            className="size-8 text-muted-foreground motion-safe:animate-pulse"
          />
        </MutedPanel>
      ) : state === "failed" ? (
        <MutedPanel>
          <ImageOff
            aria-label="Screenshot unavailable"
            className="size-8 text-muted-foreground/50"
          />
        </MutedPanel>
      ) : (
        <NeverPlaceholder hostname={hostname} siteName={site.name ?? ""} />
      )}

      {/* Caption overlay removed — "captured X ago" moved to the card footer
          as a labeled metadata row to prevent text-over-image overlap. */}
    </div>
  );
}
