<?php
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

declare(strict_types=1);

namespace WPMgr\Agent\ObjectCache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages a phpredis persistent connection with timeouts, retries, and TLS.
 */
final class RedisConnection
{
	/** Idle threshold in seconds before a PING-on-acquire is issued. */
	private const PING_AFTER_IDLE_SECONDS = 60;

	/** Maximum jitter sleep in microseconds per retry (cap = connect_timeout). */
	private const JITTER_BASE_US = 25000;

	/**
	 * FD-5: Maximum pconnect calls allowed per PHP process lifetime.
	 * Exceeding this threshold causes connect() to throw 'connect_budget_exhausted'
	 * without dialing, converting any loop bug into one slow request rather than EMFILE.
	 */
	private const MAX_DIALS_PER_REQUEST = 12;

	/** FD-5: Per-process pconnect attempt counter. */
	private static int $dialCount = 0;

	/** phpredis client instance; null when not yet connected. */
	private ?\Redis $redis = null;

	/** Whether we are in a degraded (failed) state for this request. */
	private bool $degraded = false;

	/**
	 * Whether markDegraded() has EVER been called on this instance.
	 * Set by markDegraded(); never cleared by recordSuccess() or acquire().
	 * Used by the engine to distinguish a genuine recovery (was degraded, now
	 * healthy) from a request that was healthy all along.
	 */
	private bool $wasDegraded = false;

	/** Timestamp of the last successful command. */
	private float $lastUsed = 0.0;

	/** Number of reconnect attempts made this request. */
	private int $reconnectAttempts = 0;

	/** @var array<string,mixed> Connection config. */
	private array $config;

	/**
	 * FD-4: Effective serializer after codec negotiation (may differ from configured value).
	 * Recorded by applyClientOptions() for use by checkMetadataIntegrity().
	 */
	private string $effectiveSerializer = 'php';

	/**
	 * FD-4: Effective compression after codec negotiation (may differ from configured value).
	 * Recorded by applyClientOptions() for use by checkMetadataIntegrity().
	 */
	private string $effectiveCompression = 'none';

	/**
	 * FD-4: Non-empty when a codec fallback occurred; describes the cause
	 * (e.g. 'igbinary_unavailable', 'zstd_unavailable'). Empty on clean connect.
	 */
	private string $codecFallbackCause = '';

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

		// FD-3b: close the degraded handle before dialing a fresh one.
		if ( $this->redis !== null ) {
			try {
				$this->redis->close();
			} catch ( \Throwable $closeEx ) {
				// Best-effort close; proceed to reconnect regardless.
			}
			$this->redis = null;
		}

		$this->redis = $this->connect();
		$this->degraded = false;
		$this->lastUsed = microtime( true );
		return $this->redis;
	}

	/**
	 * Mark the connection degraded. Subsequent acquire() will reconnect once.
	 * Also sets the permanent wasDegraded flag so the engine can detect recovery.
	 *
	 * @return void
	 */
	public function markDegraded(): void
	{
		$this->degraded    = true;
		$this->wasDegraded = true;
	}

	/**
	 * Whether markDegraded() has ever been called on this instance.
	 * Never reset by recordSuccess() — stays true for the lifetime of the object.
	 * The engine uses this alongside isDegraded() to detect a genuine recovery:
	 *   wasDegraded() === true  &&  isDegraded() === false  => just recovered.
	 *
	 * @return bool
	 */
	public function wasDegraded(): bool
	{
		return $this->wasDegraded;
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

	/**
	 * FD-4: Return the effective serializer after codec negotiation.
	 * May differ from the configured value when a fallback occurred.
	 *
	 * @return string
	 */
	public function effectiveSerializer(): string
	{
		return $this->effectiveSerializer;
	}

	/**
	 * FD-4: Return the effective compression after codec negotiation.
	 * May differ from the configured value when a fallback occurred.
	 *
	 * @return string
	 */
	public function effectiveCompression(): string
	{
		return $this->effectiveCompression;
	}

	/**
	 * FD-4: Return the codec fallback cause, or '' when no fallback occurred.
	 *
	 * @return string
	 */
	public function codecFallbackCause(): string
	{
		return $this->codecFallbackCause;
	}

	/**
	 * FD-5: Return the current per-process dial count (for tests).
	 *
	 * @return int
	 */
	public static function getDialCount(): int
	{
		return self::$dialCount;
	}

	/**
	 * FD-5: Reset the per-process dial count (for tests only).
	 *
	 * @return void
	 */
	public static function resetDialCount(): void
	{
		self::$dialCount = 0;
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
			// FD-5: enforce the per-process dial budget BEFORE dialing.
			if ( self::$dialCount >= self::MAX_DIALS_PER_REQUEST ) {
				throw new \RuntimeException( 'connect_budget_exhausted' );
			}

			try {
				// FD-7: jitter inside the per-attempt try so an exotic Throwable
				// from random_int (e.g. on exotic platforms) does not abort the loop.
				if ( $attempt > 1 ) {
					// random_int() is always available (PHP 7+) and works at drop-in
					// load time before WordPress functions are defined; wp_rand() is a
					// WP wrapper that is not guaranteed to be available this early.
					$jitter = random_int( 0, min( $retryIntervalUs * $attempt, $maxJitterUs ) );
					if ( $jitter > 0 ) {
						usleep( $jitter );
					}
				}

				$redis = new \Redis();
				self::$dialCount++;

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
				// Scenario-c hardening: check return values explicitly; some builds
				// return false instead of throwing on auth/select failure.
				if ( $password !== '' ) {
					if ( $username !== '' ) {
						$authResult = $redis->auth( [ $username, $password ] );
					} else {
						$authResult = $redis->auth( $password );
					}
					if ( $authResult !== true ) {
						throw new \RuntimeException( 'Redis AUTH failed (returned false)' );
					}
				}

				// Re-assert SELECT on persistent handles to prevent database leaks.
				if ( $database !== 0 ) {
					$selectResult = $redis->select( $database );
				} else {
					// Always SELECT 0 on persistent handles (defensive re-SELECT).
					$selectResult = $redis->select( 0 );
				}
				if ( $selectResult !== true ) {
					throw new \RuntimeException( 'Redis SELECT failed (returned false)' );
				}

				// Set phpredis client options with graceful codec fallback (FD-4).
				$this->applyClientOptions( $redis, $serializer, $compression, $readTimeout );

				$this->reconnectAttempts++;
				return $redis;

			} catch ( \Throwable $e ) {
				// FD-3a: explicitly close the handle before retrying so the FD is
				// not stranded. Nested try/catch so a close failure does not mask
				// the original exception.
				if ( isset( $redis ) ) {
					try {
						$redis->close();
					} catch ( \Throwable $closeEx ) {
						// Best-effort close; proceed to next attempt.
					}
				}

				// FD-7: journal each failed attempt (WP_DEBUG-gated).
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG-gated diagnostic
					error_log( 'WPMgr Object Cache: connect attempt ' . $attempt . '/' . $attempts . ' failed [' . get_class( $e ) . ']: ' . $e->getMessage() );
				}

				$lastException = $e;

				// budget_exhausted is terminal: do not retry further.
				if ( $e->getMessage() === 'connect_budget_exhausted' ) {
					break;
				}
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
	/**
	 * FD-4: Apply phpredis client options with graceful codec fallback.
	 * Unlike the previous H3 approach, this NEVER throws after a successful
	 * pconnect. Instead, unsupported serializers fall back to SERIALIZER_PHP and
	 * unsupported compression falls back to none. The effective values are
	 * recorded on $this for use by checkMetadataIntegrity().
	 *
	 * @param \Redis  $redis       Client handle.
	 * @param string  $serializer  Serializer: 'php' | 'igbinary'.
	 * @param string  $compression Compression: 'none' | 'lzf' | 'lz4' | 'zstd'.
	 * @param float   $readTimeout Read timeout in seconds.
	 * @param array<string,mixed>|null $capabilityMap Injectable capability map for tests.
	 * @return void
	 */
	private function applyClientOptions(
		\Redis $redis,
		string $serializer,
		string $compression,
		float $readTimeout,
		?array $capabilityMap = null
	): void {
		// FD-4: capability check before applying options.
		$caps = $capabilityMap ?? self::runtimeCapabilityMap();

		// Serializer: fall back to PHP on any unavailability or setOption failure.
		$resolvedSerializer = 'php';
		if ( $serializer === 'igbinary' ) {
			if ( ( $caps['igbinary_available'] ?? false ) ) {
				$setResult = $redis->setOption( \Redis::OPT_SERIALIZER, (string) constant( 'Redis::SERIALIZER_IGBINARY' ) );
				if ( $setResult !== false ) {
					$resolvedSerializer = 'igbinary';
				} else {
					// setOption returned false: igbinary unavailable at runtime.
					$redis->setOption( \Redis::OPT_SERIALIZER, (string) \Redis::SERIALIZER_PHP );
					if ( $this->codecFallbackCause === '' ) {
						$this->codecFallbackCause = 'igbinary_setOption_failed';
					}
				}
			} else {
				// igbinary extension not loaded: fall back silently.
				$redis->setOption( \Redis::OPT_SERIALIZER, (string) \Redis::SERIALIZER_PHP );
				if ( $this->codecFallbackCause === '' ) {
					$this->codecFallbackCause = 'igbinary_unavailable';
				}
			}
		} else {
			$redis->setOption( \Redis::OPT_SERIALIZER, (string) \Redis::SERIALIZER_PHP );
		}
		$this->effectiveSerializer = $resolvedSerializer;

		// Compression: fall back to none on any unavailability or setOption failure.
		$resolvedCompression = 'none';
		if ( $compression !== 'none' ) {
			if ( ! defined( 'Redis::OPT_COMPRESSION' ) ) {
				// OPT_COMPRESSION constant absent: fall back to none.
				if ( $this->codecFallbackCause === '' ) {
					$this->codecFallbackCause = $compression . '_opt_unavailable';
				}
			} else {
				$compressionMap = [
					'lzf'  => ( $caps['lzf_available'] ?? false ) && defined( 'Redis::COMPRESSION_LZF' ) ? constant( 'Redis::COMPRESSION_LZF' ) : null,
					'lz4'  => ( $caps['lz4_available'] ?? false ) && defined( 'Redis::COMPRESSION_LZ4' ) ? constant( 'Redis::COMPRESSION_LZ4' ) : null,
					'zstd' => ( $caps['zstd_available'] ?? false ) && defined( 'Redis::COMPRESSION_ZSTD' ) ? constant( 'Redis::COMPRESSION_ZSTD' ) : null,
				];
				$compressionConst = $compressionMap[ $compression ] ?? null;
				if ( $compressionConst !== null ) {
					$setResult = $redis->setOption( constant( 'Redis::OPT_COMPRESSION' ), (string) $compressionConst );
					if ( $setResult !== false ) {
						$resolvedCompression = $compression;
					} else {
						// setOption returned false: fall back to none.
						if ( $this->codecFallbackCause === '' ) {
							$this->codecFallbackCause = $compression . '_setOption_failed';
						}
					}
				} else {
					// Codec constant not defined or unavailable: fall back to none.
					if ( $this->codecFallbackCause === '' ) {
						$this->codecFallbackCause = $compression . '_unavailable';
					}
				}
			}
		}
		$this->effectiveCompression = $resolvedCompression;

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
	 * H3: Return a runtime capability map (used as the default for applyClientOptions).
	 * Separating this from probeCapabilities (which needs a live Redis handle) allows
	 * the serializer/compression check to happen before any network call.
	 *
	 * @return array<string,bool>
	 */
	private static function runtimeCapabilityMap(): array
	{
		return [
			'igbinary_available' => defined( 'Redis::SERIALIZER_IGBINARY' ),
			'lzf_available'      => defined( 'Redis::COMPRESSION_LZF' ),
			'lz4_available'      => defined( 'Redis::COMPRESSION_LZ4' ),
			'zstd_available'     => defined( 'Redis::COMPRESSION_ZSTD' ),
		];
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
			// FD-3c: close the handle before nulling so the FD is not stranded.
			try {
				$this->redis->close();
			} catch ( \Throwable $closeEx ) {
				// Best-effort close.
			}
			$this->redis    = null;
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
