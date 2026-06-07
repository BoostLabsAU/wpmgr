import { useId, useState } from "react";
import {
  AlertTriangle,
  CheckCircle2,
  Loader2,
  Replace,
  XCircle,
} from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Dialog,
  DialogBody,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { toast } from "@/components/toast";

import {
  useSearchReplace,
  type SearchReplaceResult,
} from "./use-search-replace";

// SearchReplacePanel — operator-facing tool for a serialization-safe database
// search-and-replace.
//
// UX FLOW:
//   1. Operator enters a Search string and a Replace string.
//   2. "Preview" button fires dry_run=true. The count of matched rows is shown
//      along with an unconditional backup-first advisory.
//   3. Only after a successful preview with rows_matched > 0, the "Apply" button
//      becomes available. Clicking it opens a confirm dialog.
//   4. Inside the dialog, the operator clicks "Apply now" (a second explicit
//      click). This fires dry_run=false. The rows_changed count is shown in a
//      success state. The panel resets after 5 seconds.
//
// SAFETY:
//   - dry_run=true is always the first call. Apply is disabled until a preview
//     has run and reported rows_matched > 0. This is enforced by the Phase
//     discriminated union — there is no boolean flag to override.
//   - Backup advisory is shown unconditionally in preview_done and applying
//     phases. The operator cannot miss it.
//   - A confirm dialog gates the final apply so a single mis-click cannot
//     trigger a live database rewrite.
//   - The search string must be at least 3 characters (enforced client-side
//     and independently at the server and agent layers).
//
// PERMISSION: the parent route gates on canOperate (PermSiteWrite / operator+).
// This component disables all inputs and hides action buttons when the prop is
// false. The server also returns 403 if called without the right permission.

const MIN_SEARCH_LENGTH = 3;

interface Props {
  siteId: string;
  canOperate: boolean;
}

type Phase =
  | { kind: "idle" }
  | { kind: "previewing" }
  | { kind: "preview_done"; result: SearchReplaceResult }
  | { kind: "applying"; previewResult: SearchReplaceResult }
  | { kind: "done"; result: SearchReplaceResult }
  | { kind: "error"; message: string };

export function SearchReplacePanel({ siteId, canOperate }: Props) {
  const titleId = useId();
  const descriptionId = useId();

  const [search, setSearch] = useState("");
  const [replace, setReplace] = useState("");
  const [phase, setPhase] = useState<Phase>({ kind: "idle" });
  const [confirmOpen, setConfirmOpen] = useState(false);

  const mutation = useSearchReplace(siteId);

  const isRunning =
    phase.kind === "previewing" || phase.kind === "applying";

  const searchTooShort = search.length < MIN_SEARCH_LENGTH;

  function reset() {
    setPhase({ kind: "idle" });
    mutation.reset();
  }

  // ---- Preview (dry_run=true) ----------------------------------------- //
  async function handlePreview() {
    if (searchTooShort || isRunning) return;
    setPhase({ kind: "previewing" });
    try {
      const result = await mutation.mutateAsync({
        search,
        replace,
        dry_run: true,
      });
      if (!result.ok) {
        setPhase({
          kind: "error",
          message: result.detail ?? "Preview failed (agent refused)",
        });
        return;
      }
      setPhase({ kind: "preview_done", result });
    } catch (err) {
      setPhase({
        kind: "error",
        message: err instanceof Error ? err.message : "Preview failed",
      });
    }
  }

  // ---- Apply (dry_run=false) — called after the confirm dialog ---------- //
  async function handleApply() {
    if (phase.kind !== "preview_done" || isRunning) return;
    const previewResult = phase.result;
    setConfirmOpen(false);
    setPhase({ kind: "applying", previewResult });
    try {
      const result = await mutation.mutateAsync({
        search,
        replace,
        dry_run: false,
      });
      if (!result.ok) {
        setPhase({
          kind: "error",
          message: result.detail ?? "Apply failed (agent refused)",
        });
        return;
      }
      setPhase({ kind: "done", result });
      toast.success(
        `Search-replace complete — ${result.rows_changed.toLocaleString()} row${result.rows_changed === 1 ? "" : "s"} updated.`,
      );
    } catch (err) {
      setPhase({
        kind: "error",
        message: err instanceof Error ? err.message : "Apply failed",
      });
    }
  }

  // Rows matched from the most recent preview (for the confirm dialog label).
  const previewRowCount =
    phase.kind === "preview_done" ? phase.result.rows_matched : 0;

  return (
    <div className="space-y-6">
      {/* Header */}
      <div>
        <h2 className="text-base font-semibold">Search and Replace</h2>
        <p className="mt-1 text-sm text-muted-foreground">
          Find and replace a string across every database table. Serialized PHP
          data (option values, post meta) is handled safely — length prefixes
          are recomputed after replacement.
        </p>
      </div>

      {/* Input fields */}
      <div className="space-y-4">
        <div className="space-y-1.5">
          <Label htmlFor="sr-search">Search for</Label>
          <Input
            id="sr-search"
            value={search}
            onChange={(e) => {
              setSearch(e.target.value);
              if (phase.kind !== "idle") reset();
            }}
            placeholder="e.g. https://old.example.com"
            disabled={!canOperate || isRunning}
            aria-describedby="sr-search-hint"
          />
          <p id="sr-search-hint" className="text-xs text-muted-foreground">
            Minimum {MIN_SEARCH_LENGTH} characters. Treated as a literal string
            — no wildcards.
          </p>
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="sr-replace">Replace with</Label>
          <Input
            id="sr-replace"
            value={replace}
            onChange={(e) => {
              setReplace(e.target.value);
              if (phase.kind !== "idle") reset();
            }}
            placeholder="e.g. https://new.example.com"
            disabled={!canOperate || isRunning}
          />
        </div>
      </div>

      {/* Backup advisory — shown unconditionally in preview_done and applying */}
      {(phase.kind === "preview_done" || phase.kind === "applying") && (
        <div
          role="alert"
          className="flex gap-2 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-300"
        >
          <AlertTriangle
            aria-hidden="true"
            className="mt-0.5 h-4 w-4 shrink-0"
          />
          <span>
            <strong>Back up before applying.</strong> This rewrites the live
            database. A backup lets you undo the change if something goes wrong.
          </span>
        </div>
      )}

      {/* Preview result */}
      {phase.kind === "preview_done" && (
        <div className="space-y-2 rounded-md border p-4">
          <div className="flex items-center gap-2 text-sm font-medium">
            <Replace aria-hidden="true" className="h-4 w-4 text-muted-foreground" />
            Preview result
          </div>
          <dl className="grid grid-cols-2 gap-x-4 gap-y-1 text-sm sm:grid-cols-3">
            <div>
              <dt className="text-muted-foreground">Tables scanned</dt>
              <dd className="font-mono font-medium">
                {phase.result.tables_scanned.toLocaleString()}
              </dd>
            </div>
            <div>
              <dt className="text-muted-foreground">Rows matched</dt>
              <dd className="font-mono font-medium">
                {phase.result.rows_matched.toLocaleString()}
              </dd>
            </div>
          </dl>
          {phase.result.rows_matched === 0 && (
            <p className="text-sm text-muted-foreground">
              No rows match the search string. Nothing will change.
            </p>
          )}
          {phase.result.backup_warning ? (
            <p className="text-xs text-amber-700 dark:text-amber-400">
              {phase.result.backup_warning}
            </p>
          ) : null}
        </div>
      )}

      {/* Applying in-progress */}
      {phase.kind === "applying" && (
        <div className="flex items-center gap-2 text-sm text-muted-foreground">
          <Loader2 aria-hidden="true" className="h-4 w-4 animate-spin" />
          Applying changes — scanning{" "}
          {phase.previewResult.tables_scanned.toLocaleString()} tables&hellip;
        </div>
      )}

      {/* Done */}
      {phase.kind === "done" && (
        <div className="flex items-start gap-2 rounded-md border border-green-200 bg-green-50 p-3 text-sm text-green-800 dark:border-green-800 dark:bg-green-950 dark:text-green-300">
          <CheckCircle2
            aria-hidden="true"
            className="mt-0.5 h-4 w-4 shrink-0"
          />
          <span>
            Done.{" "}
            <strong>
              {phase.result.rows_changed.toLocaleString()} row
              {phase.result.rows_changed === 1 ? "" : "s"}
            </strong>{" "}
            updated across {phase.result.tables_scanned.toLocaleString()}{" "}
            table{phase.result.tables_scanned === 1 ? "" : "s"}.
          </span>
        </div>
      )}

      {/* Error */}
      {phase.kind === "error" && (
        <div className="flex items-start gap-2 rounded-md border border-destructive/30 bg-destructive/10 p-3 text-sm text-destructive">
          <XCircle aria-hidden="true" className="mt-0.5 h-4 w-4 shrink-0" />
          <span>{phase.message}</span>
        </div>
      )}

      {/* Action buttons */}
      {canOperate && (
        <div className="flex flex-wrap gap-2">
          {(phase.kind === "idle" ||
            phase.kind === "error" ||
            phase.kind === "done") && (
            <Button
              onClick={() => void handlePreview()}
              disabled={searchTooShort || isRunning}
              variant="secondary"
            >
              Preview (dry-run)
            </Button>
          )}

          {phase.kind === "preview_done" && (
            <Button
              onClick={() => void handlePreview()}
              disabled={isRunning}
              variant="secondary"
            >
              Re-preview
            </Button>
          )}

          {/* Apply button — only enabled after a preview with rows_matched > 0.
              Opening the confirm dialog is the second explicit click gate. */}
          {phase.kind === "preview_done" &&
            phase.result.rows_matched > 0 && (
              <Button
                onClick={() => setConfirmOpen(true)}
                disabled={isRunning}
                variant="destructive"
              >
                {`Apply (${phase.result.rows_matched.toLocaleString()} rows)`}
              </Button>
            )}

          {(phase.kind === "done" || phase.kind === "error") && (
            <Button onClick={reset} variant="ghost" size="sm">
              Reset
            </Button>
          )}
        </div>
      )}

      {!canOperate && (
        <p className="text-sm text-muted-foreground">
          Operator or admin role required to run a search-replace.
        </p>
      )}

      {/* Confirm dialog — gates the live apply (dry_run=false) */}
      <Dialog open={confirmOpen} onClose={() => setConfirmOpen(false)}>
        <DialogContent
          ariaLabelledBy={titleId}
          ariaDescribedBy={descriptionId}
        >
          <DialogHeader>
            <DialogTitle id={titleId}>Apply search-replace?</DialogTitle>
          </DialogHeader>

          <DialogBody>
            <div
              id={descriptionId}
              className="flex gap-2 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-300"
            >
              <AlertTriangle
                aria-hidden="true"
                className="mt-0.5 h-4 w-4 shrink-0"
              />
              <span>
                <strong>Back up first — this rewrites your database.</strong>{" "}
                The change will be applied to{" "}
                <strong>{previewRowCount.toLocaleString()} row{previewRowCount === 1 ? "" : "s"}</strong>{" "}
                across live tables. Make sure a recent backup exists before
                continuing.
              </span>
            </div>
            <p className="text-sm text-muted-foreground">
              The operation will find every occurrence of the search string
              (including inside serialized PHP data) and replace it. This
              cannot be undone without a backup.
            </p>
          </DialogBody>

          <DialogFooter className="pt-2">
            <Button
              type="button"
              variant="outline"
              onClick={() => setConfirmOpen(false)}
            >
              Go back
            </Button>
            <Button
              type="button"
              variant="destructive"
              onClick={() => void handleApply()}
            >
              Apply now
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
