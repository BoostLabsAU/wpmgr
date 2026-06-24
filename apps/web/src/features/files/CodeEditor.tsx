// CodeEditor.tsx — eager lazy-boundary for the CodeMirror 6 editor.
//
// This file is safe to import from anywhere in the app. It does NOT import
// CodeMirror itself — that stays in the lazy chunk (CodeMirrorEditor.tsx).
// The only code that runs on the initial bundle load is the tiny Suspense
// wrapper + the skeleton fallback.
//
// langFromPath and EditorLanguage live in editor-lang.ts (separate module)
// so this file only exports React components, keeping fast-refresh working.

import { Suspense, lazy } from "react";

import { Skeleton } from "@/components/ui/skeleton";

import type { EditorLanguage } from "./editor-lang";

// ── Lazy-loaded editor chunk ──────────────────────────────────────────────────

// React.lazy: the import() call is the chunk split point. Vite places the
// CodeMirrorEditor module (and all its @codemirror/* imports) in a separate
// async chunk that is only fetched when the editor first renders.
const LazyCodeMirrorEditor = lazy(
  () => import("./CodeMirrorEditor"),
);

// ── Props ────────────────────────────────────────────────────────────────────

export interface CodeEditorProps {
  value: string;
  onChange?: (value: string) => void;
  language: EditorLanguage;
  readOnly?: boolean;
  ariaLabel?: string;
  className?: string;
  height?: string;
  /** Auto-focus on mount (pass true for the edit dialog, false/omit for read-only previews). */
  autoFocus?: boolean;
}

// ── Skeleton fallback ────────────────────────────────────────────────────────

function EditorSkeleton({ height }: { height: string }) {
  return (
    <div
      style={{ height }}
      className="overflow-hidden rounded-md border border-[var(--color-border)] bg-[var(--color-muted)]"
      aria-hidden="true"
    >
      {/* Mimic gutter + lines */}
      <div className="flex h-full">
        {/* Gutter column */}
        <div className="w-12 shrink-0 border-r border-[var(--color-border)] px-2 py-3 space-y-2">
          {Array.from({ length: 12 }).map((_, i) => (
            <Skeleton key={i} className="h-3 w-5 opacity-40" />
          ))}
        </div>
        {/* Line area */}
        <div className="flex-1 px-3 py-3 space-y-2">
          {Array.from({ length: 12 }).map((_, i) => (
            <Skeleton
              key={i}
              className="h-3 opacity-30"
              style={{ width: `${55 + ((i * 17) % 35)}%` }}
            />
          ))}
        </div>
      </div>
    </div>
  );
}

// ── CodeEditor ────────────────────────────────────────────────────────────────

/**
 * Lazy-loaded CodeMirror 6 editor with syntax highlighting, line numbers,
 * bracket matching, foldable code, and search (Ctrl/Cmd+F).
 *
 * Import this component (and langFromPath) from CodeEditor.tsx; never import
 * CodeMirrorEditor directly.
 */
export function CodeEditor({
  value,
  onChange,
  language,
  readOnly = false,
  ariaLabel,
  className,
  height = "min(60vh, 480px)",
  autoFocus = false,
}: CodeEditorProps) {
  return (
    <Suspense fallback={<EditorSkeleton height={height} />}>
      <LazyCodeMirrorEditor
        value={value}
        onChange={onChange}
        language={language}
        readOnly={readOnly}
        ariaLabel={ariaLabel}
        className={className}
        height={height}
        autoFocus={autoFocus}
      />
    </Suspense>
  );
}
