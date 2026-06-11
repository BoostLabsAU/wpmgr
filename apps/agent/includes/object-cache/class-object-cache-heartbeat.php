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
 *   - ReflectionFunction on wp_cache_init to detect an early third-party
 *     definition that pre-empted ours.
 *   - The enable_loading_object_cache_dropin filter (filter_suppressed).
 *   - Installed file absence / signature mismatch / version mismatch.
 *   - Stale opcache bytecode (breadcrumb absent while disk file has our version).
 *
 * The heartbeat block also includes 'php_version' and 'php_sapi' for diagnostics;
 * the CP ignores unknown fields.
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
			$liveStats = $liveEngine->getHeartbeatStats();
		} else {
			// Drop-in is absent or a foreign engine is loaded.
			$lastErrorClass = self::diagnoseCause();
			$liveStats      = [
				'state'                => 'disabled',
				'latency_ms'           => 0.0,
				'last_error_class'     => $lastErrorClass,
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
			return [
				'state'                => is_string( $liveStats['state'] ?? null ) ? $liveStats['state'] : 'disabled',
				'latency_ms'           => $liveStats['latency_ms'],
				'last_error_class'     => is_string( $liveStats['last_error_class'] ?? null ) ? $liveStats['last_error_class'] : '',
				'hit_ratio_window_pct' => $liveStats['hit_ratio_window_pct'],
				'used_memory_bytes'    => isset( $liveStats['used_memory_bytes'] ) ? (int) $liveStats['used_memory_bytes'] : 0,
				'engine_version'       => is_string( $liveStats['engine_version'] ?? null ) ? $liveStats['engine_version'] : '',
				'php_version'          => $phpVersion,
				'php_sapi'             => $phpSapi,
			];
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

		return [
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
	 * Derive the specific cause string when the engine is not our WPMgr_Object_Cache.
	 *
	 * Probe order (first match wins):
	 *   1. Breadcrumb bail reasons (php_floor / installing / killswitch).
	 *   2. Breadcrumb absent while disk file has our version => stale_opcache_suspected.
	 *   3. wp_cache_init defined in a file that is not our drop-in => early_definition.
	 *   4. enable_loading_object_cache_dropin filter active => filter_suppressed.
	 *   5. Drop-in file absent from wp-content => dropin_missing.
	 *   6. Drop-in header version older than shipped artifact => dropin_outdated.
	 *   7. Drop-in lacks our signature => foreign_dropin.
	 *   8. Fallback => engine_not_loaded.
	 *
	 * @return string Cause identifier for last_error_class.
	 */
	private static function diagnoseCause(): string
	{
		// Probe 1 + 2: breadcrumb.
		if ( isset( $GLOBALS['wpmgr_oc_stub'] ) && is_array( $GLOBALS['wpmgr_oc_stub'] ) ) {
			$bail = $GLOBALS['wpmgr_oc_stub']['bail'] ?? null;
			if ( $bail === 'php_floor' ) {
				return 'bail_php_floor';
			}
			if ( $bail === 'installing' ) {
				return 'bail_installing';
			}
			if ( $bail === 'killswitch' ) {
				return 'bail_killswitch';
			}
			// Breadcrumb is present and bail is 'engine_inline': the drop-in ran
			// but WPMgr_Object_Cache still isn't our instance. Fall through to
			// more specific probes.
		} else {
			// Breadcrumb absent: the drop-in's preamble block never ran.
			// Check whether the on-disk file has our current version — if so,
			// the file was compiled by opcache but the preamble code was not
			// executed, which is the stale-opcache bytecode signature.
			$installer = new ObjectCacheDropinInstaller();
			$state     = $installer->state();
			if ( $state === ObjectCacheDropinInstaller::STATE_OURS_CURRENT ) {
				return 'stale_opcache_suspected';
			}
		}

		// Probe 3: wp_cache_init defined elsewhere (early third-party definition).
		if ( function_exists( 'wp_cache_init' ) ) {
			try {
				$rf       = new \ReflectionFunction( 'wp_cache_init' );
				$filename = $rf->getFileName();
				if ( is_string( $filename ) && strpos( $filename, 'wpmgr' ) === false ) {
					return 'early_definition';
				}
			} catch ( \Throwable $e ) {
				// Reflection failed; fall through.
			}
		}

		// Probe 4: enable_loading_object_cache_dropin filter suppressing the drop-in.
		if ( function_exists( 'has_filter' ) && has_filter( 'enable_loading_object_cache_dropin' ) ) {
			return 'filter_suppressed';
		}

		// Probe 5–7: inspect the installed drop-in file.
		$installer = new ObjectCacheDropinInstaller();
		$state     = $installer->state();

		if ( $state === ObjectCacheDropinInstaller::STATE_MISSING ) {
			return 'dropin_missing';
		}
		if ( $state === ObjectCacheDropinInstaller::STATE_OURS_OUTDATED ) {
			return 'dropin_outdated';
		}
		if ( $state === ObjectCacheDropinInstaller::STATE_FOREIGN ) {
			return 'foreign_dropin';
		}

		return 'engine_not_loaded';
	}
}
