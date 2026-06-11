<?php
/**
 * ObjectcacheDisableCommand — removes the object-cache.php drop-in and
 * optionally flushes via a standalone connection.
 *
 * Wire contract (CP -> agent):
 *   POST /wp-json/wpmgr/v1/command/objectcache.disable
 *   Authorization: Bearer <Ed25519 JWT, cmd="objectcache.disable", aud=<siteId>>
 *   Body: ObjectCacheDisableRequest { flush: bool }
 *
 * Response: ObjectCacheDisableResult { ok, detail, dropin_removed, flushed }
 *
 * The flush uses a fresh standalone connection (so disable works even when the
 * live cache is broken). The config file is NOT removed (operator may re-enable).
 *
 * @package WPMgr\Agent\Commands
 */

declare(strict_types=1);

namespace WPMgr\Agent\Commands;

use WPMgr\Agent\ObjectCache\ObjectCacheConfig;
use WPMgr\Agent\ObjectCache\ObjectCacheDropinInstaller;
use WPMgr\Agent\ObjectCache\RedisConnection;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Removes the object-cache.php drop-in and optionally flushes.
 */
final class ObjectcacheDisableCommand implements CommandInterface
{
	/**
	 * {@inheritDoc}
	 */
	public function name(): string
	{
		return 'objectcache.disable';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string,mixed> $claims Validated JWT claims (unused).
	 * @param array<string,mixed> $params ObjectCacheDisableRequest fields.
	 * @return array{ok:bool,detail:string,dropin_removed:bool,flushed:bool}
	 */
	public function execute( array $claims, array $params ): array
	{
		$doFlush = ! isset( $params['flush'] ) || (bool) $params['flush'];

		$flushed       = false;
		$dropinRemoved = false;

		try {
			// 1. Flush via a standalone connection BEFORE removing the drop-in.
			if ( $doFlush ) {
				$flushed = $this->standaloneFlush();
			}

			// 2. Remove the drop-in.
			$installer     = new ObjectCacheDropinInstaller();
			$dropinRemoved = $installer->uninstall();

			return [
				'ok'             => true,
				'detail'         => 'disabled',
				'dropin_removed' => $dropinRemoved,
				'flushed'        => $flushed,
			];

		} catch ( \Throwable $e ) {
			return [
				'ok'             => false,
				'detail'         => 'objectcache.disable failed',
				'dropin_removed' => $dropinRemoved,
				'flushed'        => $flushed,
			];
		}
	}

	/**
	 * Open a fresh standalone connection and flush the prefix-scoped keyspace.
	 * Does NOT use the live engine; works even when the engine is broken.
	 *
	 * @return bool True when flush succeeded or no config is stored.
	 */
	private function standaloneFlush(): bool
	{
		$loader = new ObjectCacheConfig();
		$config = $loader->load();

		if ( $config === [] ) {
			return true; // No config; nothing to flush.
		}

		try {
			$conn   = new RedisConnection( $config );
			$redis  = $conn->acquire();
			$prefix = isset( $config['prefix'] ) && is_string( $config['prefix'] )
				? (string) $config['prefix'] : 'wpmgr';
			$shared = ! isset( $config['shared'] ) || (bool) $config['shared'];
			$async  = isset( $config['async_flush'] ) && (bool) $config['async_flush'];
			$strategy = isset( $config['flush_strategy'] ) && is_string( $config['flush_strategy'] )
				? (string) $config['flush_strategy'] : 'auto';

			$useFlushDb = ( $strategy === 'flushdb' || $strategy === 'auto' ) && ! $shared;

			if ( $useFlushDb ) {
				$redis->flushDB( $async );
			} else {
				// SCAN+MATCH+UNLINK prefix-scoped.
				$cursor  = '0';
				$pattern = $prefix . ':*';
				do {
					$result = $redis->scan( $cursor, [ 'match' => $pattern, 'count' => 500 ] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_scan -- phpredis SCAN command, not filesystem
					if ( ! is_array( $result ) || count( $result ) !== 2 ) {
						break;
					}
					$cursor = (string) $result[0];
					$keys   = is_array( $result[1] ) ? $result[1] : [];
					if ( $keys !== [] ) {
						if ( $async ) {
							$redis->unlink( ...$keys );
						} else {
							$redis->del( ...$keys );
						}
					}
				} while ( $cursor !== '0' );
			}

			$conn->close();
			return true;

		} catch ( \Throwable $e ) {
			return false; // Best-effort flush.
		}
	}
}
