package service

import (
	"context"

	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
	"github.com/mosamlife/wpmgr/apps/api/internal/media"
	"github.com/mosamlife/wpmgr/apps/api/internal/media/model"
	"github.com/mosamlife/wpmgr/apps/api/internal/media/repo"
	"github.com/mosamlife/wpmgr/apps/api/internal/site"
)

// These handlers run behind the agent Ed25519 signed-request middleware. The
// tenant + site come from the verified identity (NEVER a client header); each
// job is re-asserted to belong to that tenant + site before any mutation, so a
// compromised agent cannot manipulate another site's job even within its tenant.

// SyncBatchInput is one page of the agent's media enumeration. JobID identifies
// the sync run so every upserted row is stamped with the run's sync_generation
// (read off the sync job) — the basis for the finalize sweep.
type SyncBatchInput struct {
	JobID       string
	Attachments []repo.UpsertAssetInput
}

// HandleSyncBatch upserts a page of attachments under the agent GUC, stamping each
// with the sync run's generation (looked up off the job and re-asserted to this
// tenant+site). Returns the number of rows upserted.
func (s *Service) HandleSyncBatch(ctx context.Context, tenantID, siteID uuid.UUID, in SyncBatchInput) (int64, error) {
	if len(in.Attachments) > media.MaxSyncBatch {
		return 0, domain.Validation("sync_batch_too_large", "sync batch exceeds the per-page cap")
	}
	if in.JobID == "" {
		return 0, domain.Validation("invalid_job_id", "job_id is required")
	}
	job, err := s.assertJobSite(ctx, tenantID, siteID, in.JobID)
	if err != nil {
		return 0, err
	}
	var syncGen int64
	if job.SyncGeneration != nil {
		syncGen = *job.SyncGeneration
	}
	return s.repo.UpsertAssetsAgent(ctx, tenantID, siteID, syncGen, in.Attachments)
}

// HandleAssetDeleted removes the asset row (and its jobs) for an attachment the
// agent reports deleted in WP. Idempotent: 0 rows deleted is a 200 no-op (the
// attachment was already gone / never synced), never an error. Publishes
// media.asset.deleted only when a row was actually removed.
func (s *Service) HandleAssetDeleted(ctx context.Context, tenantID, siteID uuid.UUID, wpAttachmentID int64) error {
	n, err := s.repo.DeleteAssetAgent(ctx, tenantID, siteID, wpAttachmentID)
	if err != nil {
		return err
	}
	if n > 0 {
		s.publish(ctx, tenantID, siteID, site.EventMediaAssetDeleted, map[string]any{
			"wp_attachment_id": wpAttachmentID,
		})
	}
	return nil
}

// HandleSyncFinalize sweeps every asset NOT seen by the just-finished sync run —
// i.e. deleted in WP. The sync job is re-asserted to this tenant+site, its run
// generation read back, and every asset on an older generation removed (along with
// its jobs). Guard: a sync job with no generation (0/legacy) NEVER triggers a
// sweep, so a malformed/legacy finalize cannot wipe a site's library.
func (s *Service) HandleSyncFinalize(ctx context.Context, tenantID, siteID uuid.UUID, jobID string) error {
	job, err := s.assertJobSite(ctx, tenantID, siteID, jobID)
	if err != nil {
		return err
	}
	if job.State.Terminal() {
		return nil // dup finalize against an already-finished sync job
	}
	// Only sweep when we have a real run generation — a generation of 0 (legacy /
	// malformed) would delete EVERY asset, so we skip the sweep but still finalize.
	var swept int64
	if job.SyncGeneration != nil && *job.SyncGeneration > 0 {
		swept, err = s.repo.SweepStaleAssetsAgent(ctx, tenantID, siteID, *job.SyncGeneration)
		if err != nil {
			return err
		}
	}
	// FINALIZE the sync job — without this it sits in "queued" forever even though
	// the enumeration completed (every other callback finalizes its job; sync did
	// not). This is what moves the Jobs row out of queued → succeeded.
	_, _ = s.repo.FinalizeJobAgent(ctx, jobID, repo.FinalizeJobInput{State: model.JobSucceeded})
	s.publish(ctx, tenantID, siteID, site.EventMediaSyncCompleted, map[string]any{
		"job_id":      jobID,
		"swept_count": swept,
	})
	return nil
}

// PresignVariant is one variant the agent wants to upload a source for.
type PresignVariant struct {
	Name       string
	SourceSize int64
	SourceMime string
}

// HandlePresign mints a presigned PUT URL for each variant's source object
// (media/<tenant>/<site>/<job>/src/<name>). Returns name -> PUT URL. The job is
// re-asserted to the agent's tenant+site first.
func (s *Service) HandlePresign(ctx context.Context, tenantID, siteID uuid.UUID, jobID string, variants []PresignVariant) (map[string]string, error) {
	if s.store == nil {
		return nil, domain.ServiceUnavailable("media_store_unwired", "object storage is not configured")
	}
	if _, err := s.assertJobSite(ctx, tenantID, siteID, jobID); err != nil {
		return nil, err
	}
	if len(variants) == 0 || len(variants) > media.MaxVariantsPerJob {
		return nil, domain.Validation("invalid_variant_count", "variants must be 1..10 per job")
	}
	out := make(map[string]string, len(variants))
	for _, v := range variants {
		// Reject hostile variant names before they reach the object key — a '.'
		// or '/' could escape the job prefix (storage-key path traversal).
		if !media.ValidVariantName(v.Name) {
			return nil, domain.Validation("invalid_variant_name", "variant name must be [A-Za-z0-9_-]{1,64}")
		}
		key := media.SrcKey(tenantID, siteID, jobID, v.Name)
		url, err := s.store.PresignPut(ctx, key, s.cfg.PresignTTL)
		if err != nil {
			return nil, domain.Internal("media_presign_failed", "failed to presign media upload").WithCause(err)
		}
		out[v.Name] = url
	}
	return out, nil
}

// EncodeReadyVariant is one uploaded source the agent reports ready to encode.
type EncodeReadyVariant struct {
	Name       string
	SourceSize int64
	SourceMime string
}

// HandleEncodeReady enqueues ONE EncodeArgs River job carrying the attachment's
// variants (≤10 — ADR-043 §3), marks the job in_progress, and publishes
// media.optimize.progress. The job is re-asserted to the agent's tenant+site.
func (s *Service) HandleEncodeReady(ctx context.Context, tenantID, siteID uuid.UUID, jobID string, variants []EncodeReadyVariant) error {
	job, err := s.assertJobSite(ctx, tenantID, siteID, jobID)
	if err != nil {
		return err
	}
	if job.State.Terminal() {
		return nil // dup callback against a finished/cancelled job
	}
	if len(variants) == 0 || len(variants) > media.MaxVariantsPerJob {
		return domain.Validation("invalid_variant_count", "variants must be 1..10 per job")
	}
	if s.enqueuer == nil {
		return domain.ServiceUnavailable("media_enqueuer_unwired", "media encode enqueuer is not wired")
	}

	encVariants := make([]model.EncodeVariant, 0, len(variants))
	for _, v := range variants {
		if !media.ValidVariantName(v.Name) {
			return domain.Validation("invalid_variant_name", "variant name must be [A-Za-z0-9_-]{1,64}")
		}
		encVariants = append(encVariants, model.EncodeVariant{
			Name:       v.Name,
			SourceSize: v.SourceSize,
			SourceMime: v.SourceMime,
		})
	}
	if err := s.repo.MarkJobInProgressAgent(ctx, jobID, len(encVariants)); err != nil {
		return err
	}
	riverJobID, err := s.enqueuer.EnqueueEncode(ctx, model.EncodeArgs{
		TenantID:       tenantID,
		SiteID:         siteID,
		JobID:          jobID,
		WPAttachmentID: job.WPAttachmentID,
		TargetFormat:   job.TargetFormat,
		TargetQuality:  job.TargetQuality,
		Variants:       encVariants,
	})
	if err != nil {
		s.failJob(ctx, tenantID, siteID, jobID, "encode enqueue failed: "+err.Error())
		return domain.Internal("media_encode_enqueue_failed", "failed to enqueue encode job").WithCause(err)
	}
	// Store the River job ID on the media row so the cancel path can cancel it
	// proactively (m51). Best-effort: a storage failure only means the cancel
	// path falls back to the worker's own self-heal and is not fatal.
	if storeErr := s.repo.SetEncodeRiverJobID(ctx, jobID, riverJobID); storeErr != nil {
		s.logger.Warn("media encode-ready: could not store River job ID (best-effort)",
			"job_id", jobID,
			"river_job_id", riverJobID,
			"err", storeErr.Error())
	}
	// Nudge the scale-to-zero media-encoder awake so it cold-starts and drains the
	// just-enqueued job. No-op on self-host (always-on encoder) and when unwired.
	if s.waker != nil {
		s.waker.Kick()
	}
	s.publish(ctx, tenantID, siteID, site.EventMediaOptimizeProgress, map[string]any{
		"job_id":         jobID,
		"variants_total": len(encVariants),
		"phase":          "encoding",
	})
	return nil
}

// ApplyStatusInput is the agent's post-apply report (job-status callback). It
// finalizes the asset row + the job and emits asset_done/completed.
type ApplyStatusInput struct {
	AppliedVariants  []string
	SizesUnoptimized map[string]string
	CurrentFormat    string
	CurrentSizeBytes int64
	BytesBefore      *int64
	BytesAfter       *int64
	// SavedBytes is the all-variant savings (full + every thumbnail) the agent
	// computes from the optimization blob — the basis for the dashboard rollup.
	SavedBytes       *int64
	CompressionLevel string
	TargetFormat     string
	OriginalsDeleted bool
	Error            string
}

// HandleApplyStatus finalizes a job after the agent applies (or deletes
// originals). It updates the asset mirror, the job state, deletes the temp S3
// objects, and emits SSE.
func (s *Service) HandleApplyStatus(ctx context.Context, tenantID, siteID uuid.UUID, jobID string, in ApplyStatusInput) error {
	job, err := s.assertJobSite(ctx, tenantID, siteID, jobID)
	if err != nil {
		return err
	}

	// Hard error from the agent → fail the job + asset.
	if in.Error != "" {
		s.failJob(ctx, tenantID, siteID, jobID, in.Error)
		if job.AssetID != nil {
			_ = s.repo.SetAssetStatus(ctx, tenantID, *job.AssetID, model.AssetFailed)
		}
		s.cleanupTempObjects(ctx, tenantID, siteID, jobID)
		return nil
	}

	// Delete-originals path: just flip the asset to originals_deleted + finalize.
	if job.Kind == model.JobDeleteOriginals {
		if job.AssetID != nil {
			_ = s.repo.SetAssetStatus(ctx, tenantID, *job.AssetID, model.AssetOriginalsDeleted)
		}
		_, _ = s.repo.FinalizeJobAgent(ctx, jobID, repo.FinalizeJobInput{State: model.JobSucceeded})
		s.publish(ctx, tenantID, siteID, site.EventMediaDeleteOriginalsCompleted, map[string]any{
			"job_id":           jobID,
			"wp_attachment_id": job.WPAttachmentID,
		})
		return nil
	}

	// Optimize-apply path: mirror the blob into the asset row.
	status := model.AssetOptimized
	if len(in.AppliedVariants) == 0 {
		status = model.AssetFailed
	}
	// bytes_before is the agent's FULL image file size (M26 full-file semantic) —
	// exact-set original_size_bytes to it so the per-image original matches the real
	// file. saved_bytes carries the all-variant savings (full + every thumbnail)
	// separately, so the dashboard "Bytes saved" rollup is not limited to the full
	// image's reduction.
	var originalSizeBytes int64
	if in.BytesBefore != nil {
		originalSizeBytes = *in.BytesBefore
	}
	var savedBytes int64
	if in.SavedBytes != nil {
		savedBytes = *in.SavedBytes
	}
	if _, err := s.repo.ApplyOptimizedAgent(ctx, tenantID, siteID, job.WPAttachmentID, repo.ApplyOptimizedInput{
		CurrentFormat:     orDefault(in.CurrentFormat, model.FormatOriginal),
		CurrentSizeBytes:  in.CurrentSizeBytes,
		OriginalSizeBytes: originalSizeBytes,
		SavedBytes:        savedBytes,
		Status:            status,
		CompressionLevel:  in.CompressionLevel,
		TargetFormat:      orDefault(in.TargetFormat, job.TargetFormat),
		SizesOptimized:    in.AppliedVariants,
		SizesUnoptimized:  in.SizesUnoptimized,
	}); err != nil {
		return err
	}

	succeeded, failed, _ := s.repo.CountVariantStatesAgent(ctx, jobID)
	jobState := model.JobSucceeded
	switch {
	case succeeded == 0 && failed > 0:
		jobState = model.JobFailed
	case failed > 0:
		jobState = model.JobPartiallySucceeded
	}
	finalJob, _ := s.repo.FinalizeJobAgent(ctx, jobID, repo.FinalizeJobInput{
		State:             jobState,
		BytesBefore:       in.BytesBefore,
		BytesAfter:        in.BytesAfter,
		VariantsSucceeded: succeeded,
		VariantsFailed:    failed,
	})

	// Clean up the per-job temp objects (src/* + out/*).
	s.cleanupTempObjects(ctx, tenantID, siteID, jobID)

	s.publish(ctx, tenantID, siteID, site.EventMediaOptimizeAssetDone, map[string]any{
		"job_id":           jobID,
		"wp_attachment_id": job.WPAttachmentID,
		"applied":          len(in.AppliedVariants),
	})
	s.publish(ctx, tenantID, siteID, site.EventMediaOptimizeCompleted, map[string]any{
		"job_id": jobID,
		"state":  string(finalJob.State),
	})
	return nil
}

// RestoreStatusInput is the agent's restore report.
type RestoreStatusInput struct {
	Restored bool
	Error    string
}

// HandleRestoreStatus finalizes a restore job + the asset row.
func (s *Service) HandleRestoreStatus(ctx context.Context, tenantID, siteID uuid.UUID, jobID string, in RestoreStatusInput) error {
	job, err := s.assertJobSite(ctx, tenantID, siteID, jobID)
	if err != nil {
		return err
	}
	if in.Error != "" || !in.Restored {
		reason := in.Error
		if reason == "" {
			reason = "restore reported not restored"
		}
		s.failJob(ctx, tenantID, siteID, jobID, reason)
		if job.AssetID != nil {
			_ = s.repo.SetAssetStatus(ctx, tenantID, *job.AssetID, model.AssetFailed)
		}
		return nil
	}
	if _, err := s.repo.RestoreAssetAgent(ctx, tenantID, siteID, job.WPAttachmentID); err != nil {
		return err
	}
	_, _ = s.repo.FinalizeJobAgent(ctx, jobID, repo.FinalizeJobInput{State: model.JobSucceeded})
	s.cleanupTempObjects(ctx, tenantID, siteID, jobID)
	s.publish(ctx, tenantID, siteID, site.EventMediaRestoreAssetDone, map[string]any{
		"job_id":           jobID,
		"wp_attachment_id": job.WPAttachmentID,
	})
	s.publish(ctx, tenantID, siteID, site.EventMediaRestoreCompleted, map[string]any{"job_id": jobID})
	return nil
}

// AutoOptimizeResult is the outcome of HandleAutoOptimize.
type AutoOptimizeResult struct {
	Accepted int
	Skipped  int
}

// HandleAutoOptimize is the CP-side handler for POST /agent/v1/media/auto-optimize
// (ADR-044 §3). The agent fires it after debouncing a batch of newly-uploaded
// attachments, carrying the full attachment metadata. This method upserts the
// rows first (so a fresh upload that has not yet appeared in a media_sync is
// created in the CP), then gates and calls StartOptimize for the eligible subset.
//
// Processing order:
//  1. Read site media settings under the agent GUC; if auto_optimize_enabled is
//     false, skip all rows (defense — the agent should have checked locally too).
//  2. UPSERT all rows via UpsertAssetsAgent, stamped with the current clock as
//     syncGen. Using clock.Now().UnixMicro() (rather than a sync-job generation)
//     ensures fresh rows are NOT swept by a later sync-finalize that carries an
//     older generation — the next full sync re-stamps them normally.
//  3. For each row: re-resolve the site_media_assets row via GetAssetByWPIDAgent
//     (now guaranteed to exist after the upsert) and apply the existing gates:
//     a. Gate on media.IsOptimizableMime(asset.OriginalMime) — skip non-optimizable.
//     b. Skip if the asset status is already optimizing/optimized/originals_deleted.
//     c. Skip if a non-terminal optimize job for this attachment already exists.
//
// Eligible asset UUIDs are batched into a single StartOptimize call (format +
// quality from the stored settings). The principal is a system/agent actor for
// audit. Tenant+site come exclusively from the verified Ed25519 identity, never
// a client header.
func (s *Service) HandleAutoOptimize(ctx context.Context, tenantID, siteID uuid.UUID, rows []repo.UpsertAssetInput) (AutoOptimizeResult, error) {
	var result AutoOptimizeResult
	if len(rows) == 0 {
		return result, nil
	}

	// 1. Read settings under the agent GUC — defense in depth.
	settings, found, err := s.repo.GetMediaSettingsAgent(ctx, tenantID, siteID)
	if err != nil {
		return result, err
	}
	if !found || !settings.AutoOptimizeEnabled {
		// Auto-optimize is not enabled for this site. Skip everything.
		result.Skipped = len(rows)
		return result, nil
	}

	// 2. UPSERT all attachment rows so fresh uploads are created in the CP before
	// we attempt to resolve them. Using the current wall-clock as syncGen means
	// these rows carry a generation NEWER than any in-flight or recently-completed
	// sync run, so a sync-finalize sweep with an older generation will NOT delete
	// them. The next full sync re-stamps them with its own generation normally.
	syncGen := s.clock.Now().UnixMicro()
	if _, err := s.repo.UpsertAssetsAgent(ctx, tenantID, siteID, syncGen, rows); err != nil {
		return result, err
	}

	// 3a–c. Gate each attachment individually after the upsert guarantees the row
	// exists.
	var eligibleAssetIDs []uuid.UUID
	for _, row := range rows {
		a, exists, aerr := s.repo.GetAssetByWPIDAgent(ctx, tenantID, siteID, row.WPAttachmentID)
		if aerr != nil {
			return result, aerr
		}
		if !exists {
			// Should not happen after the upsert, but guard defensively.
			result.Skipped++
			continue
		}
		// 3a. MIME gate.
		if !media.IsOptimizableMime(a.OriginalMime) {
			result.Skipped++
			continue
		}
		// 3b. Status gate — already optimized/in-flight/originals-deleted.
		switch a.Status {
		case model.AssetOptimizing, model.AssetOptimized, model.AssetOriginalsDeleted:
			result.Skipped++
			continue
		}
		// 3c. In-flight job gate.
		inFlight, jerr := s.repo.HasInFlightOptimizeJobAgent(ctx, tenantID, siteID, row.WPAttachmentID)
		if jerr != nil {
			return result, jerr
		}
		if inFlight {
			result.Skipped++
			continue
		}
		eligibleAssetIDs = append(eligibleAssetIDs, a.ID)
	}

	if len(eligibleAssetIDs) == 0 {
		return result, nil
	}

	// Build a system-level principal for audit attribution. The caller is the
	// agent, not a human user — mirror the convention that agent callbacks have
	// no user principal on ctx; record this as a zero-UUID PrincipalUser so the
	// audit row gets a stable actor type without importing a new type.
	agentPrincipal := domain.Principal{
		Type:     domain.PrincipalUser,
		TenantID: tenantID,
		// UserID stays uuid.Nil → userPtr returns nil → initiator_user_id is NULL,
		// which is the same as the other agent-originated service calls (sync etc.)
	}

	_, err = s.StartOptimize(ctx, tenantID, siteID, eligibleAssetIDs, false,
		settings.AutoTargetFormat, settings.AutoTargetQuality, agentPrincipal)
	if err != nil {
		return result, err
	}

	result.Accepted = len(eligibleAssetIDs)
	return result, nil
}

// assertJobSite loads a job under the agent GUC and verifies it belongs to the
// agent's verified tenant + site.
func (s *Service) assertJobSite(ctx context.Context, tenantID, siteID uuid.UUID, jobID string) (model.Job, error) {
	job, err := s.repo.GetJobAgent(ctx, jobID)
	if err != nil {
		return model.Job{}, err
	}
	if job.TenantID != tenantID || job.SiteID != siteID {
		return model.Job{}, domain.Forbidden("media_job_site_mismatch", "the job does not belong to this site")
	}
	return job, nil
}

// cleanupTempObjects best-effort deletes every temp object under a job prefix
// (ADR-043 §2 — no media bytes persist on the CP). Failures are logged, not
// fatal (the GC sweep is the backstop).
func (s *Service) cleanupTempObjects(ctx context.Context, tenantID, siteID uuid.UUID, jobID string) {
	if s.store == nil {
		return
	}
	prefix := media.JobPrefix(tenantID, siteID, jobID) + "/"
	keys, err := s.store.List(ctx, prefix)
	if err != nil {
		s.logger.Warn("media temp cleanup list failed", "job_id", jobID, "err", err.Error())
		return
	}
	for _, k := range keys {
		if derr := s.store.Delete(ctx, k); derr != nil {
			s.logger.Warn("media temp cleanup delete failed", "key_prefix", prefix, "err", derr.Error())
		}
	}
}

func orDefault(s, def string) string {
	if s == "" {
		return def
	}
	return s
}
