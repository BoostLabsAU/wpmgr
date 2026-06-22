import { FolderOpen } from "lucide-react";

// FilesEmptyState — shown when a directory is empty (no entries).

export function FilesEmptyState() {
  return (
    <div
      role="status"
      aria-label="Directory is empty"
      className="flex flex-col items-center gap-3 py-16 text-center"
    >
      <FolderOpen
        aria-hidden="true"
        strokeWidth={1.5}
        className="size-8 text-[var(--color-muted-foreground)]/50"
      />
      <div className="space-y-1">
        <p className="text-balance text-sm font-medium text-[var(--color-foreground)]">
          This directory is empty.
        </p>
        <p className="text-balance text-sm text-[var(--color-muted-foreground)]">
          No files or subdirectories were found at this path.
        </p>
      </div>
    </div>
  );
}
