package agentcmd

// objectcache_contract.go -- CP->agent command contract for the Object Cache
// feature (M68, Phase 1 CP backend). The wp-agent-engineer mirrors these shapes
// in the agent's command handlers when implementing Phase 2.
//
// Wire commands (POST {site_url}/wp-json/wpmgr/v1/command/{cmd},
// Authorization: Bearer <minted EdDSA JWT>, aud=<siteId>):
//
//   objectcache.apply_config -- push the full connection config (including the
//                               DECRYPTED password) so the agent can persist it
//                               to its 0600 config file and reconnect.
//   objectcache.test         -- dial and probe with the CANDIDATE config (same
//                               payload as apply_config) WITHOUT persisting it.
//                               Returns a structured ObjectCacheTestResult.
//   objectcache.enable       -- install the object-cache.php drop-in stub.
//                               Handshake-gated: the CP only issues this command
//                               after a passing objectcache.test result.
//   objectcache.disable      -- remove the drop-in stub and flush via a standalone
//                               connection.
//   objectcache.flush        -- flush the cache (scope: all | site | group).
//
// Phase 2 (wp-agent-engineer) MUST implement all five command handlers.

// ObjectCacheConfigRequest is the POST body for `objectcache.apply_config` and
// `objectcache.test`. It carries the full connection config including the
// DECRYPTED password -- the Ed25519-signed JWT body over HTTPS is the security
// boundary. The agent MUST NOT echo the password back in any response.
type ObjectCacheConfigRequest struct {
	// Scheme is the connection type: tcp | unix | tls.
	Scheme string `json:"scheme"`

	// TCP / TLS fields. Ignored when Scheme is "unix".
	Host string `json:"host"`
	Port int    `json:"port"`

	// SocketPath is the Unix socket path. Used when Scheme is "unix".
	SocketPath string `json:"socket_path,omitempty"`

	// Database is the Redis database index (SELECT n). Default 0.
	Database int `json:"database"`

	// Username is the ACL username. Empty means password-only AUTH.
	Username string `json:"username,omitempty"`

	// Password is the DECRYPTED Redis password / ACL secret. Empty string means
	// no secret configured (Unix socket setups with no auth are common).
	// SECURITY: travels in the Ed25519-signed body over HTTPS only.
	Password string `json:"password,omitempty"`

	// Prefix is the key prefix applied to every key written by this site.
	Prefix string `json:"prefix"`

	// TTL knobs.
	MaxTTLSeconds   int `json:"maxttl_seconds"`
	QueryTTLSeconds int `json:"queryttl_seconds"`

	// Resilience knobs.
	ConnectTimeoutMs  int `json:"connect_timeout_ms"`
	ReadTimeoutMs     int `json:"read_timeout_ms"`
	RetryCount        int `json:"retry_count"`
	RetryIntervalMs   int `json:"retry_interval_ms"`

	// Serializer: php | igbinary.
	Serializer string `json:"serializer"`

	// Compression: none | lzf | lz4 | zstd.
	Compression string `json:"compression"`

	// AsyncFlush when true uses UNLINK instead of DEL and FLUSHDB ASYNC.
	AsyncFlush bool `json:"async_flush"`

	// FlushStrategy: auto | flushdb | scan.
	FlushStrategy string `json:"flush_strategy"`

	// Shared declares that this is a shared Redis instance. When true the flush
	// strategy is always scan (SCAN+MATCH+UNLINK, prefix-scoped).
	Shared bool `json:"shared"`

	// FlushOnFailback when true the agent flushes the cache when Redis returns
	// after a degraded/down window (D5).
	FlushOnFailback bool `json:"flush_on_failback"`

	// AnalyticsEnabled when false the agent stops pushing the extended stats block.
	AnalyticsEnabled bool `json:"analytics_enabled"`

	// DebugHeaderEnabled when true the drop-in emits a per-request
	// x-wpmgr-object-cache response header with cache hit/miss/state details.
	// Default false (silent in production). Named debug_header_enabled on
	// the wire to match the CP config column (m70).
	DebugHeaderEnabled bool `json:"debug_header_enabled"`
}

// ObjectCacheApplyConfigResult is the agent's response to `objectcache.apply_config`.
type ObjectCacheApplyConfigResult struct {
	OK     bool   `json:"ok"`
	Detail string `json:"detail,omitempty"`
}

// ObjectCacheTestResult is the agent's response to `objectcache.test`. It carries
// a structured probe report so the CP can store it and the web layer can render
// capability-gated selects and eviction-policy guidance.
type ObjectCacheTestResult struct {
	OK     bool   `json:"ok"`
	Detail string `json:"detail,omitempty"`

	// Reachable is true when the agent successfully connected and got a PING reply.
	Reachable bool `json:"reachable"`

	// LatencyMs is the median PING latency across 3 samples (milliseconds).
	LatencyMs float64 `json:"latency_ms"`

	// ServerVersion is the Redis version string from INFO server.
	ServerVersion string `json:"server_version,omitempty"`

	// EvictionPolicy is the maxmemory-policy value from CONFIG GET. Empty when
	// the server denied CONFIG GET (managed Redis). Tolerated gracefully.
	EvictionPolicy string `json:"eviction_policy,omitempty"`

	// MaxMemoryBytes is the maxmemory value in bytes. 0 means unlimited or
	// CONFIG GET was denied.
	MaxMemoryBytes int64 `json:"max_memory_bytes"`

	// UsedMemoryBytes is the used_memory field from INFO memory.
	UsedMemoryBytes int64 `json:"used_memory_bytes"`

	// Capabilities reports the extension and server capability probes.
	Capabilities ObjectCacheCapabilities `json:"capabilities"`

	// FlushCapabilityClass is "flushdb-safe" when FLUSHDB is permitted on the
	// confirmed-dedicated database, "scan-only" when shared or FLUSHDB is denied.
	FlushCapabilityClass string `json:"flush_capability_class"`

	// ACLDenials lists capability classes that the ACL user is denied
	// (e.g. "scan", "config", "flush"). Empty when using password-only AUTH.
	ACLDenials []string `json:"acl_denials,omitempty"`

	// RoundTripOK is true when the SETEX/GET/UNLINK round-trip under the
	// configured prefix succeeded.
	RoundTripOK bool `json:"round_trip_ok"`

	// ConfigHash is a short opaque token (sha256 hex) of the candidate config
	// snapshot. The CP stores this and uses it as the enable-gate key.
	ConfigHash string `json:"config_hash"`
}

// ObjectCacheCapabilities reports extension and server capability probe results.
type ObjectCacheCapabilities struct {
	// PhpRedisVersion is the phpredis extension version string.
	PhpRedisVersion string `json:"phpredis_version,omitempty"`

	// IgbinaryAvailable is true when the igbinary PHP extension is loaded.
	IgbinaryAvailable bool `json:"igbinary_available"`

	// LzfAvailable is true when lzf compression support is compiled in.
	LzfAvailable bool `json:"lzf_available"`

	// Lz4Available is true when lz4 compression support is compiled in.
	Lz4Available bool `json:"lz4_available"`

	// ZstdAvailable is true when zstd compression support is compiled in.
	ZstdAvailable bool `json:"zstd_available"`

	// TLSSupported is true when phpredis was compiled with TLS support.
	TLSSupported bool `json:"tls_supported"`

	// ValueMetadataReads is true when the phpredis version supports the
	// value+metadata read extension (stored-false disambiguation).
	ValueMetadataReads bool `json:"value_metadata_reads"`

	// NativeRetryOptions is true when OPT_MAX_RETRIES / jitter backoff is
	// supported by the installed phpredis version.
	NativeRetryOptions bool `json:"native_retry_options"`

	// KeepTTLSupported is true when the server supports SET ... KEEPTTL syntax
	// (Redis >= 6.0). Used for incr/decr TTL preservation.
	KeepTTLSupported bool `json:"keepttl_supported"`

	// FlushAsyncSupported is true when the server supports FLUSHDB ASYNC
	// (Redis >= 4.0).
	FlushAsyncSupported bool `json:"flush_async_supported"`
}

// ObjectCacheEnableRequest is the POST body for `objectcache.enable`.
type ObjectCacheEnableRequest struct {
	// ConfigHash is the hash of the tested config the CP is enabling against.
	// The agent verifies that its stored config matches this hash before
	// installing the drop-in.
	ConfigHash string `json:"config_hash"`
}

// ObjectCacheEnableResult is the agent's response to `objectcache.enable`.
type ObjectCacheEnableResult struct {
	OK     bool   `json:"ok"`
	Detail string `json:"detail,omitempty"`

	// DropinInstalled is true when the object-cache.php stub is now in wp-content.
	DropinInstalled bool `json:"dropin_installed"`

	// ForeignDropin is true when the agent found an existing object-cache.php
	// that is NOT ours and refused to overwrite it without a force action.
	ForeignDropin bool `json:"foreign_dropin,omitempty"`

	// TransientsPurged is the number of DB transient rows deleted (autoload
	// clean up performed after install).
	TransientsPurged int `json:"transients_purged,omitempty"`
}

// ObjectCacheDisableRequest is the POST body for `objectcache.disable`.
type ObjectCacheDisableRequest struct {
	// Flush when true the agent flushes via a standalone connection before
	// removing the drop-in. Default true from the CP.
	Flush bool `json:"flush"`
}

// ObjectCacheDisableResult is the agent's response to `objectcache.disable`.
type ObjectCacheDisableResult struct {
	OK     bool   `json:"ok"`
	Detail string `json:"detail,omitempty"`

	// DropinRemoved is true when the object-cache.php stub was removed.
	DropinRemoved bool `json:"dropin_removed"`

	// Flushed is true when the standalone flush succeeded.
	Flushed bool `json:"flushed,omitempty"`
}

// ObjectCacheFlushRequest is the POST body for `objectcache.flush`.
type ObjectCacheFlushRequest struct {
	// Scope is the flush scope: "all" | "site" | "group".
	// "all" wipes the entire prefixed keyspace.
	// "site" wipes all keys for the current blog (multisite semantics).
	// "group" wipes keys matching the specified Group name.
	Scope string `json:"scope"`

	// Group is the cache group name to flush. Required when Scope is "group".
	Group string `json:"group,omitempty"`

	// Reason is a short human-readable reason string logged to the flush audit.
	Reason string `json:"reason,omitempty"`
}

// ObjectCacheFlushResult is the agent's response to `objectcache.flush`.
type ObjectCacheFlushResult struct {
	OK     bool   `json:"ok"`
	Detail string `json:"detail,omitempty"`

	// Strategy is the flush strategy actually used: "flushdb" | "scan".
	Strategy string `json:"strategy,omitempty"`

	// KeysDeleted is the number of keys removed (approximate for SCAN strategy).
	KeysDeleted int64 `json:"keys_deleted,omitempty"`
}
