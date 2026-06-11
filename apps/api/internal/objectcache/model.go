// Package objectcache is the control-plane domain for per-site object cache
// management (M68). It owns config CRUD (with age-encrypted password), signed
// agent commands (apply_config / test / enable / disable / flush), stats-report
// ingest, time-series history, SSE event publication, and flush audit.
//
// The package follows the per-file-not-per-subpackage layering convention:
// model.go (domain types), repo.go (DB access), service.go (business logic),
// handler.go (Gin handlers), dto.go (wire <-> domain mapping), worker.go (River).
package objectcache

import (
	"time"

	"github.com/google/uuid"
)

// OCState enumerates the object-cache connectivity states reported by the agent.
type OCState string

const (
	// OCStateDisabled means no config exists or the feature is toggled off.
	OCStateDisabled   OCState = ""
	// OCStateConnected means the last command cycle completed without errors.
	OCStateConnected  OCState = "connected"
	// OCStateDegraded means array-fallback is active or reconnect-once fired.
	OCStateDegraded   OCState = "degraded"
	// OCStateDown means the boot failover to in-memory cache is engaged.
	OCStateDown       OCState = "down"
)

// Config is the domain model for site_object_cache_config. password_encrypted is
// NEVER included; use the repo's WithSecret variant for command rendering.
type Config struct {
	SiteID   uuid.UUID
	TenantID uuid.UUID

	Enabled bool

	// Connection topology (v1: tcp | unix | tls; schema reserved for future).
	Scheme     string
	Host       string
	Port       int
	SocketPath string
	Database   int
	Username   string
	// HasPassword is a derived field set by the repo: true when password_encrypted
	// is non-nil in the DB. The plaintext password is NEVER carried in this struct.
	HasPassword bool

	// Key prefix applied to every Redis key written by this site.
	Prefix string

	// TTL knobs (seconds).
	MaxTTLSeconds   int
	QueryTTLSeconds int

	// Connection resilience knobs.
	ConnectTimeoutMs int
	ReadTimeoutMs    int
	RetryCount       int
	RetryIntervalMs  int

	// Serializer and compression options.
	Serializer  string
	Compression string

	// Flush configuration.
	AsyncFlush      bool
	FlushStrategy   string
	Shared          bool
	FlushOnFailback bool

	// Analytics toggle.
	AnalyticsEnabled bool

	// Test gate: non-empty when the most recent test PASSED for the config hash.
	// Enable is rejected when this is empty (handshake gate).
	LastTestConfigHash string
	// LastTestResultJSON is the raw JSON payload from the most recent test result.
	// Stored for display; not used for gate logic.
	LastTestResultJSON []byte
	LastTestedAt       *time.Time

	// Live status fields sourced from the heartbeat. Used for SSE transition
	// detection without hitting the agent.
	OCState          OCState
	OCLatencyMs      int
	OCLastErrorClass string
	OCUsedMemoryBytes int64
	OCHitRatioPct    *float64

	CreatedAt time.Time
	UpdatedAt time.Time
}

// StatsPoint is one row of site_object_cache_stats_history.
type StatsPoint struct {
	ID               uuid.UUID
	SiteID           uuid.UUID
	TenantID         uuid.UUID
	HitCount         int64
	MissCount        int64
	RatioPct         *float64
	UsedMemoryBytes  int64
	AvgWaitMs        float64
	OpsPerSec        int
	EvictedKeysDelta int64
	ConnectedClients int
	SampledAt        time.Time
	CreatedAt        time.Time
}

// StatsHistoryPoint is one aggregated (daily-bucketed) point as returned by
// GetObjectCacheStatsHistory.
type StatsHistoryPoint struct {
	SampledAt        time.Time `json:"sampled_at"`
	RatioPct         *float64  `json:"ratio_pct"`
	HitCount         int64     `json:"hit_count"`
	MissCount        int64     `json:"miss_count"`
	UsedMemoryBytes  int64     `json:"used_memory_bytes"`
	AvgWaitMs        float64   `json:"avg_wait_ms"`
	OpsPerSec        int       `json:"ops_per_sec"`
	EvictedKeysDelta int64     `json:"evicted_keys_delta"`
}

// StatsHistoryResponse is the payload for GET /object-cache/stats-history.
type StatsHistoryResponse struct {
	Points      []StatsHistoryPoint `json:"points"`
	AvgRatioPct float64             `json:"avg_ratio_pct"`
}

// HeartbeatBlock is the optional object_cache block the agent appends to its
// heartbeat push. Every field is optional; absent block = disabled state.
type HeartbeatBlock struct {
	State          OCState `json:"state"`
	LatencyMs      int     `json:"latency_ms"`
	LastErrorClass string  `json:"last_error_class,omitempty"`
	UsedMemoryBytes int64  `json:"used_memory_bytes"`
	HitRatioPct    float64 `json:"hit_ratio_window_pct"`
}

// IngestStatsInput is the agent stats-report optional object_cache block.
// Mirrors the agent's wire format (tolerant ingest: unknown fields ignored).
type IngestStatsInput struct {
	SiteID   uuid.UUID
	TenantID uuid.UUID

	// Delta counts since the agent's last emission window.
	HitCount  int64 `json:"hit_count"`
	MissCount int64 `json:"miss_count"`

	// Server INFO snapshot.
	UsedMemoryBytes  int64   `json:"used_memory_bytes"`
	AvgWaitMs        float64 `json:"avg_wait_ms"`
	OpsPerSec        int     `json:"ops_per_sec"`
	EvictedKeysDelta int64   `json:"evicted_keys_delta"`
	ConnectedClients int     `json:"connected_clients"`
}

// TestInput carries the candidate config to test (before saving to DB).
type TestInput struct {
	SiteID   uuid.UUID
	TenantID uuid.UUID
	// Password is the plaintext secret supplied by the operator in this request.
	// Empty means "use the stored password" (the service decrypts from DB).
	Password string
}

// FlushInput carries the operator's flush request.
type FlushInput struct {
	SiteID      uuid.UUID
	TenantID    uuid.UUID
	Scope       string // "all" | "site" | "group"
	Group       string // required when Scope=="group"
	InitiatorID uuid.UUID
}
