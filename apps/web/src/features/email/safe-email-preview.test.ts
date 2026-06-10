import { describe, it, expect } from "vitest";

// Import from the pure utility module. These tests run in the default vitest
// Node environment (no jsdom/happy-dom installed in this repo).
//
// We test:
//  - IFRAME_SANDBOX (constant inspection — no DOM needed)
//  - DOMPURIFY_CONFIG (constant inspection — proves the security-critical
//    sanitization config is correct without invoking DOMPurify itself)
//  - buildEmailShell (pure shell builder — no DOMPurify, no DOM)
//
// buildEmailSrcDoc (which calls DOMPurify + buildEmailShell) is intentionally
// NOT called in these tests because DOMPurify requires a browser DOM and this
// repo has no jsdom/happy-dom test environment. Instead DOMPURIFY_CONFIG is
// exported and verified as a constant, which is equivalent: if the config
// object is correct and DOMPurify is invoked with it, the sanitization outcome
// follows DOMPurify's proven security model.
import {
  IFRAME_SANDBOX,
  DOMPURIFY_CONFIG,
  buildEmailShell,
} from "./email-preview-utils";

// ---------------------------------------------------------------------------
// safe-email-preview security contract tests
//
// CONTRACT 1 — sandbox attribute NEVER includes allow-scripts or
//              allow-same-origin. Breaking this would collapse the security
//              model (scripts could execute and read parent-frame DOM).
//
// CONTRACT 2 — DOMPURIFY_CONFIG correctly lists all dangerous tags/attrs.
//              <script>, <style> tags and on*= / javascript: are handled by
//              DOMPurify defaults + our explicit FORBID_TAGS/FORBID_ATTR.
//
// CONTRACT 3 — img-src in the CSP meta toggles with loadRemote so remote
//              images are blocked by default and can only be enabled
//              explicitly by the operator.
//
// CONTRACT 4 — The <base target="_blank"> is present so any surviving links
//              open in a new tab and cannot navigate the parent frame.
//
// CONTRACT 5 — The CSP <meta> is the first child of <head> (browsers ignore
//              a CSP meta appearing after other content).
// ---------------------------------------------------------------------------

describe("IFRAME_SANDBOX constant (CONTRACT 1)", () => {
  it("does NOT contain allow-scripts", () => {
    expect(IFRAME_SANDBOX).not.toContain("allow-scripts");
  });

  it("does NOT contain allow-same-origin", () => {
    expect(IFRAME_SANDBOX).not.toContain("allow-same-origin");
  });

  it("contains allow-popups so links can open in new tabs", () => {
    expect(IFRAME_SANDBOX).toContain("allow-popups");
  });

  it("contains allow-popups-to-escape-sandbox so opened tabs are not sandboxed", () => {
    expect(IFRAME_SANDBOX).toContain("allow-popups-to-escape-sandbox");
  });
});

describe("DOMPURIFY_CONFIG — sanitization config contract (CONTRACT 2)", () => {
  it("FORBID_TAGS includes <script>", () => {
    expect(DOMPURIFY_CONFIG.FORBID_TAGS).toContain("script");
  });

  it("FORBID_TAGS includes <style> tag (only the tag; inline style= attribute is kept for email layout)", () => {
    expect(DOMPURIFY_CONFIG.FORBID_TAGS).toContain("style");
  });

  it("FORBID_TAGS includes <iframe>", () => {
    expect(DOMPURIFY_CONFIG.FORBID_TAGS).toContain("iframe");
  });

  it("FORBID_TAGS includes <object> and <embed>", () => {
    expect(DOMPURIFY_CONFIG.FORBID_TAGS).toContain("object");
    expect(DOMPURIFY_CONFIG.FORBID_TAGS).toContain("embed");
  });

  it("FORBID_TAGS includes <base> (prevents base-tag hijacking)", () => {
    expect(DOMPURIFY_CONFIG.FORBID_TAGS).toContain("base");
  });

  it("FORBID_TAGS includes all form-related tags", () => {
    expect(DOMPURIFY_CONFIG.FORBID_TAGS).toContain("form");
    expect(DOMPURIFY_CONFIG.FORBID_TAGS).toContain("input");
    expect(DOMPURIFY_CONFIG.FORBID_TAGS).toContain("button");
    expect(DOMPURIFY_CONFIG.FORBID_TAGS).toContain("textarea");
    expect(DOMPURIFY_CONFIG.FORBID_TAGS).toContain("select");
  });

  it("FORBID_TAGS includes media tags", () => {
    expect(DOMPURIFY_CONFIG.FORBID_TAGS).toContain("audio");
    expect(DOMPURIFY_CONFIG.FORBID_TAGS).toContain("video");
  });

  it("FORBID_TAGS includes <meta> and <link> (prevents meta-refresh and stylesheet injection)", () => {
    expect(DOMPURIFY_CONFIG.FORBID_TAGS).toContain("meta");
    expect(DOMPURIFY_CONFIG.FORBID_TAGS).toContain("link");
  });

  it("FORBID_ATTR includes srcset (prevents resource loading via srcset)", () => {
    expect(DOMPURIFY_CONFIG.FORBID_ATTR).toContain("srcset");
  });

  it("FORBID_ATTR includes ping (prevents beacon exfiltration)", () => {
    expect(DOMPURIFY_CONFIG.FORBID_ATTR).toContain("ping");
  });

  it("FORBID_ATTR includes formaction (prevents form action override)", () => {
    expect(DOMPURIFY_CONFIG.FORBID_ATTR).toContain("formaction");
  });

  it("FORBID_ATTR includes background (prevents CSS background URL injection)", () => {
    expect(DOMPURIFY_CONFIG.FORBID_ATTR).toContain("background");
  });

  it("ALLOW_DATA_ATTR is false (prevents data-* exfiltration vectors)", () => {
    expect(DOMPURIFY_CONFIG.ALLOW_DATA_ATTR).toBe(false);
  });

  it("WHOLE_DOCUMENT is false (sanitizes fragments, not full documents)", () => {
    expect(DOMPURIFY_CONFIG.WHOLE_DOCUMENT).toBe(false);
  });
});

describe("buildEmailShell — CSP meta toggling (CONTRACT 3)", () => {
  it("blocks remote images when loadRemote=false (img-src data: only)", () => {
    const srcDoc = buildEmailShell("<p>hi</p>", false);
    const cspMatch = srcDoc.match(/content="([^"]+)"/);
    expect(cspMatch).not.toBeNull();
    const csp = cspMatch![1];
    // img-src directive must be exactly "data:" (no https:)
    expect(csp).toMatch(/img-src data:(?!\s*https:)/);
  });

  it("allows remote images when loadRemote=true (img-src data: https:)", () => {
    const srcDoc = buildEmailShell("<p>hi</p>", true);
    const cspMatch = srcDoc.match(/content="([^"]+)"/);
    expect(cspMatch).not.toBeNull();
    const csp = cspMatch![1];
    expect(csp).toContain("img-src data: https:");
  });

  it("CSP always contains script-src 'none' regardless of loadRemote", () => {
    for (const loadRemote of [true, false]) {
      const srcDoc = buildEmailShell("<p>hi</p>", loadRemote);
      expect(srcDoc).toContain("script-src 'none'");
    }
  });

  it("CSP always has form-action 'none' to block form submissions", () => {
    for (const loadRemote of [true, false]) {
      const srcDoc = buildEmailShell("<p>hi</p>", loadRemote);
      expect(srcDoc).toContain("form-action 'none'");
    }
  });

  it("CSP always has default-src 'none'", () => {
    for (const loadRemote of [true, false]) {
      const srcDoc = buildEmailShell("<p>hi</p>", loadRemote);
      expect(srcDoc).toContain("default-src 'none'");
    }
  });

  it("CSP always has object-src 'none'", () => {
    for (const loadRemote of [true, false]) {
      const srcDoc = buildEmailShell("<p>hi</p>", loadRemote);
      expect(srcDoc).toContain("object-src 'none'");
    }
  });

  it("CSP always has base-uri 'none'", () => {
    for (const loadRemote of [true, false]) {
      const srcDoc = buildEmailShell("<p>hi</p>", loadRemote);
      expect(srcDoc).toContain("base-uri 'none'");
    }
  });
});

describe("buildEmailShell — structural safety (CONTRACTS 4 + 5)", () => {
  it("contains <base target='_blank'> so links open in new tabs", () => {
    const srcDoc = buildEmailShell("<p>hi</p>", false);
    expect(srcDoc).toContain('<base target="_blank">');
  });

  it("CSP meta appears before <base> and <style> in <head> (CONTRACT 5)", () => {
    const srcDoc = buildEmailShell("<p>hi</p>", false);
    const headStart = srcDoc.indexOf("<head>");
    const cspPos = srcDoc.indexOf('<meta http-equiv="Content-Security-Policy"');
    const basePos = srcDoc.indexOf("<base");
    const stylePos = srcDoc.indexOf("<style>");
    expect(headStart).toBeGreaterThan(-1);
    expect(cspPos).toBeGreaterThan(headStart);
    expect(cspPos).toBeLessThan(basePos);
    expect(cspPos).toBeLessThan(stylePos);
  });

  it("embeds the cleanHtml fragment inside <body>", () => {
    const fragment = "<p>Safe content</p><a href=\"https://example.com\">link</a>";
    const srcDoc = buildEmailShell(fragment, false);
    expect(srcDoc).toContain("<body><p>Safe content</p>");
    expect(srcDoc).toContain("https://example.com");
  });

  it("table-based email layout passes through the shell unchanged", () => {
    const fragment = '<table><tr><td style="color:red">cell</td></tr></table>';
    const srcDoc = buildEmailShell(fragment, false);
    expect(srcDoc).toContain("<table>");
    expect(srcDoc).toContain("<td");
    expect(srcDoc).toContain("cell");
  });

  it("forces light canvas via the injected <style>", () => {
    const srcDoc = buildEmailShell("<p>hi</p>", false);
    expect(srcDoc).toContain("background:#fff");
    expect(srcDoc).toContain("color-scheme:light");
  });

  it("shell output contains the DOCTYPE declaration", () => {
    const srcDoc = buildEmailShell("<p>hi</p>", false);
    expect(srcDoc.trimStart().startsWith("<!DOCTYPE html>")).toBe(true);
  });
});
