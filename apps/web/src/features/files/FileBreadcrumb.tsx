import { ChevronRight, HardDrive } from "lucide-react";

import { cn } from "@/lib/utils";

// FileBreadcrumb — path navigation for the file browser.
//
// Renders "Root / subdir / current" with clickable segments that call onNavigate.
// The last segment is not clickable (you are already there).
// Root is shown as a drive icon so the breadcrumb reads naturally even at "/".

export interface FileBreadcrumbProps {
  path: string;
  onNavigate: (path: string) => void;
}

/** Split a forward-slash path into breadcrumb segments. */
function splitPath(path: string): Array<{ label: string; path: string }> {
  // Normalize: strip leading/trailing slashes, split on /, filter empty.
  const parts = path.replace(/^\/+|\/+$/g, "").split("/").filter(Boolean);
  const segments: Array<{ label: string; path: string }> = [];
  let accumulated = "";
  for (const part of parts) {
    accumulated = accumulated ? `${accumulated}/${part}` : part;
    segments.push({ label: part, path: accumulated });
  }
  return segments;
}

export function FileBreadcrumb({ path, onNavigate }: FileBreadcrumbProps) {
  const segments = splitPath(path);

  return (
    <nav
      aria-label="File path"
      className="flex min-w-0 items-center gap-0.5 overflow-x-auto"
    >
      {/* Root segment */}
      <button
        type="button"
        onClick={() => onNavigate("")}
        aria-label="Go to root directory"
        className={cn(
          "inline-flex shrink-0 items-center gap-1 rounded px-1.5 py-1 text-xs transition-colors",
          segments.length === 0
            ? "font-medium text-[var(--color-foreground)]"
            : "text-[var(--color-muted-foreground)] hover:text-[var(--color-foreground)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-ring)]",
        )}
      >
        <HardDrive aria-hidden="true" className="size-3.5 shrink-0" />
        Root
      </button>

      {segments.map((seg, i) => {
        const isLast = i === segments.length - 1;
        return (
          <span key={seg.path} className="flex items-center gap-0.5">
            <ChevronRight
              aria-hidden="true"
              className="size-3.5 shrink-0 text-[var(--color-muted-foreground)]/60"
            />
            {isLast ? (
              <span
                className="inline-flex items-center rounded px-1.5 py-1 text-xs font-medium text-[var(--color-foreground)]"
                aria-current="page"
              >
                {seg.label}
              </span>
            ) : (
              <button
                type="button"
                onClick={() => onNavigate(seg.path)}
                className="inline-flex items-center rounded px-1.5 py-1 text-xs text-[var(--color-muted-foreground)] transition-colors hover:text-[var(--color-foreground)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-ring)]"
              >
                {seg.label}
              </button>
            )}
          </span>
        );
      })}
    </nav>
  );
}
