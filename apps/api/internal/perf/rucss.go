package perf

import (
	"bytes"
	"context"
	"encoding/binary"
	"fmt"
	"io"
	"log/slog"

	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/agentcmd"
	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
	rucssmodel "github.com/mosamlife/wpmgr/apps/api/internal/rucss/model"
	rucssworker "github.com/mosamlife/wpmgr/apps/api/internal/rucss/worker"
	siteevents "github.com/mosamlife/wpmgr/apps/api/internal/site/events"
)

// RucssBundleStore is the object-storage surface used to stash the agent-posted
// HTML+CSS bundle on a RUCSS cache miss so the River worker can fetch it back.
// Delete removes the temp bundle once the worker has consumed it (or a backstop
// sweeper reaps an orphan): the source HTML is page output we must NOT retain in
// object storage past the single computation it feeds. *blobstore.Store
// satisfies it (its Delete is idempotent on a missing key).
type RucssBundleStore interface {
	Put(ctx context.Context, key string, body io.Reader, size int64) error
	Get(ctx context.Context, key string) (io.ReadCloser, error)
	Delete(ctx context.Context, key string) error
}

// RucssEnqueuer is the subset of rucss/worker.RiverEnqueuer the perf service
// needs. *rucssworker.RiverEnqueuer satisfies it.
type RucssEnqueuer interface {
	EnqueueRucss(ctx context.Context, a rucssworker.RucssArgs) error
}

// RucssRepo is the subset of rucss/repo.Repo the agent ingest endpoint needs to
// short-circuit on a cache hit and create the job row on a miss. *repo.Repo
// satisfies it (GetByHash + InsertJob). Declared as an interface so the ingest
// service is unit-testable with a fake.
type RucssRepo interface {
	GetByHash(ctx context.Context, tenantID, siteID uuid.UUID, structureHash string) (rucssmodel.Result, error)
	InsertJob(ctx context.Context, j rucssmodel.Job) (rucssmodel.Job, error)
}

// On a GetByHash miss *rucss/repo.Repo returns repo.ErrNotFound. The ingest
// service treats ANY non-nil GetByHash error (or an empty stored key) as a
// miss-or-degrade and proceeds to enqueue — a transient DB error simply falls
// through to the enqueue path (the agent serves full CSS this render), so the
// exact sentinel does not matter for correctness.

// RucssIngestInput is one agent RUCSS request after the multipart parse.
type RucssIngestInput struct {
	TenantID      uuid.UUID
	SiteID        uuid.UUID
	StructureHash string
	URL           string
	HTML          []byte
	CSS           []byte
	Safelist      []string
	// Reheat marks this ingest as originating from a CP post-compute re-warm
	// self-fetch. It flows into the enqueued RucssArgs so the worker will NOT
	// re-trigger another reheat for a result computed from a reheat render
	// (terminates the loop if structure_hash drifts between renders).
	Reheat bool
}

// RucssIngestResult is what the agent endpoint returns. On a hit, Cached is true
// and the used-CSS CONTENT is loaded into UsedCSS (the agent has no S3 access, so
// the CP returns the bytes, not the key). UsedCSSGzip reports whether UsedCSS is
// gzip-compressed (it always is — the service stores .css.gz — so the handler
// sets Content-Encoding: gzip). S3Key/ReductionPct/UsedCSSBytes are metadata. On
// a miss, Processing is true and JobID identifies the enqueued job (the agent
// serves full CSS now).
type RucssIngestResult struct {
	Cached       bool
	Processing   bool
	S3Key        string
	ReductionPct float64
	UsedCSSBytes int
	JobID        string
	// UsedCSS is the cached used-CSS object bytes (gzip-compressed when
	// UsedCSSGzip is true). Populated ONLY on a cache hit that successfully read
	// the object; empty when the object read failed (the handler then degrades to
	// a 202 miss so the agent keeps serving full CSS).
	UsedCSS     []byte
	UsedCSSGzip bool
}

// RucssIngestService orchestrates the agent RUCSS endpoint: cache lookup → on
// hit return the stored key; on miss stash the source bundle in object storage,
// create the job row, and enqueue the rucss_process River job (the agent NEVER
// blocks — it serves full CSS this render).
type RucssIngestService struct {
	repo     RucssRepo
	store    RucssBundleStore
	enqueuer RucssEnqueuer
	clock    domain.Clock
	logger   *slog.Logger
}

// NewRucssIngestService builds the ingest service.
func NewRucssIngestService(repo RucssRepo, store RucssBundleStore, enqueuer RucssEnqueuer, clock domain.Clock, logger *slog.Logger) *RucssIngestService {
	if logger == nil {
		logger = slog.Default()
	}
	return &RucssIngestService{repo: repo, store: store, enqueuer: enqueuer, clock: clock, logger: logger}
}

// Ingest runs the compute-or-cache decision. It is non-blocking on a miss.
func (s *RucssIngestService) Ingest(ctx context.Context, in RucssIngestInput) (RucssIngestResult, error) {
	if in.StructureHash == "" {
		return RucssIngestResult{}, domain.Validation("rucss_missing_hash", "structure_hash is required")
	}

	// 1. Cache hit? On a hit we must return the used-CSS CONTENT (the agent has no
	// S3 access and cannot fetch by key), so read the stored object here. If the
	// object read fails (storage blip / object GC'd) we fall through to the miss
	// path and let the agent serve full CSS this render — never error the agent.
	if s.repo != nil {
		if cached, err := s.repo.GetByHash(ctx, in.TenantID, in.SiteID, in.StructureHash); err == nil && cached.UsedCSSS3Key != "" {
			body, rerr := s.fetchUsedCSS(ctx, cached.UsedCSSS3Key)
			if rerr == nil {
				return RucssIngestResult{
					Cached:       true,
					S3Key:        cached.UsedCSSS3Key,
					ReductionPct: cached.ReductionPct,
					UsedCSSBytes: cached.UsedCSSBytes,
					UsedCSS:      body,
					// The service always stores used-CSS gzip-compressed (.css.gz).
					UsedCSSGzip: true,
				}, nil
			}
			s.logger.Warn("rucss ingest: read used-css on hit failed; degrading to recompute",
				slog.String("site_id", in.SiteID.String()),
				slog.String("s3_key", cached.UsedCSSS3Key),
				slog.Any("error", rerr))
			// fall through to miss/enqueue
		}
	}

	// 2. Miss: we need both store + enqueuer to process. If either is unwired,
	// report processing=false so the agent keeps serving full CSS (no error to
	// the agent — RUCSS is strictly an enhancement).
	if s.store == nil || s.enqueuer == nil {
		return RucssIngestResult{Processing: false}, nil
	}

	jobID := siteevents.NewULID(s.clock.Now())
	sourceKey := rucssBundleKey(in.TenantID, in.SiteID, jobID)

	bundle := encodeBundle(in.HTML, in.CSS)
	if err := s.store.Put(ctx, sourceKey, bytes.NewReader(bundle), int64(len(bundle))); err != nil {
		// Storage blip: do not fail the agent. Log + report not-processing.
		s.logger.Warn("rucss ingest: stash source failed",
			slog.String("site_id", in.SiteID.String()), slog.Any("error", err))
		return RucssIngestResult{Processing: false}, nil
	}

	if s.repo != nil {
		if _, err := s.repo.InsertJob(ctx, rucssmodel.Job{
			ID:            jobID,
			TenantID:      in.TenantID,
			SiteID:        in.SiteID,
			StructureHash: in.StructureHash,
			URL:           in.URL,
			State:         rucssmodel.JobStateQueued,
		}); err != nil {
			s.logger.Warn("rucss ingest: insert job failed",
				slog.String("site_id", in.SiteID.String()), slog.Any("error", err))
		}
	}

	if err := s.enqueuer.EnqueueRucss(ctx, rucssworker.RucssArgs{
		TenantID:      in.TenantID,
		SiteID:        in.SiteID,
		JobID:         jobID,
		StructureHash: in.StructureHash,
		URL:           in.URL,
		SourceKey:     sourceKey,
		Safelist:      in.Safelist,
		Reheat:        in.Reheat,
	}); err != nil {
		s.logger.Warn("rucss ingest: enqueue failed",
			slog.String("site_id", in.SiteID.String()), slog.Any("error", err))
		return RucssIngestResult{Processing: false}, nil
	}

	return RucssIngestResult{Processing: true, JobID: jobID}, nil
}

// rucssSourceFetcher resolves the HTML+CSS bundle for a RUCSS job from the
// bundle store, and deletes it once the worker is done with it. It implements
// both rucss/worker.SourceFetcher and rucss/worker.SourceDeleter.
type rucssSourceFetcher struct {
	store RucssBundleStore
}

// NewRucssSourceFetcher builds the worker's source fetcher over the bundle store.
// The returned value also satisfies rucss/worker.SourceDeleter so the worker can
// reap the temp source bundle after it has been consumed.
func NewRucssSourceFetcher(store RucssBundleStore) *rucssSourceFetcher {
	return &rucssSourceFetcher{store: store}
}

// Fetch reads the length-prefixed [htmlLen|html|cssLen|css] bundle the agent
// ingest endpoint stashed at args.SourceKey.
func (f *rucssSourceFetcher) Fetch(ctx context.Context, args rucssworker.RucssArgs) (rucssworker.Source, error) {
	if f.store == nil {
		return rucssworker.Source{}, fmt.Errorf("rucss source fetcher: bundle store unwired")
	}
	if args.SourceKey == "" {
		return rucssworker.Source{}, fmt.Errorf("rucss source fetcher: empty source key")
	}
	rc, err := f.store.Get(ctx, args.SourceKey)
	if err != nil {
		return rucssworker.Source{}, fmt.Errorf("rucss source fetcher: get %q: %w", args.SourceKey, err)
	}
	defer func() { _ = rc.Close() }()
	raw, err := io.ReadAll(rc)
	if err != nil {
		return rucssworker.Source{}, fmt.Errorf("rucss source fetcher: read bundle: %w", err)
	}
	html, css, derr := decodeBundle(raw)
	if derr != nil {
		return rucssworker.Source{}, fmt.Errorf("rucss source fetcher: decode bundle: %w", derr)
	}
	return rucssworker.Source{HTML: html, CSS: css}, nil
}

// DeleteSource removes the temp HTML+CSS bundle at args.SourceKey. The worker
// calls this once the bundle has been consumed (success) or the job has failed
// terminally and will not be retried, so page HTML is never retained in object
// storage past the single computation it feeds. Delete is idempotent on a
// missing key, so a double-delete (e.g. worker + backstop sweeper race) is safe.
func (f *rucssSourceFetcher) DeleteSource(ctx context.Context, args rucssworker.RucssArgs) error {
	if f.store == nil || args.SourceKey == "" {
		return nil
	}
	if err := f.store.Delete(ctx, args.SourceKey); err != nil {
		return fmt.Errorf("rucss source fetcher: delete %q: %w", args.SourceKey, err)
	}
	return nil
}

// encodeBundle serializes html+css as [uint32 htmlLen][html][uint32 cssLen][css].
func encodeBundle(html, css []byte) []byte {
	buf := bytes.NewBuffer(make([]byte, 0, 8+len(html)+len(css)))
	var hdr [4]byte
	binary.BigEndian.PutUint32(hdr[:], uint32(len(html)))
	buf.Write(hdr[:])
	buf.Write(html)
	binary.BigEndian.PutUint32(hdr[:], uint32(len(css)))
	buf.Write(hdr[:])
	buf.Write(css)
	return buf.Bytes()
}

// decodeBundle reverses encodeBundle.
func decodeBundle(raw []byte) (html, css []byte, err error) {
	if len(raw) < 4 {
		return nil, nil, fmt.Errorf("bundle too short")
	}
	hl := binary.BigEndian.Uint32(raw[0:4])
	off := 4 + int(hl)
	if off+4 > len(raw) {
		return nil, nil, fmt.Errorf("bundle html length out of range")
	}
	html = raw[4:off]
	cl := binary.BigEndian.Uint32(raw[off : off+4])
	cssStart := off + 4
	if cssStart+int(cl) > len(raw) {
		return nil, nil, fmt.Errorf("bundle css length out of range")
	}
	css = raw[cssStart : cssStart+int(cl)]
	return html, css, nil
}

// rucssBundleKey is the deterministic temp-object key for a RUCSS source bundle.
func rucssBundleKey(tenantID, siteID uuid.UUID, jobID string) string {
	return fmt.Sprintf("rucss-src/%s/%s/%s.bin", tenantID.String(), siteID.String(), jobID)
}

// rucssReheater re-warms a URL's page cache after the RUCSS worker has just
// computed + stored its used-CSS, so the NEXT render of that URL is a CP cache
// HIT (200) whose optimized HTML the agent writes to disk. Without this, the
// async compute leaves the agent's page cache holding the un-optimized (202)
// render forever. It satisfies rucss/worker.CacheReheater and reuses the perf
// service's existing agent command client + site URL lookup.
//
// Mechanism (mirrors the reference's "completion -> purge(url) -> re-render"
// chain): purge the URL's cached page (so the static fast-path cannot serve the
// stale 202 file), then re-send rucss_compute{urls:[url], reheat:true}. That
// command self-fetches with the cache-bypass header, the render's RUCSS stage
// re-POSTs to /agent/v1/rucss, the CP now returns the cached result (200), the
// agent applies the used-CSS and caches the optimized page. The reheat:true flag
// rides through to the worker so a drifted-hash re-miss does not loop.
type rucssReheater struct {
	agent  AgentPerfClient
	sites  SiteLookup
	logger *slog.Logger
}

// NewRucssReheater builds the post-compute cache re-warm seam for the RUCSS
// worker. agent/sites may be nil in degraded environments (ReheatURL is then a
// no-op error the worker logs and ignores).
func NewRucssReheater(agent AgentPerfClient, sites SiteLookup, logger *slog.Logger) *rucssReheater {
	if logger == nil {
		logger = slog.Default()
	}
	return &rucssReheater{agent: agent, sites: sites, logger: logger}
}

// ReheatURL purges then re-computes the given URL so the page cache is rebuilt
// with the just-computed used-CSS applied. Best-effort: the worker treats any
// error as non-fatal (the organic next-visit path is the backstop).
func (r *rucssReheater) ReheatURL(ctx context.Context, tenantID, siteID uuid.UUID, pageURL string) error {
	if r.agent == nil || r.sites == nil {
		return fmt.Errorf("rucss reheater: agent client not wired")
	}
	siteURL, err := r.sites.GetSiteURL(ctx, tenantID, siteID)
	if err != nil {
		return fmt.Errorf("rucss reheater: site url: %w", err)
	}
	// Purge the just-computed URL so the agent's static fast-path can't keep
	// serving the un-optimized .gz. Best-effort — a purge failure must not block
	// the re-warm (the rucss_compute command purges again before its self-fetch).
	if _, perr := r.agent.CachePurge(ctx, siteID, siteURL, agentcmd.CachePurgeRequest{Scope: "url", URL: pageURL}); perr != nil {
		r.logger.Warn("rucss reheat: pre-purge failed (continuing)",
			slog.String("site_id", siteID.String()), slog.String("url", pageURL), slog.Any("error", perr))
	}
	if _, perr := r.agent.RucssCompute(ctx, siteID, siteURL, agentcmd.RucssComputeRequest{
		URLs:   []string{pageURL},
		Reheat: true,
	}); perr != nil {
		return fmt.Errorf("rucss reheater: re-compute: %w", perr)
	}
	return nil
}

// maxServedUsedCSS caps how many bytes of a stored used-CSS object the hit path
// will read back to the agent. The object is gzip-compressed used CSS (the
// uncompressed source CSS is itself capped at maxRucssCSS = 5 MiB, so the gzip
// is far smaller); this ceiling is generous headroom that still bounds the
// response so a corrupt/oversize object can't blow up the agent request.
const maxServedUsedCSS = 8 << 20 // 8 MiB

// fetchUsedCSS reads the cached used-CSS object bytes from the bundle store
// (same blobstore the service wrote them to). It enforces maxServedUsedCSS so an
// unexpectedly large object cannot be streamed unbounded to the agent.
func (s *RucssIngestService) fetchUsedCSS(ctx context.Context, key string) ([]byte, error) {
	if s.store == nil {
		return nil, fmt.Errorf("rucss ingest: bundle store unwired")
	}
	rc, err := s.store.Get(ctx, key)
	if err != nil {
		return nil, fmt.Errorf("rucss ingest: get used-css %q: %w", key, err)
	}
	defer func() { _ = rc.Close() }()
	body, err := io.ReadAll(io.LimitReader(rc, int64(maxServedUsedCSS)+1))
	if err != nil {
		return nil, fmt.Errorf("rucss ingest: read used-css %q: %w", key, err)
	}
	if len(body) > maxServedUsedCSS {
		return nil, fmt.Errorf("rucss ingest: used-css %q exceeds %d bytes", key, maxServedUsedCSS)
	}
	return body, nil
}
