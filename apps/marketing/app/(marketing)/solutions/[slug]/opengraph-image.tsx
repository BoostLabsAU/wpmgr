import { ImageResponse } from "next/og";
import { getSolution, SOLUTION_SLUGS } from "@/lib/content/solutions";
import { OgLogo } from "@/lib/og-logo";

export const runtime = "nodejs";
export const size = { width: 1200, height: 630 };
export const contentType = "image/png";

// OG image uses hex colors instead of oklch() because Satori does not parse oklch().
// See app/opengraph-image.tsx for the full color mapping rationale.
const COLORS = {
  bg: "#101F22",
  teal: "#1791A6",
  fg: "#E8F0F1",
  muted: "#748A8D",
  chipBg: "#263236",
  chipText: "#1791A6",
  border: "#1D2F33",
};

export function generateStaticParams() {
  return SOLUTION_SLUGS.map((slug) => ({ slug }));
}

type Props = { params: Promise<{ slug: string }> };

export default async function SolutionOgImage({ params }: Props) {
  const { slug } = await params;
  const solution = getSolution(slug);

  if (!solution) {
    return new ImageResponse(
      <div style={{ background: COLORS.bg, width: "100%", height: "100%" }} />,
      { ...size },
    );
  }

  const title = solution.heading;
  const tagline = solution.hero.subhead;
  const eyebrow = solution.hero.eyebrow;

  return new ImageResponse(
    (
      <div
        style={{
          width: "100%",
          height: "100%",
          display: "flex",
          flexDirection: "column",
          background: COLORS.bg,
          padding: "56px 64px",
        }}
      >
        {/* Logo row */}
        <div style={{ display: "flex", alignItems: "center", gap: "14px", marginBottom: "36px" }}>
          <OgLogo teal={COLORS.teal} fg={COLORS.fg} markSize={44} fontSize={28} />
          <div
            style={{
              marginLeft: "12px",
              padding: "4px 14px",
              borderRadius: "999px",
              background: COLORS.chipBg,
              color: COLORS.chipText,
              fontSize: "14px",
              fontWeight: 500,
            }}
          >
            {eyebrow}
          </div>
        </div>

        {/* Title */}
        <div
          style={{
            fontSize: title.length > 60 ? "38px" : "46px",
            fontWeight: 600,
            color: COLORS.fg,
            lineHeight: 1.15,
            letterSpacing: "-0.02em",
            maxWidth: "1000px",
            flex: 1,
          }}
        >
          {title}
        </div>

        {/* Tagline: truncated to two lines */}
        <div
          style={{
            fontSize: "21px",
            color: COLORS.muted,
            lineHeight: 1.5,
            maxWidth: "900px",
            marginTop: "20px",
            display: "-webkit-box",
            WebkitLineClamp: 2,
            WebkitBoxOrient: "vertical",
            overflow: "hidden",
          }}
        >
          {tagline}
        </div>

        {/* Bottom bar */}
        <div
          style={{
            display: "flex",
            alignItems: "center",
            justifyContent: "space-between",
            marginTop: "32px",
            paddingTop: "20px",
            borderTop: `1px solid ${COLORS.border}`,
          }}
        >
          <span style={{ fontSize: "16px", color: COLORS.muted }}>wpmgr.app/solutions</span>
          <div style={{ display: "flex", gap: "10px" }}>
            {["Open source", "Self-hostable"].map((label) => (
              <div
                key={label}
                style={{
                  padding: "5px 14px",
                  borderRadius: "999px",
                  background: COLORS.chipBg,
                  color: COLORS.chipText,
                  fontSize: "14px",
                  fontWeight: 500,
                }}
              >
                {label}
              </div>
            ))}
          </div>
        </div>
      </div>
    ),
    { ...size },
  );
}
