import { useState } from "react";
import { Icon } from "@/components/icon";
import { cn } from "@/lib/cn";

/** A single shell command in IBM Plex Mono with a copy button. */
export function CodeSnippet({
  command,
  className,
}: {
  command: string;
  className?: string;
}) {
  const [copied, setCopied] = useState(false);

  async function copy() {
    try {
      await navigator.clipboard.writeText(command);
      setCopied(true);
      window.setTimeout(() => setCopied(false), 1600);
    } catch {
      // clipboard may be unavailable; the command is still selectable.
    }
  }

  return (
    <div
      className={cn(
        "flex items-center justify-between gap-4 rounded-[var(--radius)] border border-border bg-card px-4 py-3",
        className,
      )}
    >
      <code className="font-mono text-sm text-foreground">
        <span className="select-none text-muted-foreground">$ </span>
        {command}
      </code>
      <button
        type="button"
        onClick={copy}
        aria-label={copied ? "Copied" : "Copy command"}
        className="inline-flex shrink-0 cursor-pointer items-center gap-1.5 rounded-md border border-border bg-background px-2.5 py-1.5 text-xs font-medium text-muted-foreground transition-colors duration-[var(--duration-fast)] hover:bg-accent hover:text-accent-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--ring)]"
      >
        <Icon name={copied ? "Check" : "Copy"} size={14} />
        {copied ? "Copied" : "Copy"}
      </button>
    </div>
  );
}
