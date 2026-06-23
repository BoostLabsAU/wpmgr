import type { ReactElement } from "react";

// Shared logo mark for OG image cards (Satori / Node runtime).
// Renders the Fleet Hub mark (one center node, four hollow satellites, floating
// spokes) plus the two-tone "wpmgr" wordmark. Colors are passed explicitly
// because the Satori renderer does not resolve currentColor or oklch().
// The mark deliberately avoids any WordPress letterform or circle-W.
export function OgLogo({
  teal,
  fg,
  markSize = 56,
  fontSize = 40,
}: {
  teal: string;
  fg: string;
  markSize?: number;
  fontSize?: number;
}): ReactElement {
  return (
    <div style={{ display: "flex", alignItems: "center", gap: "16px" }}>
      <svg width={markSize} height={markSize} viewBox="0 0 32 32" fill="none">
        <rect x="12" y="12" width="8" height="8" rx="2" fill={teal} />
        <rect x="4.5" y="4.5" width="5" height="5" rx="1.5" stroke={teal} strokeWidth="1.75" fill="none" />
        <rect x="22.5" y="4.5" width="5" height="5" rx="1.5" stroke={teal} strokeWidth="1.75" fill="none" />
        <rect x="4.5" y="22.5" width="5" height="5" rx="1.5" stroke={teal} strokeWidth="1.75" fill="none" />
        <rect x="22.5" y="22.5" width="5" height="5" rx="1.5" stroke={teal} strokeWidth="1.75" fill="none" />
        <line x1="9.8" y1="9.8" x2="11.8" y2="11.8" stroke={teal} strokeWidth="1.75" strokeLinecap="round" />
        <line x1="22.2" y1="9.8" x2="20.2" y2="11.8" stroke={teal} strokeWidth="1.75" strokeLinecap="round" />
        <line x1="9.8" y1="22.2" x2="11.8" y2="20.2" stroke={teal} strokeWidth="1.75" strokeLinecap="round" />
        <line x1="22.2" y1="22.2" x2="20.2" y2="20.2" stroke={teal} strokeWidth="1.75" strokeLinecap="round" />
      </svg>
      <div
        style={{
          display: "flex",
          fontSize: `${fontSize}px`,
          fontWeight: 700,
          letterSpacing: "-0.02em",
        }}
      >
        <span style={{ color: fg }}>wp</span>
        <span style={{ color: teal }}>mgr</span>
      </div>
    </div>
  );
}
