<?php
/**
 * ObjectcacheEnableCommand — installs the object-cache.php drop-in stub
 * after verifying the stored config hash matches the tested hash.
 *
 * Wire contract (CP -> agent):
 *   POST /wp-json/wpmgr/v1/command/objectcache.enable
 *   Authorization: Bearer <Ed25519 JWT, cmd="objectcache.enable", aud=<siteId>>
 *   Body: ObjectCacheEnableRequest { config_hash: string }
 *
 * Response: ObjectCacheEnableResult {
 *   ok, detail, dropin_installed, foreign_dropin, transients_purged
 * }
 *
 * Handshake gate: the CP only issues this command after a passing objectcache.test
 * result. The agent verifies that the stored config hash matches the hash in the
 * request before proceeding.
 *
 * @package WPMgr\Agent\Commands
 */

declare(strict_types=1);

namespace WPMgr\Agent\Commands;

use WPMgr\Agent\ObjectCache\ObjectCacheConfig;
use WPMgr\Agent\ObjectCache\ObjectCacheDropinInstaller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Installs the object-cache.php drop-in.
 */
final class ObjectcacheEnableCommand implements CommandInterface
{
	/**
	 * {@inheritDoc}
	 */
	public function name(): string
	{
		return 'objectcache.enable';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string,mixed> $claims Validated JWT claims (unused).
	 * @param array<string,mixed> $params ObjectCacheEnableRequest fields.
	 * @return array{ok:bool,detail:string,dropin_installed:bool,foreign_dropin:bool,transients_purged:int}
	 */
	public function execute( array $claims, array $params ): array
	{
		$defaultResult = [
			'ok'               => false,
			'detail'           => '',
			'dropin_installed' => false,
			'foreign_dropin'   => false,
			'transients_purged' => 0,
		];

		try {
			// Validate the config hash.
			$requestedHash = isset( $params['config_hash'] ) && is_string( $params['config_hash'] )
				? sanitize_text_field( $params['config_hash'] ) : '';

			if ( $requestedHash === '' ) {
				$defaultResult['detail'] = 'config_hash is required';
				return $defaultResult;
			}

			$loader = new ObjectCacheConfig();
			$config = $loader->load();

			if ( $config === [] ) {
				$defaultResult['detail'] = 'no config stored; call objectcache.apply_config first';
				return $defaultResult;
			}

			$storedHash = $loader->computeHash( $config );

			// hash_equals: constant-time comparison for config hashes.
			if ( ! hash_equals( $storedHash, $requestedHash ) ) {
				$defaultResult['detail'] = 'config hash mismatch; re-run objectcache.test with the current config';
				return $defaultResult;
			}

			// Install the drop-in.
			$installer = new ObjectCacheDropinInstaller();
			$install   = $installer->install();

			if ( $install['foreign_dropin'] ) {
				$defaultResult['detail']        = $install['detail'];
				$defaultResult['foreign_dropin'] = true;
				return $defaultResult;
			}

			if ( ! $install['ok'] ) {
				$defaultResult['detail'] = $install['detail'];
				return $defaultResult;
			}

			// Purge transients so they migrate to Redis.
			$purged = $installer->purgeTransients();

			return [
				'ok'               => true,
				'detail'           => 'drop-in installed',
				'dropin_installed' => true,
				'foreign_dropin'   => false,
				'transients_purged' => $purged,
			];

		} catch ( \Throwable $e ) {
			$defaultResult['detail'] = 'objectcache.enable failed: ' . get_class( $e );
			return $defaultResult;
		}
	}
}
