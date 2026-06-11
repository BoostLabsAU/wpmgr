package objectcache

import (
	"context"
	"errors"
	"math/big"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgtype"

	"github.com/mosamlife/wpmgr/apps/api/internal/db"
	"github.com/mosamlife/wpmgr/apps/api/internal/db/sqlc"
)

// ErrNotFound is returned when no site_object_cache_config row exists for the
// requested site.
var ErrNotFound = errors.New("objectcache: not found")

// Repo is the persistence layer for the Object Cache domain. Operator
// reads/writes run under InTenantTx; agent heartbeat/GC writes run under
// InAgentTx. The repo NEVER sets GUCs; the pool helpers do. updated_at is set
// by now() in the queries (no trigger, project convention).
type Repo struct {
	pool *db.Pool
}

// NewRepo wires a Repo with the shared pgx pool.
func NewRepo(pool *db.Pool) *Repo { return &Repo{pool: pool} }

// GetConfig returns the per-site object cache config without the encrypted
// password. HasPassword reflects whether ciphertext exists in the DB.
// Returns ErrNotFound when no row exists yet.
func (r *Repo) GetConfig(ctx context.Context, tenantID, siteID uuid.UUID) (Config, error) {
	var out sqlc.GetObjectCacheConfigRow
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		row, qerr := sqlc.New(tx).GetObjectCacheConfig(ctx, siteID)
		if qerr != nil {
			if errors.Is(qerr, pgx.ErrNoRows) {
				return ErrNotFound
			}
			return qerr
		}
		out = row
		return nil
	})
	if err != nil {
		return Config{}, err
	}
	return configFromRow(out), nil
}

// GetConfigWithSecret fetches the full row including password_encrypted.
// Used ONLY by the service when rendering a signed agent command.
func (r *Repo) GetConfigWithSecret(ctx context.Context, tenantID, siteID uuid.UUID) (sqlc.SiteObjectCacheConfig, error) {
	var out sqlc.SiteObjectCacheConfig
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		row, qerr := sqlc.New(tx).GetObjectCacheConfigWithSecret(ctx, siteID)
		if qerr != nil {
			if errors.Is(qerr, pgx.ErrNoRows) {
				return ErrNotFound
			}
			return qerr
		}
		out = row
		return nil
	})
	return out, err
}

// UpsertConfig inserts or updates the per-site object cache config.
// passwordEncrypted must be nil to preserve a stored secret (nil-sentinel).
// clearTestHash controls whether last_test_config_hash is set to NULL
// (call with true whenever connection-critical fields changed).
func (r *Repo) UpsertConfig(ctx context.Context, tenantID uuid.UUID, cfg Config, passwordEncrypted []byte, clearTestHash bool) (Config, error) {
	var out sqlc.GetObjectCacheConfigRow

	var testHash *string
	if !clearTestHash && cfg.LastTestConfigHash != "" {
		testHash = &cfg.LastTestConfigHash
	}

	var testResultJSON []byte
	if len(cfg.LastTestResultJSON) > 0 {
		testResultJSON = cfg.LastTestResultJSON
	} else {
		testResultJSON = []byte(`{}`)
	}

	var lastTestedAt pgtype.Timestamptz
	if cfg.LastTestedAt != nil {
		lastTestedAt = pgtype.Timestamptz{Time: *cfg.LastTestedAt, Valid: true}
	}

	var hitRatioPct pgtype.Numeric
	if cfg.OCHitRatioPct != nil {
		hitRatioPct = numericFromFloat64(*cfg.OCHitRatioPct)
	}

	var ocHitRatioOut pgtype.Numeric // for the out row -- passed through via RETURNING

	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		row, qerr := sqlc.New(tx).UpsertObjectCacheConfig(ctx, sqlc.UpsertObjectCacheConfigParams{
			SiteID:             cfg.SiteID,
			TenantID:           tenantID,
			Enabled:            cfg.Enabled,
			Scheme:             cfg.Scheme,
			Host:               cfg.Host,
			Port:               int32(cfg.Port),
			SocketPath:         cfg.SocketPath,
			Database:           int32(cfg.Database),
			Username:           cfg.Username,
			PasswordEncrypted:  passwordEncrypted,
			Prefix:             cfg.Prefix,
			MaxttlSeconds:      int32(cfg.MaxTTLSeconds),
			QueryttlSeconds:    int32(cfg.QueryTTLSeconds),
			ConnectTimeoutMs:   int32(cfg.ConnectTimeoutMs),
			ReadTimeoutMs:      int32(cfg.ReadTimeoutMs),
			RetryCount:         int32(cfg.RetryCount),
			RetryIntervalMs:    int32(cfg.RetryIntervalMs),
			Serializer:         cfg.Serializer,
			Compression:        cfg.Compression,
			AsyncFlush:         cfg.AsyncFlush,
			FlushStrategy:      cfg.FlushStrategy,
			Shared:             cfg.Shared,
			FlushOnFailback:    cfg.FlushOnFailback,
			AnalyticsEnabled:   cfg.AnalyticsEnabled,
			LastTestConfigHash: testHash,
			LastTestResultJson: testResultJSON,
			LastTestedAt:       lastTestedAt,
			OcState:            string(cfg.OCState),
			OcLatencyMs:        int32(cfg.OCLatencyMs),
			OcLastErrorClass:   cfg.OCLastErrorClass,
			OcUsedMemoryBytes:  cfg.OCUsedMemoryBytes,
			OcHitRatioPct:      hitRatioPct,
			OcConfigDrift:      cfg.OCConfigDrift,
		})
		if qerr != nil {
			return qerr
		}
		out = upsertRowToConfigRow(row)
		_ = ocHitRatioOut
		return nil
	})
	if err != nil {
		return Config{}, err
	}
	return configFromRow(out), nil
}

// UpdateTestResult records the outcome of an objectcache.test command.
// configHash is the hash of the config snapshot that was tested.
// passedAt should be non-nil on a passing test and nil on failure (so we
// do not advance last_tested_at on failures).
func (r *Repo) UpdateTestResult(ctx context.Context, tenantID, siteID uuid.UUID, configHash string, resultJSON []byte, passedAt *time.Time) (Config, error) {
	var out sqlc.GetObjectCacheConfigRow

	var lastTestedAt pgtype.Timestamptz
	if passedAt != nil {
		lastTestedAt = pgtype.Timestamptz{Time: *passedAt, Valid: true}
	}

	if len(resultJSON) == 0 {
		resultJSON = []byte(`{}`)
	}

	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		row, qerr := sqlc.New(tx).UpdateObjectCacheTestResult(ctx, sqlc.UpdateObjectCacheTestResultParams{
			LastTestConfigHash: &configHash,
			LastTestResultJson: resultJSON,
			LastTestedAt:       lastTestedAt,
			SiteID:             siteID,
			TenantID:           tenantID,
		})
		if qerr != nil {
			if errors.Is(qerr, pgx.ErrNoRows) {
				return ErrNotFound
			}
			return qerr
		}
		out = testResultRowToConfigRow(row)
		return nil
	})
	if err != nil {
		return Config{}, err
	}
	return configFromRow(out), nil
}

// UpdateEnabled flips the enabled flag. The service layer must verify the
// handshake gate (non-empty LastTestConfigHash) before calling this.
func (r *Repo) UpdateEnabled(ctx context.Context, tenantID, siteID uuid.UUID, enabled bool) (Config, error) {
	var out sqlc.GetObjectCacheConfigRow
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		row, qerr := sqlc.New(tx).UpdateObjectCacheEnabled(ctx, sqlc.UpdateObjectCacheEnabledParams{
			Enabled:  enabled,
			SiteID:   siteID,
			TenantID: tenantID,
		})
		if qerr != nil {
			if errors.Is(qerr, pgx.ErrNoRows) {
				return ErrNotFound
			}
			return qerr
		}
		out = enabledRowToConfigRow(row)
		return nil
	})
	if err != nil {
		return Config{}, err
	}
	return configFromRow(out), nil
}

// UpdateHeartbeatState updates the live status columns from a heartbeat push.
// Returns the updated row so the service can detect state transitions.
// tenantID is required for defence-in-depth: the explicit WHERE predicate
// prevents a cross-tenant write even though InAgentTx sets app.agent='on'.
// Runs under InAgentTx (cross-tenant heartbeat path).
func (r *Repo) UpdateHeartbeatState(ctx context.Context, tenantID, siteID uuid.UUID, state OCState, latencyMs int, lastErrorClass string, usedMemoryBytes int64, hitRatioPct *float64) (Config, error) {
	var out sqlc.GetObjectCacheConfigRow

	var hitRatioNum pgtype.Numeric
	if hitRatioPct != nil {
		hitRatioNum = numericFromFloat64(*hitRatioPct)
	}

	err := r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		row, qerr := sqlc.New(tx).UpdateObjectCacheHeartbeatState(ctx, sqlc.UpdateObjectCacheHeartbeatStateParams{
			OcState:           string(state),
			OcLatencyMs:       int32(latencyMs),
			OcLastErrorClass:  lastErrorClass,
			OcUsedMemoryBytes: usedMemoryBytes,
			OcHitRatioPct:     hitRatioNum,
			SiteID:            siteID,
			TenantID:          tenantID,
		})
		if qerr != nil {
			if errors.Is(qerr, pgx.ErrNoRows) {
				// No config row yet: not a fatal error for the heartbeat path.
				return nil
			}
			return qerr
		}
		out = heartbeatRowToConfigRow(row)
		return nil
	})
	if err != nil {
		return Config{}, err
	}
	return configFromRow(out), nil
}

// UpdateDrift persists the oc_config_drift indicator for a site. Called from
// IngestHeartbeat when the agent-reported config_hash is compared against the
// CP-computed hash of the stored config. Runs under InAgentTx.
func (r *Repo) UpdateDrift(ctx context.Context, tenantID, siteID uuid.UUID, drift bool) error {
	return r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		return sqlc.New(tx).UpdateObjectCacheDrift(ctx, sqlc.UpdateObjectCacheDriftParams{
			OcConfigDrift: drift,
			SiteID:        siteID,
			TenantID:      tenantID,
		})
	})
}

// InsertStatsHistory appends one stats data point.
// ON CONFLICT DO NOTHING makes it idempotent within the same second.
// Runs under InTenantTx (the perf service forwards tenant context).
func (r *Repo) InsertStatsHistory(ctx context.Context, tenantID uuid.UUID, p StatsPoint) error {
	var ratioPct pgtype.Numeric
	if p.RatioPct != nil {
		ratioPct = numericFromFloat64(*p.RatioPct)
	}

	avgWaitMs := numericFromFloat64(p.AvgWaitMs)

	return r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		_, err := sqlc.New(tx).InsertObjectCacheStatsHistory(ctx, sqlc.InsertObjectCacheStatsHistoryParams{
			SiteID:           p.SiteID,
			TenantID:         p.TenantID,
			HitCount:         p.HitCount,
			MissCount:        p.MissCount,
			RatioPct:         ratioPct,
			UsedMemoryBytes:  p.UsedMemoryBytes,
			AvgWaitMs:        avgWaitMs,
			OpsPerSec:        int32(p.OpsPerSec),
			EvictedKeysDelta: p.EvictedKeysDelta,
			ConnectedClients: int32(p.ConnectedClients),
			SampledAt:        p.SampledAt,
		})
		return err
	})
}

// GetStatsHistory returns daily-aggregated history points for the trend chart.
func (r *Repo) GetStatsHistory(ctx context.Context, tenantID, siteID uuid.UUID, since time.Time) ([]StatsHistoryPoint, error) {
	var rows []sqlc.GetObjectCacheStatsHistoryRow
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		var qerr error
		rows, qerr = sqlc.New(tx).GetObjectCacheStatsHistory(ctx, sqlc.GetObjectCacheStatsHistoryParams{
			SiteID:   siteID,
			TenantID: tenantID,
			Since:    since,
		})
		return qerr
	})
	if err != nil {
		return nil, err
	}
	// Reverse to oldest-first for chart rendering.
	out := make([]StatsHistoryPoint, 0, len(rows))
	for i := len(rows) - 1; i >= 0; i-- {
		r := rows[i]
		pt := StatsHistoryPoint{
			SampledAt:        r.SampledAt,
			HitCount:         r.HitCount,
			MissCount:        r.MissCount,
			UsedMemoryBytes:  r.UsedMemoryBytes,
			OpsPerSec:        int(r.OpsPerSec),
			EvictedKeysDelta: r.EvictedKeysDelta,
		}
		if r.RatioPct.Valid {
			v, _ := numericToFloat64(r.RatioPct)
			pt.RatioPct = &v
		}
		if r.AvgWaitMs.Valid {
			v, _ := numericToFloat64(r.AvgWaitMs)
			pt.AvgWaitMs = v
		}
		out = append(out, pt)
	}
	return out, nil
}

// PruneHistory deletes rows older than cutoff across all tenants (InAgentTx).
func (r *Repo) PruneHistory(ctx context.Context, cutoff time.Time) (int64, error) {
	var deleted int64
	err := r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		n, qerr := sqlc.New(tx).PruneObjectCacheStatsHistory(ctx, cutoff)
		if qerr != nil {
			return qerr
		}
		deleted = n
		return nil
	})
	return deleted, err
}

// ---------------------------------------------------------------------------
// helpers
// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// row-type converters: each UPDATE/INSERT query returns a distinct sqlc type
// with the same columns as GetObjectCacheConfigRow. These shims normalize them
// so the shared configFromRow mapper can handle all paths.
// ---------------------------------------------------------------------------

func upsertRowToConfigRow(r sqlc.UpsertObjectCacheConfigRow) sqlc.GetObjectCacheConfigRow {
	return sqlc.GetObjectCacheConfigRow{
		SiteID: r.SiteID, TenantID: r.TenantID, Enabled: r.Enabled,
		Scheme: r.Scheme, Host: r.Host, Port: r.Port, SocketPath: r.SocketPath,
		Database: r.Database, Username: r.Username, Prefix: r.Prefix,
		MaxttlSeconds: r.MaxttlSeconds, QueryttlSeconds: r.QueryttlSeconds,
		ConnectTimeoutMs: r.ConnectTimeoutMs, ReadTimeoutMs: r.ReadTimeoutMs,
		RetryCount: r.RetryCount, RetryIntervalMs: r.RetryIntervalMs,
		Serializer: r.Serializer, Compression: r.Compression, AsyncFlush: r.AsyncFlush,
		FlushStrategy: r.FlushStrategy, Shared: r.Shared, FlushOnFailback: r.FlushOnFailback,
		AnalyticsEnabled: r.AnalyticsEnabled,
		LastTestConfigHash: r.LastTestConfigHash, LastTestResultJson: r.LastTestResultJson,
		LastTestedAt: r.LastTestedAt,
		OcState: r.OcState, OcLatencyMs: r.OcLatencyMs, OcLastErrorClass: r.OcLastErrorClass,
		OcUsedMemoryBytes: r.OcUsedMemoryBytes, OcHitRatioPct: r.OcHitRatioPct,
		OcConfigDrift: r.OcConfigDrift,
		CreatedAt: r.CreatedAt, UpdatedAt: r.UpdatedAt,
		HasPassword: r.HasPassword,
	}
}

func testResultRowToConfigRow(r sqlc.UpdateObjectCacheTestResultRow) sqlc.GetObjectCacheConfigRow {
	return sqlc.GetObjectCacheConfigRow{
		SiteID: r.SiteID, TenantID: r.TenantID, Enabled: r.Enabled,
		Scheme: r.Scheme, Host: r.Host, Port: r.Port, SocketPath: r.SocketPath,
		Database: r.Database, Username: r.Username, Prefix: r.Prefix,
		MaxttlSeconds: r.MaxttlSeconds, QueryttlSeconds: r.QueryttlSeconds,
		ConnectTimeoutMs: r.ConnectTimeoutMs, ReadTimeoutMs: r.ReadTimeoutMs,
		RetryCount: r.RetryCount, RetryIntervalMs: r.RetryIntervalMs,
		Serializer: r.Serializer, Compression: r.Compression, AsyncFlush: r.AsyncFlush,
		FlushStrategy: r.FlushStrategy, Shared: r.Shared, FlushOnFailback: r.FlushOnFailback,
		AnalyticsEnabled: r.AnalyticsEnabled,
		LastTestConfigHash: r.LastTestConfigHash, LastTestResultJson: r.LastTestResultJson,
		LastTestedAt: r.LastTestedAt,
		OcState: r.OcState, OcLatencyMs: r.OcLatencyMs, OcLastErrorClass: r.OcLastErrorClass,
		OcUsedMemoryBytes: r.OcUsedMemoryBytes, OcHitRatioPct: r.OcHitRatioPct,
		OcConfigDrift: r.OcConfigDrift,
		CreatedAt: r.CreatedAt, UpdatedAt: r.UpdatedAt,
		HasPassword: r.HasPassword,
	}
}

func enabledRowToConfigRow(r sqlc.UpdateObjectCacheEnabledRow) sqlc.GetObjectCacheConfigRow {
	return sqlc.GetObjectCacheConfigRow{
		SiteID: r.SiteID, TenantID: r.TenantID, Enabled: r.Enabled,
		Scheme: r.Scheme, Host: r.Host, Port: r.Port, SocketPath: r.SocketPath,
		Database: r.Database, Username: r.Username, Prefix: r.Prefix,
		MaxttlSeconds: r.MaxttlSeconds, QueryttlSeconds: r.QueryttlSeconds,
		ConnectTimeoutMs: r.ConnectTimeoutMs, ReadTimeoutMs: r.ReadTimeoutMs,
		RetryCount: r.RetryCount, RetryIntervalMs: r.RetryIntervalMs,
		Serializer: r.Serializer, Compression: r.Compression, AsyncFlush: r.AsyncFlush,
		FlushStrategy: r.FlushStrategy, Shared: r.Shared, FlushOnFailback: r.FlushOnFailback,
		AnalyticsEnabled: r.AnalyticsEnabled,
		LastTestConfigHash: r.LastTestConfigHash, LastTestResultJson: r.LastTestResultJson,
		LastTestedAt: r.LastTestedAt,
		OcState: r.OcState, OcLatencyMs: r.OcLatencyMs, OcLastErrorClass: r.OcLastErrorClass,
		OcUsedMemoryBytes: r.OcUsedMemoryBytes, OcHitRatioPct: r.OcHitRatioPct,
		OcConfigDrift: r.OcConfigDrift,
		CreatedAt: r.CreatedAt, UpdatedAt: r.UpdatedAt,
		HasPassword: r.HasPassword,
	}
}

func heartbeatRowToConfigRow(r sqlc.UpdateObjectCacheHeartbeatStateRow) sqlc.GetObjectCacheConfigRow {
	return sqlc.GetObjectCacheConfigRow{
		SiteID: r.SiteID, TenantID: r.TenantID, Enabled: r.Enabled,
		Scheme: r.Scheme, Host: r.Host, Port: r.Port, SocketPath: r.SocketPath,
		Database: r.Database, Username: r.Username, Prefix: r.Prefix,
		MaxttlSeconds: r.MaxttlSeconds, QueryttlSeconds: r.QueryttlSeconds,
		ConnectTimeoutMs: r.ConnectTimeoutMs, ReadTimeoutMs: r.ReadTimeoutMs,
		RetryCount: r.RetryCount, RetryIntervalMs: r.RetryIntervalMs,
		Serializer: r.Serializer, Compression: r.Compression, AsyncFlush: r.AsyncFlush,
		FlushStrategy: r.FlushStrategy, Shared: r.Shared, FlushOnFailback: r.FlushOnFailback,
		AnalyticsEnabled: r.AnalyticsEnabled,
		LastTestConfigHash: r.LastTestConfigHash, LastTestResultJson: r.LastTestResultJson,
		LastTestedAt: r.LastTestedAt,
		OcState: r.OcState, OcLatencyMs: r.OcLatencyMs, OcLastErrorClass: r.OcLastErrorClass,
		OcUsedMemoryBytes: r.OcUsedMemoryBytes, OcHitRatioPct: r.OcHitRatioPct,
		OcConfigDrift: r.OcConfigDrift,
		CreatedAt: r.CreatedAt, UpdatedAt: r.UpdatedAt,
		HasPassword: r.HasPassword,
	}
}

// configFromRow maps a sqlc GetObjectCacheConfigRow (no password) to Config.
// HasPassword is set from the derived has_password column in the SELECT.
func configFromRow(r sqlc.GetObjectCacheConfigRow) Config {
	c := Config{
		SiteID:           r.SiteID,
		TenantID:         r.TenantID,
		Enabled:          r.Enabled,
		Scheme:           r.Scheme,
		Host:             r.Host,
		Port:             int(r.Port),
		SocketPath:       r.SocketPath,
		Database:         int(r.Database),
		Username:         r.Username,
		HasPassword:      r.HasPassword,
		Prefix:           r.Prefix,
		MaxTTLSeconds:    int(r.MaxttlSeconds),
		QueryTTLSeconds:  int(r.QueryttlSeconds),
		ConnectTimeoutMs: int(r.ConnectTimeoutMs),
		ReadTimeoutMs:    int(r.ReadTimeoutMs),
		RetryCount:       int(r.RetryCount),
		RetryIntervalMs:  int(r.RetryIntervalMs),
		Serializer:       r.Serializer,
		Compression:      r.Compression,
		AsyncFlush:       r.AsyncFlush,
		FlushStrategy:    r.FlushStrategy,
		Shared:           r.Shared,
		FlushOnFailback:  r.FlushOnFailback,
		AnalyticsEnabled: r.AnalyticsEnabled,
		LastTestResultJSON: r.LastTestResultJson,
		OCState:           OCState(r.OcState),
		OCLatencyMs:       int(r.OcLatencyMs),
		OCLastErrorClass:  r.OcLastErrorClass,
		OCUsedMemoryBytes: r.OcUsedMemoryBytes,
		OCConfigDrift:     r.OcConfigDrift,
		CreatedAt:         r.CreatedAt,
		UpdatedAt:         r.UpdatedAt,
	}
	if r.LastTestConfigHash != nil {
		c.LastTestConfigHash = *r.LastTestConfigHash
	}
	if r.LastTestedAt.Valid {
		t := r.LastTestedAt.Time
		c.LastTestedAt = &t
	}
	if r.OcHitRatioPct.Valid {
		v, _ := numericToFloat64(r.OcHitRatioPct)
		c.OCHitRatioPct = &v
	}
	return c
}

// numericFromFloat64 converts a float64 to a pgtype.Numeric.
func numericFromFloat64(f float64) pgtype.Numeric {
	bf := new(big.Float).SetFloat64(f)
	// Convert via string representation for accuracy.
	bi, acc := new(big.Int), big.ToNearestEven
	_ = acc
	bf.Int(bi)
	// Use the Int approach for simple integer-representable values.
	// For general floats, round to 4 decimal places.
	scaled := int64(f * 10000)
	return pgtype.Numeric{
		Int:              big.NewInt(scaled),
		Exp:              -4,
		NaN:              false,
		InfinityModifier: pgtype.Finite,
		Valid:            true,
	}
}

// numericToFloat64 converts a pgtype.Numeric to float64.
func numericToFloat64(n pgtype.Numeric) (float64, bool) {
	if !n.Valid || n.NaN {
		return 0, false
	}
	if n.Int == nil {
		return 0, true
	}
	f := new(big.Float).SetInt(n.Int)
	if n.Exp != 0 {
		exp := new(big.Float).SetPrec(64)
		base := big.NewFloat(10)
		expInt := int(n.Exp)
		if expInt > 0 {
			for i := 0; i < expInt; i++ {
				exp.Mul(exp.SetFloat64(1), base)
			}
		} else {
			exp.SetFloat64(1)
			for i := 0; i > expInt; i-- {
				exp.Quo(exp, base)
			}
		}
		f.Mul(f, exp)
	}
	result, _ := f.Float64()
	return result, true
}
