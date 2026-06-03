// Package service is the RUCSS business logic: it computes (or returns a cached)
// "used CSS" for one page-structure, stores the gzip-compressed result in object
// storage, and records the metadata in rucss_results. It dedups concurrent
// identical jobs via singleflight so a burst of agent requests for the same
// structure computes the purge exactly once.
package service

import (
	"bytes"
	"compress/gzip"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"io"
	"log/slog"
	"time"

	"github.com/google/uuid"
	"golang.org/x/sync/singleflight"

	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
	"github.com/mosamlife/wpmgr/apps/api/internal/rucss/engine"
	"github.com/mosamlife/wpmgr/apps/api/internal/rucss/model"
	"github.com/mosamlife/wpmgr/apps/api/internal/rucss/repo"
)

// BlobStore is the minimal object-storage surface the service needs. Unlike the
// backup chunk path (ciphertext via presigned URLs) the RUCSS used-CSS is small,
// plaintext, and CP-generated, so the control plane writes it directly with Put.
// *blobstore.Store satisfies this interface.
type BlobStore interface {
	Put(ctx context.Context, key string, body io.Reader, size int64) error
	Bucket() string
}

// Repository is the persistence surface (satisfied by *repo.Repo). Declared as
// an interface so the service is unit-testable with a fake.
type Repository interface {
	GetByHash(ctx context.Context, tenantID, siteID uuid.UUID, structureHash string) (model.Result, error)
	Upsert(ctx context.Context, in repo.UpsertInput) (model.Result, error)
	TouchLastUsed(ctx context.Context, tenantID, siteID uuid.UUID, structureHash string) error
}

// ComputeInput is one RUCSS request. HTML+CSS are the rendered page and its
// concatenated stylesheets; StructureHash is the agent-computed cache key
// (typically a hash of the DOM structure / template id). Safelist comes from the
// per-site css_rucss_include_selectors config.
type ComputeInput struct {
	TenantID      uuid.UUID
	SiteID        uuid.UUID
	StructureHash string
	URL           string
	HTML          []byte
	CSS           []byte
	Safelist      []string
}

// ComputeResult is what the service returns: the stored result row plus the
// per-pass stats (so the caller can surface them without re-reading the row).
type ComputeResult struct {
	Result model.Result
	Stats  model.Stats
	// CacheHit is true when an existing rucss_results row satisfied the request
	// (no purge was run).
	CacheHit bool
}

// Service orchestrates the compute-or-cache flow.
type Service struct {
	repo   Repository
	store  BlobStore
	clock  domain.Clock
	logger *slog.Logger
	// keyPrefix is the S3 key namespace for used-CSS objects.
	keyPrefix string
	// group dedups concurrent identical (site, hash) computations.
	group singleflight.Group
}

// NewService builds the service. store may be nil in environments where object
// storage is not configured — ComputeOrGetCached then returns a domain
// ServiceUnavailable error rather than panicking.
func NewService(r Repository, store BlobStore, clock domain.Clock, logger *slog.Logger) *Service {
	if logger == nil {
		logger = slog.Default()
	}
	return &Service{
		repo:      r,
		store:     store,
		clock:     clock,
		logger:    logger,
		keyPrefix: "rucss",
	}
}

// ComputeOrGetCached returns the used CSS for the request, computing it iff no
// cached result exists. On a cache hit it bumps last_used_at and returns the
// existing S3 key; on a miss it runs the engine, gzips the used CSS, stores it,
// and writes the rucss_results row. Concurrent identical requests collapse to a
// single computation via singleflight.
func (s *Service) ComputeOrGetCached(ctx context.Context, in ComputeInput) (ComputeResult, error) {
	if in.StructureHash == "" {
		return ComputeResult{}, domain.Validation("rucss_missing_hash", "structure_hash is required")
	}
	if s.store == nil {
		return ComputeResult{}, domain.ServiceUnavailable("rucss_store_unwired", "object storage is not configured for RUCSS")
	}

	// 1. Cache check (cheap, no singleflight needed for the read).
	if cached, err := s.repo.GetByHash(ctx, in.TenantID, in.SiteID, in.StructureHash); err == nil {
		// Hit: bump warmth, return the existing key. A touch failure is non-fatal
		// (the result is still valid) — log and proceed.
		if terr := s.repo.TouchLastUsed(ctx, in.TenantID, in.SiteID, in.StructureHash); terr != nil {
			s.logger.Warn("rucss touch last_used failed",
				slog.String("site_id", in.SiteID.String()),
				slog.String("structure_hash", in.StructureHash),
				slog.Any("error", terr))
		}
		return ComputeResult{Result: cached, CacheHit: true, Stats: statsFromResult(cached)}, nil
	} else if err != repo.ErrNotFound {
		return ComputeResult{}, fmt.Errorf("rucss cache lookup: %w", err)
	}

	// 2. Miss: dedup concurrent identical computations. The singleflight key
	// scopes to (site, hash) so two different sites never collide.
	key := in.SiteID.String() + "|" + in.StructureHash
	v, err, _ := s.group.Do(key, func() (any, error) {
		return s.computeAndStore(ctx, in)
	})
	if err != nil {
		return ComputeResult{}, err
	}
	return v.(ComputeResult), nil
}

// computeAndStore runs the engine, stores the gzip'd used CSS, and upserts the
// row. It re-checks the cache once more inside the singleflight critical section
// so a request that queued behind a just-finished identical one returns the
// fresh cached row instead of recomputing.
func (s *Service) computeAndStore(ctx context.Context, in ComputeInput) (ComputeResult, error) {
	if cached, err := s.repo.GetByHash(ctx, in.TenantID, in.SiteID, in.StructureHash); err == nil {
		_ = s.repo.TouchLastUsed(ctx, in.TenantID, in.SiteID, in.StructureHash)
		return ComputeResult{Result: cached, CacheHit: true, Stats: statsFromResult(cached)}, nil
	}

	start := s.clock.Now()
	usedCSS, eStats, err := engine.Purge(in.HTML, in.CSS, in.Safelist)
	if err != nil {
		// engine.Purge never returns a non-nil err today (it degrades to keep-all
		// with FellBack=true), but treat it defensively as a transient failure.
		return ComputeResult{}, fmt.Errorf("rucss purge: %w", err)
	}
	computeMs := int(s.clock.Now().Sub(start) / time.Millisecond)

	// gzip the used CSS for storage (text compresses ~5-8x).
	gz, err := gzipBytes([]byte(usedCSS))
	if err != nil {
		return ComputeResult{}, fmt.Errorf("rucss gzip: %w", err)
	}

	s3Key := s.objectKey(in.TenantID, in.SiteID, in.StructureHash)
	if err := s.store.Put(ctx, s3Key, bytes.NewReader(gz), int64(len(gz))); err != nil {
		return ComputeResult{}, fmt.Errorf("rucss store used css: %w", err)
	}

	row, err := s.repo.Upsert(ctx, repo.UpsertInput{
		TenantID:         in.TenantID,
		SiteID:           in.SiteID,
		StructureHash:    in.StructureHash,
		URL:              in.URL,
		OriginalCSSBytes: eStats.OriginalBytes,
		UsedCSSBytes:     eStats.UsedBytes,
		ReductionPct:     eStats.ReductionPct,
		UsedCSSS3Key:     s3Key,
		SelectorsTotal:   eStats.SelectorsTotal,
		SelectorsKept:    eStats.SelectorsKept,
		SelectorsDropped: eStats.SelectorsDropped,
		ComputeMs:        computeMs,
	})
	if err != nil {
		return ComputeResult{}, fmt.Errorf("rucss upsert result: %w", err)
	}

	stats := model.Stats{
		OriginalBytes:    eStats.OriginalBytes,
		UsedBytes:        eStats.UsedBytes,
		ReductionPct:     eStats.ReductionPct,
		SelectorsTotal:   eStats.SelectorsTotal,
		SelectorsKept:    eStats.SelectorsKept,
		SelectorsDropped: eStats.SelectorsDropped,
		FellBack:         eStats.FellBack,
		Note:             eStats.Note,
		ComputeMs:        computeMs,
	}
	if eStats.FellBack {
		s.logger.Info("rucss kept full css (fallback)",
			slog.String("site_id", in.SiteID.String()),
			slog.String("structure_hash", in.StructureHash),
			slog.String("note", eStats.Note))
	}
	return ComputeResult{Result: row, Stats: stats}, nil
}

// objectKey builds the deterministic S3 key for a used-CSS object. The key is
// namespaced by tenant+site and ends in the structure hash so it is stable and
// collision-free across tenants. .css.gz signals the gzip encoding.
func (s *Service) objectKey(tenantID, siteID uuid.UUID, structureHash string) string {
	return fmt.Sprintf("%s/%s/%s/%s.css.gz", s.keyPrefix, tenantID.String(), siteID.String(), structureHash)
}

// HashStructure is a convenience used by callers/tests to derive a stable
// structure hash from HTML when the agent did not supply one. It is the sha256
// hex of the HTML bytes; the agent normally computes a smarter DOM-shape hash.
func HashStructure(html []byte) string {
	sum := sha256.Sum256(html)
	return hex.EncodeToString(sum[:])
}

func gzipBytes(b []byte) ([]byte, error) {
	var buf bytes.Buffer
	w := gzip.NewWriter(&buf)
	if _, err := w.Write(b); err != nil {
		_ = w.Close()
		return nil, err
	}
	if err := w.Close(); err != nil {
		return nil, err
	}
	return buf.Bytes(), nil
}

func statsFromResult(r model.Result) model.Stats {
	return model.Stats{
		OriginalBytes:    r.OriginalCSSBytes,
		UsedBytes:        r.UsedCSSBytes,
		ReductionPct:     r.ReductionPct,
		SelectorsTotal:   r.SelectorsTotal,
		SelectorsKept:    r.SelectorsKept,
		SelectorsDropped: r.SelectorsDropped,
		ComputeMs:        r.ComputeMs,
	}
}
