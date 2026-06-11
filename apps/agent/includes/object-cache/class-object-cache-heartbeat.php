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
 * The persisted option ('wpmgr_object_cache_stats') remains the carrier for the
 * analytics window-delta metrics (hit_count/miss_count/ops_per_sec/avg_wait_ms)
 * which are accumulated across requests between heartbeat pushes and then
 * consumed-and-reset here. Those fields are only merged in when analytics is on.
 *
 * When the config exists but the drop-in global is absent or not our class (e.g.
 * a foreign drop-in is installed, or the engine failed to load), state is emitted
 * as 'disabled' with last_error_class 'engine_not_loaded' so the CP can
 * distinguish "engine working" from "engine not in memory".
 *
 * Config absent → returns null (feature not configured; CP treats absence as
 * disabled and leaves its stored state unchanged).
 * Analytics off → emits the state-only block (no delta fields); the pill still
 * shows the correct live state.
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

		// Check config file exists (feature is configured).
		$configLoader = new ObjectCacheConfig();
		$config       = $configLoader->load();

		if ( $config === [] ) {
			// Feature not configured: return null so the CP leaves its stored
			// state unchanged (absence = no-op, not "disabled").
			return null;
		}

		// ---------- Live-state read ------------------------------------------
		// The heartbeat request itself has the drop-in active (it fires inside
		// the same WP request). If our engine is loaded, $GLOBALS['wp_object_cache']
		// is our WPMgr_Object_Cache instance. Read state directly — no option chain.

		$liveEngine = isset( $GLOBALS['wp_object_cache'] ) && $GLOBALS['wp_object_cache'] instanceof \WPMgr_Object_Cache
			? $GLOBALS['wp_object_cache']
			: null;

		if ( $liveEngine !== null ) {
			$liveStats = $liveEngine->getHeartbeatStats();
		} else {
			// Drop-in is absent or a foreign engine is loaded.
			$liveStats = [
				'state'                => 'disabled',
				'latency_ms'           => 0.0,
				'last_error_class'     => 'engine_not_loaded',
				'hit_ratio_window_pct' => 0.0,
				'engine_version'       => '',
			];
		}

		// Sanitize derived floats: a NAN or INF in the live stats block would
		// cause json_encode to return false and drop the entire report payload.
		$liveStats['latency_ms']           = is_finite( (float) ( $liveStats['latency_ms'] ?? 0.0 ) )
			? (float) $liveStats['latency_ms']
			: 0.0;
		$liveStats['hit_ratio_window_pct'] = is_finite( (float) ( $liveStats['hit_ratio_window_pct'] ?? 0.0 ) )
			? (float) $liveStats['hit_ratio_window_pct']
			: 0.0;

		// ---------- Analytics delta metrics ----------------------------------
		// Analytics defaults to ON when the key is absent (matching the m68
		// default and the engine's persistStats gate).
		$analyticsOn = ! isset( $config['analytics_enabled'] ) || (bool) $config['analytics_enabled'];

		if ( ! $analyticsOn ) {
			// Analytics disabled: return state-only block (no delta fields).
			// The pill will show the live state; throughput metrics are omitted.
			return [
				'state'                => is_string( $liveStats['state'] ?? null ) ? $liveStats['state'] : 'disabled',
				'latency_ms'           => $liveStats['latency_ms'],
				'last_error_class'     => is_string( $liveStats['last_error_class'] ?? null ) ? $liveStats['last_error_class'] : '',
				'hit_ratio_window_pct' => $liveStats['hit_ratio_window_pct'],
				'used_memory_bytes'    => isset( $liveStats['used_memory_bytes'] ) ? (int) $liveStats['used_memory_bytes'] : 0,
				'engine_version'       => is_string( $liveStats['engine_version'] ?? null ) ? $liveStats['engine_version'] : '',
			];
		}

		// Read persisted stats (written by engine shutdown hook across prior requests).
		$stats = get_option( self::OPTION_STATS, null );
		if ( ! is_array( $stats ) ) {
			$stats = [];
		}

		// Consume the window-delta counters and compute derived analytics fields.
		$hitCount  = isset( $stats['delta_hit_count'] ) ? (int) $stats['delta_hit_count'] : 0;
		$missCount = isset( $stats['delta_miss_count'] ) ? (int) $stats['delta_miss_count'] : 0;
		$ops       = isset( $stats['delta_ops'] ) ? (int) $stats['delta_ops'] : 0;
		$waitMs    = isset( $stats['delta_wait_ms'] ) ? (float) $stats['delta_wait_ms'] : 0.0;
		$samples   = isset( $stats['delta_sample_count'] ) ? (int) $stats['delta_sample_count'] : 0;
		$sinceTs   = isset( $stats['delta_since_ts'] ) ? (float) $stats['delta_since_ts'] : 0.0;

		// ops_per_sec: total ops in the window / elapsed seconds since window start.
		$elapsedSec = $sinceTs > 0 ? max( 0.001, microtime( true ) - $sinceTs ) : 0.0;
		$opsPerSec  = ( $elapsedSec > 0 && $ops > 0 ) ? round( $ops / $elapsedSec, 2 ) : 0.0;

		// avg_wait_ms: total wait time / number of samples (requests with Redis ops).
		$avgWaitMs = $samples > 0 ? round( $waitMs / $samples, 2 ) : 0.0;

		// Sanitize derived floats before they enter the payload.
		$opsPerSec = is_finite( $opsPerSec ) ? $opsPerSec : 0.0;
		$avgWaitMs = is_finite( $avgWaitMs ) ? $avgWaitMs : 0.0;

		// Reset the delta counters in the stored option (consume-and-reset).
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
}
