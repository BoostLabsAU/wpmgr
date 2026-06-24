package files

import (
	"context"
	"log/slog"
	"time"

	"github.com/jackc/pgx/v5"
	"github.com/riverqueue/river"

	"github.com/mosamlife/wpmgr/apps/api/internal/db"
	"github.com/mosamlife/wpmgr/apps/api/internal/db/sqlc"
)

// fileTransfersGCHorizon is the age at which a file_transfers row becomes
// eligible for deletion. Rows older than this are stale staging artifacts —
// the presigned URLs they reference have long expired and the associated
// objects are no longer accessible.
const fileTransfersGCHorizon = 24 * time.Hour

// ObjectDeleter deletes a single object from object storage by key.
// *blobstore.Store satisfies this via its Delete method.
// A nil implementation disables object deletion during GC (DB rows are still
// pruned — self-host deployments without object storage get row-only GC).
type ObjectDeleter interface {
	Delete(ctx context.Context, key string) error
}

// FileTransfersGCArgs is the River job payload for the periodic file_transfers
// GC. No fields — the worker uses a fixed horizon and enumerates rows itself.
type FileTransfersGCArgs struct{}

// Kind implements river.JobArgs.
func (FileTransfersGCArgs) Kind() string { return "file_transfers_gc" }

// FileTransfersGCWorker deletes file_transfers rows older than
// fileTransfersGCHorizon and best-effort deletes their staged object from
// object storage (when a Deleter is wired). It runs cross-tenant under
// InAgentTx (app.agent = 'on'), matching the DBSizeHistoryGCWorker pattern.
//
// The sweep is capped at 500 rows per run (see ListStaleFileTransfers) so a
// large backlog doesn't hold the pass open indefinitely — the next periodic
// run picks up where this one left off.
type FileTransfersGCWorker struct {
	river.WorkerDefaults[FileTransfersGCArgs]
	pool    *db.Pool
	deleter ObjectDeleter // may be nil (object GC disabled)
	logger  *slog.Logger
}

// NewFileTransfersGCWorker builds the GC worker. deleter may be nil (when
// object storage is not configured), in which case only DB rows are pruned.
func NewFileTransfersGCWorker(pool *db.Pool, deleter ObjectDeleter, logger *slog.Logger) *FileTransfersGCWorker {
	if logger == nil {
		logger = slog.Default()
	}
	return &FileTransfersGCWorker{pool: pool, deleter: deleter, logger: logger}
}

// Work deletes stale file_transfers rows (and their staged objects) in one
// cross-tenant pass. Object deletion is best-effort: a storage error is logged
// and the row is still deleted so the DB does not accumulate orphaned rows.
func (w *FileTransfersGCWorker) Work(ctx context.Context, _ *river.Job[FileTransfersGCArgs]) error {
	cutoff := time.Now().UTC().Add(-fileTransfersGCHorizon)

	// Phase 1: collect the stale rows (cross-tenant read under InAgentTx).
	var stale []sqlc.ListStaleFileTransfersRow
	if err := w.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		var qerr error
		stale, qerr = sqlc.New(tx).ListStaleFileTransfers(ctx, cutoff)
		return qerr
	}); err != nil {
		w.logger.Warn("file_transfers GC: failed to list stale rows", slog.Any("error", err))
		return err
	}

	if len(stale) == 0 {
		return nil
	}

	// Phase 2: for each stale row, best-effort delete the staged object, then
	// delete the DB row under InAgentTx. Object first / row second: a crash
	// between the two leaves a dangling row pointing at a missing object, which
	// the next GC pass self-heals. A storage error does NOT skip the row delete.
	deleted := 0
	for _, row := range stale {
		if w.deleter != nil && row.ObjectKey != "" {
			if derr := w.deleter.Delete(ctx, row.ObjectKey); derr != nil {
				// Storage 404 counts as success (idempotent). Log other errors
				// but continue so DB rows are always pruned.
				w.logger.Warn("file_transfers GC: object delete error (row will still be pruned)",
					slog.String("object_key", row.ObjectKey),
					slog.Any("error", derr))
			}
		}
		if err := w.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
			return sqlc.New(tx).DeleteFileTransfer(ctx, row.ID)
		}); err != nil {
			w.logger.Warn("file_transfers GC: failed to delete stale row",
				slog.String("id", row.ID.String()),
				slog.Any("error", err))
			continue
		}
		deleted++
	}

	w.logger.Info("file_transfers GC", slog.Int("rows_deleted", deleted), slog.Int("rows_found", len(stale)))
	return nil
}
