package font

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"time"

	"github.com/google/uuid"
	"github.com/riverqueue/river"
)

// FontTranscodeRepo is the subset of the perf repo the font_transcode worker
// needs. *perf.Repo satisfies it via the font_repo.go methods.
type FontTranscodeRepo interface {
	MarkFontTranscodeReady(ctx context.Context, tenantID uuid.UUID, sourceHash, woff2Key string) error
	MarkFontTranscodeNegative(ctx context.Context, tenantID uuid.UUID, sourceHash, errorDetail string) error
}

// FontPresigner mints presigned GET/PUT URLs. *blobstore.Store satisfies it.
type FontPresigner interface {
	PresignGet(ctx context.Context, key string, ttl time.Duration) (string, error)
	PresignPut(ctx context.Context, key string, ttl time.Duration) (string, error)
}

// TranscodeWorker is the font_transcode River worker. It is registered ONLY
// in cmd/media-encoder (pure-Go; CGO_ENABLED=0 is fine).
//
// For each job it:
//  1. Validates the server-derived storage keys before any presign call.
//  2. Presigned-GETs the source font bytes from object storage.
//  3. Calls TranscodeToWOFF2 (pure-Go, no CGO) inside a panic-recovery
//     wrapper so malformed font bytes cannot crash the worker process.
//  4. Presigned-PUTs the WOFF2 output.
//  5. Records a ready result in font_transcode_results.
//
// On a permanent error (ErrUnsupportedFormat, ErrAlreadyWOFF2, malformed
// input including any panic from the font library), it records a negative-result
// marker so the job is never retried. On a transient error (storage, network),
// River retries normally.
type TranscodeWorker struct {
	river.WorkerDefaults[TranscodeArgs]
	repo       FontTranscodeRepo
	store      FontPresigner
	http       *http.Client
	presignTTL time.Duration
	logger     *slog.Logger
}

// NewTranscodeWorker builds the worker.
func NewTranscodeWorker(
	repo FontTranscodeRepo,
	store FontPresigner,
	presignTTL time.Duration,
	logger *slog.Logger,
) *TranscodeWorker {
	if logger == nil {
		logger = slog.Default()
	}
	if presignTTL <= 0 {
		presignTTL = 15 * time.Minute
	}
	return &TranscodeWorker{
		repo:       repo,
		store:      store,
		http:       &http.Client{Timeout: 5 * time.Minute},
		presignTTL: presignTTL,
		logger:     logger,
	}
}

// Timeout gives each font_transcode job 3 minutes: presigned-GET (fast,
// fonts are typically < 1 MiB), pure-Go encode (< 1s on any modern CPU),
// presigned-PUT (fast). The 3-minute ceiling is conservative but generous
// enough for slow self-hosted storage.
func (w *TranscodeWorker) Timeout(*river.Job[TranscodeArgs]) time.Duration {
	return 3 * time.Minute
}

// Work transcodes one font from its source format to WOFF2.
func (w *TranscodeWorker) Work(ctx context.Context, job *river.Job[TranscodeArgs]) error {
	a := job.Args

	// Validate source hash before building any key. This is defense in depth:
	// the handler already validates before enqueue, but we re-check here so a
	// stale or manually-inserted job payload cannot produce a malformed key.
	if !ValidSourceHash(a.SourceHash) {
		reason := "invalid source_hash: must be 64 lowercase hex chars"
		w.logger.WarnContext(ctx, "font transcode: permanent failure",
			slog.String("source_hash", a.SourceHash),
			slog.String("reason", reason))
		_ = w.repo.MarkFontTranscodeNegative(ctx, a.TenantID, a.SourceHash, reason)
		return river.JobCancel(errors.New(reason))
	}

	// Re-derive BOTH keys server-side from the verified identity. We do NOT
	// trust the key stored in the job payload — it should match, but we always
	// build the authoritative key from TenantID + SourceHash here.
	sourceKey := DeriveSourceKey(a.TenantID, a.SourceHash)
	woff2Key := DeriveWoff2Key(a.TenantID, a.SourceHash)

	// Defense-in-depth: guard every presign key before calling the store.
	if err := GuardStorageKey(sourceKey); err != nil {
		return river.JobCancel(fmt.Errorf("font transcode: source key guard: %w", err))
	}
	if err := GuardStorageKey(woff2Key); err != nil {
		return river.JobCancel(fmt.Errorf("font transcode: woff2 key guard: %w", err))
	}

	// 1. Fetch source bytes via presigned GET.
	src, err := w.fetchPresigned(ctx, sourceKey)
	if err != nil {
		return fmt.Errorf("font transcode: fetch source %q: %w", sourceKey, err)
	}

	// 2. Transcode to WOFF2 inside a panic-recovery wrapper.
	//    tdewolff/font may panic on sufficiently malformed input; any such panic
	//    is converted to a permanent negative result so the job is never retried.
	woff2, encErr := safeTranscode(src)
	if encErr != nil {
		// Permanent failures: record a negative marker and cancel the River job
		// so it is not retried.
		if isPermanent(encErr) {
			reason := encErr.Error()
			w.logger.WarnContext(ctx, "font transcode: permanent failure",
				slog.String("source_hash", a.SourceHash),
				slog.String("reason", reason))
			if nerr := w.repo.MarkFontTranscodeNegative(ctx, a.TenantID, a.SourceHash, reason); nerr != nil {
				w.logger.WarnContext(ctx, "font transcode: failed to record negative",
					slog.String("source_hash", a.SourceHash), slog.Any("err", nerr))
			}
			return river.JobCancel(encErr)
		}
		return fmt.Errorf("font transcode: encode %q: %w", a.SourceHash, encErr)
	}

	// 3. Upload WOFF2 via presigned PUT.
	if putErr := w.putPresigned(ctx, woff2Key, woff2); putErr != nil {
		return fmt.Errorf("font transcode: upload WOFF2 %q: %w", woff2Key, putErr)
	}

	// 4. Record the ready result.
	if readyErr := w.repo.MarkFontTranscodeReady(ctx, a.TenantID, a.SourceHash, woff2Key); readyErr != nil {
		// The WOFF2 is in storage; a failure here is survivable — the agent
		// will poll again and the CP can recover it. Log and return for retry.
		return fmt.Errorf("font transcode: record ready %q: %w", a.SourceHash, readyErr)
	}

	w.logger.InfoContext(ctx, "font transcode: complete",
		slog.String("source_hash", a.SourceHash),
		slog.String("woff2_key", woff2Key),
		slog.Int("src_bytes", len(src)),
		slog.Int("woff2_bytes", len(woff2)),
	)
	return nil
}

// safeTranscode wraps TranscodeToWOFF2 with a panic-recovery guard.
// Any panic from tdewolff/font parsing is caught and converted into a permanent
// ErrUnsupportedFormat-flavored error so the job is cancelled rather than retried.
func safeTranscode(src []byte) (out []byte, err error) {
	defer func() {
		if r := recover(); r != nil {
			err = fmt.Errorf("%w: panic in font parser: %v", ErrUnsupportedFormat, r)
		}
	}()
	return TranscodeToWOFF2(src)
}

// isPermanent returns true for errors that mean the font can never be
// transcoded regardless of retries (unsupported format, already WOFF2,
// size cap exceeded, or decoded output too large).
func isPermanent(err error) bool {
	return errors.Is(err, ErrUnsupportedFormat) ||
		errors.Is(err, ErrAlreadyWOFF2) ||
		errors.Is(err, ErrFontTooLarge) ||
		errors.Is(err, ErrDecodedTooLarge)
}

// fetchPresigned mints a presigned GET URL for key and fetches the bytes.
func (w *TranscodeWorker) fetchPresigned(ctx context.Context, key string) ([]byte, error) {
	url, err := w.store.PresignGet(ctx, key, w.presignTTL)
	if err != nil {
		return nil, err
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		return nil, err
	}
	resp, err := w.http.Do(req)
	if err != nil {
		return nil, err
	}
	defer func() { _, _ = io.Copy(io.Discard, resp.Body); _ = resp.Body.Close() }()
	if resp.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("fetch: status %d", resp.StatusCode)
	}
	return io.ReadAll(io.LimitReader(resp.Body, MaxFontBytes+1))
}

// putPresigned mints a presigned PUT URL for key and uploads the bytes.
func (w *TranscodeWorker) putPresigned(ctx context.Context, key string, body []byte) error {
	url, err := w.store.PresignPut(ctx, key, w.presignTTL)
	if err != nil {
		return err
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodPut, url, bytes.NewReader(body))
	if err != nil {
		return err
	}
	req.ContentLength = int64(len(body))
	req.Header.Set("Content-Type", "font/woff2")
	resp, err := w.http.Do(req)
	if err != nil {
		return err
	}
	defer func() { _, _ = io.Copy(io.Discard, resp.Body); _ = resp.Body.Close() }()
	if resp.StatusCode != http.StatusOK && resp.StatusCode != http.StatusNoContent {
		return fmt.Errorf("upload: status %d", resp.StatusCode)
	}
	return nil
}
