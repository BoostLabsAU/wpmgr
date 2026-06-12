package objectcache

import (
	"encoding/json"
	"time"
)

// ---------------------------------------------------------------------------
// Wire DTOs for the REST API surface
// ---------------------------------------------------------------------------

// ConfigDTO is the wire shape returned by GET /object-cache/config.
// password_encrypted is never included; has_password signals its presence.
type ConfigDTO struct {
	Enabled     bool   `json:"enabled"`
	Scheme      string `json:"scheme"`
	Host        string `json:"host"`
	Port        int    `json:"port"`
	SocketPath  string `json:"socket_path,omitempty"`
	Database    int    `json:"database"`
	Username    string `json:"username,omitempty"`
	HasPassword bool   `json:"has_password"`
	Prefix      string `json:"prefix"`

	MaxTTLSeconds   int `json:"maxttl_seconds"`
	QueryTTLSeconds int `json:"queryttl_seconds"`

	ConnectTimeoutMs int `json:"connect_timeout_ms"`
	ReadTimeoutMs    int `json:"read_timeout_ms"`
	RetryCount       int `json:"retry_count"`
	RetryIntervalMs  int `json:"retry_interval_ms"`

	Serializer    string `json:"serializer"`
	Compression   string `json:"compression"`
	AsyncFlush    bool   `json:"async_flush"`
	FlushStrategy string `json:"flush_strategy"`
	Shared        bool   `json:"shared"`
	FlushOnFailback bool  `json:"flush_on_failback"`

	AnalyticsEnabled bool `json:"analytics_enabled"`

	// DebugHeaderEnabled gates the per-request X-WPMgr-Cache debug response
	// header emitted by the drop-in. False by default.
	DebugHeaderEnabled bool `json:"debug_header_enabled"`

	LastTestConfigHash string     `json:"last_test_config_hash,omitempty"`
	LastTestedAt       *time.Time `json:"last_tested_at,omitempty"`
	// LastTestResult is the stored agent test result (including the server
	// capability report) passed through verbatim so the dashboard renders
	// server requirements without re-running a test.
	LastTestResult json.RawMessage `json:"last_test_result,omitempty"`

	// Live status (from heartbeat).
	OCState           string   `json:"oc_state"`
	OCLatencyMs       int      `json:"oc_latency_ms"`
	OCLastErrorClass  string   `json:"oc_last_error_class,omitempty"`
	OCUsedMemoryBytes int64    `json:"oc_used_memory_bytes"`
	OCHitRatioPct     *float64 `json:"oc_hit_ratio_pct,omitempty"`
	// ConfigDrift is true when the agent's live config hash differs from the
	// CP-computed hash of the stored config (requires a config re-push).
	ConfigDrift bool `json:"config_drift,omitempty"`

	// PushWarning is set when the config was saved but the apply_config push to
	// the agent failed. The value is a capped error string for display.
	PushWarning string `json:"push_warning,omitempty"`

	CreatedAt time.Time `json:"created_at,omitempty"`
	UpdatedAt time.Time `json:"updated_at,omitempty"`
}

// ConfigPutDTO is the wire shape accepted by PUT /object-cache/config.
// Password is write-only: empty string means "keep stored password".
type ConfigPutDTO struct {
	Enabled     *bool   `json:"enabled,omitempty"`
	Scheme      string  `json:"scheme,omitempty"`
	Host        string  `json:"host,omitempty"`
	Port        int     `json:"port,omitempty"`
	SocketPath  string  `json:"socket_path,omitempty"`
	Database    int     `json:"database,omitempty"`
	Username    string  `json:"username,omitempty"`
	Password    string  `json:"password,omitempty"` // write-only; empty = keep stored
	Prefix      string  `json:"prefix,omitempty"`

	MaxTTLSeconds   int `json:"maxttl_seconds,omitempty"`
	QueryTTLSeconds int `json:"queryttl_seconds,omitempty"`

	ConnectTimeoutMs int `json:"connect_timeout_ms,omitempty"`
	ReadTimeoutMs    int `json:"read_timeout_ms,omitempty"`
	RetryCount       int `json:"retry_count,omitempty"`
	RetryIntervalMs  int `json:"retry_interval_ms,omitempty"`

	Serializer    string `json:"serializer,omitempty"`
	Compression   string `json:"compression,omitempty"`
	AsyncFlush    *bool  `json:"async_flush,omitempty"`
	FlushStrategy string `json:"flush_strategy,omitempty"`
	Shared        *bool  `json:"shared,omitempty"`
	FlushOnFailback *bool `json:"flush_on_failback,omitempty"`

	AnalyticsEnabled   *bool `json:"analytics_enabled,omitempty"`
	DebugHeaderEnabled *bool `json:"debug_header_enabled,omitempty"`
}

// toConfigDTO maps the domain Config to its wire representation.
// HasPassword is derived: true when the DB has a non-nil password_encrypted.
func toConfigDTO(c Config) ConfigDTO {
	dto := ConfigDTO{
		Enabled:          c.Enabled,
		Scheme:           orDefault(c.Scheme, "tcp"),
		Host:             c.Host,
		Port:             orDefaultInt(c.Port, 6379),
		SocketPath:       c.SocketPath,
		Database:         c.Database,
		Username:         c.Username,
		HasPassword:      c.HasPassword,
		Prefix:           c.Prefix,
		MaxTTLSeconds:    orDefaultInt(c.MaxTTLSeconds, 604800),
		QueryTTLSeconds:  orDefaultInt(c.QueryTTLSeconds, 86400),
		ConnectTimeoutMs: orDefaultInt(c.ConnectTimeoutMs, 1000),
		ReadTimeoutMs:    orDefaultInt(c.ReadTimeoutMs, 1000),
		RetryCount:       orDefaultInt(c.RetryCount, 3),
		RetryIntervalMs:  orDefaultInt(c.RetryIntervalMs, 25),
		Serializer:       orDefault(c.Serializer, "php"),
		Compression:      orDefault(c.Compression, "none"),
		AsyncFlush:       c.AsyncFlush,
		FlushStrategy:    orDefault(c.FlushStrategy, "auto"),
		Shared:           c.Shared,
		FlushOnFailback:  c.FlushOnFailback,
		AnalyticsEnabled:   c.AnalyticsEnabled,
		DebugHeaderEnabled: c.DebugHeaderEnabled,
		LastTestConfigHash: c.LastTestConfigHash,
		LastTestedAt:     c.LastTestedAt,
		LastTestResult:   c.LastTestResultJSON,
		OCState:           string(c.OCState),
		OCLatencyMs:       c.OCLatencyMs,
		OCLastErrorClass:  c.OCLastErrorClass,
		OCUsedMemoryBytes: c.OCUsedMemoryBytes,
		OCHitRatioPct:     c.OCHitRatioPct,
		ConfigDrift:       c.OCConfigDrift,
		CreatedAt:         c.CreatedAt,
		UpdatedAt:         c.UpdatedAt,
	}
	return dto
}

// fromConfigPutDTO maps a PUT body onto a Config. Missing fields carry their
// zero values; the service merges them onto the stored config as appropriate.
func fromConfigPutDTO(dto ConfigPutDTO, base Config) (Config, string) {
	cfg := base

	if dto.Scheme != "" {
		cfg.Scheme = dto.Scheme
	}
	if dto.Host != "" {
		cfg.Host = dto.Host
	}
	if dto.Port != 0 {
		cfg.Port = dto.Port
	}
	if dto.SocketPath != "" {
		cfg.SocketPath = dto.SocketPath
	}
	if dto.Database != 0 {
		cfg.Database = dto.Database
	}
	if dto.Username != "" {
		cfg.Username = dto.Username
	}
	if dto.Prefix != "" {
		cfg.Prefix = dto.Prefix
	}
	if dto.MaxTTLSeconds != 0 {
		cfg.MaxTTLSeconds = dto.MaxTTLSeconds
	}
	if dto.QueryTTLSeconds != 0 {
		cfg.QueryTTLSeconds = dto.QueryTTLSeconds
	}
	if dto.ConnectTimeoutMs != 0 {
		cfg.ConnectTimeoutMs = dto.ConnectTimeoutMs
	}
	if dto.ReadTimeoutMs != 0 {
		cfg.ReadTimeoutMs = dto.ReadTimeoutMs
	}
	if dto.RetryCount != 0 {
		cfg.RetryCount = dto.RetryCount
	}
	if dto.RetryIntervalMs != 0 {
		cfg.RetryIntervalMs = dto.RetryIntervalMs
	}
	if dto.Serializer != "" {
		cfg.Serializer = dto.Serializer
	}
	if dto.Compression != "" {
		cfg.Compression = dto.Compression
	}
	if dto.AsyncFlush != nil {
		cfg.AsyncFlush = *dto.AsyncFlush
	}
	if dto.FlushStrategy != "" {
		cfg.FlushStrategy = dto.FlushStrategy
	}
	if dto.Shared != nil {
		cfg.Shared = *dto.Shared
	}
	if dto.FlushOnFailback != nil {
		cfg.FlushOnFailback = *dto.FlushOnFailback
	}
	if dto.AnalyticsEnabled != nil {
		cfg.AnalyticsEnabled = *dto.AnalyticsEnabled
	}
	if dto.DebugHeaderEnabled != nil {
		cfg.DebugHeaderEnabled = *dto.DebugHeaderEnabled
	}
	if dto.Enabled != nil {
		cfg.Enabled = *dto.Enabled
	}

	// Password is returned separately so the service can decide whether to
	// encrypt it or preserve the nil-sentinel.
	return cfg, dto.Password
}

func orDefault(s, def string) string {
	if s == "" {
		return def
	}
	return s
}

func orDefaultInt(v, def int) int {
	if v == 0 {
		return def
	}
	return v
}
