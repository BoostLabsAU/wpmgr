<?php
/**
 * SyncEmailConfigCommand — receives a per-site email config from the control
 * plane and persists it into wp-options + the agent keystore.
 *
 * Wire contract (CP -> agent):
 *   POST /wp-json/wpmgr/v1/command/sync_email_config
 *   Authorization: Bearer <Ed25519 JWT with cmd="sync_email_config", aud=<siteId>>
 *   Content-Type: application/json
 *   Body: EmailConfigRequest (see apps/api/internal/agentcmd/email_contract.go)
 *
 * Response: { "ok": bool, "detail": string }
 *
 * The secret field travels in the signed JWT-protected body over HTTPS.
 * The agent immediately AES-256-GCM-encrypts it into the keystore option
 * wpmgr_agent_email_secret and never echoes or logs it. Passing secret=""
 * removes any previously stored secret.
 *
 * @package WPMgr\Agent\Commands
 */

declare(strict_types=1);

namespace WPMgr\Agent\Commands;

use WPMgr\Agent\Email\EmailConfig;
use WPMgr\Agent\Email\EmailKeystoreInterface;

/**
 * Persists a CP-pushed email config into wp-options + keystore.
 */
final class SyncEmailConfigCommand implements CommandInterface {

	/** Recognised non-secret config keys (whitelist). */
	private const KNOWN_KEYS = array(
		'provider',
		'from_address',
		'from_name',
		'force_from_email',
		'force_from_name',
		'return_path',
		'config',
		'mappings',
		'log_emails',
		'store_body',
		'retention_days',
	);

	private EmailKeystoreInterface $keystore;

	/**
	 * @param EmailKeystoreInterface $keystore Agent keystore (for secret storage/removal).
	 */
	public function __construct( EmailKeystoreInterface $keystore ) {
		$this->keystore = $keystore;
	}

	/** @inheritDoc */
	public function name(): string {
		return 'sync_email_config';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string,mixed> $claims Validated JWT claims.
	 * @param array<string,mixed> $params EmailConfigRequest fields.
	 * @return array{ok:bool,detail:string}
	 */
	public function execute( array $claims, array $params ): array {
		// Validate provider if present.
		if ( array_key_exists( 'provider', $params ) ) {
			$provider = $params['provider'];
			if ( ! is_string( $provider ) ) {
				return array( 'ok' => false, 'detail' => 'provider must be a string' );
			}
			if ( ! in_array( $provider, EmailConfig::PROVIDERS, true ) ) {
				return array( 'ok' => false, 'detail' => 'provider must be one of: ' . implode( ', ', EmailConfig::PROVIDERS ) );
			}
		}

		// Validate config is an object/array if present.
		if ( array_key_exists( 'config', $params ) && ! is_array( $params['config'] ) ) {
			return array( 'ok' => false, 'detail' => 'config must be an object' );
		}

		// Validate mappings is an object/array if present.
		if ( array_key_exists( 'mappings', $params ) && ! is_array( $params['mappings'] ) ) {
			return array( 'ok' => false, 'detail' => 'mappings must be an object' );
		}

		// Extract the secret BEFORE building the config array; it is never stored
		// in the wp-option, only in the keystore.
		$secret = '';
		if ( array_key_exists( 'secret', $params ) ) {
			if ( ! is_string( $params['secret'] ) ) {
				return array( 'ok' => false, 'detail' => 'secret must be a string' );
			}
			$secret = $params['secret'];
		}

		// Build a clean config map from the known keys only.
		$current = EmailConfig::load()->to_array();
		$clean   = $current;
		foreach ( self::KNOWN_KEYS as $key ) {
			if ( array_key_exists( $key, $params ) ) {
				$clean[ $key ] = $params[ $key ];
			}
		}

		// Persist the non-secret config.
		try {
			$cfg = new EmailConfig( $clean );
			if ( function_exists( 'update_option' ) ) {
				update_option( EmailConfig::OPTION, $cfg->to_array(), false );
			}
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'detail' => 'failed to persist email config' );
		}

		// Persist the secret into the keystore (empty string removes it).
		// The secret was transmitted only in the signed JWT body over HTTPS;
		// we never log it.
		try {
			$this->keystore->storeEmailSecret( $secret );
		} catch ( \Throwable $e ) {
			// Secret storage failure is non-fatal for the config itself; the
			// operator will see send failures and can retry.
			return array( 'ok' => true, 'detail' => 'email config saved; secret storage failed: keystore unavailable' );
		}

		$detail = $secret === '' ? 'email config saved; secret cleared' : 'email config saved';
		return array( 'ok' => true, 'detail' => $detail );
	}
}
