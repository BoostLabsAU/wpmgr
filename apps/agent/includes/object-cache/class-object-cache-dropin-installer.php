<?php
/**
 * ObjectCacheDropinInstaller — manages the object-cache.php drop-in lifecycle
 * in wp-content.
 *
 * Mirrors the page-cache DropinInstaller pattern (install/verify/version/remove +
 * writability probe + foreign detection), but the stub is static (no inlined
 * config; config lives in the 0600 file). The stub is the file
 * assets/wpmgr-object-cache.php from the plugin directory.
 *
 * Security constraints:
 *   - Foreign object-cache.php (not ours) is never overwritten without a force flag.
 *   - Writability is proven by a real temp-file write probe before any action.
 *   - DISALLOW_FILE_MODS is honored.
 *
 * @package WPMgr\Agent\ObjectCache
 */

declare(strict_types=1);

namespace WPMgr\Agent\ObjectCache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Installs and removes the object-cache.php drop-in.
 */
final class ObjectCacheDropinInstaller
{
	/** WordPress canonical object-cache drop-in filename. */
	public const CANONICAL = 'object-cache.php';

	/** Signature line proving a drop-in on disk is ours. */
	public const SIGNATURE = 'WPMgr Object Cache drop-in';

	/** Version header string prefix in the stub. */
	public const VERSION_PREFIX = 'Version: ';

	/** Placeholder token in the stub template replaced at install time. */
	public const ENGINE_PATH_PLACEHOLDER = '__WPMGR_OC_ENGINE_PATH__';

	/** Drop-in state: ours and current. */
	public const STATE_OURS_CURRENT = 'ours-current';

	/** Drop-in state: ours but outdated. */
	public const STATE_OURS_OUTDATED = 'ours-outdated';

	/** Drop-in state: another plugin owns this file. */
	public const STATE_FOREIGN = 'foreign';

	/** Drop-in state: file absent. */
	public const STATE_MISSING = 'missing';

	/** Absolute path to the wp-content directory. */
	private string $contentDir;

	/** Absolute path to the stub template file. */
	private string $stubPath;

	/**
	 * @param string|null $contentDir wp-content path override (for tests).
	 * @param string|null $stubPath   Stub template path override (for tests).
	 */
	public function __construct( ?string $contentDir = null, ?string $stubPath = null )
	{
		if ( $contentDir !== null ) {
			$this->contentDir = rtrim( $contentDir, '/\\' );
		} elseif ( defined( 'WP_CONTENT_DIR' ) ) {
			$this->contentDir = rtrim( (string) constant( 'WP_CONTENT_DIR' ), '/\\' );
		} else {
			$this->contentDir = '';
		}

		if ( $stubPath !== null ) {
			$this->stubPath = $stubPath;
		} elseif ( defined( 'WPMGR_AGENT_DIR' ) ) {
			$this->stubPath = rtrim( (string) constant( 'WPMGR_AGENT_DIR' ), '/\\' )
				. '/assets/wpmgr-object-cache.php';
		} else {
			$this->stubPath = '';
		}
	}

	/**
	 * Absolute path where the drop-in would be installed.
	 *
	 * @return string
	 */
	public function dropinPath(): string
	{
		return $this->contentDir !== '' ? $this->contentDir . '/' . self::CANONICAL : '';
	}

	/**
	 * Inspect the current state of the drop-in.
	 *
	 * @return string One of the STATE_* constants.
	 */
	public function state(): string
	{
		$path = $this->dropinPath();
		if ( $path === '' || ! @is_file( $path ) ) {
			return self::STATE_MISSING;
		}
		$content = @file_get_contents( $path );
		if ( $content === false ) {
			return self::STATE_FOREIGN;
		}
		if ( strpos( $content, self::SIGNATURE ) === false ) {
			return self::STATE_FOREIGN;
		}
		// Check version.
		$installedVersion = $this->extractVersion( $content );
		$stubVersion      = $this->stubVersion();
		if ( $stubVersion !== '' && $installedVersion !== '' && $installedVersion !== $stubVersion ) {
			return self::STATE_OURS_OUTDATED;
		}
		return self::STATE_OURS_CURRENT;
	}

	/**
	 * Whether the drop-in is installed and ours (current or outdated).
	 *
	 * @return bool
	 */
	public function isInstalled(): bool
	{
		$s = $this->state();
		return $s === self::STATE_OURS_CURRENT || $s === self::STATE_OURS_OUTDATED;
	}

	/**
	 * Whether wp-content is writable (via a real temp-file probe).
	 *
	 * @return bool
	 */
	public function isWritable(): bool
	{
		if ( $this->contentDir === '' ) {
			return false;
		}
		if ( defined( 'DISALLOW_FILE_MODS' ) && constant( 'DISALLOW_FILE_MODS' ) ) {
			return false;
		}
		// Temp-file probe.
		$tmp = $this->contentDir . '/.wpmgr_oc_probe_' . wp_rand( 100000, 999999 );
		$ok  = @file_put_contents( $tmp, '1' ) !== false;
		if ( $ok ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- probe temp file; not an attachment
		}
		return $ok;
	}

	/**
	 * Install the object-cache.php stub. Idempotent.
	 *
	 * Refuses to overwrite a foreign drop-in unless $force is true.
	 *
	 * @param bool $force Overwrite a foreign drop-in.
	 * @return array{ok:bool,detail:string,foreign_dropin:bool}
	 */
	public function install( bool $force = false ): array
	{
		$path = $this->dropinPath();
		if ( $path === '' ) {
			return [ 'ok' => false, 'detail' => 'wp-content path unavailable', 'foreign_dropin' => false ];
		}

		if ( $this->stubPath === '' || ! @is_file( $this->stubPath ) ) {
			return [ 'ok' => false, 'detail' => 'stub template not found', 'foreign_dropin' => false ];
		}

		if ( defined( 'DISALLOW_FILE_MODS' ) && constant( 'DISALLOW_FILE_MODS' ) ) {
			return [ 'ok' => false, 'detail' => 'DISALLOW_FILE_MODS is set', 'foreign_dropin' => false ];
		}

		// Foreign drop-in check. Treat an unreadable existing file as foreign:
		// we cannot confirm it is ours, so we must not overwrite it without $force.
		if ( @is_file( $path ) ) {
			$existing = @file_get_contents( $path );
			$isForeign = $existing === false
				|| ( strpos( $existing, self::SIGNATURE ) === false && trim( $existing ) !== '' );
			if ( $isForeign && ! $force ) {
				return [
					'ok'            => false,
					'detail'        => 'another object-cache drop-in is installed; use force to replace',
					'foreign_dropin' => true,
				];
			}
		}

		// Writability check.
		if ( ! $this->isWritable() ) {
			return [ 'ok' => false, 'detail' => 'wp-content is not writable', 'foreign_dropin' => false ];
		}

		$stub = @file_get_contents( $this->stubPath );
		if ( $stub === false ) {
			return [ 'ok' => false, 'detail' => 'could not read stub template', 'foreign_dropin' => false ];
		}

		// Stamp the absolute engine path into the stub at install time. The stub
		// template ships with the literal placeholder; we replace it here with the
		// var_export'd resolved absolute path so the drop-in can locate the engine
		// even before WordPress has defined plugin-directory constants (which are
		// not available during wp_start_object_cache()).
		$stub = $this->stampEnginePath( $stub );

		// Idempotent: byte-identical content.
		if ( @is_file( $path ) ) {
			$current = @file_get_contents( $path );
			if ( $current === $stub ) {
				return [ 'ok' => true, 'detail' => 'already current', 'foreign_dropin' => false ];
			}
		}

		$result = @file_put_contents( $path, $stub, LOCK_EX );
		if ( $result === false ) {
			return [ 'ok' => false, 'detail' => 'write failed', 'foreign_dropin' => false ];
		}

		// Invalidate opcache so the new file is picked up immediately.
		if ( function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( $path, true );
		}

		return [ 'ok' => true, 'detail' => 'installed', 'foreign_dropin' => false ];
	}

	/**
	 * Remove the drop-in if (and only if) it is ours. Idempotent.
	 *
	 * @return bool True when the drop-in is absent or successfully removed.
	 */
	public function uninstall(): bool
	{
		$path = $this->dropinPath();
		if ( $path === '' || ! @is_file( $path ) ) {
			return true;
		}
		$content = @file_get_contents( $path );
		if ( $content === false ) {
			return true;
		}
		// Only remove our own drop-in.
		if ( strpos( $content, self::SIGNATURE ) === false ) {
			return true; // Foreign: leave it alone.
		}
		wp_delete_file( $path );
		if ( function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( $path, true );
		}
		return ! @file_exists( $path );
	}

	/**
	 * Purge transients from the DB after the object cache is enabled, so
	 * they migrate to Redis on next write.
	 *
	 * @return int Number of transient rows deleted.
	 */
	public function purgeTransients(): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return 0;
		}
		$count = 0;

		// Per-site transients.
		$optionsTable = $wpdb->options ?? '';
		if ( $optionsTable !== '' ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- no WP API for bulk transient delete; anti-replay / transient purge; caching would defeat the purpose
			$count += (int) $wpdb->query(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $optionsTable is $wpdb->options, a trusted WP core property, not user input
				"DELETE FROM `{$optionsTable}` WHERE option_name LIKE '_transient_%' OR option_name LIKE '_site_transient_%'"
			);
		}

		return $count;
	}

	/**
	 * Auto-refresh the drop-in when it is ours-outdated and wp-content is writable.
	 *
	 * Called from the agent's periodic work path (PerfReporter / heartbeat) after
	 * the object-cache config is confirmed enabled. Never touches a foreign drop-in.
	 * Returns true when the stub is now current (already was, or successfully refreshed).
	 *
	 * @return bool True when the installed stub is current after this call.
	 */
	public function maybeAutoRefresh(): bool
	{
		$state = $this->state();

		if ( $state === self::STATE_OURS_CURRENT ) {
			return true;
		}

		if ( $state !== self::STATE_OURS_OUTDATED ) {
			// Missing or foreign: do not auto-install/replace.
			return false;
		}

		// Outdated ours-stub: refresh it.
		$result = $this->install();
		return (bool) $result['ok'];
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Stamp the resolved absolute engine path into the stub content.
	 *
	 * Replaces the ENGINE_PATH_PLACEHOLDER token with the var_export'd absolute
	 * path to the engine file, derived from the stub file's own location. The
	 * engine file lives alongside the stub template inside the plugin tree, so
	 * we can compute it reliably at install time when WPMGR_AGENT_DIR is defined.
	 *
	 * The SIGNATURE check used by state() and isInstalled() does NOT depend on
	 * the placeholder content, so stamping does not affect foreign-detection.
	 *
	 * @param string $stubContent Raw stub template content.
	 * @return string Stamped content (placeholder replaced with resolved path).
	 */
	private function stampEnginePath( string $stubContent ): string
	{
		// Derive the engine path from the stub template location. The engine file
		// lives two directories up from assets/ at:
		//   <plugin_root>/assets/wpmgr-object-cache.php  (stub template)
		//   <plugin_root>/includes/object-cache/class-object-cache-engine.php  (engine)
		$enginePath = '';

		if ( $this->stubPath !== '' ) {
			$pluginRoot = dirname( dirname( $this->stubPath ) );
			$candidate  = $pluginRoot . '/includes/object-cache/class-object-cache-engine.php';
			if ( @is_file( $candidate ) ) {
				$enginePath = $candidate;
			}
		}

		// Also try WPMGR_AGENT_DIR as a direct source of truth.
		if ( $enginePath === '' && defined( 'WPMGR_AGENT_DIR' ) ) {
			$candidate = rtrim( (string) constant( 'WPMGR_AGENT_DIR' ), '/\\' )
				. '/includes/object-cache/class-object-cache-engine.php';
			if ( @is_file( $candidate ) ) {
				$enginePath = $candidate;
			}
		}

		if ( $enginePath === '' ) {
			// Cannot resolve at install time; leave the placeholder in place.
			// Probes 2 and 3 in the stub will still work as fallbacks.
			return $stubContent;
		}

		// Use var_export to produce a valid PHP string literal (handles backslashes
		// on Windows and any unusual characters in the path).
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- serializing a file path into a PHP stub file; not a debug/logging use
		$exported = var_export( $enginePath, true );

		return str_replace(
			"'" . self::ENGINE_PATH_PLACEHOLDER . "'",
			$exported,
			$stubContent
		);
	}

	/**
	 * Extract the Version header value from a drop-in file's content.
	 *
	 * @param string $content File content.
	 * @return string Version string or '' if not found.
	 */
	private function extractVersion( string $content ): string
	{
		$pos = strpos( $content, self::VERSION_PREFIX );
		if ( $pos === false ) {
			return '';
		}
		$start = $pos + strlen( self::VERSION_PREFIX );
		$end   = strpos( $content, "\n", $start );
		$version = $end !== false ? substr( $content, $start, $end - $start ) : substr( $content, $start );
		return trim( $version );
	}

	/**
	 * Extract the version from the stub template.
	 *
	 * @return string
	 */
	private function stubVersion(): string
	{
		if ( $this->stubPath === '' || ! @is_file( $this->stubPath ) ) {
			return '';
		}
		// Read only the first 512 bytes to find the version header cheaply.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- headless agent; WP_Filesystem not initialized; streaming read of plugin-controlled stub file only
		$handle = @fopen( $this->stubPath, 'r' );
		if ( $handle === false ) {
			return '';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- same justification as fopen above
		$header = (string) fread( $handle, 512 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- same justification as fopen above
		fclose( $handle );
		return $this->extractVersion( $header );
	}
}
