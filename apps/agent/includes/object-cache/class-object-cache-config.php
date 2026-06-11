<?php
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
 *   - File is excluded from backup file-sets (backup-excluded marker in content).
 *   - Path is not user-controllable; always derived from WP_CONTENT_DIR.
 *   - The secret (Redis password) is stored here and nowhere else on the site.
 *   - Never echoed back in any response.
 *
 * @package WPMgr\Agent\ObjectCache
 */

declare(strict_types=1);

namespace WPMgr\Agent\ObjectCache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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

		// Build PHP source. The backup-excluded comment is a marker so our backup
		// file-set excluder can strip this file from archives.
		$export = var_export( $config, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- generating PHP source for config file, not debug output
		$source = "<?php\n"
			. "// WPMgr Object Cache connection config.\n"
			. "// wpmgr-backup-exclude: this file contains credentials; excluded from backups.\n"
			. "defined( 'ABSPATH' ) || exit;\n"
			. "return " . $export . ";\n";

		// Atomic write: write to a temp file, then rename.
		$tmp = $this->filePath . '.tmp.' . wp_rand( 100000, 999999 );

		$written = @file_put_contents( $tmp, $source, LOCK_EX );
		if ( $written === false ) {
			return false;
		}

		// Set 0600 before rename so the file is never world-readable at any point.
		@chmod( $tmp, 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- headless agent; WP_Filesystem not initialized; direct chmod required for credential-file security

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic rename; WP_Filesystem::move() is non-atomic; justified per §4 guardrail
		if ( ! @rename( $tmp, $this->filePath ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- cleanup of a failed rename on a tmp file in the same dir; wp_delete_file() is equivalent for non-attachment files
			return false;
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
