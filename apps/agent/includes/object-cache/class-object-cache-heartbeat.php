<?php
/**
 * ObjectCacheHeartbeat — extends the PerfReporter heartbeat/stats push with an
 * optional object_cache block.
 *
 * The block is added when the object cache feature is enabled and analytics are
 * not explicitly disabled. It reads from the wp-option written by the engine's
 * shutdown hook (wpmgr_object_cache_stats) so there is zero extra Redis cost:
 * all counters are tallied in-request by the engine.
 *
 * When the feature is disabled or no stats are persisted, the block is omitted
 * entirely (the CP treats its absence as state=disabled).
 *
 * @package WPMgr\Agent\ObjectCache
 */

declare(strict_types=1);

namespace WPMgr\Agent\ObjectCache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the persisted object-cache stats and injects the optional heartbeat block.
 */
final class ObjectCacheHeartbeat
{
	/** wp-option key for the engine-persisted stats snapshot. */
	public const OPTION_STATS = 'wpmgr_object_cache_stats';

	/**
	 * Build the optional object_cache block for the heartbeat payload.
	 * Returns null when the feature is disabled or no stats are available.
	 *
	 * Consumes-and-resets the cumulative delta counters accumulated by the
	 * engine's persistStats() shutdown hook across all requests since the
	 * last heartbeat. The snapshot fields (state, latency_ms, last_error_class,
	 * hit_ratio_window_pct, used_memory_bytes) are kept as-is from the last
	 * persisted request; the delta fields are zeroed after consumption.
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
			// Feature not configured.
			return null;
		}

		// Analytics disabled flag.
		if ( isset( $config['analytics_enabled'] ) && ! $config['analytics_enabled'] ) {
			return null;
		}

		// Read persisted stats (written by engine shutdown hook).
		$stats = get_option( self::OPTION_STATS, null );

		if ( ! is_array( $stats ) ) {
			// No stats yet (engine not loaded this request, or first boot).
			// Return a minimal block so the CP knows the feature is enabled.
			return [
				'state'               => 'disabled',
				'latency_ms'          => 0.0,
				'last_error_class'    => '',
				'hit_ratio_window_pct' => 0.0,
			];
		}

		// Consume the window-delta counters and compute derived analytics fields.
		$hitCount    = isset( $stats['delta_hit_count'] ) ? (int) $stats['delta_hit_count'] : 0;
		$missCount   = isset( $stats['delta_miss_count'] ) ? (int) $stats['delta_miss_count'] : 0;
		$ops         = isset( $stats['delta_ops'] ) ? (int) $stats['delta_ops'] : 0;
		$waitMs      = isset( $stats['delta_wait_ms'] ) ? (float) $stats['delta_wait_ms'] : 0.0;
		$samples     = isset( $stats['delta_sample_count'] ) ? (int) $stats['delta_sample_count'] : 0;
		$sinceTs     = isset( $stats['delta_since_ts'] ) ? (float) $stats['delta_since_ts'] : 0.0;

		// ops_per_sec: total ops in the window / elapsed seconds since window start.
		$elapsedSec = $sinceTs > 0 ? max( 0.001, microtime( true ) - $sinceTs ) : 0.0;
		$opsPerSec  = ( $elapsedSec > 0 && $ops > 0 ) ? round( $ops / $elapsedSec, 2 ) : 0.0;

		// avg_wait_ms: total wait time / number of samples (requests with Redis ops).
		$avgWaitMs = $samples > 0 ? round( $waitMs / $samples, 2 ) : 0.0;

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
			'state'               => is_string( $stats['state'] ?? null ) ? $stats['state'] : 'disabled',
			'latency_ms'          => is_float( $stats['latency_ms'] ?? null ) ? $stats['latency_ms'] : 0.0,
			'last_error_class'    => is_string( $stats['last_error_class'] ?? null ) ? $stats['last_error_class'] : '',
			'hit_ratio_window_pct' => is_float( $stats['hit_ratio_window_pct'] ?? null ) ? $stats['hit_ratio_window_pct'] : 0.0,
			'used_memory_bytes'   => isset( $stats['used_memory_bytes'] ) ? (int) $stats['used_memory_bytes'] : 0,
			'hit_count'           => $hitCount,
			'miss_count'          => $missCount,
			'ops_per_sec'         => $opsPerSec,
			'avg_wait_ms'         => $avgWaitMs,
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
