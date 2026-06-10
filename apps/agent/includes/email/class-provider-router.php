<?php
/**
 * ProviderRouter — resolves the right provider handler for a given message,
 * drives send + one fallback retry, and delegates logging to EmailLogger.
 *
 * Resolution order:
 *   1. Suppression check: any recipient whose sha256(lower(email)) is in the
 *      local SuppressionCache is filtered out before dispatch. If ALL recipients
 *      are suppressed the send is failed immediately (logged as 'suppressed').
 *   2. If a FROM-address-to-connection mapping is configured and matches the
 *      outgoing From address, that connection is used.
 *   3. Otherwise the default (single) connection from EmailConfig is used.
 *
 * On failure the router retries ONCE via the configured fallback connection
 * (same row, retries incremented) — unless $disable_fallback is true (used
 * by the send_test_email command so the real provider error surfaces).
 *
 * v1 implements a single connection; the mappings/fallback fields are wired but
 * the multi-connection registry is left for Phase 3.
 *
 * @package WPMgr\Agent\Email
 */

declare(strict_types=1);

namespace WPMgr\Agent\Email;

use WPMgr\Agent\Email\EmailKeystoreInterface;

/**
 * Resolves the outgoing-mail handler and drives send + log for a single message.
 */
class ProviderRouter {

	private EmailKeystoreInterface $keystore;

	private EmailLogger $logger;

	/** @var array<string,ProviderHandlerInterface> Slug => handler instance. */
	private array $handlers = array();

	/** Optional suppression checker for pre-send recipient checks. */
	private ?SuppressionCheckerInterface $suppression_cache = null;

	/**
	 * @param EmailKeystoreInterface          $keystore          Agent keystore (for secret retrieval).
	 * @param EmailLogger                     $logger            Local send-event logger.
	 * @param SuppressionCheckerInterface|null $suppression_cache Optional local suppression checker.
	 */
	public function __construct(
		EmailKeystoreInterface $keystore,
		EmailLogger $logger,
		?SuppressionCheckerInterface $suppression_cache = null
	) {
		$this->keystore          = $keystore;
		$this->logger            = $logger;
		$this->suppression_cache = $suppression_cache;
	}

	/**
	 * Register a provider handler. Called during Plugin boot.
	 *
	 * @param ProviderHandlerInterface $handler Provider handler.
	 * @return void
	 */
	public function register( ProviderHandlerInterface $handler ): void {
		$this->handlers[ $handler->provider() ] = $handler;
	}

	/**
	 * Send a normalised mail payload via the resolved provider handler.
	 *
	 * Before dispatching, any recipient whose sha256(lower(email)) is in the
	 * local SuppressionCache is removed. When ALL recipients are suppressed the
	 * send fails immediately and a log row with status='suppressed' is written.
	 *
	 * @param array<string,mixed> $mail            Normalised mail payload.
	 * @param EmailConfig         $cfg             Current email config.
	 * @param bool                $disable_fallback When true, skip the fallback retry.
	 * @return array{ok:bool,message_id:string,detail:string}
	 */
	public function send( array $mail, EmailConfig $cfg, bool $disable_fallback = false ): array {
		// -- Suppression check ------------------------------------------------
		$filtered = $this->filter_suppressed_recipients( $mail );
		if ( $filtered['all_suppressed'] ) {
			$error = 'recipient suppressed';
			$this->maybe_log( $mail, $cfg->provider, 'suppressed', '', $error, '', 0, $cfg );
			return array( 'ok' => false, 'message_id' => '', 'detail' => $error );
		}
		// Replace the To list with the filtered (non-suppressed) set.
		$mail = $filtered['mail'];

		// Resolve the connection for the outgoing From address.
		$connection = $this->resolve_connection( (string) ( $mail['from'] ?? '' ), $cfg );

		$secret = $this->keystore->get_email_secret();

		$handler = $this->handlers[ $connection['provider'] ] ?? null;
		if ( $handler === null ) {
			$error = 'no handler registered for provider: ' . $connection['provider'];
			$this->maybe_log( $mail, $connection['provider'], 'failed', '', $error, '', 0, $cfg );
			return array( 'ok' => false, 'message_id' => '', 'detail' => $error );
		}

		$result = $handler->send( $mail, $connection['config'], $secret );

		if ( $result['ok'] ) {
			$this->maybe_log( $mail, $connection['provider'], 'sent', $result['message_id'], '', $result['provider_response'], 0, $cfg );
			return array( 'ok' => true, 'message_id' => $result['message_id'], 'detail' => '' );
		}

		// First attempt failed — try fallback unless disabled.
		if ( ! $disable_fallback ) {
			$fallback = $this->resolve_fallback( $cfg );
			if ( $fallback !== null ) {
				$fallback_secret  = $this->keystore->get_email_secret();
				$fallback_handler = $this->handlers[ $fallback['provider'] ] ?? null;
				if ( $fallback_handler !== null ) {
					$fb_result = $fallback_handler->send( $mail, $fallback['config'], $fallback_secret );
					$status    = $fb_result['ok'] ? 'sent' : 'failed';
					$this->maybe_log( $mail, $fallback['provider'], $status, $fb_result['message_id'], $fb_result['error'], $fb_result['provider_response'], 1, $cfg );
					return array(
						'ok'         => $fb_result['ok'],
						'message_id' => $fb_result['message_id'],
						'detail'     => $fb_result['ok'] ? '' : $fb_result['error'],
					);
				}
			}
		}

		// No fallback or fallback handler missing — log the original failure.
		$this->maybe_log( $mail, $connection['provider'], 'failed', '', $result['error'], $result['provider_response'], 0, $cfg );
		return array( 'ok' => false, 'message_id' => '', 'detail' => $result['error'] );
	}

	/**
	 * Resolve the active connection config for a given From address.
	 *
	 * v1: mappings are reserved for multi-connection Phase 3. All sends use
	 * the single default connection from EmailConfig.
	 *
	 * @param string      $from_address Resolved From address.
	 * @param EmailConfig $cfg          Current email config.
	 * @return array{provider:string,config:array<string,mixed>}
	 */
	public function resolve_connection( string $from_address, EmailConfig $cfg ): array {
		// Check per-FROM mappings (Phase 3 multi-connection hook).
		if ( $from_address !== '' && isset( $cfg->mappings[ $from_address ] ) ) {
			$mapped = $cfg->mappings[ $from_address ];
			if ( is_array( $mapped ) && isset( $mapped['provider'] ) && is_string( $mapped['provider'] ) ) {
				return array(
					'provider' => $mapped['provider'],
					'config'   => is_array( $mapped['config'] ?? null ) ? $mapped['config'] : array(),
				);
			}
		}

		// Default single connection.
		return array(
			'provider' => $cfg->provider,
			'config'   => $cfg->config,
		);
	}

	/**
	 * Resolve the fallback connection, if any.
	 *
	 * v1: fallback is a reserved field on EmailConfig (config['fallback_provider']
	 * and config['fallback_config']). When absent, returns null (no retry).
	 *
	 * @param EmailConfig $cfg Current email config.
	 * @return array{provider:string,config:array<string,mixed>}|null
	 */
	private function resolve_fallback( EmailConfig $cfg ): ?array {
		$fallback_provider = isset( $cfg->config['fallback_provider'] )
			? (string) $cfg->config['fallback_provider'] : '';

		if ( $fallback_provider === '' || ! in_array( $fallback_provider, EmailConfig::PROVIDERS, true ) ) {
			return null;
		}

		$fallback_config = isset( $cfg->config['fallback_config'] ) && is_array( $cfg->config['fallback_config'] )
			? $cfg->config['fallback_config'] : array();

		return array(
			'provider' => $fallback_provider,
			'config'   => $fallback_config,
		);
	}

	/**
	 * Filter suppressed recipients from the mail payload.
	 *
	 * Returns:
	 *   - all_suppressed: true when every To address is in the suppression list.
	 *   - mail: the payload with the To list reduced to non-suppressed addresses.
	 *
	 * When no SuppressionCheckerInterface is wired (null), no recipients are filtered.
	 *
	 * @param array<string,mixed> $mail Original mail payload.
	 * @return array{all_suppressed:bool,mail:array<string,mixed>}
	 */
	private function filter_suppressed_recipients( array $mail ): array {
		if ( $this->suppression_cache === null ) {
			return array( 'all_suppressed' => false, 'mail' => $mail );
		}

		$to = (array) ( $mail['to'] ?? array() );
		if ( $to === array() ) {
			return array( 'all_suppressed' => false, 'mail' => $mail );
		}

		$allowed = array();
		foreach ( $to as $addr ) {
			if ( ! $this->suppression_cache->is_suppressed( (string) $addr ) ) {
				$allowed[] = $addr;
			}
		}

		if ( $allowed === array() ) {
			// Every recipient suppressed.
			return array( 'all_suppressed' => true, 'mail' => $mail );
		}

		$mail['to'] = array_values( $allowed );
		return array( 'all_suppressed' => false, 'mail' => $mail );
	}

	/**
	 * Write a log row if email logging is enabled.
	 *
	 * @param array<string,mixed> $mail      Normalised mail payload.
	 * @param string              $provider  Provider slug.
	 * @param string              $status    'sent', 'failed', or 'suppressed'.
	 * @param string              $msg_id    Provider message-id.
	 * @param string              $error     Error string (empty on success).
	 * @param string              $response  Raw provider response.
	 * @param int                 $retries   Retry count consumed.
	 * @param EmailConfig         $cfg       Runtime config.
	 * @return void
	 */
	private function maybe_log(
		array $mail,
		string $provider,
		string $status,
		string $msg_id,
		string $error,
		string $response,
		int $retries,
		EmailConfig $cfg
	): void {
		if ( ! $cfg->log_emails ) {
			return;
		}
		$this->logger->write( $mail, $provider, $status, $msg_id, $error, $response, $retries, $cfg );
	}
}
