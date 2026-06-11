<?php
/**
 * WPMgr Object Cache drop-in
 * Version: 2.0.0
 *
 * Self-contained object-cache.php drop-in for WordPress. All engine classes are
 * inlined; no external file resolution can fail after installation.
 *
 * @package WPMgr\Agent\ObjectCache
 */

declare(strict_types=1);

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		exit; // No direct access.
	}

	// Breadcrumb: set immediately after ABSPATH guard so the heartbeat can detect
	// whether the drop-in was executed at all and identify early-bail causes.
	$GLOBALS['wpmgr_oc_stub'] = [ 'v' => '2.0.0', 'bail' => null ];

	// PHP floor: the engine uses PHP 8.1 features.
	if ( PHP_VERSION_ID < 80100 ) {
		$GLOBALS['wpmgr_oc_stub']['bail'] = 'php_floor';
		return;
	}

	// WP install-mode bail-out: during install the DB is not ready.
	if ( function_exists( 'wp_installing' ) && wp_installing() ) {
		$GLOBALS['wpmgr_oc_stub']['bail'] = 'installing';
		return;
	}

	// Kill-switch: operator or host can disable without removing the file.
	if ( defined( 'WPMGR_OBJECT_CACHE_DISABLED' ) && WPMGR_OBJECT_CACHE_DISABLED ) {
		$GLOBALS['wpmgr_oc_stub']['bail'] = 'killswitch';
		return;
	}

	// Success path: all engine classes are inlined below.
	$GLOBALS['wpmgr_oc_stub']['bail'] = 'engine_inline';

} // end namespace (preamble)
namespace WPMgr\Agent\ObjectCache {

	if ( ! class_exists( 'WPMgr\\Agent\\ObjectCache\\ObjectCacheConfig', false ) ) {
		/**
 * ObjectCacheConfig — loads and persists the object-cache connection config.
 *
 * The config lives in wp-content/wpmgr-object-cache-config.php, chmod 0600,
 * written atomically (tmp + rename). It returns a plain PHP array; no DB round
 * trips on the hot path.
 *
 * Security constraints:
 *   - 0600 permissions: owner-only read, kept on every write.
 *   - Atomic write: tmp file + rename so a crash mid-write leaves the old config.
 *   - Excluded from backups via FilesArchiver DEFAULT_EXCLUDES (exact filename).
 *   - Excluded from restores via FilesRestorer EXCLUDE_SUBSTRINGS.
 *   - Path is not user-controllable; always derived from WP_CONTENT_DIR.
 *   - The secret (Redis password) is stored here and nowhere else on the site.
 *   - Never echoed back in any response.
 *   - Tmp file written under umask 0077 so secret bytes are never world-readable.
 *
 * @package WPMgr\Agent\ObjectCache
 */



/**
 * Loads and persists the object-cache connection config from the dedicated
 * 0600 PHP config file in wp-content.
 */
final class ObjectCacheConfig
{
	/** Filename inside wp-content. */
	public const FILENAME = 'wpmgr-object-cache-config.php';

	/** Config hash option name (non-autoloaded). */
	public const OPTION_CONFIG_HASH = 'wpmgr_object_cache_config_hash';

	/** Default max TTL in seconds (7 days, decision D6). */
	public const DEFAULT_MAXTTL = 604800;

	/** Default query TTL in seconds (24h). */
	public const DEFAULT_QUERYTTL = 86400;

	/** Default connect timeout in milliseconds. */
	public const DEFAULT_CONNECT_TIMEOUT_MS = 1000;

	/** Default read timeout in milliseconds. */
	public const DEFAULT_READ_TIMEOUT_MS = 1000;

	/** Default retry count. */
	public const DEFAULT_RETRY_COUNT = 3;

	/** Default retry interval base in milliseconds. */
	public const DEFAULT_RETRY_INTERVAL_MS = 25;

	/** Absolute path to the config file. */
	private string $filePath;

	/** @var array<string,mixed>|null Loaded config cache. */
	private ?array $loaded = null;

	/**
	 * @param string|null $contentDir wp-content path override (for tests).
	 */
	public function __construct( ?string $contentDir = null )
	{
		if ( $contentDir !== null ) {
			$base = rtrim( $contentDir, '/\\' );
		} elseif ( defined( 'WP_CONTENT_DIR' ) ) {
			$base = rtrim( (string) constant( 'WP_CONTENT_DIR' ), '/\\' );
		} else {
			$base = '';
		}
		$this->filePath = $base !== '' ? $base . '/' . self::FILENAME : '';
	}

	/**
	 * Absolute path to the config file.
	 *
	 * @return string
	 */
	public function filePath(): string
	{
		return $this->filePath;
	}

	/**
	 * Load and return the config array. Returns an empty array when the file
	 * is absent, unreadable, or malformed. Result is memoized per instance.
	 *
	 * @return array<string,mixed>
	 */
	public function load(): array
	{
		if ( $this->loaded !== null ) {
			return $this->loaded;
		}

		if ( $this->filePath === '' || ! @is_file( $this->filePath ) ) {
			$this->loaded = [];
			return $this->loaded;
		}

		// Permission check: refuse to use a world-readable secrets file.
		if ( function_exists( 'fileperms' ) ) {
			$perms = @fileperms( $this->filePath );
			if ( $perms !== false ) {
				// 0600 => 0x8180; allow 0600 and 0640. Reject world-readable (0044).
				if ( ( $perms & 0x0004 ) !== 0 ) {
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG-gated diagnostic
						error_log( 'WPMgr Object Cache: config file is world-readable; refusing to load.' );
					}
					$this->loaded = [];
					return $this->loaded;
				}
			}
		}

		try {
			// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.NotAbsolutePath -- path is derived from WP_CONTENT_DIR, always absolute
			$result = include $this->filePath;
		} catch ( \Throwable $e ) {
			$result = false;
		}

		$this->loaded = is_array( $result ) ? $result : [];
		return $this->loaded;
	}

	/**
	 * Persist a new config to the 0600 file using an atomic tmp+rename write.
	 * Never stores the password in the hash stored in wp-options.
	 *
	 * @param array<string,mixed> $config Config to persist.
	 * @return bool True on success.
	 */
	public function save( array $config ): bool
	{
		if ( $this->filePath === '' ) {
			return false;
		}

		$dir = dirname( $this->filePath );
		if ( ! @is_dir( $dir ) ) {
			return false;
		}

		// Build PHP source.
		$export = var_export( $config, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- generating PHP source for config file, not debug output
		$source = "<?php\n"
			. "// WPMgr Object Cache connection config.\n"
			. "defined( 'ABSPATH' ) || exit;\n"
			. "return " . $export . ";\n";

		// Atomic write: write to a temp file, then rename.
		$tmp = $this->filePath . '.tmp.' . wp_rand( 100000, 999999 );

		// Narrow the umask so the tmp file is created 0600 at the OS level;
		// the explicit chmod below is a belt-and-suspenders second layer.
		// This closes the window between write and chmod (S8).
		$prevUmask = umask( 0077 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_umask -- headless agent; must bracket secret-file write to ensure 0600 at creation
		$written   = @file_put_contents( $tmp, $source, LOCK_EX );
		umask( $prevUmask ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_umask -- restores umask after the secret-file write window

		if ( $written === false ) {
			return false;
		}

		// Belt-and-suspenders: explicit chmod in case umask was already overridden
		// by the SAPI or an earlier call.
		@chmod( $tmp, 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- headless agent; WP_Filesystem not initialized; direct chmod required for credential-file security

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic rename; WP_Filesystem::move() is non-atomic; justified per §4 guardrail
		if ( ! @rename( $tmp, $this->filePath ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- cleanup of a failed rename on a tmp file in the same dir; wp_delete_file() is equivalent for non-attachment files
			return false;
		}

		// Invalidate opcache so a credential rotation is not silently served from
		// stale bytecode on validate_timestamps=0 hosts (S7).
		if ( function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( $this->filePath, true );
		}

		// Invalidate the memoized load.
		$this->loaded = null;

		// Persist config hash (password-redacted) to wp-options for drift detection.
		$this->persistHash( $config );

		return true;
	}

	/**
	 * Remove the config file. Idempotent.
	 *
	 * @return bool True when the file is absent or successfully removed.
	 */
	public function delete(): bool
	{
		if ( $this->filePath === '' || ! @is_file( $this->filePath ) ) {
			return true;
		}
		$result = @unlink( $this->filePath ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- wp_delete_file wraps unlink; direct unlink is equivalent for a non-attachment file
		if ( $result && function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( $this->filePath, true );
		}
		$this->loaded = null;
		return $result;
	}

	/**
	 * Whether the config file exists on disk.
	 *
	 * @return bool
	 */
	public function exists(): bool
	{
		return $this->filePath !== '' && @is_file( $this->filePath );
	}

	/**
	 * Compute a SHA-256 config hash (password redacted) for drift detection.
	 *
	 * @param array<string,mixed> $config Full config including password.
	 * @return string Hex hash.
	 */
	public function computeHash( array $config ): string
	{
		$redacted = $config;
		unset( $redacted['password'] );
		ksort( $redacted );
		return hash( 'sha256', (string) wp_json_encode( $redacted ) );
	}

	/**
	 * Persist the config hash to wp-options for CP drift detection.
	 *
	 * @param array<string,mixed> $config Config array.
	 * @return void
	 */
	private function persistHash( array $config ): void
	{
		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION_CONFIG_HASH, $this->computeHash( $config ), false );
		}
	}

	/**
	 * Build a config array from the command request params with safe defaults.
	 * Validates and clamps all values.
	 *
	 * @param array<string,mixed> $params Raw command params.
	 * @return array<string,mixed>
	 */
	public static function fromParams( array $params ): array
	{
		$scheme = isset( $params['scheme'] ) && is_string( $params['scheme'] )
			? $params['scheme'] : 'tcp';
		if ( ! in_array( $scheme, [ 'tcp', 'unix', 'tls' ], true ) ) {
			$scheme = 'tcp';
		}

		$host = isset( $params['host'] ) && is_string( $params['host'] )
			? sanitize_text_field( $params['host'] ) : '127.0.0.1';

		$port = isset( $params['port'] ) && is_int( $params['port'] )
			? (int) $params['port'] : 6379;
		if ( $port < 1 || $port > 65535 ) {
			$port = 6379;
		}

		$socketPath = isset( $params['socket_path'] ) && is_string( $params['socket_path'] )
			? sanitize_text_field( $params['socket_path'] ) : '';

		$database = isset( $params['database'] ) && is_int( $params['database'] )
			? (int) $params['database'] : 0;
		if ( $database < 0 || $database > 15 ) {
			$database = 0;
		}

		$username = isset( $params['username'] ) && is_string( $params['username'] )
			? sanitize_text_field( $params['username'] ) : '';

		// Password: not sanitized to preserve exact bytes; stored in 0600 file only.
		$password = isset( $params['password'] ) && is_string( $params['password'] )
			? $params['password'] : '';

		$prefix = isset( $params['prefix'] ) && is_string( $params['prefix'] )
			? sanitize_text_field( $params['prefix'] ) : 'wpmgr';
		// An empty/whitespace prefix defeats shared-Redis namespacing; SCAN `:*`
		// would match all keys across every tenant on a shared instance.
		if ( $prefix === '' ) {
			$prefix = 'wpmgr';
		}

		$maxttl = isset( $params['maxttl_seconds'] ) && is_int( $params['maxttl_seconds'] )
			? (int) $params['maxttl_seconds'] : self::DEFAULT_MAXTTL;
		if ( $maxttl < 0 ) {
			$maxttl = self::DEFAULT_MAXTTL;
		}

		$queryttl = isset( $params['queryttl_seconds'] ) && is_int( $params['queryttl_seconds'] )
			? (int) $params['queryttl_seconds'] : self::DEFAULT_QUERYTTL;
		if ( $queryttl < 0 ) {
			$queryttl = self::DEFAULT_QUERYTTL;
		}

		$connectTimeoutMs = isset( $params['connect_timeout_ms'] ) && is_int( $params['connect_timeout_ms'] )
			? (int) $params['connect_timeout_ms'] : self::DEFAULT_CONNECT_TIMEOUT_MS;
		if ( $connectTimeoutMs < 100 ) {
			$connectTimeoutMs = 100;
		}
		if ( $connectTimeoutMs > 5000 ) {
			$connectTimeoutMs = 5000;
		}

		$readTimeoutMs = isset( $params['read_timeout_ms'] ) && is_int( $params['read_timeout_ms'] )
			? (int) $params['read_timeout_ms'] : self::DEFAULT_READ_TIMEOUT_MS;
		if ( $readTimeoutMs < 100 ) {
			$readTimeoutMs = 100;
		}
		if ( $readTimeoutMs > 5000 ) {
			$readTimeoutMs = 5000;
		}

		$retryCount = isset( $params['retry_count'] ) && is_int( $params['retry_count'] )
			? (int) $params['retry_count'] : self::DEFAULT_RETRY_COUNT;
		if ( $retryCount < 0 ) {
			$retryCount = 0;
		}
		if ( $retryCount > 10 ) {
			$retryCount = 10;
		}

		$retryIntervalMs = isset( $params['retry_interval_ms'] ) && is_int( $params['retry_interval_ms'] )
			? (int) $params['retry_interval_ms'] : self::DEFAULT_RETRY_INTERVAL_MS;
		if ( $retryIntervalMs < 1 ) {
			$retryIntervalMs = 25;
		}

		$serializer = isset( $params['serializer'] ) && is_string( $params['serializer'] )
			? $params['serializer'] : 'php';
		if ( ! in_array( $serializer, [ 'php', 'igbinary' ], true ) ) {
			$serializer = 'php';
		}

		$compression = isset( $params['compression'] ) && is_string( $params['compression'] )
			? $params['compression'] : 'none';
		if ( ! in_array( $compression, [ 'none', 'lzf', 'lz4', 'zstd' ], true ) ) {
			$compression = 'none';
		}

		$asyncFlush = isset( $params['async_flush'] ) && is_bool( $params['async_flush'] )
			? $params['async_flush'] : false;

		$flushStrategy = isset( $params['flush_strategy'] ) && is_string( $params['flush_strategy'] )
			? $params['flush_strategy'] : 'auto';
		if ( ! in_array( $flushStrategy, [ 'auto', 'flushdb', 'scan' ], true ) ) {
			$flushStrategy = 'auto';
		}

		$shared = isset( $params['shared'] ) && is_bool( $params['shared'] )
			? $params['shared'] : true;

		$flushOnFailback = isset( $params['flush_on_failback'] ) && is_bool( $params['flush_on_failback'] )
			? $params['flush_on_failback'] : true;

		$analyticsEnabled = isset( $params['analytics_enabled'] ) && is_bool( $params['analytics_enabled'] )
			? $params['analytics_enabled'] : true;

		return [
			'scheme'              => $scheme,
			'host'                => $host,
			'port'                => $port,
			'socket_path'         => $socketPath,
			'database'            => $database,
			'username'            => $username,
			'password'            => $password,
			'prefix'              => $prefix,
			'maxttl_seconds'      => $maxttl,
			'queryttl_seconds'    => $queryttl,
			'connect_timeout_ms'  => $connectTimeoutMs,
			'read_timeout_ms'     => $readTimeoutMs,
			'retry_count'         => $retryCount,
			'retry_interval_ms'   => $retryIntervalMs,
			'serializer'          => $serializer,
			'compression'         => $compression,
			'async_flush'         => $asyncFlush,
			'flush_strategy'      => $flushStrategy,
			'shared'              => $shared,
			'flush_on_failback'   => $flushOnFailback,
			'analytics_enabled'   => $analyticsEnabled,
		];
	}
}

	}

	if ( ! class_exists( 'WPMgr\\Agent\\ObjectCache\\RedisConnection', false ) ) {
		/**
 * RedisConnection — phpredis connection layer for the WPMgr object cache.
 *
 * Design commitments (from plan section 3):
 *   1. pconnect with explicit persistent_id derived from connection identity.
 *   2. Finite timeouts always (1.0s defaults, CP-tunable).
 *   3. Coherent retry policy: decorrelated-jitter backoff, AUTH+SELECT inside
 *      the retried section.
 *   4. Command-level resilience: retry-once for idempotent reads on timeout.
 *   5. PING-on-acquire after long idle.
 *   6. TLS and ACL first-class (v1: single instance + unix socket + TLS).
 *
 * @package WPMgr\Agent\ObjectCache
 */



/**
 * Manages a phpredis persistent connection with timeouts, retries, and TLS.
 */
final class RedisConnection
{
	/** Idle threshold in seconds before a PING-on-acquire is issued. */
	private const PING_AFTER_IDLE_SECONDS = 60;

	/** Maximum jitter sleep in microseconds per retry (cap = connect_timeout). */
	private const JITTER_BASE_US = 25000;

	/** phpredis client instance; null when not yet connected. */
	private ?\Redis $redis = null;

	/** Whether we are in a degraded (failed) state for this request. */
	private bool $degraded = false;

	/** Timestamp of the last successful command. */
	private float $lastUsed = 0.0;

	/** Number of reconnect attempts made this request. */
	private int $reconnectAttempts = 0;

	/** @var array<string,mixed> Connection config. */
	private array $config;

	/**
	 * @param array<string,mixed> $config Connection config (from ObjectCacheConfig::fromParams()).
	 */
	public function __construct( array $config )
	{
		$this->config = $config;
	}

	/**
	 * Acquire (or re-use) a ready phpredis client.
	 * Throws on failure after all retries are exhausted.
	 *
	 * @return \Redis
	 * @throws \RuntimeException If the connection cannot be established.
	 */
	public function acquire(): \Redis
	{
		if ( $this->redis !== null && ! $this->degraded ) {
			$this->maybeePing();
			return $this->redis;
		}

		$this->redis = $this->connect();
		$this->degraded = false;
		$this->lastUsed = microtime( true );
		return $this->redis;
	}

	/**
	 * Mark the connection degraded. Subsequent acquire() will reconnect once.
	 *
	 * @return void
	 */
	public function markDegraded(): void
	{
		$this->degraded = true;
	}

	/**
	 * Whether the connection is currently in a degraded state.
	 *
	 * @return bool
	 */
	public function isDegraded(): bool
	{
		return $this->degraded;
	}

	/**
	 * Whether we have already exhausted our one reconnect attempt this request.
	 *
	 * @return bool
	 */
	public function reconnectExhausted(): bool
	{
		return $this->reconnectAttempts >= 1;
	}

	/**
	 * Record that a command succeeded (updates lastUsed).
	 *
	 * @return void
	 */
	public function recordSuccess(): void
	{
		$this->lastUsed = microtime( true );
		$this->degraded = false;
	}

	/**
	 * Close the connection cleanly. No-op when not connected.
	 *
	 * @return void
	 */
	public function close(): void
	{
		if ( $this->redis !== null ) {
			try {
				$this->redis->close();
			} catch ( \Throwable $e ) {
				// Best-effort close.
			}
			$this->redis = null;
		}
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Build and return a new phpredis handle with the full configured connection
	 * flow: pconnect, AUTH, SELECT, capability options.
	 *
	 * AUTH and SELECT are inside the retry loop so transient auth failures are
	 * retried consistently with connect failures.
	 *
	 * @return \Redis
	 * @throws \RuntimeException If all retry attempts fail.
	 */
	private function connect(): \Redis
	{
		if ( ! extension_loaded( 'redis' ) ) {
			throw new \RuntimeException( 'phpredis extension not loaded' );
		}

		$scheme          = (string) ( $this->config['scheme'] ?? 'tcp' );
		$host            = (string) ( $this->config['host'] ?? '127.0.0.1' );
		$port            = (int) ( $this->config['port'] ?? 6379 );
		$socketPath      = (string) ( $this->config['socket_path'] ?? '' );
		$database        = (int) ( $this->config['database'] ?? 0 );
		$username        = (string) ( $this->config['username'] ?? '' );
		$password        = (string) ( $this->config['password'] ?? '' );
		$connectTimeout  = ( (int) ( $this->config['connect_timeout_ms'] ?? 1000 ) ) / 1000.0;
		$readTimeout     = ( (int) ( $this->config['read_timeout_ms'] ?? 1000 ) ) / 1000.0;
		$retryCount      = (int) ( $this->config['retry_count'] ?? 3 );
		$retryIntervalUs = (int) ( $this->config['retry_interval_ms'] ?? 25 ) * 1000;
		$serializer      = (string) ( $this->config['serializer'] ?? 'php' );
		$compression     = (string) ( $this->config['compression'] ?? 'none' );

		// persistent_id derived from connection identity to prevent pooled-socket
		// database leaks across different configs on the same FPM worker.
		$prefixVersion = 'v1';
		$persistentId = hash(
			'crc32b',
			implode(
				'|',
				[
					$scheme === 'unix' ? $socketPath : $host,
					(string) $port,
					(string) $database,
					$scheme === 'tls' ? 'tls' : 'plain',
					$username,
					$prefixVersion,
				]
			)
		);

		$maxJitterUs = (int) ( $connectTimeout * 1000000 );

		$lastException = null;
		$attempts = max( 1, $retryCount );

		for ( $attempt = 1; $attempt <= $attempts; $attempt++ ) {
			// Decorrelated jitter: sleep before retry (not before first attempt).
			if ( $attempt > 1 ) {
				// random_int() is always available (PHP 7+) and works at drop-in
				// load time before WordPress functions are defined; wp_rand() is a
				// WP wrapper that is not guaranteed to be available this early.
				$jitter = random_int( 0, min( $retryIntervalUs * $attempt, $maxJitterUs ) );
				if ( $jitter > 0 ) {
					usleep( $jitter );
				}
			}

			try {
				$redis = new \Redis();

				if ( $scheme === 'unix' && $socketPath !== '' ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_pconnect -- phpredis API; not a file system operation
					$redis->pconnect( $socketPath, 0, $connectTimeout, $persistentId );
				} elseif ( $scheme === 'tls' ) {
					$context = $this->buildTlsContext();
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_pconnect -- phpredis API; not a file system operation
					$redis->pconnect( 'tls://' . $host, $port, $connectTimeout, $persistentId, 0, $readTimeout, $context );
				} else {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_pconnect -- phpredis API; not a file system operation
					$redis->pconnect( $host, $port, $connectTimeout, $persistentId, 0, $readTimeout );
				}

				// AUTH and SELECT are inside the retry loop.
				if ( $password !== '' ) {
					if ( $username !== '' ) {
						$redis->auth( [ $username, $password ] );
					} else {
						$redis->auth( $password );
					}
				}

				// Re-assert SELECT on persistent handles to prevent database leaks.
				if ( $database !== 0 ) {
					$redis->select( $database );
				} else {
					// Always SELECT 0 on persistent handles (defensive re-SELECT).
					$redis->select( 0 );
				}

				// Set phpredis client options.
				$this->applyClientOptions( $redis, $serializer, $compression, $readTimeout );

				$this->reconnectAttempts++;
				return $redis;

			} catch ( \Throwable $e ) {
				$lastException = $e;
			}
		}

		$lastMessage = $lastException !== null ? $lastException->getMessage() : 'unknown error';
		throw new \RuntimeException(
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception message is not browser output; escaping here would corrupt the message text
			'WPMgr Object Cache: connection failed after ' . $attempts . ' attempts: ' . $lastMessage
		);
	}

	/**
	 * Apply phpredis client options: serializer, compression, read timeout,
	 * and native retry options when supported.
	 *
	 * @param \Redis $redis      Client handle.
	 * @param string $serializer Serializer: 'php' | 'igbinary'.
	 * @param string $compression Compression: 'none' | 'lzf' | 'lz4' | 'zstd'.
	 * @param float  $readTimeout Read timeout in seconds.
	 * @return void
	 */
	private function applyClientOptions( \Redis $redis, string $serializer, string $compression, float $readTimeout ): void
	{
		// Serializer.
		if ( $serializer === 'igbinary' && defined( 'Redis::SERIALIZER_IGBINARY' ) ) {
			$redis->setOption( \Redis::OPT_SERIALIZER, (string) constant( 'Redis::SERIALIZER_IGBINARY' ) );
		} else {
			$redis->setOption( \Redis::OPT_SERIALIZER, (string) \Redis::SERIALIZER_PHP );
		}

		// Compression.
		if ( $compression !== 'none' && defined( 'Redis::OPT_COMPRESSION' ) ) {
			$compressionMap = [
				'lzf'  => defined( 'Redis::COMPRESSION_LZF' ) ? constant( 'Redis::COMPRESSION_LZF' ) : null,
				'lz4'  => defined( 'Redis::COMPRESSION_LZ4' ) ? constant( 'Redis::COMPRESSION_LZ4' ) : null,
				'zstd' => defined( 'Redis::COMPRESSION_ZSTD' ) ? constant( 'Redis::COMPRESSION_ZSTD' ) : null,
			];
			$compressionConst = $compressionMap[ $compression ] ?? null;
			if ( $compressionConst !== null ) {
				$redis->setOption( constant( 'Redis::OPT_COMPRESSION' ), (string) $compressionConst );
			}
		}

		// Read timeout.
		if ( $readTimeout > 0 ) {
			$redis->setOption( \Redis::OPT_READ_TIMEOUT, (string) $readTimeout );
		}

		// Native retry options (phpredis >= 5.3).
		if ( defined( 'Redis::OPT_MAX_RETRIES' ) ) {
			$redis->setOption( constant( 'Redis::OPT_MAX_RETRIES' ), '0' ); // Engine handles its own retries.
		}
	}

	/**
	 * Build the TLS stream context from config.
	 *
	 * @return array<string,mixed>
	 */
	private function buildTlsContext(): array
	{
		$tls = [];
		if ( isset( $this->config['tls_verify_peer'] ) ) {
			$tls['verify_peer'] = (bool) $this->config['tls_verify_peer'];
			$tls['verify_peer_name'] = (bool) $this->config['tls_verify_peer'];
		}
		if ( isset( $this->config['tls_cafile'] ) && is_string( $this->config['tls_cafile'] ) ) {
			$tls['cafile'] = (string) $this->config['tls_cafile'];
		}
		if ( isset( $this->config['tls_local_cert'] ) && is_string( $this->config['tls_local_cert'] ) ) {
			$tls['local_cert'] = (string) $this->config['tls_local_cert'];
		}
		if ( isset( $this->config['tls_local_pk'] ) && is_string( $this->config['tls_local_pk'] ) ) {
			$tls['local_pk'] = (string) $this->config['tls_local_pk'];
		}
		return [ 'stream' => $tls ];
	}

	/**
	 * Issue a PING-on-acquire when the handle has been idle beyond the threshold.
	 * Reconnects on failure.
	 *
	 * @return void
	 */
	private function maybeePing(): void
	{
		if ( $this->redis === null ) {
			return;
		}
		$idle = microtime( true ) - $this->lastUsed;
		if ( $idle < self::PING_AFTER_IDLE_SECONDS ) {
			return;
		}
		try {
			$this->redis->ping();
			$this->lastUsed = microtime( true );
		} catch ( \Throwable $e ) {
			// Silent: reconnect on next acquire.
			$this->redis = null;
			$this->degraded = true;
		}
	}

	/**
	 * Probe extension and server capabilities for the TEST command.
	 *
	 * @param \Redis $redis An already-connected client.
	 * @return array<string,mixed> Capability map matching ObjectCacheCapabilities contract.
	 */
	public static function probeCapabilities( \Redis $redis ): array
	{
		$phpredisVersion = defined( 'Redis::REDIS_VERSION' )
			? (string) constant( 'Redis::REDIS_VERSION' )
			: ( phpversion( 'redis' ) ?: '' );

		$igbinaryAvailable = defined( 'Redis::SERIALIZER_IGBINARY' );
		$lzfAvailable      = defined( 'Redis::COMPRESSION_LZF' );
		$lz4Available      = defined( 'Redis::COMPRESSION_LZ4' );
		$zstdAvailable     = defined( 'Redis::COMPRESSION_ZSTD' );
		$tlsSupported      = method_exists( $redis, 'connect' ) && defined( 'OPENSSL_VERSION_NUMBER' );
		$nativeRetryOptions = defined( 'Redis::OPT_MAX_RETRIES' );

		// KEEPTTL support: Redis >= 6.0 (server-side).
		$keepTtlSupported    = false;
		$flushAsyncSupported = false;
		try {
			$info = $redis->info( 'server' );
			if ( is_array( $info ) && isset( $info['redis_version'] ) ) {
				$serverVersion = (string) $info['redis_version'];
				$vParts = explode( '.', $serverVersion );
				$major = (int) ( $vParts[0] ?? 0 );
				$minor = (int) ( $vParts[1] ?? 0 );
				// KEEPTTL: Redis >= 6.0.
				if ( $major >= 6 ) {
					$keepTtlSupported = true;
				}
				// FLUSHDB ASYNC: Redis >= 4.0.
				if ( $major >= 4 || ( $major === 4 && $minor >= 0 ) ) {
					$flushAsyncSupported = true;
				}
			}
		} catch ( \Throwable $e ) {
			// Tolerate INFO denial.
		}

		// Value+metadata reads (stored-false disambiguation): phpredis >= 6.0.
		$valueMetadataReads = false;
		if ( $phpredisVersion !== '' ) {
			$vParts = explode( '.', $phpredisVersion );
			$major  = (int) ( $vParts[0] ?? 0 );
			if ( $major >= 6 ) {
				$valueMetadataReads = true;
			}
		}

		return [
			'phpredis_version'     => $phpredisVersion,
			'igbinary_available'   => $igbinaryAvailable,
			'lzf_available'        => $lzfAvailable,
			'lz4_available'        => $lz4Available,
			'zstd_available'       => $zstdAvailable,
			'tls_supported'        => $tlsSupported,
			'value_metadata_reads' => $valueMetadataReads,
			'native_retry_options' => $nativeRetryOptions,
			'keepttl_supported'    => $keepTtlSupported,
			'flush_async_supported' => $flushAsyncSupported,
		];
	}
}

	}

} // end namespace WPMgr\Agent\ObjectCache

namespace {

	if ( ! class_exists( 'WPMgr_Object_Cache', false ) ) {
		// ---------------------------------------------------------------------------
// WPMgr_Object_Cache class definition.
// This class is in the global namespace as WordPress expects it.
// ---------------------------------------------------------------------------

/**
 * WPMgr persistent object cache backed by phpredis.
 *
 * Implements the full WordPress wp_cache_* API with:
 *   - L1 per-request runtime array cache (clone-on-store/read).
 *   - Group semantics: global groups, non-persistent groups, wildcard matching.
 *   - Key shape: prefix:[blogId:]group:key.
 *   - Graceful degradation: boot failure or runtime errors => array-only mode.
 *   - maxttl ceiling on every write (D6, default 7d).
 *   - NX/XX conditional writes, KEEPTTL incr/decr with old-server fallback.
 *   - MGET + pipelines for multi ops.
 *   - UNLINK for async deletes when configured.
 *   - Flush strategies: FLUSHDB (dedicated) or SCAN+MATCH+UNLINK (shared).
 *   - Metadata integrity: JSON metadata key, flush on risky-option change.
 *   - Per-request error journal for CP diagnostics.
 *   - In-process stats counters for heartbeat block.
 */
class WPMgr_Object_Cache
{
	// -------------------------------------------------------------------------
	// Engine version — visible on the heartbeat wire so operators can confirm
	// which code is actually executing after an agent update.
	// -------------------------------------------------------------------------

	/** Version of this engine class. Included in every heartbeat block. */
	public const ENGINE_VERSION = '0.41.4';

	// -------------------------------------------------------------------------
	// Feature advertisement (wp_cache_supports).
	// -------------------------------------------------------------------------

	/** @var array<string> Supported features. */
	private const SUPPORTS = [
		'add_multiple',
		'set_multiple',
		'get_multiple',
		'delete_multiple',
		'flush_runtime',
		'flush_group',
	];

	// -------------------------------------------------------------------------
	// Global group and non-persistent group defaults.
	// -------------------------------------------------------------------------

	/** @var array<string> Groups that share a global (site-agnostic) namespace. */
	private const DEFAULT_GLOBAL_GROUPS = [
		'blog-details',
		'blog-id-cache',
		'blog-lookup',
		'global-posts',
		'networks',
		'rss',
		'site-details',
		'site-lookup',
		'site-options',
		'site-transient',
		'users',
		'useremail',
		'userlogins',
		'usermeta',
		'user_meta',
		'userslugs',
	];

	/** @var array<string> Groups whose values are never stored in Redis. */
	private const DEFAULT_NON_PERSISTENT = [
		'comment',
		'counts',
		'plugins',
		'themes',
	];

	// -------------------------------------------------------------------------
	// Instance state.
	// -------------------------------------------------------------------------

	/** @var \WPMgr\Agent\ObjectCache\RedisConnection|null Redis connection (null in array-only mode). */
	private ?\WPMgr\Agent\ObjectCache\RedisConnection $connection = null;

	/** @var \Redis|null Active phpredis handle (null in array-only mode). */
	private ?\Redis $redis = null;

	/** @var bool True when boot failed and we are running as a pure-array cache. */
	private bool $arrayMode = false;

	/** @var bool True when a reconnect this request has already been attempted. */
	private bool $reconnectAttempted = false;

	/** @var array<string,array<string,mixed>> L1 runtime cache: group => key => value. */
	private array $cache = [];

	/** @var array<string> Global group registry. */
	private array $globalGroups = [];

	/** @var array<string> Non-persistent group registry. */
	private array $nonPersistentGroups = [];

	/** @var array<string> Non-prefetchable group registry (v2, stored for later). */
	private array $nonPrefetchableGroups = [];

	/** @var array<string,bool> Memoized wildcard group-match results. */
	private array $wildcardMemo = [];

	/** @var string Prefix applied to all keys. */
	private string $prefix = 'wpmgr';

	/** @var int Current blog ID for key namespacing in multisite. */
	private int $blogId = 1;

	/** @var int Max TTL in seconds (D6, default 7d). */
	private int $maxttl = 604800;

	/** @var int Query-group TTL in seconds (default 24h). */
	private int $queryttl = 86400;

	/** @var bool Whether to use UNLINK for deletes. */
	private bool $asyncFlush = false;

	/** @var string Flush strategy: 'auto' | 'flushdb' | 'scan'. */
	private string $flushStrategy = 'auto';

	/** @var bool Whether this is a shared Redis instance. */
	private bool $shared = true;

	/** @var bool Whether to flush on failback after an outage. */
	private bool $flushOnFailback = true;

	/** @var bool Whether we flushed on a previous failback this boot. */
	private bool $failbackFlushed = false;

	/** @var array<string,mixed> Loaded config array. */
	private array $config = [];

	/** @var int Hit counter for current request. */
	public int $cache_hits = 0;

	/** @var int Miss counter for current request. */
	public int $cache_misses = 0;

	/** Legacy aliases for plugins that poke internals. */
	public int $hits = 0;

	/** Legacy alias. */
	public int $misses = 0;

	/** @var array<string> Per-request error journal (last N errors). */
	private array $errorJournal = [];

	/** Maximum entries in the error journal. */
	private const MAX_JOURNAL = 20;

	/** @var float Total wait time (ms) for Redis commands this request. */
	private float $totalWaitMs = 0.0;

	/** @var int Total Redis reads this request. */
	private int $redisReads = 0;

	/** @var int Total Redis writes this request. */
	private int $redisWrites = 0;

	/** @var bool Whether KEEPTTL is supported (probed at connect). */
	private bool $keepttlSupported = false;

	// -------------------------------------------------------------------------
	// Factory + boot
	// -------------------------------------------------------------------------

	/**
	 * Boot the cache: load config, connect, return the instance.
	 * On any Throwable during boot, return an array-mode instance.
	 *
	 * @return self
	 */
	public static function boot(): self
	{
		$instance = new self();
		$instance->globalGroups         = array_flip( self::DEFAULT_GLOBAL_GROUPS );
		$instance->nonPersistentGroups  = array_flip( self::DEFAULT_NON_PERSISTENT );

		// Set the current blog ID from WordPress globals when available.
		if ( isset( $GLOBALS['blog_id'] ) ) {
			$instance->blogId = (int) $GLOBALS['blog_id'];
		}

		try {
			// Load config from the 0600 file.
			if ( ! class_exists( 'WPMgr\Agent\ObjectCache\ObjectCacheConfig' ) ) {
				// Supporting classes not loaded (e.g. engine loaded standalone).
				$instance->bootArrayMode( 'classes_missing' );
				return $instance;
			}

			$configLoader = new \WPMgr\Agent\ObjectCache\ObjectCacheConfig();
			$config       = $configLoader->load();

			if ( $config === [] ) {
				// No config stored yet; run in array mode.
				$instance->bootArrayMode( 'config_empty' );
				return $instance;
			}

			$instance->config     = $config;
			$instance->prefix     = isset( $config['prefix'] ) && is_string( $config['prefix'] )
				? $instance->sanitizePrefix( (string) $config['prefix'] )
				: 'wpmgr';
			$instance->maxttl     = isset( $config['maxttl_seconds'] ) ? (int) $config['maxttl_seconds'] : 604800;
			$instance->queryttl   = isset( $config['queryttl_seconds'] ) ? (int) $config['queryttl_seconds'] : 86400;
			$instance->asyncFlush = isset( $config['async_flush'] ) && (bool) $config['async_flush'];
			$instance->flushStrategy = isset( $config['flush_strategy'] ) && is_string( $config['flush_strategy'] )
				? (string) $config['flush_strategy'] : 'auto';
			$instance->shared        = ! isset( $config['shared'] ) || (bool) $config['shared'];
			$instance->flushOnFailback = ! isset( $config['flush_on_failback'] ) || (bool) $config['flush_on_failback'];

			// Connect.
			$instance->connection = new \WPMgr\Agent\ObjectCache\RedisConnection( $config );
			$instance->redis      = $instance->connection->acquire();

			// Probe KEEPTTL support.
			$caps = \WPMgr\Agent\ObjectCache\RedisConnection::probeCapabilities( $instance->redis );
			$instance->keepttlSupported = (bool) ( $caps['keepttl_supported'] ?? false );

			// Metadata integrity check.
			$instance->checkMetadataIntegrity( $config );

		} catch ( \Throwable $e ) {
			$instance->bootArrayMode( $e->getMessage() );
		}

		return $instance;
	}

	/**
	 * Enter array-only mode (graceful degradation).
	 *
	 * @param string $reason Reason for the fallback (logged when WP_DEBUG is on).
	 * @return void
	 */
	private function bootArrayMode( string $reason ): void
	{
		$this->arrayMode  = true;
		$this->redis      = null;
		$this->connection = null;

		if ( $reason !== '' ) {
			$this->journalError( 'boot_failure', $reason );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG-gated diagnostic
				error_log( 'WPMgr Object Cache: degraded to array-only mode. Reason: ' . $reason );
			}
		}
	}

	// -------------------------------------------------------------------------
	// wp_cache_* API implementation
	// -------------------------------------------------------------------------

	/**
	 * Adds data to the cache if the key does not already exist.
	 *
	 * @param int|string $key    Cache key.
	 * @param mixed      $data   Data to store.
	 * @param string     $group  Cache group.
	 * @param int        $expire TTL in seconds.
	 * @return bool
	 */
	public function add( $key, $data, string $group = '', int $expire = 0 ): bool
	{
		if ( function_exists( 'wp_suspend_cache_addition' ) && wp_suspend_cache_addition() ) {
			return false;
		}
		if ( ! $this->validateKey( $key ) ) {
			return false;
		}
		$group   = $this->normalizeGroup( $group );
		$keyStr  = (string) $key;

		// L1 hit: key already exists.
		if ( isset( $this->cache[ $group ][ $keyStr ] ) ) {
			return false;
		}

		if ( $this->isNonPersistent( $group ) || $this->arrayMode ) {
			$this->storeL1( $group, $keyStr, $data );
			return true;
		}

		$redisKey = $this->buildKey( $keyStr, $group );
		$ttl      = $this->clampTtl( $expire, $group );

		return $this->redisOp(
			function () use ( $redisKey, $data, $ttl ): bool {
				$this->redisWrites++;
				if ( $ttl > 0 ) {
					$result = $this->redis->set( $redisKey, $data, [ 'nx', 'ex' => $ttl ] );
				} else {
					$result = $this->redis->set( $redisKey, $data, [ 'nx' ] );
				}
				return $result === true;
			},
			static function (): bool {
				return false;
			}
		) && $this->storeL1( $group, $keyStr, $data );
	}

	/**
	 * Adds multiple cache entries.
	 *
	 * @param array<int|string,mixed> $data   Map of key => value.
	 * @param string                  $group  Cache group.
	 * @param int                     $expire TTL in seconds.
	 * @return array<int|string,bool>
	 */
	public function add_multiple( array $data, string $group = '', int $expire = 0 ): array
	{
		$results = [];
		foreach ( $data as $key => $value ) {
			$results[ $key ] = $this->add( $key, $value, $group, $expire );
		}
		return $results;
	}

	/**
	 * Replaces cached data only when the key already exists.
	 *
	 * @param int|string $key    Cache key.
	 * @param mixed      $data   New data.
	 * @param string     $group  Cache group.
	 * @param int        $expire TTL in seconds.
	 * @return bool
	 */
	public function replace( $key, $data, string $group = '', int $expire = 0 ): bool
	{
		if ( ! $this->validateKey( $key ) ) {
			return false;
		}
		$group  = $this->normalizeGroup( $group );
		$keyStr = (string) $key;

		// Must already exist.
		$found  = null;
		$this->get( $key, $group, false, $found );
		if ( ! $found ) {
			return false;
		}

		if ( $this->isNonPersistent( $group ) || $this->arrayMode ) {
			$this->storeL1( $group, $keyStr, $data );
			return true;
		}

		$redisKey = $this->buildKey( $keyStr, $group );
		$ttl      = $this->clampTtl( $expire, $group );

		return $this->redisOp(
			function () use ( $redisKey, $data, $ttl ): bool {
				$this->redisWrites++;
				if ( $ttl > 0 ) {
					$result = $this->redis->set( $redisKey, $data, [ 'xx', 'ex' => $ttl ] );
				} else {
					$result = $this->redis->set( $redisKey, $data, [ 'xx' ] );
				}
				return $result === true;
			},
			static function (): bool {
				return false;
			}
		) && $this->storeL1( $group, $keyStr, $data );
	}

	/**
	 * Saves data to the cache.
	 *
	 * @param int|string $key    Cache key.
	 * @param mixed      $data   Data to store.
	 * @param string     $group  Cache group.
	 * @param int        $expire TTL in seconds (0 = use maxttl).
	 * @return bool
	 */
	public function set( $key, $data, string $group = '', int $expire = 0 ): bool
	{
		if ( ! $this->validateKey( $key ) ) {
			return false;
		}
		$group  = $this->normalizeGroup( $group );
		$keyStr = (string) $key;

		$this->storeL1( $group, $keyStr, $data );

		if ( $this->isNonPersistent( $group ) || $this->arrayMode ) {
			return true;
		}

		$redisKey = $this->buildKey( $keyStr, $group );
		$ttl      = $this->clampTtl( $expire, $group );

		return $this->redisOp(
			function () use ( $redisKey, $data, $ttl ): bool {
				$this->redisWrites++;
				if ( $ttl > 0 ) {
					return $this->redis->setex( $redisKey, $ttl, $data ) === true;
				}
				return $this->redis->set( $redisKey, $data ) !== false;
			},
			static function (): bool {
				return false;
			}
		);
	}

	/**
	 * Sets multiple cache entries using a pipeline.
	 *
	 * @param array<int|string,mixed> $data   Map of key => value.
	 * @param string                  $group  Cache group.
	 * @param int                     $expire TTL in seconds.
	 * @return array<int|string,bool>
	 */
	public function set_multiple( array $data, string $group = '', int $expire = 0 ): array
	{
		$group   = $this->normalizeGroup( $group );
		$results = [];

		if ( $this->isNonPersistent( $group ) || $this->arrayMode ) {
			foreach ( $data as $key => $value ) {
				if ( $this->validateKey( $key ) ) {
					$this->storeL1( $group, (string) $key, $value );
					$results[ $key ] = true;
				} else {
					$results[ $key ] = false;
				}
			}
			return $results;
		}

		// Validate and batch.
		$valid   = [];
		foreach ( $data as $key => $value ) {
			if ( $this->validateKey( $key ) ) {
				$valid[ (string) $key ] = $value;
				$this->storeL1( $group, (string) $key, $value );
				$results[ $key ] = false; // Pre-fill; overwritten on success.
			} else {
				$results[ $key ] = false;
			}
		}

		if ( $valid === [] ) {
			return $results;
		}

		$ttl = $this->clampTtl( $expire, $group );

		$this->redisOp(
			function () use ( $valid, $group, $ttl, &$results ): bool {
				$this->redisWrites += count( $valid );
				$pipe = $this->redis->pipeline();
				foreach ( $valid as $keyStr => $value ) {
					$redisKey = $this->buildKey( $keyStr, $group );
					if ( $ttl > 0 ) {
						$pipe->setex( $redisKey, $ttl, $value );
					} else {
						$pipe->set( $redisKey, $value );
					}
				}
				$pipeResults = $pipe->exec();
				if ( is_array( $pipeResults ) ) {
					$keys = array_keys( $valid );
					foreach ( $pipeResults as $i => $res ) {
						if ( isset( $keys[ $i ] ) ) {
							$results[ $keys[ $i ] ] = ( $res === true || $res === 'OK' );
						}
					}
				}
				return true;
			},
			static function () use ( &$results ): bool {
				// On failure all remain false.
				return false;
			}
		);

		return $results;
	}

	/**
	 * Retrieves cached data.
	 *
	 * @param int|string $key   Cache key.
	 * @param string     $group Cache group.
	 * @param bool       $force Bypass L1.
	 * @param bool|null  $found Output: whether the key was found.
	 * @return mixed False when not found.
	 */
	public function get( $key, string $group = '', bool $force = false, ?bool &$found = null )
	{
		if ( ! $this->validateKey( $key ) ) {
			$found = false;
			return false;
		}
		$group  = $this->normalizeGroup( $group );
		$keyStr = (string) $key;

		// L1 hit (unless forced).
		if ( ! $force && isset( $this->cache[ $group ][ $keyStr ] ) ) {
			$found = true;
			$this->cache_hits++;
			$this->hits++;
			return $this->cloneValue( $this->cache[ $group ][ $keyStr ] );
		}

		if ( $this->isNonPersistent( $group ) || $this->arrayMode ) {
			$found = false;
			$this->cache_misses++;
			$this->misses++;
			return false;
		}

		$redisKey = $this->buildKey( $keyStr, $group );

		$value = $this->redisOp(
			function () use ( $redisKey ): mixed {
				$this->redisReads++;
				$val = $this->redis->get( $redisKey );
				return $val;
			},
			static function (): mixed {
				return false;
			},
			true // idempotent read: retry-once on timeout
		);

		if ( $value === false ) {
			$found = false;
			$this->cache_misses++;
			$this->misses++;
			return false;
		}

		$found = true;
		$this->cache_hits++;
		$this->hits++;
		$this->storeL1( $group, $keyStr, $value );
		return $this->cloneValue( $value );
	}

	/**
	 * Retrieves multiple cached values using MGET.
	 *
	 * @param array<int|string> $keys  Cache keys.
	 * @param string            $group Cache group.
	 * @param bool              $force Bypass L1.
	 * @return array<int|string,mixed>
	 */
	public function get_multiple( array $keys, string $group = '', bool $force = false ): array
	{
		$group   = $this->normalizeGroup( $group );
		$results = [];

		if ( $this->isNonPersistent( $group ) || $this->arrayMode ) {
			foreach ( $keys as $key ) {
				if ( $this->validateKey( $key ) ) {
					$keyStr = (string) $key;
					$results[ $key ] = isset( $this->cache[ $group ][ $keyStr ] )
						? $this->cloneValue( $this->cache[ $group ][ $keyStr ] )
						: false;
				} else {
					$results[ $key ] = false;
				}
			}
			return $results;
		}

		// Partition: L1 hits vs Redis misses.
		$l1Results   = [];
		$redisKeys   = [];
		$redisKeyMap = []; // redisKey => original key

		foreach ( $keys as $key ) {
			if ( ! $this->validateKey( $key ) ) {
				$results[ $key ] = false;
				continue;
			}
			$keyStr = (string) $key;
			if ( ! $force && isset( $this->cache[ $group ][ $keyStr ] ) ) {
				$l1Results[ $key ] = $this->cloneValue( $this->cache[ $group ][ $keyStr ] );
				$this->cache_hits++;
				$this->hits++;
			} else {
				$redisKey = $this->buildKey( $keyStr, $group );
				$redisKeys[]              = $redisKey;
				$redisKeyMap[ $redisKey ] = $key;
				$results[ $key ]          = false; // Default to miss.
			}
		}

		// Merge L1 hits.
		foreach ( $l1Results as $key => $value ) {
			$results[ $key ] = $value;
		}

		if ( $redisKeys === [] ) {
			return $results;
		}

		$this->redisOp(
			function () use ( $redisKeys, $redisKeyMap, $group, &$results ): bool {
				$this->redisReads += count( $redisKeys );
				$fetched = $this->redis->mget( $redisKeys );
				if ( ! is_array( $fetched ) ) {
					return false;
				}
				foreach ( $redisKeys as $i => $redisKey ) {
					if ( ! isset( $fetched[ $i ] ) ) {
						continue;
					}
					$val = $fetched[ $i ];
					$origKey = $redisKeyMap[ $redisKey ] ?? null;
					if ( $origKey === null ) {
						continue;
					}
					if ( $val === false ) {
						$this->cache_misses++;
						$this->misses++;
					} else {
						$this->cache_hits++;
						$this->hits++;
						$this->storeL1( $group, (string) $origKey, $val );
						$results[ $origKey ] = $this->cloneValue( $val );
					}
				}
				return true;
			},
			function () use ( $redisKeyMap, &$results ): bool {
				foreach ( $redisKeyMap as $origKey ) {
					$this->cache_misses++;
					$this->misses++;
				}
				return false;
			},
			true
		);

		return $results;
	}

	/**
	 * Deletes cached data.
	 *
	 * @param int|string $key   Cache key.
	 * @param string     $group Cache group.
	 * @return bool
	 */
	public function delete( $key, string $group = '' ): bool
	{
		if ( ! $this->validateKey( $key ) ) {
			return false;
		}
		$group  = $this->normalizeGroup( $group );
		$keyStr = (string) $key;

		unset( $this->cache[ $group ][ $keyStr ] );

		if ( $this->isNonPersistent( $group ) || $this->arrayMode ) {
			return true;
		}

		$redisKey = $this->buildKey( $keyStr, $group );

		return $this->redisOp(
			function () use ( $redisKey ): bool {
				$this->redisWrites++;
				if ( $this->asyncFlush ) {
					return $this->redis->unlink( $redisKey ) >= 0;
				}
				return $this->redis->del( $redisKey ) >= 0;
			},
			static function (): bool {
				return false;
			}
		);
	}

	/**
	 * Deletes multiple cached entries.
	 *
	 * @param array<int|string> $keys  Cache keys.
	 * @param string            $group Cache group.
	 * @return array<int|string,bool>
	 */
	public function delete_multiple( array $keys, string $group = '' ): array
	{
		$group   = $this->normalizeGroup( $group );
		$results = [];

		foreach ( $keys as $key ) {
			$results[ $key ] = $this->delete( $key, $group );
		}
		return $results;
	}

	/**
	 * Increments a numeric cache item, preserving TTL via KEEPTTL where supported.
	 *
	 * @param int|string $key    Cache key.
	 * @param int        $offset Amount to increment.
	 * @param string     $group  Cache group.
	 * @return int|false New value or false on failure.
	 */
	public function incr( $key, int $offset = 1, string $group = '' )
	{
		if ( ! $this->validateKey( $key ) ) {
			return false;
		}
		$group  = $this->normalizeGroup( $group );
		$keyStr = (string) $key;

		if ( $this->isNonPersistent( $group ) || $this->arrayMode ) {
			$current = isset( $this->cache[ $group ][ $keyStr ] )
				? (int) $this->cache[ $group ][ $keyStr ] : 0;
			$new = max( 0, $current + $offset );
			$this->storeL1( $group, $keyStr, $new );
			return $new;
		}

		$redisKey = $this->buildKey( $keyStr, $group );

		$result = $this->redisOp(
			function () use ( $redisKey, $keyStr, $group, $offset ): int|false {
				$this->redisWrites++;
				if ( $this->keepttlSupported ) {
					// Get current value and TTL, then SET KEEPTTL.
					$current = $this->redis->get( $redisKey );
					$newVal  = max( 0, ( $current === false ? 0 : (int) $current ) + $offset );
					$ttl     = $this->redis->ttl( $redisKey );
					$opts    = [ 'keepttl' ];
					if ( $ttl > 0 ) {
						$opts = [ 'ex' => $ttl ];
					}
					$this->redis->set( $redisKey, $newVal, $opts );
					return $newVal;
				} else {
					// Fallback: INCRBY (does not preserve TTL, but is atomic).
					$newVal = $this->redis->incrBy( $redisKey, $offset );
					if ( $newVal < 0 ) {
						$this->redis->set( $redisKey, 0 );
						return 0;
					}
					return $newVal;
				}
			},
			static function (): false {
				return false;
			}
		);

		if ( $result !== false ) {
			$this->storeL1( $group, $keyStr, $result );
		} else {
			unset( $this->cache[ $group ][ $keyStr ] );
		}
		return $result;
	}

	/**
	 * Decrements a numeric cache item, preserving TTL via KEEPTTL where supported.
	 *
	 * @param int|string $key    Cache key.
	 * @param int        $offset Amount to decrement.
	 * @param string     $group  Cache group.
	 * @return int|false New value or false on failure.
	 */
	public function decr( $key, int $offset = 1, string $group = '' )
	{
		if ( ! $this->validateKey( $key ) ) {
			return false;
		}
		$group  = $this->normalizeGroup( $group );
		$keyStr = (string) $key;

		if ( $this->isNonPersistent( $group ) || $this->arrayMode ) {
			$current = isset( $this->cache[ $group ][ $keyStr ] )
				? (int) $this->cache[ $group ][ $keyStr ] : 0;
			$new = max( 0, $current - $offset );
			$this->storeL1( $group, $keyStr, $new );
			return $new;
		}

		$redisKey = $this->buildKey( $keyStr, $group );

		$result = $this->redisOp(
			function () use ( $redisKey, $offset ): int|false {
				$this->redisWrites++;
				if ( $this->keepttlSupported ) {
					$current = $this->redis->get( $redisKey );
					$newVal  = max( 0, ( $current === false ? 0 : (int) $current ) - $offset );
					$ttl     = $this->redis->ttl( $redisKey );
					$opts    = [ 'keepttl' ];
					if ( $ttl > 0 ) {
						$opts = [ 'ex' => $ttl ];
					}
					$this->redis->set( $redisKey, $newVal, $opts );
					return $newVal;
				} else {
					$newVal = $this->redis->decrBy( $redisKey, $offset );
					if ( $newVal < 0 ) {
						$this->redis->set( $redisKey, 0 );
						return 0;
					}
					return $newVal;
				}
			},
			static function (): false {
				return false;
			}
		);

		if ( $result !== false ) {
			$this->storeL1( $group, $keyStr, $result );
		} else {
			unset( $this->cache[ $group ][ $keyStr ] );
		}
		return $result;
	}

	/**
	 * Flushes the entire cache. Strategy: FLUSHDB on dedicated, SCAN+UNLINK on shared.
	 *
	 * @return bool
	 */
	public function flush(): bool
	{
		$this->cache = [];

		if ( $this->arrayMode ) {
			return true;
		}

		return $this->redisOp(
			function (): bool {
				return $this->executeFlush( 'all' );
			},
			static function (): bool {
				return false;
			}
		);
	}

	/**
	 * Flushes only the in-memory runtime cache.
	 *
	 * @return bool
	 */
	public function flush_runtime(): bool
	{
		$this->cache = [];
		return true;
	}

	/**
	 * Flushes all entries in a specific group.
	 *
	 * @param string $group Cache group.
	 * @return bool
	 */
	public function flush_group( string $group ): bool
	{
		$group = $this->normalizeGroup( $group );
		unset( $this->cache[ $group ] );

		if ( $this->arrayMode || $this->isNonPersistent( $group ) ) {
			return true;
		}

		return $this->redisOp(
			function () use ( $group ): bool {
				return $this->executeGroupFlush( $group );
			},
			static function (): bool {
				return false;
			}
		);
	}

	/**
	 * Initialises the cache (called from WordPress init hook).
	 *
	 * @return void
	 */
	public function init(): void
	{
		if ( isset( $GLOBALS['blog_id'] ) ) {
			$this->blogId = (int) $GLOBALS['blog_id'];
		}
	}

	/**
	 * Closes the connection. Work is deferred to shutdown(); this is a no-op.
	 *
	 * @return bool
	 */
	public function close(): bool
	{
		return true;
	}

	/**
	 * Shutdown hook: persist stats (for heartbeat), close connection.
	 *
	 * @return void
	 */
	public function shutdown(): void
	{
		try {
			$this->persistStats();
		} catch ( \Throwable $e ) {
			// Best-effort.
		}
		if ( $this->connection !== null ) {
			// pconnect handles stay pooled in the FPM worker; close is a no-op.
		}
	}

	/**
	 * Switches the blog context (multisite).
	 *
	 * @param int $blogId Blog ID to switch to.
	 * @return void
	 */
	public function switch_to_blog( int $blogId ): void
	{
		$this->blogId    = $blogId;
		$this->wildcardMemo = []; // Invalidate memos when blog changes.
	}

	/**
	 * Registers global groups.
	 *
	 * @param array<string> $groups Groups to add.
	 * @return void
	 */
	public function add_global_groups( array $groups ): void
	{
		foreach ( $groups as $group ) {
			if ( is_string( $group ) && $group !== '' ) {
				$this->globalGroups[ $group ] = true;
				// Memo invalidation: a late registration may change routing.
				unset( $this->wildcardMemo[ $group ] );
			}
		}
	}

	/**
	 * Registers non-persistent groups.
	 *
	 * @param array<string> $groups Groups to add.
	 * @return void
	 */
	public function add_non_persistent_groups( array $groups ): void
	{
		foreach ( $groups as $group ) {
			if ( is_string( $group ) && $group !== '' ) {
				$this->nonPersistentGroups[ $group ] = true;
				unset( $this->wildcardMemo[ $group ] );
			}
		}
	}

	/**
	 * Registers non-prefetchable groups (v2 stub; stored for future prefetch).
	 *
	 * @param array<string> $groups Groups to add.
	 * @return void
	 */
	public function add_non_prefetchable_groups( array $groups ): void
	{
		foreach ( $groups as $group ) {
			if ( is_string( $group ) ) {
				$this->nonPrefetchableGroups[] = $group;
			}
		}
	}

	/**
	 * Reports whether a specific feature is supported.
	 *
	 * @param string $feature Feature name.
	 * @return bool
	 */
	public function supports( string $feature ): bool
	{
		return in_array( $feature, self::SUPPORTS, true );
	}

	/**
	 * Whether the cache is in array-only (degraded) mode.
	 *
	 * @return bool
	 */
	public function isArrayMode(): bool
	{
		return $this->arrayMode;
	}

	/**
	 * Return the per-request error journal (for diagnostics/heartbeat).
	 *
	 * @return array<string>
	 */
	public function getErrorJournal(): array
	{
		return $this->errorJournal;
	}

	/**
	 * Return stats suitable for the heartbeat block.
	 *
	 * @return array<string,mixed>
	 */
	public function getHeartbeatStats(): array
	{
		$state = 'disabled';
		if ( $this->arrayMode && count( $this->errorJournal ) > 0 ) {
			$state = 'down';
		} elseif ( $this->arrayMode ) {
			$state = 'disabled';
		} elseif ( $this->connection !== null && $this->connection->isDegraded() ) {
			$state = 'degraded';
		} elseif ( $this->redis !== null ) {
			$state = 'connected';
		}

		$totalOps = $this->cache_hits + $this->cache_misses;
		$hitRatio = $totalOps > 0 ? round( $this->cache_hits / $totalOps * 100, 1 ) : 0.0;
		$latencyMs = $this->redisReads + $this->redisWrites > 0
			? round( $this->totalWaitMs / ( $this->redisReads + $this->redisWrites ), 2 )
			: 0.0;

		$lastError = $this->errorJournal !== [] ? $this->errorJournal[ count( $this->errorJournal ) - 1 ] : '';

		$stats = [
			'state'              => $state,
			'latency_ms'         => $latencyMs,
			'last_error_class'   => $lastError,
			'hit_ratio_window_pct' => $hitRatio,
			'engine_version'     => self::ENGINE_VERSION,
		];

		// used_memory_bytes: attempt a live INFO query (best-effort, no extra cost
		// if INFO is denied or throws).
		if ( $this->redis !== null && ! $this->arrayMode ) {
			try {
				$info = @$this->redis->info( 'memory' );
				if ( is_array( $info ) && isset( $info['used_memory'] ) ) {
					$stats['used_memory_bytes'] = (int) $info['used_memory'];
				}
			} catch ( \Throwable $e ) {
				// Best-effort; omit the field on denial.
			}
		}

		return $stats;
	}

	// -------------------------------------------------------------------------
	// Internal: key building, group classification, TTL, L1
	// -------------------------------------------------------------------------

	/**
	 * Build a fully-qualified Redis key.
	 * Shape: prefix:[blogId:]group:key
	 *
	 * @param string $key   Cache key (already validated as string).
	 * @param string $group Normalized group name.
	 * @return string
	 */
	private function buildKey( string $key, string $group ): string
	{
		$isGlobal = isset( $this->globalGroups[ $group ] );
		$prefix   = $this->prefix;

		if ( $isGlobal ) {
			return $prefix . ':' . $group . ':' . $key;
		}
		return $prefix . ':' . $this->blogId . ':' . $group . ':' . $key;
	}

	/**
	 * Normalize a group string: trim + default to 'default'.
	 *
	 * @param string $group Raw group.
	 * @return string
	 */
	private function normalizeGroup( string $group ): string
	{
		$group = trim( $group );
		return $group !== '' ? $group : 'default';
	}

	/**
	 * Sanitize and truncate the prefix to 32 characters, replacing unsafe chars.
	 *
	 * @param string $prefix Raw prefix.
	 * @return string
	 */
	private function sanitizePrefix( string $prefix ): string
	{
		$prefix = preg_replace( '/[^a-zA-Z0-9_-]/', '_', $prefix ) ?? 'wpmgr';
		$prefix = substr( $prefix, 0, 32 );
		// An empty prefix after sanitization defeats shared-Redis namespacing
		// and makes SCAN `:*` flush cross site boundaries. Fall back to 'wpmgr'.
		return $prefix !== '' ? $prefix : 'wpmgr';
	}

	/**
	 * Whether a group is non-persistent (runtime-only).
	 * Supports fnmatch wildcards in registered group names; results are memoized.
	 *
	 * @param string $group Normalized group.
	 * @return bool
	 */
	private function isNonPersistent( string $group ): bool
	{
		if ( isset( $this->nonPersistentGroups[ $group ] ) ) {
			return true;
		}
		// Wildcard match (memoized).
		if ( array_key_exists( 'np_' . $group, $this->wildcardMemo ) ) {
			return $this->wildcardMemo[ 'np_' . $group ];
		}
		foreach ( array_keys( $this->nonPersistentGroups ) as $pattern ) {
			if ( strpos( $pattern, '*' ) !== false && fnmatch( $pattern, $group ) ) {
				$this->wildcardMemo[ 'np_' . $group ] = true;
				return true;
			}
		}
		$this->wildcardMemo[ 'np_' . $group ] = false;
		return false;
	}

	/**
	 * Clamp a TTL: negative => 0 (delete), 0 or > maxttl => maxttl.
	 * Query groups get min(queryttl, maxttl).
	 *
	 * @param int    $ttl   Requested TTL.
	 * @param string $group Normalized group.
	 * @return int
	 */
	private function clampTtl( int $ttl, string $group ): int
	{
		if ( $ttl < 0 ) {
			return 1; // Treat negative as "expire immediately".
		}

		// Query groups.
		if ( strpos( $group, '-queries' ) !== false ) {
			$limit = min( $this->queryttl, $this->maxttl );
			if ( $ttl === 0 || $ttl > $limit ) {
				return $limit;
			}
			return $ttl;
		}

		if ( $ttl === 0 || $ttl > $this->maxttl ) {
			return $this->maxttl;
		}
		return $ttl;
	}

	/**
	 * Store a value in the L1 array cache with clone-on-store.
	 *
	 * @param string $group  Normalized group.
	 * @param string $keyStr Key string.
	 * @param mixed  $value  Value to store.
	 * @return bool Always true (for fluent chaining).
	 */
	private function storeL1( string $group, string $keyStr, mixed $value ): bool
	{
		$this->cache[ $group ][ $keyStr ] = $this->cloneValue( $value );
		return true;
	}

	/**
	 * Clone an object or return a scalar/array as-is. Clone-on-read/store
	 * prevents by-reference mutation leaks.
	 *
	 * @param mixed $value Value to clone.
	 * @return mixed
	 */
	private function cloneValue( mixed $value ): mixed
	{
		return is_object( $value ) ? clone $value : $value;
	}

	/**
	 * Validate that a key is a string or integer. Non-valid keys are journaled.
	 *
	 * @param mixed $key Raw key.
	 * @return bool
	 */
	private function validateKey( mixed $key ): bool
	{
		if ( is_int( $key ) || is_string( $key ) ) {
			return true;
		}
		$this->journalError( 'invalid_key', 'key must be int or string; got ' . gettype( $key ) );
		return false;
	}

	// -------------------------------------------------------------------------
	// Internal: Redis operation wrapper with degradation
	// -------------------------------------------------------------------------

	/**
	 * Execute a Redis operation with per-op try/catch degradation.
	 *
	 * On failure: journal the error, try reconnect-once (only for idempotent
	 * reads), then fall back to the $onError result for the remainder of the
	 * request. The site never errors.
	 *
	 * @template T
	 * @param callable(): T $op          Redis operation.
	 * @param callable(): T $onError     Fallback when degraded.
	 * @param bool          $idempotent  Whether a read-timeout retry is safe.
	 * @return T
	 */
	private function redisOp( callable $op, callable $onError, bool $idempotent = false ): mixed
	{
		if ( $this->arrayMode || $this->redis === null || $this->connection === null ) {
			return $onError();
		}

		$t0 = microtime( true );

		try {
			$result = $op();
			$this->totalWaitMs += ( microtime( true ) - $t0 ) * 1000.0;
			$this->connection->recordSuccess();

			// Failback flush: if we were degraded and are now healthy again.
			if ( $this->flushOnFailback && ! $this->failbackFlushed && $this->connection->isDegraded() === false ) {
				// The connection is now healthy; schedule a flush.
				$this->executeFailbackFlush();
			}

			return $result;

		} catch ( \Throwable $e ) {
			$this->totalWaitMs += ( microtime( true ) - $t0 ) * 1000.0;
			$this->journalError( get_class( $e ), $e->getMessage() );

			// Attempt reconnect-once per request for idempotent reads.
			if ( $idempotent && ! $this->reconnectAttempted && $this->connection !== null ) {
				$this->reconnectAttempted = true;
				$this->connection->markDegraded();
				try {
					$this->redis = $this->connection->acquire();
					$t1 = microtime( true );
					$result = $op();
					$this->totalWaitMs += ( microtime( true ) - $t1 ) * 1000.0;
					return $result;
				} catch ( \Throwable $e2 ) {
					$this->journalError( 'reconnect_failed', $e2->getMessage() );
				}
			}

			$this->connection->markDegraded();
			return $onError();
		}
	}

	// -------------------------------------------------------------------------
	// Internal: flush strategies
	// -------------------------------------------------------------------------

	/**
	 * Execute the flush strategy for a full or site-scoped flush.
	 * FLUSHALL is never issued.
	 *
	 * @param string $scope 'all' | 'site' | 'group' (group handled separately).
	 * @return bool
	 */
	private function executeFlush( string $scope ): bool
	{
		$useFlushDb = false;

		if ( $this->flushStrategy === 'flushdb' && ! $this->shared ) {
			$useFlushDb = true;
		} elseif ( $this->flushStrategy === 'auto' && ! $this->shared ) {
			$useFlushDb = true;
		}

		if ( $useFlushDb ) {
			if ( $this->asyncFlush ) {
				$this->redis->flushDB( true );
			} else {
				$this->redis->flushDB( false );
			}
			return true;
		}

		// Shared or scan-only: SCAN+MATCH+UNLINK prefix-scoped.
		return $this->executeScanFlush( $this->prefix . ':' );
	}

	/**
	 * Execute a SCAN+MATCH+UNLINK flush scoped to the given pattern prefix.
	 * COUNT 500, inter-batch sleep (0.5ms) to bound instance impact.
	 *
	 * Uses the canonical phpredis SCAN idiom: by-ref integer iterator and
	 * SCAN_RETRY so phpredis handles empty-batch re-scanning internally,
	 * returning a flat key array (not the [cursor, keys] tuple used by Predis).
	 *
	 * @param string $prefixPattern Key prefix to match (e.g. "wpmgr:").
	 * @return bool
	 */
	private function executeScanFlush( string $prefixPattern ): bool
	{
		if ( $this->redis === null ) {
			return false;
		}

		$this->redis->setOption( \Redis::OPT_SCAN, \Redis::SCAN_RETRY );
		$it      = null;
		$pattern = $prefixPattern . '*';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_scan -- phpredis SCAN command, not filesystem; by-ref iterator is the canonical phpredis pattern
		while ( ( $keys = $this->redis->scan( $it, $pattern, 500 ) ) !== false ) {
			if ( ! empty( $keys ) ) {
				if ( $this->asyncFlush ) {
					$this->redis->unlink( ...$keys );
				} else {
					$this->redis->del( ...$keys );
				}
				usleep( 500 ); // 0.5ms inter-batch sleep to reduce instance impact.
			}
			if ( $it === 0 ) {
				break;
			}
		}

		return true;
	}

	/**
	 * Flush all keys for a specific group via SCAN+MATCH+UNLINK.
	 *
	 * The SCAN globs use '*' to span blog-ID and key segments, but '*' in Redis
	 * glob spans ':' — so the pattern `prefix:*:post:*` also matches a key like
	 * `prefix:1:postmeta:key` if the group substring appears as an interior
	 * token. Post-filter each SCAN batch: only UNLINK keys whose colon-delimited
	 * segments contain the exact group token at the correct position.
	 *
	 * Key shapes:
	 *   Global:   prefix:group:key
	 *   Per-blog: prefix:blogId:group:key
	 *
	 * @param string $group Normalized group.
	 * @return bool
	 */
	private function executeGroupFlush( string $group ): bool
	{
		// Match both global (no blog segment) and per-blog variants.
		$globalPattern = $this->prefix . ':' . $group . ':';
		$blogPattern   = $this->prefix . ':*:' . $group . ':';

		$this->executeScanFlushWithGroupFilter( $globalPattern, $group, false );
		$this->executeScanFlushWithGroupFilter( $blogPattern, $group, true );

		return true;
	}

	/**
	 * SCAN+MATCH+UNLINK with exact group-segment post-filter.
	 *
	 * After each SCAN batch the keys are filtered to those where the group
	 * token sits at the exact colon-segment position:
	 *   $hasBlogSegment=false: prefix:group:key     => segment index 1
	 *   $hasBlogSegment=true:  prefix:blogId:group:key => segment index 2
	 *
	 * @param string $prefixPattern SCAN MATCH pattern.
	 * @param string $group         Exact group name to confirm.
	 * @param bool   $hasBlogSegment Whether the pattern includes a blog-ID wildcard.
	 * @return void
	 */
	private function executeScanFlushWithGroupFilter( string $prefixPattern, string $group, bool $hasBlogSegment ): void
	{
		if ( $this->redis === null ) {
			return;
		}

		$pattern = $prefixPattern . '*';
		// Group segment index in the colon-delimited key:
		// Global key:   0=prefix, 1=group, 2+=key
		// Per-blog key: 0=prefix, 1=blogId, 2=group, 3+=key
		$groupSegmentIndex = $hasBlogSegment ? 2 : 1;

		// Canonical phpredis SCAN idiom: by-ref integer iterator, SCAN_RETRY,
		// flat key array return (not the [cursor, keys] tuple used by Predis).
		$this->redis->setOption( \Redis::OPT_SCAN, \Redis::SCAN_RETRY );
		$it = null;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_scan -- phpredis SCAN command, not filesystem; by-ref iterator is the canonical phpredis pattern
		while ( ( $keys = $this->redis->scan( $it, $pattern, 500 ) ) !== false ) {
			if ( ! empty( $keys ) ) {
				// Post-filter: confirm the key's group segment is an exact match.
				$confirmed = [];
				foreach ( $keys as $k ) {
					$parts = explode( ':', (string) $k );
					if ( isset( $parts[ $groupSegmentIndex ] ) && $parts[ $groupSegmentIndex ] === $group ) {
						$confirmed[] = $k;
					}
				}
				if ( $confirmed !== [] ) {
					if ( $this->asyncFlush ) {
						$this->redis->unlink( ...$confirmed );
					} else {
						$this->redis->del( ...$confirmed );
					}
				}
				usleep( 500 ); // 0.5ms inter-batch sleep to reduce instance impact.
			}
			if ( $it === 0 ) {
				break;
			}
		}
	}

	/**
	 * Flush on failback: executed once per request after connection recovery.
	 *
	 * @return void
	 */
	private function executeFailbackFlush(): void
	{
		$this->failbackFlushed = true;
		try {
			$this->executeFlush( 'all' );
		} catch ( \Throwable $e ) {
			$this->journalError( 'failback_flush_failed', $e->getMessage() );
		}
	}

	// -------------------------------------------------------------------------
	// Internal: metadata integrity
	// -------------------------------------------------------------------------

	/**
	 * Metadata integrity key. Written raw (no serializer/compression) so it
	 * survives serializer changes. maxttl-exempt.
	 *
	 * @return string
	 */
	private function metadataKey(): string
	{
		return $this->prefix . ':__wpmgr_oc_meta__';
	}

	/**
	 * Check the metadata integrity key. If risky options changed, flush and
	 * rewrite the metadata key.
	 *
	 * @param array<string,mixed> $config Current config.
	 * @return void
	 */
	private function checkMetadataIntegrity( array $config ): void
	{
		if ( $this->redis === null ) {
			return;
		}

		$metaKey = $this->metadataKey();

		// Read metadata using a raw (no-serializer) client to survive format changes.
		try {
			// Temporarily switch to no-serializer for the raw read.
			$savedSerializer = $this->redis->getOption( \Redis::OPT_SERIALIZER );
			$this->redis->setOption( \Redis::OPT_SERIALIZER, (string) \Redis::SERIALIZER_NONE );

			$stored = $this->redis->get( $metaKey );
			$this->redis->setOption( \Redis::OPT_SERIALIZER, $savedSerializer );

			if ( $stored !== false && is_string( $stored ) ) {
				$meta = json_decode( $stored, true );
				if ( is_array( $meta ) ) {
					$riskyChanged = false;
					if ( isset( $meta['serializer'] ) && $meta['serializer'] !== ( $config['serializer'] ?? 'php' ) ) {
						$riskyChanged = true;
					}
					if ( isset( $meta['compression'] ) && $meta['compression'] !== ( $config['compression'] ?? 'none' ) ) {
						$riskyChanged = true;
					}
					if ( isset( $meta['database'] ) && (int) $meta['database'] !== (int) ( $config['database'] ?? 0 ) ) {
						$riskyChanged = true;
					}
					if ( $riskyChanged ) {
						// Integrity flush: risky option changed.
						$this->executeFlush( 'all' );
						if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
							// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG-gated diagnostic
							error_log( 'WPMgr Object Cache: integrity flush triggered by config change.' );
						}
					}
				}
			}

			// Write/rewrite the metadata key (raw bytes, no TTL).
			$newMeta = (string) wp_json_encode( [
				'database'    => (int) ( $config['database'] ?? 0 ),
				'prefix'      => $this->prefix,
				'serializer'  => $config['serializer'] ?? 'php',
				'compression' => $config['compression'] ?? 'none',
				'wp_version'  => isset( $GLOBALS['wp_version'] ) ? (string) $GLOBALS['wp_version'] : '',
			] );
			$this->redis->setOption( \Redis::OPT_SERIALIZER, (string) \Redis::SERIALIZER_NONE );
			$this->redis->set( $metaKey, $newMeta ); // maxttl-exempt: no TTL.
			$this->redis->setOption( \Redis::OPT_SERIALIZER, $savedSerializer );

		} catch ( \Throwable $e ) {
			// Tolerate metadata key failures gracefully.
			$this->journalError( 'metadata_integrity_failed', $e->getMessage() );
		}
	}

	// -------------------------------------------------------------------------
	// Internal: stats persistence
	// -------------------------------------------------------------------------

	/**
	 * Persist aggregated stats for the heartbeat block.
	 *
	 * ACCUMULATES this request's counters into the wp-option so that the
	 * heartbeat can consume window-delta values (hit_count, miss_count,
	 * ops, wait_ms) across multiple requests between heartbeat pushes.
	 * The heartbeat consumer reads the accumulated deltas and resets them
	 * (consume-and-reset pattern).
	 *
	 * The STATE SNAPSHOT fields (state, latency_ms, last_error_class,
	 * hit_ratio_window_pct, engine_version) are persisted UNCONDITIONALLY so
	 * the heartbeat always has a fresh snapshot to report, even when analytics
	 * is disabled. The delta accumulation fields are gated on analytics_enabled.
	 * Missing analytics_enabled is treated as ON (matching the m68 default).
	 *
	 * @return void
	 */
	private function persistStats(): void
	{
		if ( ! function_exists( 'update_option' ) || ! function_exists( 'get_option' ) ) {
			return;
		}

		// Compute per-request snapshot fields (state, latency, last error).
		// These are written unconditionally so the heartbeat can always read
		// a fresh live snapshot, independent of the analytics setting.
		$snapshot = $this->getHeartbeatStats();

		// Read the existing accumulated option (default empty array).
		$existing = get_option( 'wpmgr_object_cache_stats', [] );
		if ( ! is_array( $existing ) ) {
			$existing = [];
		}

		// Analytics-gated: accumulate delta counters only when analytics is on.
		// Missing analytics_enabled is treated as ON (the default).
		$analyticsOn = ! isset( $this->config['analytics_enabled'] ) || (bool) $this->config['analytics_enabled'];

		if ( $analyticsOn ) {
			// Accumulate cumulative delta counters into the stored option.
			// These are consumed-and-reset by ObjectCacheHeartbeat::build().
			$totalOps = $this->redisReads + $this->redisWrites;

			$merged = array_merge( $snapshot, [
				// Carry forward any unconsumed deltas from prior requests.
				'delta_hit_count'   => ( isset( $existing['delta_hit_count'] ) ? (int) $existing['delta_hit_count'] : 0 )
					+ $this->cache_hits,
				'delta_miss_count'  => ( isset( $existing['delta_miss_count'] ) ? (int) $existing['delta_miss_count'] : 0 )
					+ $this->cache_misses,
				'delta_ops'         => ( isset( $existing['delta_ops'] ) ? (int) $existing['delta_ops'] : 0 )
					+ $totalOps,
				'delta_wait_ms'     => ( isset( $existing['delta_wait_ms'] ) ? (float) $existing['delta_wait_ms'] : 0.0 )
					+ $this->totalWaitMs,
				'delta_sample_count' => ( isset( $existing['delta_sample_count'] ) ? (int) $existing['delta_sample_count'] : 0 )
					+ ( $totalOps > 0 ? 1 : 0 ),
				// Timestamp of the first un-consumed delta (for ops_per_sec calculation).
				'delta_since_ts'    => isset( $existing['delta_since_ts'] ) && (float) $existing['delta_since_ts'] > 0
					? (float) $existing['delta_since_ts']
					: microtime( true ),
			] );
		} else {
			// Analytics off: persist only the state snapshot; preserve any
			// existing unconsumed delta fields so they are not silently lost.
			$merged = array_merge( $existing, $snapshot );
		}

		update_option( 'wpmgr_object_cache_stats', $merged, false );
	}

	// -------------------------------------------------------------------------
	// Internal: error journal
	// -------------------------------------------------------------------------

	/**
	 * Add an entry to the per-request error journal.
	 *
	 * @param string $class   Error class name.
	 * @param string $message Error message.
	 * @return void
	 */
	private function journalError( string $class, string $message ): void
	{
		if ( count( $this->errorJournal ) >= self::MAX_JOURNAL ) {
			array_shift( $this->errorJournal );
		}
		$this->errorJournal[] = $class;
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG-gated diagnostic
			error_log( 'WPMgr Object Cache error [' . $class . ']: ' . $message );
		}
	}
}
	}

} // end namespace (WPMgr_Object_Cache class)

namespace {

/**
 * WPMgr Object Cache engine — implements the full WordPress wp_cache_* API
 * backed by phpredis with graceful degradation to a pure in-memory array cache.
 *
 * This file is included from the object-cache.php drop-in stub. It:
 *   1. Loads the supporting classes (autoloader may not be available this early).
 *   2. Builds the config from the 0600 config file.
 *   3. Attempts to connect; on any boot Throwable, falls back to a pure-array
 *      cache so the site never errors.
 *   4. Instantiates the global $wp_object_cache and registers the shutdown hook.
 *
 * @package WPMgr\Agent\ObjectCache
 */

// ---------------------------------------------------------------------------
// Boot the cache: try Redis, fall back to pure array on any Throwable.
// ---------------------------------------------------------------------------

/**
 * Returns the global WP Object Cache instance, booting it if necessary.
 *
 * @return \WPMgr_Object_Cache
 */
function wpmgr_get_object_cache(): \WPMgr_Object_Cache
{
	global $wp_object_cache;
	if ( ! ( $wp_object_cache instanceof \WPMgr_Object_Cache ) ) {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- object-cache drop-ins MUST assign $wp_object_cache; this is the required WP pattern
		$wp_object_cache = \WPMgr_Object_Cache::boot();
	}
	return $wp_object_cache;
}

// Boot now and install the shutdown hook for stats persist + close.
global $wp_object_cache;
// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- object-cache drop-ins MUST assign $wp_object_cache; this is the required WP pattern
$wp_object_cache = \WPMgr_Object_Cache::boot();

register_shutdown_function(
	static function (): void {
		global $wp_object_cache;
		if ( $wp_object_cache instanceof \WPMgr_Object_Cache ) {
			$wp_object_cache->shutdown();
		}
	}
);

// ---------------------------------------------------------------------------
// WordPress wp_cache_* function bridge.
// WordPress defines these functions in wp-includes/cache.php ONLY when an
// object-cache drop-in is NOT present. Since we ARE the drop-in we must
// define them all here. All names are mandated by the WordPress cache API;
// they cannot carry a plugin prefix — PrefixAllGlobals is disabled for
// this bridge section only.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- required WordPress object-cache drop-in API; function names are not ours to choose
// ---------------------------------------------------------------------------

/**
 * Adds data to the cache if the key doesn't already exist.
 *
 * @param int|string $key    Cache key.
 * @param mixed      $data   Data to store.
 * @param string     $group  Cache group.
 * @param int        $expire TTL in seconds.
 * @return bool
 */
function wp_cache_add( $key, $data, $group = '', $expire = 0 ): bool
{
	return wpmgr_get_object_cache()->add( $key, $data, $group, $expire );
}

/**
 * Adds multiple cache entries.
 *
 * @param array<int|string,mixed> $data   Map of key => value.
 * @param string                  $group  Cache group.
 * @param int                     $expire TTL in seconds.
 * @return array<int|string,bool>
 */
function wp_cache_add_multiple( array $data, $group = '', $expire = 0 ): array
{
	return wpmgr_get_object_cache()->add_multiple( $data, $group, $expire );
}

/**
 * Replaces the cached data only when it already exists.
 *
 * @param int|string $key    Cache key.
 * @param mixed      $data   New data.
 * @param string     $group  Cache group.
 * @param int        $expire TTL in seconds.
 * @return bool
 */
function wp_cache_replace( $key, $data, $group = '', $expire = 0 ): bool
{
	return wpmgr_get_object_cache()->replace( $key, $data, $group, $expire );
}

/**
 * Saves data to the cache.
 *
 * @param int|string $key    Cache key.
 * @param mixed      $data   Data to store.
 * @param string     $group  Cache group.
 * @param int        $expire TTL in seconds.
 * @return bool
 */
function wp_cache_set( $key, $data, $group = '', $expire = 0 ): bool
{
	return wpmgr_get_object_cache()->set( $key, $data, $group, $expire );
}

/**
 * Sets multiple cache entries.
 *
 * @param array<int|string,mixed> $data   Map of key => value.
 * @param string                  $group  Cache group.
 * @param int                     $expire TTL in seconds.
 * @return array<int|string,bool>
 */
function wp_cache_set_multiple( array $data, $group = '', $expire = 0 ): array
{
	return wpmgr_get_object_cache()->set_multiple( $data, $group, $expire );
}

/**
 * Retrieves cached data.
 *
 * @param int|string $key   Cache key.
 * @param string     $group Cache group.
 * @param bool       $force Force a fresh fetch from the backend.
 * @param bool|null  $found Output: whether the key was found.
 * @return mixed False when not found.
 */
function wp_cache_get( $key, $group = '', $force = false, &$found = null )
{
	return wpmgr_get_object_cache()->get( $key, $group, $force, $found );
}

/**
 * Retrieves multiple cached values.
 *
 * @param array<int|string>  $keys  Cache keys.
 * @param string             $group Cache group.
 * @param bool               $force Force fetch.
 * @return array<int|string,mixed>
 */
function wp_cache_get_multiple( $keys, $group = '', $force = false ): array
{
	return wpmgr_get_object_cache()->get_multiple( $keys, $group, $force );
}

/**
 * Deletes cached data.
 *
 * @param int|string $key   Cache key.
 * @param string     $group Cache group.
 * @return bool
 */
function wp_cache_delete( $key, $group = '' ): bool
{
	return wpmgr_get_object_cache()->delete( $key, $group );
}

/**
 * Deletes multiple cached entries.
 *
 * @param array<int|string> $keys  Cache keys.
 * @param string            $group Cache group.
 * @return array<int|string,bool>
 */
function wp_cache_delete_multiple( array $keys, $group = '' ): array
{
	return wpmgr_get_object_cache()->delete_multiple( $keys, $group );
}

/**
 * Increments a numeric cache item.
 *
 * @param int|string $key    Cache key.
 * @param int        $offset Amount to increment.
 * @param string     $group  Cache group.
 * @return int|false New value or false on failure.
 */
function wp_cache_incr( $key, $offset = 1, $group = '' )
{
	return wpmgr_get_object_cache()->incr( $key, $offset, $group );
}

/**
 * Decrements a numeric cache item.
 *
 * @param int|string $key    Cache key.
 * @param int        $offset Amount to decrement.
 * @param string     $group  Cache group.
 * @return int|false New value or false on failure.
 */
function wp_cache_decr( $key, $offset = 1, $group = '' )
{
	return wpmgr_get_object_cache()->decr( $key, $offset, $group );
}

/**
 * Flushes the entire object cache.
 *
 * @return bool
 */
function wp_cache_flush(): bool
{
	return wpmgr_get_object_cache()->flush();
}

/**
 * Flushes only the in-memory runtime cache (not the persistent backend).
 *
 * @return bool
 */
function wp_cache_flush_runtime(): bool
{
	return wpmgr_get_object_cache()->flush_runtime();
}

/**
 * Flushes all entries in a specific cache group.
 *
 * @param string $group Cache group.
 * @return bool
 */
function wp_cache_flush_group( $group ): bool
{
	return wpmgr_get_object_cache()->flush_group( $group );
}

/**
 * Initialises the cache. Called by WordPress on init.
 *
 * @return void
 */
function wp_cache_init(): void
{
	wpmgr_get_object_cache()->init();
}

/**
 * Closes the cache connection. Called at shutdown.
 *
 * @return bool
 */
function wp_cache_close(): bool
{
	return wpmgr_get_object_cache()->close();
}

/**
 * Switches the blog context in multisite.
 *
 * @param int $blog_id Blog ID to switch to.
 * @return void
 */
function wp_cache_switch_to_blog( $blog_id ): void
{
	wpmgr_get_object_cache()->switch_to_blog( (int) $blog_id );
}

/**
 * Adds a list of groups that should share a global namespace.
 *
 * @param array<string>|string $groups Groups to add.
 * @return void
 */
function wp_cache_add_global_groups( $groups ): void
{
	wpmgr_get_object_cache()->add_global_groups( (array) $groups );
}

/**
 * Adds a list of groups that should not be backed by the persistent cache.
 *
 * @param array<string>|string $groups Groups to add.
 * @return void
 */
function wp_cache_add_non_persistent_groups( $groups ): void
{
	wpmgr_get_object_cache()->add_non_persistent_groups( (array) $groups );
}

/**
 * Registers groups that should not be prefetched (v2 stub).
 *
 * @param array<string>|string $groups Groups to register.
 * @return void
 */
function wp_cache_add_non_prefetchable_groups( $groups ): void
{
	wpmgr_get_object_cache()->add_non_prefetchable_groups( (array) $groups );
}

/**
 * Reports whether a specific feature is supported.
 *
 * @param string $feature Feature name.
 * @return bool
 */
function wp_cache_supports( $feature ): bool
{
	return wpmgr_get_object_cache()->supports( (string) $feature );
}

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound

} // end namespace (boot + wp_cache_* functions)
