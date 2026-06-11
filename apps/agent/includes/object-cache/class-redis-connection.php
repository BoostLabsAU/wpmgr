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
				$jitter = wp_rand( 0, min( $retryIntervalUs * $attempt, $maxJitterUs ) );
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
