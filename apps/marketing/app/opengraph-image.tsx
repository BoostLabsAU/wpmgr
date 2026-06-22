import { ImageResponse } from "next/og";
import { OgLogo } from "@/lib/og-logo";

export const runtime = "nodejs";
export const alt = "WPMgr - Open-source WordPress fleet management";
export const size = { width: 1200, height: 630 };
export const contentType = "image/png";

// OG image uses hex colors instead of oklch() because the ImageResponse
// Satori renderer does not parse oklch() color syntax.
// Brand teal #1791A6 (hue 195) mapped to dark/light equivalents:
//   Dark bg: roughly oklch(15% 0.012 195) -> #101F22
//   Light teal: roughly oklch(68% 0.14 195) -> #1791A6
//   Foreground: roughly oklch(94% 0.008 195) -> #E8F0F1
//   Muted fg: roughly oklch(65% 0.014 195) -> #748A8D
//   Chip bg: roughly oklch(28% 0.07 195) -> #263236

const COLORS = {
  bg: "#101F22",
  teal: "#1791A6",
  fg: "#E8F0F1",
  muted: "#748A8D",
  chipBg: "#263236",
  chipText: "#1791A6",
};

export default function OgImage() {
  return new ImageResponse(
    (
      <div
        style={{
          width: "100%",
          height: "100%",
          display: "flex",
          flexDirection: "column",
          alignItems: "center",
          justifyContent: "center",
          background: COLORS.bg,
          padding: "64px",
          gap: "24px",
        }}
      >
        {/* Logo mark */}
        <div style={{ display: "flex", marginBottom: "8px" }}>
          <OgLogo teal={COLORS.teal} fg={COLORS.fg} />
        </div>

        {/* Headline */}
        <div
          style={{
            fontSize: "48px",
            fontWeight: 600,
            color: COLORS.fg,
            textAlign: "center",
            lineHeight: 1.12,
            letterSpacing: "-0.02em",
            maxWidth: "900px",
          }}
        >
          Open-source WordPress fleet management
        </div>

        {/* Subhead */}
        <div
          style={{
            fontSize: "24px",
            color: COLORS.muted,
            textAlign: "center",
            maxWidth: "800px",
            lineHeight: 1.5,
          }}
        >
          Backups, Media Optimizer, caching, security, and uptime monitoring. Self-hostable. Free.
        </div>

        {/* Chips row */}
        <div style={{ display: "flex", gap: "12px", marginTop: "8px" }}>
          {["AGPL-3.0", "MIT agent", "Ed25519-signed", "Self-hosted"].map((label) => (
            <div
              key={label}
              style={{
                padding: "6px 16px",
                borderRadius: "999px",
                background: COLORS.chipBg,
                color: COLORS.chipText,
                fontSize: "16px",
                fontWeight: 500,
              }}
            >
              {label}
            </div>
          ))}
        </div>

        {/* Base URL */}
        <div style={{ fontSize: "18px", color: COLORS.muted, marginTop: "4px" }}>
          wpmgr.app
        </div>
      </div>
    ),
    { ...size },
  );
}
