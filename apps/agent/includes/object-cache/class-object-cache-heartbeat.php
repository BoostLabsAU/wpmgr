<?php
/**
 * ObjectCacheHeartbeat — extends the PerfReporter heartbeat/stats push with an
 * optional object_cache block.
 *
 * The block is added when the object cache feature is configured. State, latency,
 * last_error_class, hit_ratio, and engine_version are read LIVE from the
 * WPMgr_Object_Cache instance already loaded in this request (the drop-in is
 * active, so $GLOBALS['wp_object_cache'] is right here). This eliminates the
 * fragile persisted-option chain that previously caused the status pill to stay
 * "Disabled" on working sites.
 *
 * Phase B — cause-vector diagnosability: when the drop-in is present on disk
 * but the engine is not our WPMgr_Object_Cache instance, last_error_class is
 * replaced by a SPECIFIC cause derived from:
 *   - The breadcrumb ($GLOBALS['wpmgr_oc_stub']) set by the drop-in preamble.
 *   - The 'booted' flag set inside the drop-in after the engine global assignment.
 *   - ReflectionFunction on wp_cache_init to detect an early third-party
 *     definition that pre-empted ours.
 *   - The enable_loading_object_cache_dropin filter (filter_suppressed).
 *   - Installed file absence / signature mismatch / version mismatch.
 *   - Stale opcache bytecode (breadcrumb absent while disk file has our version).
 *
 * The heartbeat block also includes 'php_version' and 'php_sapi' for diagnostics,
 * and 'early_definer' (bounded 64 chars) when a non-WPMgr definer is detected.
 * The CP ignores unknown fields.
 *
 * @package WPMgr\Agent\ObjectCache
 */

declare(strict_types=1);

namespace WPMgr\Agent\ObjectCache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads live object-cache state and injects the optional heartbeat block.
 */
final class ObjectCacheHeartbeat
{
	/** wp-option key for the engine-persisted stats snapshot. */
	public const OPTION_STATS = 'wpmgr_object_cache_stats';

	/**
	 * Build the optional object_cache block for the heartbeat payload.
	 * Returns null only when the feature is not configured.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function build(): ?array
	{
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return null;
		}

		$configLoader = new ObjectCacheConfig();
		$config       = $configLoader->load();

		if ( $config === [] ) {
			return null;
		}

		// ---------- Live-state read ------------------------------------------
		$liveEngine = isset( $GLOBALS['wp_object_cache'] ) && $GLOBALS['wp_object_cache'] instanceof \WPMgr_Object_Cache
			? $GLOBALS['wp_object_cache']
			: null;

		if ( $liveEngine !== null ) {
			$liveStats   = $liveEngine->getHeartbeatStats();
			$earlyDefiner = '';
		} else {
			// Drop-in is absent or a foreign engine is loaded.
			$diagnosis    = self::diagnose();
			$earlyDefiner = $diagnosis['definer'];
			$liveStats    = [
				'state'                => 'disabled',
				'latency_ms'           => 0.0,
				'last_error_class'     => $diagnosis['cause'],
				'hit_ratio_window_pct' => 0.0,
				'engine_version'       => '',
			];
		}

		// Sanitize derived floats.
		$liveStats['latency_ms'] = is_finite( (float) ( $liveStats['latency_ms'] ?? 0.0 ) )
			? (float) $liveStats['latency_ms']
			: 0.0;
		$liveStats['hit_ratio_window_pct'] = is_finite( (float) ( $liveStats['hit_ratio_window_pct'] ?? 0.0 ) )
			? (float) $liveStats['hit_ratio_window_pct']
			: 0.0;

		// Phase B: runtime environment fields (bounded short strings; CP ignores unknowns).
		$phpVersion = PHP_VERSION;
		$phpSapi    = PHP_SAPI;

		// ---------- Analytics delta metrics ----------------------------------
		$analyticsOn = ! isset( $config['analytics_enabled'] ) || (bool) $config['analytics_enabled'];

		if ( ! $analyticsOn ) {
			$block = [
				'state'                => is_string( $liveStats['state'] ?? null ) ? $liveStats['state'] : 'disabled',
				'latency_ms'           => $liveStats['latency_ms'],
				'last_error_class'     => is_string( $liveStats['last_error_class'] ?? null ) ? $liveStats['last_error_class'] : '',
				'hit_ratio_window_pct' => $liveStats['hit_ratio_window_pct'],
				'used_memory_bytes'    => isset( $liveStats['used_memory_bytes'] ) ? (int) $liveStats['used_memory_bytes'] : 0,
				'engine_version'       => is_string( $liveStats['engine_version'] ?? null ) ? $liveStats['engine_version'] : '',
				'php_version'          => $phpVersion,
				'php_sapi'             => $phpSapi,
			];
			if ( $earlyDefiner !== '' ) {
				$block['early_definer'] = substr( $earlyDefiner, 0, 64 );
			}
			return $block;
		}

		// Read persisted stats (written by engine shutdown hook).
		$stats = get_option( self::OPTION_STATS, null );
		if ( ! is_array( $stats ) ) {
			$stats = [];
		}

		// Consume window-delta counters.
		$hitCount  = isset( $stats['delta_hit_count'] ) ? (int) $stats['delta_hit_count'] : 0;
		$missCount = isset( $stats['delta_miss_count'] ) ? (int) $stats['delta_miss_count'] : 0;
		$ops       = isset( $stats['delta_ops'] ) ? (int) $stats['delta_ops'] : 0;
		$waitMs    = isset( $stats['delta_wait_ms'] ) ? (float) $stats['delta_wait_ms'] : 0.0;
		$samples   = isset( $stats['delta_sample_count'] ) ? (int) $stats['delta_sample_count'] : 0;
		$sinceTs   = isset( $stats['delta_since_ts'] ) ? (float) $stats['delta_since_ts'] : 0.0;

		$elapsedSec = $sinceTs > 0 ? max( 0.001, microtime( true ) - $sinceTs ) : 0.0;
		$opsPerSec  = ( $elapsedSec > 0 && $ops > 0 ) ? round( $ops / $elapsedSec, 2 ) : 0.0;
		$avgWaitMs  = $samples > 0 ? round( $waitMs / $samples, 2 ) : 0.0;

		$opsPerSec = is_finite( $opsPerSec ) ? $opsPerSec : 0.0;
		$avgWaitMs = is_finite( $avgWaitMs ) ? $avgWaitMs : 0.0;

		// Reset the delta counters (consume-and-reset).
		$reset = array_merge( $stats, [
			'delta_hit_count'    => 0,
			'delta_miss_count'   => 0,
			'delta_ops'          => 0,
			'delta_wait_ms'      => 0.0,
			'delta_sample_count' => 0,
			'delta_since_ts'     => 0.0,
		] );
		update_option( self::OPTION_STATS, $reset, false );

		$block = [
			'state'                => is_string( $liveStats['state'] ?? null ) ? $liveStats['state'] : 'disabled',
			'latency_ms'           => $liveStats['latency_ms'],
			'last_error_class'     => is_string( $liveStats['last_error_class'] ?? null ) ? $liveStats['last_error_class'] : '',
			'hit_ratio_window_pct' => $liveStats['hit_ratio_window_pct'],
			'used_memory_bytes'    => isset( $liveStats['used_memory_bytes'] ) ? (int) $liveStats['used_memory_bytes'] : 0,
			'engine_version'       => is_string( $liveStats['engine_version'] ?? null ) ? $liveStats['engine_version'] : '',
			'hit_count'            => $hitCount,
			'miss_count'           => $missCount,
			'ops_per_sec'          => $opsPerSec,
			'avg_wait_ms'          => $avgWaitMs,
			'php_version'          => $phpVersion,
			'php_sapi'             => $phpSapi,
		];
		if ( $earlyDefiner !== '' ) {
			$block['early_definer'] = substr( $earlyDefiner, 0, 64 );
		}
		return $block;
	}

	/**
	 * Inject the object_cache block into an existing heartbeat payload array.
	 * Mutates in place only when the block is non-null.
	 *
	 * @param array<string,mixed> $payload Heartbeat payload to mutate.
	 * @return void
	 */
	public static function inject( array &$payload ): void
	{
		$block = self::build();
		if ( $block !== null ) {
			$payload['object_cache'] = $block;
		}
	}

	// -------------------------------------------------------------------------
	// Phase B: non-activation cause diagnosis
	// -------------------------------------------------------------------------

	/**
	 * Derive the specific cause and definer when the engine is not our WPMgr_Object_Cache.
	 *
	 * Returns an array with keys:
	 *   'cause'   — string identifier for last_error_class.
	 *   'definer' — string description of the foreign definer (empty when not applicable).
	 *
	 * Probe order (first match wins):
	 *
	 *   Breadcrumb PRESENT:
	 *     1a. bail=php_floor/installing/killswitch => bail_* cause.
	 *     1b. bail=engine_inline + 'booted' flag set but global is foreign
	 *         => engine_replaced + definer (class + parent-dir/basename of its file).
	 *     1c. bail=engine_inline without 'booted' flag => engine_boot_incomplete.
	 *
	 *   Breadcrumb ABSENT:
	 *     2.  installer state() first:
	 *         STATE_MISSING   => dropin_missing.
	 *         STATE_FOREIGN   => foreign_dropin + definer (class name from wp_cache_init).
	 *         STATE_OURS_OUTDATED => dropin_outdated.
	 *         STATE_OURS_CURRENT  => reflect wp_cache_init:
	 *             isOurDropinFile() => stale_opcache_suspected.
	 *             has_filter('enable_loading_object_cache_dropin') => filter_suppressed + definer.
	 *             else => early_definition + definer (parent-dir/basename of ReflectionFunction file).
	 *
	 * @return array{cause:string,definer:string}
	 */
	public static function diagnose(): array
	{
		// ---- Breadcrumb present ----
		if ( isset( $GLOBALS['wpmgr_oc_stub'] ) && is_array( $GLOBALS['wpmgr_oc_stub'] ) ) {
			$bail   = $GLOBALS['wpmgr_oc_stub']['bail'] ?? null;
			$booted = ! empty( $GLOBALS['wpmgr_oc_stub']['booted'] );

			if ( $bail === 'php_floor' ) {
				return [ 'cause' => 'bail_php_floor', 'definer' => '' ];
			}
			if ( $bail === 'installing' ) {
				return [ 'cause' => 'bail_installing', 'definer' => '' ];
			}
			if ( $bail === 'killswitch' ) {
				return [ 'cause' => 'bail_killswitch', 'definer' => '' ];
			}

			// bail=engine_inline: drop-in ran its full preamble.
			if ( $booted ) {
				// The drop-in booted but $wp_object_cache is not ours — another
				// plugin replaced the global after we set it.
				$definer = '';
				if ( isset( $GLOBALS['wp_object_cache'] ) && is_object( $GLOBALS['wp_object_cache'] ) ) {
					$definer = self::classDefinerHint( get_class( $GLOBALS['wp_object_cache'] ) );
				}
				return [ 'cause' => 'engine_replaced', 'definer' => $definer ];
			}

			// Breadcrumb present, bail=engine_inline, booted flag absent: the engine
			// file was included but the boot code did not complete.
			return [ 'cause' => 'engine_boot_incomplete', 'definer' => '' ];
		}

		// ---- Breadcrumb absent: drop-in preamble never ran ----
		// Check the installer state first to classify file-level issues.
		$installer = new ObjectCacheDropinInstaller();
		$state     = $installer->state();

		if ( $state === ObjectCacheDropinInstaller::STATE_MISSING ) {
			return [ 'cause' => 'dropin_missing', 'definer' => '' ];
		}

		if ( $state === ObjectCacheDropinInstaller::STATE_FOREIGN ) {
			$definer = '';
			if ( function_exists( 'wp_cache_init' ) ) {
				$definer = self::functionDefinerHint( 'wp_cache_init' );
			}
			return [ 'cause' => 'foreign_dropin', 'definer' => $definer ];
		}

		if ( $state === ObjectCacheDropinInstaller::STATE_OURS_OUTDATED ) {
			return [ 'cause' => 'dropin_outdated', 'definer' => '' ];
		}

		// STATE_OURS_CURRENT: our file is on disk but breadcrumb never ran.
		// Reflect wp_cache_init to determine who defined it.
		if ( function_exists( 'wp_cache_init' ) ) {
			if ( self::isOurDropinFile( $installer->dropinPath(), 'wp_cache_init' ) ) {
				// Our own file defines it but the preamble was skipped — stale opcache.
				return [ 'cause' => 'stale_opcache_suspected', 'definer' => '' ];
			}

			// Someone else defined wp_cache_init before WordPress loaded our drop-in.
			if ( function_exists( 'has_filter' ) && has_filter( 'enable_loading_object_cache_dropin' ) ) {
				$definer = self::filterDefinerHint( 'enable_loading_object_cache_dropin' );
				return [ 'cause' => 'filter_suppressed', 'definer' => $definer ];
			}

			$definer = self::functionDefinerHint( 'wp_cache_init' );
			return [ 'cause' => 'early_definition', 'definer' => $definer ];
		}

		return [ 'cause' => 'engine_not_loaded', 'definer' => '' ];
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Whether wp_cache_init is defined in our installed drop-in file.
	 *
	 * Uses realpath-vs-realpath comparison; falls back to plain-string comparison
	 * under open_basedir environments where realpath may return false.
	 *
	 * @param string $dropinPath Absolute path to the installed object-cache.php.
	 * @param string $funcName   Function name to reflect.
	 * @return bool
	 */
	private static function isOurDropinFile( string $dropinPath, string $funcName ): bool
	{
		if ( $dropinPath === '' || ! function_exists( $funcName ) ) {
			return false;
		}
		try {
			$rf       = new \ReflectionFunction( $funcName );
			$filename = $rf->getFileName();
			if ( ! is_string( $filename ) ) {
				return false;
			}
			// Prefer realpath for symlink resolution; fall back to plain strings.
			$resolvedDropin = realpath( $dropinPath );
			$resolvedFunc   = realpath( $filename );
			if ( $resolvedDropin !== false && $resolvedFunc !== false ) {
				return $resolvedDropin === $resolvedFunc;
			}
			return $dropinPath === $filename;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Build a short definer hint for a class: ClassName + parent-dir/basename of
	 * the file where the class is defined, capped at 64 characters total.
	 *
	 * @param string $className Fully-qualified class name.
	 * @return string
	 */
	private static function classDefinerHint( string $className ): string
	{
		$hint = $className;
		try {
			$rc   = new \ReflectionClass( $className );
			$file = $rc->getFileName();
			if ( is_string( $file ) ) {
				$rel  = basename( dirname( $file ) ) . '/' . basename( $file );
				$hint = $className . ' ' . $rel;
			}
		} catch ( \Throwable $e ) {
			// Reflection failed; return class name only.
		}
		return substr( $hint, 0, 64 );
	}

	/**
	 * Build a short definer hint for a function: parent-dir/basename of the file
	 * where the function is defined, capped at 64 characters.
	 *
	 * @param string $funcName Function name.
	 * @return string
	 */
	private static function functionDefinerHint( string $funcName ): string
	{
		if ( ! function_exists( $funcName ) ) {
			return '';
		}
		try {
			$rf   = new \ReflectionFunction( $funcName );
			$file = $rf->getFileName();
			if ( is_string( $file ) ) {
				return substr( basename( dirname( $file ) ) . '/' . basename( $file ), 0, 64 );
			}
		} catch ( \Throwable $e ) {
			// Reflection failed.
		}
		return '';
	}

	/**
	 * Build a short definer hint for a filter: first callback's class/file.
	 *
	 * @param string $hookName Hook name.
	 * @return string
	 */
	private static function filterDefinerHint( string $hookName ): string
	{
		global $wp_filter;
		if ( ! isset( $wp_filter[ $hookName ] ) ) {
			return '';
		}
		$hooks = $wp_filter[ $hookName ];
		// $hooks is a WP_Hook or array; iterate to find the first callback.
		$callbacks = is_object( $hooks ) && isset( $hooks->callbacks )
			? $hooks->callbacks
			: ( is_array( $hooks ) ? $hooks : [] );
		foreach ( $callbacks as $priority ) {
			if ( ! is_array( $priority ) ) {
				continue;
			}
			foreach ( $priority as $cb ) {
				$fn = $cb['function'] ?? null;
				if ( $fn === null ) {
					continue;
				}
				if ( is_string( $fn ) ) {
					return substr( $fn, 0, 64 );
				}
				if ( is_array( $fn ) && isset( $fn[0] ) ) {
					$obj = $fn[0];
					return substr( is_object( $obj ) ? get_class( $obj ) : (string) $obj, 0, 64 );
				}
				if ( $fn instanceof \Closure ) {
					try {
						$rf   = new \ReflectionFunction( $fn );
						$file = $rf->getFileName();
						if ( is_string( $file ) ) {
							return substr( basename( dirname( $file ) ) . '/' . basename( $file ), 0, 64 );
						}
					} catch ( \Throwable $e ) {
						// Fall through.
					}
				}
			}
		}
		return '';
	}
}
