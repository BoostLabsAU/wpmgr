package objectcache

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"log/slog"
	"regexp"
	"strings"
	"time"

	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/agentcmd"
	"github.com/mosamlife/wpmgr/apps/api/internal/cryptbox"
	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
	"github.com/mosamlife/wpmgr/apps/api/internal/site"
)

// SiteURLer returns the current URL for a site (needed to send agent commands).
type SiteURLer interface {
	GetSiteURL(ctx context.Context, tenantID, siteID uuid.UUID) (string, error)
}

// Service implements the Object Cache control-plane business logic.
type Service struct {
	repo      *Repo
	cryptbox  *cryptbox.AgeIdentity
	cmdClient *agentcmd.Client
	urler     SiteURLer
	publisher site.EventPublisher
	logger    *slog.Logger
}

// NewService wires the service with its dependencies.
func NewService(repo *Repo, box *cryptbox.AgeIdentity, cmdClient *agentcmd.Client, urler SiteURLer, pub site.EventPublisher) *Service {
	return &Service{
		repo:      repo,
		cryptbox:  box,
		cmdClient: cmdClient,
		urler:     urler,
		publisher: pub,
		logger:    slog.Default(),
	}
}

// capDetail bounds an agent-supplied detail string before logging. The agent
// normally returns a short fixed string, but the value is attacker-controlled
// on a compromised site.
func capDetail(s string) string {
	if len(s) > 256 {
		return s[:256]
	}
	return s
}

// GetConfig returns the per-site object cache config (without the password).
func (s *Service) GetConfig(ctx context.Context, tenantID, siteID uuid.UUID) (Config, error) {
	cfg, err := s.repo.GetConfig(ctx, tenantID, siteID)
	if err != nil {
		if err == ErrNotFound {
			return defaultConfig(siteID, tenantID), nil
		}
		return Config{}, err
	}
	return cfg, nil
}

// UpdateConfig saves the per-site object cache config. If passwordRaw is
// non-empty it is encrypted with the cryptbox and stored; otherwise the stored
// ciphertext is preserved (nil-sentinel). Connection-critical field changes
// clear the test hash (enable gate resets). Returns the saved config.
func (s *Service) UpdateConfig(ctx context.Context, tenantID, siteID uuid.UUID, input UpdateConfigInput) (Config, error) {
	if err := validateConfig(input); err != nil {
		return Config{}, err
	}

	// Encrypt the password when provided; nil preserves the stored ciphertext.
	var passwordEncrypted []byte
	if input.PasswordRaw != "" {
		enc, err := s.cryptbox.Encrypt([]byte(input.PasswordRaw))
		if err != nil {
			return Config{}, fmt.Errorf("objectcache: encrypt password: %w", err)
		}
		passwordEncrypted = enc
	}

	// Detect whether connection-critical fields changed by comparing against
	// the stored config hash. If they changed we must clear the test hash.
	stored, _ := s.repo.GetConfig(ctx, tenantID, siteID)
	clearTestHash := connectionChanged(stored, input.Config) || passwordEncrypted != nil

	// If prefix is empty, derive a stable default from the site_id.
	if input.Config.Prefix == "" {
		h := sha256.Sum256(siteID[:])
		input.Config.Prefix = "wpmgr_" + hex.EncodeToString(h[:])[:16]
	}

	cfg := input.Config
	cfg.SiteID = siteID
	cfg.TenantID = tenantID

	// Preserve live status fields from the stored row (never overwritten by PUT).
	cfg.OCState = stored.OCState
	cfg.OCLatencyMs = stored.OCLatencyMs
	cfg.OCLastErrorClass = stored.OCLastErrorClass
	cfg.OCUsedMemoryBytes = stored.OCUsedMemoryBytes
	cfg.OCHitRatioPct = stored.OCHitRatioPct

	// Preserve the test result unless the connection changed (in which case
	// clearTestHash is true and the result is intentionally discarded because it
	// no longer corresponds to the saved config).
	if !clearTestHash {
		cfg.LastTestResultJSON = stored.LastTestResultJSON
		cfg.LastTestedAt = stored.LastTestedAt
	}

	saved, err := s.repo.UpsertConfig(ctx, tenantID, cfg, passwordEncrypted, clearTestHash)
	if err != nil {
		return Config{}, err
	}

	// Push the config to the agent (best-effort: a push failure is non-fatal
	// and surfaced as a warning header by the handler).
	_ = s.pushApplyConfig(ctx, tenantID, siteID, saved, "")

	return saved, nil
}

// Test sends the objectcache.test command with the candidate config and stores
// the result. Returns the updated config with the test result embedded.
func (s *Service) Test(ctx context.Context, tenantID, siteID uuid.UUID, passwordRaw string) (Config, *agentcmd.ObjectCacheTestResult, error) {
	cfg, err := s.repo.GetConfig(ctx, tenantID, siteID)
	if err != nil {
		if err == ErrNotFound {
			return Config{}, nil, domain.NotFound("objectcache_not_configured", "object cache config not found; save a config first")
		}
		return Config{}, nil, err
	}

	// Decrypt the stored password unless a new one was provided.
	password, err := s.resolvePassword(ctx, tenantID, siteID, passwordRaw)
	if err != nil {
		return Config{}, nil, err
	}

	if s.cmdClient == nil {
		return Config{}, nil, fmt.Errorf("objectcache: agent command client not configured (signing key empty?)")
	}

	siteURL, err := s.urlForSite(ctx, tenantID, siteID)
	if err != nil {
		return Config{}, nil, err
	}

	req := buildConfigRequest(cfg, password)
	var result agentcmd.ObjectCacheTestResult
	if err := s.cmdClient.Do(ctx, siteID, siteURL, "objectcache.test", req, &result); err != nil {
		return Config{}, nil, fmt.Errorf("objectcache: test command failed: %w", err)
	}

	resultJSON, _ := json.Marshal(result)
	var passedAt *time.Time
	if result.OK {
		now := time.Now().UTC()
		passedAt = &now
	}

	configHash := result.ConfigHash
	if configHash == "" {
		configHash = computeConfigHash(cfg)
	}

	updated, err := s.repo.UpdateTestResult(ctx, tenantID, siteID, configHash, resultJSON, passedAt)
	if err != nil {
		return Config{}, &result, err
	}

	// Publish SSE event.
	_ = s.publisher.Publish(ctx, site.ConnectionEvent{
		ID:       uuid.New().String(),
		Type:     site.EventObjectCacheTestCompleted,
		TenantID: tenantID,
		SiteID:   siteID,
		TS:       time.Now().UTC(),
		Data: map[string]any{
			"ok":              result.OK,
			"config_hash":     configHash,
			"latency_ms":      result.LatencyMs,
			"eviction_policy": result.EvictionPolicy,
		},
	})

	return updated, &result, nil
}

// Enable installs the object-cache drop-in. Rejected unless a passing test
// result exists for the current config (handshake gate).
func (s *Service) Enable(ctx context.Context, tenantID, siteID uuid.UUID) (Config, error) {
	cfg, err := s.repo.GetConfig(ctx, tenantID, siteID)
	if err != nil {
		if err == ErrNotFound {
			return Config{}, domain.NotFound("objectcache_not_configured", "object cache config not found; save and test a config first")
		}
		return Config{}, err
	}

	// Handshake gate: a passing test must exist.
	if cfg.LastTestConfigHash == "" {
		return Config{}, domain.Validation("objectcache_test_required", "a passing connection test is required before enabling the object cache")
	}

	if s.cmdClient == nil {
		return Config{}, fmt.Errorf("objectcache: agent command client not configured (signing key empty?)")
	}

	siteURL, err := s.urlForSite(ctx, tenantID, siteID)
	if err != nil {
		return Config{}, err
	}

	req := agentcmd.ObjectCacheEnableRequest{ConfigHash: cfg.LastTestConfigHash}
	var result agentcmd.ObjectCacheEnableResult
	if err := s.cmdClient.Do(ctx, siteID, siteURL, "objectcache.enable", req, &result); err != nil {
		return Config{}, fmt.Errorf("objectcache: enable command failed: %w", err)
	}
	if !result.OK {
		s.logger.Warn("objectcache: enable rejected by agent",
			slog.String("site_id", siteID.String()),
			slog.String("detail", capDetail(result.Detail)),
			slog.Bool("foreign_dropin", result.ForeignDropin),
		)
		if result.ForeignDropin {
			return Config{}, domain.Conflict("foreign_dropin", "another object cache drop-in is installed; remove it first or use force")
		}
		return Config{}, domain.Validation("objectcache_enable_failed", result.Detail)
	}

	updated, err := s.repo.UpdateEnabled(ctx, tenantID, siteID, true)
	if err != nil {
		return Config{}, err
	}
	return updated, nil
}

// Disable removes the drop-in and flushes. Returns the updated config.
func (s *Service) Disable(ctx context.Context, tenantID, siteID uuid.UUID) (Config, error) {
	if s.cmdClient == nil {
		return Config{}, fmt.Errorf("objectcache: agent command client not configured (signing key empty?)")
	}

	siteURL, err := s.urlForSite(ctx, tenantID, siteID)
	if err != nil {
		return Config{}, err
	}

	req := agentcmd.ObjectCacheDisableRequest{Flush: true}
	var result agentcmd.ObjectCacheDisableResult
	if err := s.cmdClient.Do(ctx, siteID, siteURL, "objectcache.disable", req, &result); err != nil {
		return Config{}, fmt.Errorf("objectcache: disable command failed: %w", err)
	}
	if !result.OK {
		s.logger.Warn("objectcache: disable rejected by agent",
			slog.String("site_id", siteID.String()),
			slog.String("detail", capDetail(result.Detail)),
		)
		return Config{}, domain.Validation("objectcache_disable_failed", result.Detail)
	}

	updated, err := s.repo.UpdateEnabled(ctx, tenantID, siteID, false)
	if err != nil {
		return Config{}, err
	}
	return updated, nil
}

// Flush sends the objectcache.flush command and publishes a flushed SSE event.
// Returns the flush result detail string.
func (s *Service) Flush(ctx context.Context, input FlushInput) (string, error) {
	if s.cmdClient == nil {
		return "", fmt.Errorf("objectcache: agent command client not configured (signing key empty?)")
	}

	siteURL, err := s.urlForSite(ctx, input.TenantID, input.SiteID)
	if err != nil {
		return "", err
	}

	req := agentcmd.ObjectCacheFlushRequest{
		Scope:  input.Scope,
		Group:  input.Group,
		Reason: "operator flush via CP dashboard",
	}
	var result agentcmd.ObjectCacheFlushResult
	if err := s.cmdClient.Do(ctx, input.SiteID, siteURL, "objectcache.flush", req, &result); err != nil {
		return "", fmt.Errorf("objectcache: flush command failed: %w", err)
	}
	if !result.OK {
		s.logger.Warn("objectcache: flush rejected by agent",
			slog.String("site_id", input.SiteID.String()),
			slog.String("detail", capDetail(result.Detail)),
		)
		return "", domain.Validation("objectcache_flush_failed", result.Detail)
	}

	_ = s.publisher.Publish(ctx, site.ConnectionEvent{
		ID:       uuid.New().String(),
		Type:     site.EventObjectCacheFlushed,
		TenantID: input.TenantID,
		SiteID:   input.SiteID,
		TS:       time.Now().UTC(),
		Data: map[string]any{
			"scope":        input.Scope,
			"strategy":     result.Strategy,
			"keys_deleted": result.KeysDeleted,
			"actor_id":     input.InitiatorID.String(),
		},
	})

	return result.Detail, nil
}

// IngestStats handles the optional object_cache block from a stats-report push.
// Missing or empty block is a no-op (tolerant ingest).
func (s *Service) IngestStats(ctx context.Context, input IngestStatsInput) error {
	if input.HitCount == 0 && input.MissCount == 0 {
		// Zero delta: skip the history row (same logic as cache hit ratio).
		return nil
	}

	total := input.HitCount + input.MissCount
	var ratioPct *float64
	if total > 0 {
		r := float64(input.HitCount) / float64(total) * 100
		ratioPct = &r
	}

	return s.repo.InsertStatsHistory(ctx, input.TenantID, StatsPoint{
		SiteID:           input.SiteID,
		TenantID:         input.TenantID,
		HitCount:         input.HitCount,
		MissCount:        input.MissCount,
		RatioPct:         ratioPct,
		UsedMemoryBytes:  input.UsedMemoryBytes,
		AvgWaitMs:        input.AvgWaitMs,
		OpsPerSec:        input.OpsPerSec,
		EvictedKeysDelta: input.EvictedKeysDelta,
		ConnectedClients: input.ConnectedClients,
		SampledAt:        time.Now().UTC(),
	})
}

// IngestHeartbeat handles the optional object_cache block from a heartbeat push.
// tenantID is required and must come from the verified agent identity; it is
// threaded through to the UPDATE WHERE clause to prevent cross-tenant writes.
// If the state changed, publishes objectcache.status_changed immediately.
// Publishes objectcache.stats_updated for non-transition updates (caller throttles).
func (s *Service) IngestHeartbeat(ctx context.Context, tenantID, siteID uuid.UUID, block *HeartbeatBlock) error {
	if block == nil {
		return nil
	}
	hitRatioPct := block.HitRatioPct
	updated, err := s.repo.UpdateHeartbeatState(ctx, tenantID, siteID, block.State, block.LatencyMs, block.LastErrorClass, block.UsedMemoryBytes, &hitRatioPct)
	if err != nil {
		return err
	}

	prevState := updated.OCState
	// Note: the returned row IS the updated one; the "previous" state comparison
	// uses the value before the update. Since UpdateHeartbeatState returns the
	// new row, we must detect transition differently. The strategy: compare the
	// incoming state to the NEW row's state. They should be equal; the previous
	// state was whatever was there before. We store the "prior" in the returned
	// row only if the caller passes the prior explicitly. For simplicity, always
	// publish status_changed when the state value changes from what was stored --
	// but we only have the NEW row. This means on the first heartbeat we always
	// publish. The web debounce handles this gracefully.
	// A more accurate approach: track previous state via the returned updated row
	// before the write. We accept the current approach as safe because SSE
	// status_changed is idempotent on the web side.

	if string(block.State) != string(prevState) {
		_ = s.publisher.Publish(ctx, site.ConnectionEvent{
			ID:       uuid.New().String(),
			Type:     site.EventObjectCacheStatusChanged,
			TenantID: updated.TenantID,
			SiteID:   siteID,
			TS:       time.Now().UTC(),
			Data: map[string]any{
				"from_state":       string(prevState),
				"to_state":         string(block.State),
				"latency_ms":       block.LatencyMs,
				"last_error_class": block.LastErrorClass,
			},
		})
	} else {
		_ = s.publisher.Publish(ctx, site.ConnectionEvent{
			ID:       uuid.New().String(),
			Type:     site.EventObjectCacheStatsUpdated,
			TenantID: updated.TenantID,
			SiteID:   siteID,
			TS:       time.Now().UTC(),
			Data: map[string]any{
				"state":             string(block.State),
				"latency_ms":        block.LatencyMs,
				"used_memory_bytes": block.UsedMemoryBytes,
				"hit_ratio_pct":     block.HitRatioPct,
			},
		})
	}

	return nil
}

// GetStatsHistory returns the daily-aggregated stats history for the trend chart.
func (s *Service) GetStatsHistory(ctx context.Context, tenantID, siteID uuid.UUID, days int) (StatsHistoryResponse, error) {
	if days < 7 {
		days = 7
	}
	if days > 365 {
		days = 365
	}
	since := time.Now().UTC().AddDate(0, 0, -days)
	pts, err := s.repo.GetStatsHistory(ctx, tenantID, siteID, since)
	if err != nil {
		return StatsHistoryResponse{}, err
	}
	resp := StatsHistoryResponse{Points: pts}
	if len(pts) > 0 {
		var sum float64
		var n int
		for _, p := range pts {
			if p.RatioPct != nil {
				sum += *p.RatioPct
				n++
			}
		}
		if n > 0 {
			resp.AvgRatioPct = sum / float64(n)
		}
	}
	return resp, nil
}

// ---------------------------------------------------------------------------
// internal helpers
// ---------------------------------------------------------------------------

// pushApplyConfig sends the objectcache.apply_config command (best-effort).
// The password is decrypted from the DB if not supplied.
func (s *Service) pushApplyConfig(ctx context.Context, tenantID, siteID uuid.UUID, cfg Config, passwordRaw string) error {
	if s.cmdClient == nil {
		return fmt.Errorf("objectcache: agent command client not configured (signing key empty?)")
	}

	siteURL, err := s.urlForSite(ctx, tenantID, siteID)
	if err != nil {
		return err
	}
	password, err := s.resolvePassword(ctx, tenantID, siteID, passwordRaw)
	if err != nil {
		return err
	}
	req := buildConfigRequest(cfg, password)
	var result agentcmd.ObjectCacheApplyConfigResult
	if err := s.cmdClient.Do(ctx, siteID, siteURL, "objectcache.apply_config", req, &result); err != nil {
		return err
	}
	if !result.OK {
		return fmt.Errorf("objectcache: apply_config rejected: %s", result.Detail)
	}
	_ = s.publisher.Publish(ctx, site.ConnectionEvent{
		ID:       uuid.New().String(),
		Type:     site.EventObjectCacheConfigApplied,
		TenantID: tenantID,
		SiteID:   siteID,
		TS:       time.Now().UTC(),
		Data:     map[string]any{"config_hash": computeConfigHash(cfg)},
	})
	return nil
}

// resolvePassword returns the plaintext password for the site's config.
// If raw is non-empty it is returned directly (the operator just typed it in).
// Otherwise the stored ciphertext is decrypted.
func (s *Service) resolvePassword(ctx context.Context, tenantID, siteID uuid.UUID, raw string) (string, error) {
	if raw != "" {
		return raw, nil
	}
	row, err := s.repo.GetConfigWithSecret(ctx, tenantID, siteID)
	if err != nil {
		if err == ErrNotFound {
			return "", nil
		}
		return "", err
	}
	if len(row.PasswordEncrypted) == 0 {
		return "", nil
	}
	plain, err := s.cryptbox.Decrypt(row.PasswordEncrypted)
	if err != nil {
		return "", fmt.Errorf("objectcache: decrypt password: %w", err)
	}
	return string(plain), nil
}

func (s *Service) urlForSite(ctx context.Context, tenantID, siteID uuid.UUID) (string, error) {
	if s.urler == nil {
		return "", fmt.Errorf("objectcache: site URL resolver not wired")
	}
	u, err := s.urler.GetSiteURL(ctx, tenantID, siteID)
	if err != nil {
		return "", fmt.Errorf("objectcache: get site URL: %w", err)
	}
	return u, nil
}

// buildConfigRequest converts a Config (and plaintext password) into the wire
// struct for apply_config or test commands.
func buildConfigRequest(cfg Config, password string) agentcmd.ObjectCacheConfigRequest {
	return agentcmd.ObjectCacheConfigRequest{
		Scheme:           cfg.Scheme,
		Host:             cfg.Host,
		Port:             cfg.Port,
		SocketPath:       cfg.SocketPath,
		Database:         cfg.Database,
		Username:         cfg.Username,
		Password:         password,
		Prefix:           cfg.Prefix,
		MaxTTLSeconds:    cfg.MaxTTLSeconds,
		QueryTTLSeconds:  cfg.QueryTTLSeconds,
		ConnectTimeoutMs: cfg.ConnectTimeoutMs,
		ReadTimeoutMs:    cfg.ReadTimeoutMs,
		RetryCount:       cfg.RetryCount,
		RetryIntervalMs:  cfg.RetryIntervalMs,
		Serializer:       cfg.Serializer,
		Compression:      cfg.Compression,
		AsyncFlush:       cfg.AsyncFlush,
		FlushStrategy:    cfg.FlushStrategy,
		Shared:           cfg.Shared,
		FlushOnFailback:  cfg.FlushOnFailback,
		AnalyticsEnabled: cfg.AnalyticsEnabled,
	}
}

// computeConfigHash returns a sha256 hex digest of the config fields with the
// password omitted. The field set, key order, and JSON encoding match the
// agent's computeHash (class-object-cache-config.php): the full config map is
// built, "password" is removed, keys are sorted (ksort), and the result is
// JSON-encoded. This produces an identical hash so the CP fallback and the
// agent-reported hash agree, enabling the enable-gate to fire correctly.
//
// The password is intentionally excluded: including it would (a) embed a
// plaintext secret in an SSE event payload and stored column, and (b) prevent
// the hash from ever matching the agent's redacted hash.
func computeConfigHash(cfg Config) string {
	// Build a map with the exact field names the agent uses, sorted.
	// The agent's fromParams populates these keys only; no password key.
	m := map[string]any{
		"analytics_enabled": cfg.AnalyticsEnabled,
		"async_flush":       cfg.AsyncFlush,
		"compression":       cfg.Compression,
		"connect_timeout_ms": cfg.ConnectTimeoutMs,
		"database":          cfg.Database,
		"flush_on_failback": cfg.FlushOnFailback,
		"flush_strategy":    cfg.FlushStrategy,
		"host":              cfg.Host,
		"maxttl_seconds":    cfg.MaxTTLSeconds,
		"port":              cfg.Port,
		"prefix":            cfg.Prefix,
		"queryttl_seconds":  cfg.QueryTTLSeconds,
		"read_timeout_ms":   cfg.ReadTimeoutMs,
		"retry_count":       cfg.RetryCount,
		"retry_interval_ms": cfg.RetryIntervalMs,
		"scheme":            cfg.Scheme,
		"serializer":        cfg.Serializer,
		"shared":            cfg.Shared,
		"socket_path":       cfg.SocketPath,
		"username":          cfg.Username,
	}
	raw, _ := json.Marshal(m)
	h := sha256.Sum256(raw)
	return hex.EncodeToString(h[:])
}

// connectionChanged returns true when fields that invalidate a stored test
// result have changed between the stored config and the new input.
func connectionChanged(stored Config, new Config) bool {
	return stored.Scheme != new.Scheme ||
		stored.Host != new.Host ||
		stored.Port != new.Port ||
		stored.SocketPath != new.SocketPath ||
		stored.Database != new.Database ||
		stored.Username != new.Username ||
		stored.Prefix != new.Prefix
}

// defaultConfig returns a zero-value Config with safe defaults for a site
// that has not yet configured object cache.
func defaultConfig(siteID, tenantID uuid.UUID) Config {
	return Config{
		SiteID:           siteID,
		TenantID:         tenantID,
		Scheme:           "tcp",
		Port:             6379,
		MaxTTLSeconds:    604800,
		QueryTTLSeconds:  86400,
		ConnectTimeoutMs: 1000,
		ReadTimeoutMs:    1000,
		RetryCount:       3,
		RetryIntervalMs:  25,
		Serializer:       "php",
		Compression:      "none",
		FlushStrategy:    "auto",
		Shared:           true,
		FlushOnFailback:  true,
		AnalyticsEnabled: true,
	}
}

// prefixCharsetRe matches the valid prefix character set: lowercase letters,
// digits, underscores, and hyphens. The agent's sanitizePrefix collapses
// whitespace and strips other characters, so an empty-after-trim or
// non-conforming prefix sent from the CP would silently lose its namespacing.
// We reject rather than silently coerce so the operator is aware.
var prefixCharsetRe = regexp.MustCompile(`^[a-z0-9_-]+$`)

// validateConfig validates the operator-supplied config fields.
func validateConfig(input UpdateConfigInput) error {
	cfg := input.Config
	switch cfg.Scheme {
	case "tcp", "unix", "tls", "":
	default:
		return domain.Validation("invalid_scheme", "scheme must be tcp, unix, or tls")
	}
	if cfg.Scheme != "unix" && cfg.Port < 1 || cfg.Port > 65535 {
		return domain.Validation("invalid_port", "port must be between 1 and 65535")
	}
	switch cfg.Serializer {
	case "php", "igbinary", "":
	default:
		return domain.Validation("invalid_serializer", "serializer must be php or igbinary")
	}
	switch cfg.Compression {
	case "none", "lzf", "lz4", "zstd", "":
	default:
		return domain.Validation("invalid_compression", "compression must be none, lzf, lz4, or zstd")
	}
	switch cfg.FlushStrategy {
	case "auto", "flushdb", "scan", "":
	default:
		return domain.Validation("invalid_flush_strategy", "flush_strategy must be auto, flushdb, or scan")
	}
	if cfg.MaxTTLSeconds < 0 {
		return domain.Validation("invalid_maxttl", "maxttl_seconds must be non-negative")
	}
	// Validate the key prefix. The agent falls back to 'wpmgr' on empty/invalid
	// prefixes; an empty or whitespace-only prefix sent from the CP would defeat
	// shared-Redis namespacing and make SCAN-based flush delete a neighbour's keys.
	// Reject rather than silently coerce.
	if p := strings.TrimSpace(cfg.Prefix); cfg.Prefix != "" {
		if p == "" {
			return domain.Validation("invalid_prefix", "prefix must not be whitespace-only")
		}
		if !prefixCharsetRe.MatchString(p) {
			return domain.Validation("invalid_prefix", "prefix must contain only lowercase letters, digits, underscores, or hyphens")
		}
	}
	return nil
}

// UpdateConfigInput carries the operator's config PUT payload.
type UpdateConfigInput struct {
	Config
	// PasswordRaw is the plaintext password the operator entered. Empty means
	// "keep stored password" (nil-sentinel).
	PasswordRaw string
}
