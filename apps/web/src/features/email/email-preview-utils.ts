import DOMPurify from "dompurify";
import type { Config as DOMPurifyConfig } from "dompurify";

// ---------------------------------------------------------------------------
// email-preview-utils.ts
//
// Pure (non-React) utilities for the sandboxed email HTML preview.
// Extracted from safe-email-preview.tsx so they can be imported and tested
// without triggering react-refresh warnings about mixed component/utility
// exports in the same module.
// ---------------------------------------------------------------------------

/**
 * The iframe sandbox attribute value.
 * NEVER add allow-scripts or allow-same-origin here — that would collapse
 * the security model entirely.
 */
export const IFRAME_SANDBOX = "allow-popups allow-popups-to-escape-sandbox" as const;

/**
 * The DOMPurify configuration used for sanitizing email HTML bodies.
 *
 * Exported so tests can verify the security-critical configuration without
 * needing to invoke DOMPurify (which requires a DOM environment).
 *
 * Key decisions:
 *  - FORBID_TAGS: strips all active-content and navigation tags.
 *    The `<style>` TAG is forbidden (prevents CSS injection into the frame's
 *    stylesheet) but the inline `style=` ATTRIBUTE is kept (emails need it).
 *  - FORBID_ATTR: strips attributes that can load remote resources or
 *    trigger navigation outside the anchor href channel.
 *  - ALLOW_DATA_ATTR: false — prevents data-* exfiltration vectors.
 *  - DOMPurify's built-in defaults additionally strip: on*= event handlers,
 *    javascript:/vbscript: URLs, SVG mXSS patterns, and DOM clobbering.
 */
export const DOMPURIFY_CONFIG: DOMPurifyConfig = {
  WHOLE_DOCUMENT: false,
  FORBID_TAGS: [
    "script",
    "style",
    "iframe",
    "object",
    "embed",
    "base",
    "form",
    "input",
    "button",
    "textarea",
    "select",
    "audio",
    "video",
    "meta",
    "link",
  ],
  FORBID_ATTR: ["srcset", "ping", "formaction", "background"],
  ALLOW_DATA_ATTR: false,
};

/**
 * Build a minimal HTML document shell that wraps the already-sanitized body
 * fragment. The CSP <meta> is injected as the FIRST child of <head> —
 * browsers ignore a CSP meta that appears after other content.
 *
 * Exported as a pure function (no DOMPurify, no DOM) so unit tests can
 * exercise the shell construction without a browser environment.
 *
 * @param cleanHtml - The sanitized HTML fragment to embed in <body>.
 * @param loadRemote - When true, CSP img-src allows https: origins.
 */
export function buildEmailShell(cleanHtml: string, loadRemote: boolean): string {
  // img-src: allow https: only when the operator has explicitly opted in.
  const imgSrc = loadRemote ? "data: https:" : "data:";
  const csp = [
    "default-src 'none'",
    `img-src ${imgSrc}`,
    "style-src 'unsafe-inline'",
    "font-src data:",
    "script-src 'none'",
    "object-src 'none'",
    "frame-src 'none'",
    "form-action 'none'",
    "base-uri 'none'",
  ].join("; ");

  // <base target="_blank"> ensures any surviving links open in a new tab and
  // cannot navigate the parent frame.
  return `<!DOCTYPE html>
<html lang="en">
<head>
<meta http-equiv="Content-Security-Policy" content="${csp}">
<base target="_blank">
<style>html,body{margin:0;background:#fff;color:#111;color-scheme:light;}body{padding:16px;font:14px/1.5 -apple-system,system-ui,sans-serif;}</style>
</head>
<body>${cleanHtml}</body>
</html>`;
}

/**
 * Sanitize `html` with DOMPurify and wrap it in a minimal HTML document shell
 * that embeds a strict CSP <meta> as the FIRST child of <head>.
 *
 * Requires a browser DOM (DOMPurify cannot run in Node). For unit testing,
 * use `buildEmailShell` (pure) and validate `DOMPURIFY_CONFIG` (exported
 * constant) independently.
 */
export function buildEmailSrcDoc(html: string, loadRemote: boolean): string {
  const clean = DOMPurify.sanitize(html, DOMPURIFY_CONFIG);
  return buildEmailShell(clean, loadRemote);
}
