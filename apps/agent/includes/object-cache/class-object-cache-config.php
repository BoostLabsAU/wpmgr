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
 *   - Excluded from backups via FilesArchiver DEFAULT_EXCLUDES (exact filename).
 *   - Excluded from restores via FilesRestorer EXCLUDE_SUBSTRINGS.
 *   - Path is not user-controllable; always derived from WP_CONTENT_DIR.
 *   - The secret (Redis password) is stored here and nowhere else on the site.
 *   - Never echoed back in any response.
 *   - Tmp file written under umask 0077 so secret bytes are never world-readable.
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

	/**
	 * FD-6: Filename for the reconnect cool-down state side channel.
	 * Lives next to the config file in wp-content, 0600 permissions.
	 * Holds only {last_failure_ts, consecutive_failures} — no secrets.
	 */
	public const STATE_FILENAME = '.wpmgr-oc-state.json';

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
	/** Sentinel value indicating the file exists but could not be read (H7). */
	public const LOAD_UNREADABLE = '__config_unreadable__';

	/**
	 * Load and return the config array. Returns an empty array when the file
	 * is absent or malformed, and the LOAD_UNREADABLE sentinel as reason when
	 * the file exists but cannot be read (H7: config_unreadable vs config_empty).
	 *
	 * Callers that need to distinguish the two cases can call loadWithReason().
	 *
	 * @return array<string,mixed>
	 */
	public function load(): array
	{
		[ $config ] = $this->loadWithReason();
		return $config;
	}

	/**
	 * Load the config and return [config_array, reason_string].
	 * reason is one of: '' (success/empty), 'config_empty', 'config_unreadable'.
	 *
	 * @return array{0:array<string,mixed>,1:string}
	 */
	public function loadWithReason(): array
	{
		if ( $this->loaded !== null ) {
			return [ $this->loaded, '' ];
		}

		if ( $this->filePath === '' || ! @is_file( $this->filePath ) ) {
			$this->loaded = [];
			return [ $this->loaded, 'config_empty' ];
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
					return [ $this->loaded, 'config_unreadable' ];
				}
			}
		}

		try {
			// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.NotAbsolutePath -- path is derived from WP_CONTENT_DIR, always absolute
			$result = include $this->filePath;
		} catch ( \Throwable $e ) {
			// H7: include failure (e.g. permission denied, parse error).
			$this->loaded = [];
			return [ $this->loaded, 'config_unreadable' ];
		}

		if ( ! is_array( $result ) ) {
			$this->loaded = [];
			return [ $this->loaded, 'config_unreadable' ];
		}

		$this->loaded = $result;
		return [ $this->loaded, '' ];
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

		// Ownership alignment: when a privileged process writes the config (a
		// command line provisioning step), the 0600 file is unreadable by the web
		// server user and the web SAPI silently runs the cache in array mode.
		// The target owner is whoever owns the WordPress core entry file the web
		// server demonstrably serves (ABSPATH/index.php), falling back to the
		// containing directory. The chown attempt is unconditional: it only
		// succeeds when this process is privileged, and fails silently otherwise.
		try {
			$refFile = defined( 'ABSPATH' ) ? constant( 'ABSPATH' ) . 'index.php' : '';
			$ref     = ( $refFile !== '' && @is_file( $refFile ) ) ? $refFile : dirname( $this->filePath ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort reference probe
			$owner   = @fileowner( $ref ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort; failure means we skip chown
			$group   = @filegroup( $ref ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort; failure means we skip chgrp
			$current = @fileowner( $this->filePath ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort current-owner probe
			if ( $owner !== false && $owner !== 0 && $owner !== $current ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chown,WordPress.PHP.NoSilencedErrors.Discouraged -- headless agent; WP_Filesystem not initialised; best-effort ownership alignment; never fatal
				@chown( $this->filePath, $owner );
				if ( $group !== false ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chgrp,WordPress.PHP.NoSilencedErrors.Discouraged -- headless agent; WP_Filesystem not initialised; best-effort group alignment; never fatal
					@chgrp( $this->filePath, $group );
				}
			}
		} catch ( \Throwable $_ ) {
			// Best-effort: chown/chgrp failed; the file remains as written but
			// save() still succeeds so the config is persisted.
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
		// Canonical encoding shared with the control plane hash: keys sorted,
		// slashes unescaped, no HTML escaping. Plain json_encode keeps the
		// encoder semantics exact and stays available without WordPress loaded.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- canonical cross-system hash requires exact encoder flags; the WP wrapper escapes slashes
		return hash( 'sha256', (string) json_encode( $redacted, JSON_UNESCAPED_SLASHES ) );
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
		if ( $retryIntervalMs > 5000 ) {
			$retryIntervalMs = 5000;
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

		$debugHeaderEnabled = isset( $params['debug_header_enabled'] ) && is_bool( $params['debug_header_enabled'] )
			? $params['debug_header_enabled'] : false;

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
			'analytics_enabled'     => $analyticsEnabled,
			'debug_header_enabled'  => $debugHeaderEnabled,
		];
	}
}
