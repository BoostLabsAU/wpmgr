import { FolderLock } from "lucide-react";

import { Button } from "@/components/ui/button";

// FilesDisabledGate — shown when the file manager is disabled for this site.
// Admins+ see the Enable button; other roles see a neutral "not enabled" message.

export interface FilesDisabledGateProps {
  /** Whether the current user may enable the file manager (admin+). */
  canManage: boolean;
  isPending: boolean;
  onEnable: () => void;
}

export function FilesDisabledGate({
  canManage,
  isPending,
  onEnable,
}: FilesDisabledGateProps) {
  return (
    <div
      role="status"
      aria-label="File manager not enabled"
      className="flex flex-col items-center gap-4 py-16 text-center"
    >
      <FolderLock
        aria-hidden="true"
        strokeWidth={1.5}
        className="size-10 text-[var(--color-muted-foreground)]/50"
      />
      <div className="max-w-sm space-y-1.5">
        <p className="text-balance text-sm font-medium text-[var(--color-foreground)]">
          File manager is off for this site.
        </p>
        <p className="text-balance text-sm text-[var(--color-muted-foreground)]">
          The file manager gives read access to the site filesystem. Every
          browse, preview, and download action is recorded in the audit log.
          It is off by default and must be explicitly enabled per site.
        </p>
      </div>
      {canManage ? (
        <Button
          type="button"
          variant="outline"
          size="sm"
          disabled={isPending}
          onClick={onEnable}
        >
          Enable file manager
        </Button>
      ) : (
        <p className="text-xs text-[var(--color-muted-foreground)]">
          Ask a site admin or owner to enable it.
        </p>
      )}
    </div>
  );
}
