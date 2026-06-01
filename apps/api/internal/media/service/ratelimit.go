package service

import (
	"sync"
	"time"

	"github.com/google/uuid"
)

// rateLimiter is a small in-process token-bucket-ish cap on how many optimize
// REQUESTS a single site (and tenant) may issue per window (one unit per
// bulk-optimize call — NOT per image; a single call may fan out to the whole
// library). It guards against a runaway LOOP of repeated bulk-optimize calls;
// the per-batch fan-out itself is safe because the agent drains uploads in
// chunks and the encoder queue's bounded MaxWorkers is the hard backstop. It is
// intentionally process-local; for a multi-instance deployment the queue bound
// dominates.
type rateLimiter struct {
	mu        sync.Mutex
	perSite   int
	perTenant int
	window    time.Duration
	sites     map[uuid.UUID]*window
	tenants   map[uuid.UUID]*window
	now       func() time.Time
}

type window struct {
	count   int
	resetAt time.Time
}

// newRateLimiter builds a limiter. perSite/perTenant are the max optimize
// requests per window; win is the rolling reset interval. Zero caps disable the
// limit.
func newRateLimiter(perSite, perTenant int, win time.Duration) *rateLimiter {
	if win <= 0 {
		win = time.Minute
	}
	return &rateLimiter{
		perSite:   perSite,
		perTenant: perTenant,
		window:    win,
		sites:     map[uuid.UUID]*window{},
		tenants:   map[uuid.UUID]*window{},
		now:       time.Now,
	}
}

// allow reports whether `n` encode enqueues for (tenant, site) fit within the
// caps. It records the spend when it returns true. A disabled cap (<=0) never
// blocks that dimension.
func (r *rateLimiter) allow(tenantID, siteID uuid.UUID, n int) bool {
	if n <= 0 {
		return true
	}
	r.mu.Lock()
	defer r.mu.Unlock()
	now := r.now()

	if r.perSite > 0 {
		w := r.bucket(r.sites, siteID, now)
		if w.count+n > r.perSite {
			return false
		}
	}
	if r.perTenant > 0 {
		w := r.bucket(r.tenants, tenantID, now)
		if w.count+n > r.perTenant {
			return false
		}
	}
	// Commit the spend on both dimensions (only after both pass).
	if r.perSite > 0 {
		r.bucket(r.sites, siteID, now).count += n
	}
	if r.perTenant > 0 {
		r.bucket(r.tenants, tenantID, now).count += n
	}
	return true
}

func (r *rateLimiter) bucket(m map[uuid.UUID]*window, id uuid.UUID, now time.Time) *window {
	w, ok := m[id]
	if !ok || now.After(w.resetAt) {
		w = &window{resetAt: now.Add(r.window)}
		m[id] = w
	}
	return w
}
