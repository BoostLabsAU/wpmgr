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
	 * @return array<string,mixed>|null
	 */
	public static function build(): ?array
	{
		if ( ! function_exists( 'get_option' ) ) {
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

		return [
			'state'               => is_string( $stats['state'] ?? null ) ? $stats['state'] : 'disabled',
			'latency_ms'          => is_float( $stats['latency_ms'] ?? null ) ? $stats['latency_ms'] : 0.0,
			'last_error_class'    => is_string( $stats['last_error_class'] ?? null ) ? $stats['last_error_class'] : '',
			'hit_ratio_window_pct' => is_float( $stats['hit_ratio_window_pct'] ?? null ) ? $stats['hit_ratio_window_pct'] : 0.0,
			'used_memory_bytes'   => isset( $stats['used_memory_bytes'] ) ? (int) $stats['used_memory_bytes'] : 0,
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
