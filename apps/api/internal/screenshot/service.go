package screenshot

import (
	"context"
	"net/url"
	"strings"
	"time"

	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/blobstore"
	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
	"github.com/mosamlife/wpmgr/apps/api/internal/httpclient"
)

// Presigner mints presigned GET URLs. *blobstore.Store satisfies it.
type Presigner interface {
	PresignGet(ctx context.Context, key string, ttl time.Duration) (string, error)
}

// CaptureEnqueuer enqueues screenshot capture jobs. *Enqueuer satisfies it.
type CaptureEnqueuer interface {
	Enqueue(ctx context.Context, args CaptureArgs) (int64, error)
}

// EncoderWaker kicks the media-encoder wake loop after a successful enqueue.
// media.EncoderWaker satisfies it.
type EncoderWaker interface {
	Kick()
}

// Service implements the operator-facing screenshot operations.
type Service struct {
	repo       Repo
	store      Presigner
	enqueuer   CaptureEnqueuer
	waker      EncoderWaker
	presignTTL time.Duration
}

// NewService builds a screenshot Service.
// store may be nil when object storage is not configured (screenshots are
// then effectively disabled — PresignURL returns "" and EnqueueCapture
// requires the enqueuer to be non-nil to enqueue).
func NewService(repo Repo, store Presigner, enqueuer CaptureEnqueuer, waker EncoderWaker) *Service {
	return &Service{
		repo:       repo,
		store:      store,
		enqueuer:   enqueuer,
		waker:      waker,
		presignTTL: DefaultPresignTTL,
	}
}

// SetEnqueuer wires the River enqueuer post-River-start. Must be called before
// serving traffic. Mirrors the deferred-wiring pattern used by media and email.
func (s *Service) SetEnqueuer(e CaptureEnqueuer) {
	s.enqueuer = e
}

// SetWaker wires the encoder waker (e.g. media.EncoderWaker) so that enqueuing
// a capture job also cold-starts the scale-to-zero media-encoder instance.
// May be called after NewService when the waker is constructed later.
func (s *Service) SetWaker(w EncoderWaker) {
	s.waker = w
}

// EnqueueCapture validates the site URL (SSRF guard), marks the row pending,
// enqueues the capture job, and kicks the encoder waker. Returns the updated
// screenshot row (status=pending).
func (s *Service) EnqueueCapture(ctx context.Context, tenantID, siteID uuid.UUID, siteURL string, reason CaptureReason) (Screenshot, error) {
	// Pre-validate the URL before enqueuing. The headless browser will follow
	// it, but a string-level sanity check here blocks obviously-invalid or
	// non-http(s) inputs from ever reaching the queue. The SSRF dialer inside
	// the worker provides the authoritative per-connection guard.
	if err := validateSiteURL(siteURL); err != nil {
		return Screenshot{}, err
	}

	row, err := s.repo.MarkPending(ctx, tenantID, siteID)
	if err != nil {
		return Screenshot{}, err
	}

	if s.enqueuer != nil {
		if _, err := s.enqueuer.Enqueue(ctx, CaptureArgs{
			SiteID:   siteID,
			TenantID: tenantID,
			SiteURL:  siteURL,
			Reason:   reason,
		}); err != nil {
			// Enqueue failure is logged but does NOT roll back the pending mark.
			// The operator can retry via the manual refresh endpoint.
			return row, domain.Internal("screenshot_enqueue_failed", "failed to enqueue capture job").WithCause(err)
		}
		if s.waker != nil {
			s.waker.Kick()
		}
	}
	return row, nil
}

// Get returns the screenshot row for the given tenant-scoped site.
func (s *Service) Get(ctx context.Context, tenantID, siteID uuid.UUID) (Screenshot, error) {
	return s.repo.Get(ctx, tenantID, siteID)
}

// PresignURL mints a presigned GET URL for the given GCS/S3 object key.
// Returns "" when the key is empty (no screenshot yet).
func (s *Service) PresignURL(ctx context.Context, key string) (string, error) {
	if key == "" {
		return "", nil
	}
	return s.store.PresignGet(ctx, key, s.presignTTL)
}

// validateSiteURL performs a string-level pre-check on the site URL before
// enqueuing a capture. The headless SSRF proxy is the authoritative guard at
// dial time; this is a defence-in-depth pre-filter that rejects obviously
// unusable inputs early.
//
// Rules:
//   - Must parse as a valid URL.
//   - Scheme must be http or https (rejects javascript:, file:, ftp:, etc.).
//   - Host must be non-empty.
//   - No credentials (user:pass@) embedded in the URL.
func validateSiteURL(raw string) error {
	u, err := url.Parse(raw)
	if err != nil {
		return domain.Validation("screenshot_invalid_url", "site URL is not a valid URL")
	}
	scheme := strings.ToLower(u.Scheme)
	if scheme != "http" && scheme != "https" {
		return domain.Validation("screenshot_invalid_scheme", "site URL must use http or https scheme")
	}
	if u.Host == "" {
		return domain.Validation("screenshot_missing_host", "site URL must have a non-empty host")
	}
	// Reject embedded credentials — they are an information-leak risk and not
	// expected from a legitimate WordPress site URL.
	if u.User != nil {
		return domain.Validation("screenshot_credentials_in_url", "site URL must not embed credentials")
	}
	return nil
}

// IsSSRFBlocked is re-exported so callers (tests) can use the httpclient
// guard without importing httpclient directly.
var IsSSRFBlocked = httpclient.IsSSRFBlocked

// Ensure *blobstore.Store satisfies Presigner at compile time.
var _ Presigner = (*blobstore.Store)(nil)
