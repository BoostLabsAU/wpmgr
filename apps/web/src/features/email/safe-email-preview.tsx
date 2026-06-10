import * as React from "react";

import { buildEmailSrcDoc, IFRAME_SANDBOX } from "./email-preview-utils";

// eslint-disable-next-line react-refresh/only-export-components -- re-exporting pure utilities from a co-located module; tests consume these exports directly.
export { buildEmailSrcDoc, IFRAME_SANDBOX } from "./email-preview-utils";

// ---------------------------------------------------------------------------
// SafeEmailPreview
//
// Renders arbitrary email HTML inside a sandboxed <iframe> so the host page
// is fully isolated from:
//   - Script execution (no allow-scripts in sandbox)
//   - Same-origin DOM access (no allow-same-origin in sandbox)
//   - Redirect / form submission (no allow-forms / allow-top-navigation)
//   - Exfiltration via remote images (CSP img-src gated by loadRemote)
//
// Security layer order:
//   1. DOMPurify (in email-preview-utils.ts) removes XSS payloads from the
//      HTML string itself.
//   2. A strict CSP <meta> (first child of <head>) prevents any residual
//      active content from executing inside the frame.
//   3. The iframe sandbox attribute provides OS-level process isolation.
//
// NEVER add allow-scripts or allow-same-origin to the sandbox — that would
// collapse the security model entirely.
// ---------------------------------------------------------------------------

export interface SafeEmailPreviewProps {
  html: string;
  loadRemote: boolean;
}

/**
 * Renders `html` inside a fully sandboxed iframe.
 * Degrades to a plain <pre> if DOMPurify or the shell builder throws.
 */
export function SafeEmailPreview({ html, loadRemote }: SafeEmailPreviewProps) {
  return (
    <SafeEmailPreviewInner html={html} loadRemote={loadRemote} />
  );
}

// ---------------------------------------------------------------------------
// Error boundary (class component — hooks are not available in error boundaries)
// Lives as a child so functional callers can use React 19 hooks normally.
// ---------------------------------------------------------------------------

interface InnerState { error: boolean }

class SafeEmailPreviewInner extends React.Component<SafeEmailPreviewProps, InnerState> {
  constructor(props: SafeEmailPreviewProps) {
    super(props);
    this.state = { error: false };
  }

  // React types declare getDerivedStateFromError as an optional static
  // property on the class (not via inheritance) so `override` does not apply.
  static getDerivedStateFromError(): InnerState {
    return { error: true };
  }

  override render() {
    const { html, loadRemote } = this.props;

    if (this.state.error) {
      return (
        <pre className="max-h-64 overflow-auto rounded-md bg-[var(--color-muted)] px-3 py-2 text-xs">
          {html}
        </pre>
      );
    }

    return <SafeEmailIframe html={html} loadRemote={loadRemote} />;
  }
}

// ---------------------------------------------------------------------------
// The actual iframe renderer — memoizes the srcDoc
// ---------------------------------------------------------------------------

function SafeEmailIframe({ html, loadRemote }: SafeEmailPreviewProps) {
  const srcDoc = React.useMemo(
    () => buildEmailSrcDoc(html, loadRemote),
    [html, loadRemote],
  );

  return (
    <iframe
      title="Email preview"
      srcDoc={srcDoc}
      sandbox={IFRAME_SANDBOX}
      referrerPolicy="no-referrer"
      loading="lazy"
      className="w-full h-[24rem] max-h-[50vh] overflow-auto rounded-md border border-[var(--color-border)] bg-white"
    />
  );
}
