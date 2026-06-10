<?php
/**
 * SendgridHandler — sends via the SendGrid Web API v3.
 *
 * Endpoint: POST https://api.sendgrid.com/v3/mail/send
 * Auth:     Authorization: Bearer <api_key>
 * Success:  HTTP 202 Accepted (no body; Message-ID from X-Message-Id header).
 *
 * Config shape: (none — the API key is the sole configuration).
 * Secret (from keystore): SendGrid API key.
 *
 * @package WPMgr\Agent\Email\Handlers
 */

declare(strict_types=1);

namespace WPMgr\Agent\Email\Handlers;

use WPMgr\Agent\Email\ProviderHandlerInterface;

/**
 * SendGrid Web API v3 provider handler.
 */
final class SendgridHandler implements ProviderHandlerInterface {

	private const ENDPOINT = 'https://api.sendgrid.com/v3/mail/send';

	/** @inheritDoc */
	public function provider(): string {
		return 'sendgrid';
	}

	/** @inheritDoc */
	public function send( array $mail, array $config, string $secret ): array {
		if ( $secret === '' ) {
			return $this->failure( 'SendGrid API key not configured' );
		}

		$body = $this->build_payload( $mail );

		$json = wp_json_encode( $body );
		if ( $json === false ) {
			return $this->failure( 'Failed to encode SendGrid request body' );
		}

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $secret,
					'Content-Type'  => 'application/json',
				),
				'body'    => $json,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->failure( $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		// SendGrid returns 202 on success with no body.
		if ( $code !== 202 ) {
			$body_str = (string) wp_remote_retrieve_body( $response );
			$msg      = $this->parse_error( $body_str );
			return $this->failure( 'SendGrid error ' . $code . ': ' . $msg );
		}

		// Message-ID is returned in the X-Message-Id header.
		$message_id = (string) wp_remote_retrieve_header( $response, 'x-message-id' );

		return array(
			'ok'                => true,
			'message_id'        => $message_id,
			'error'             => '',
			'provider_response' => '202 Accepted',
		);
	}

	/**
	 * Build the SendGrid Web API v3 JSON payload.
	 *
	 * @param array<string,mixed> $mail Normalised mail payload.
	 * @return array<string,mixed>
	 */
	private function build_payload( array $mail ): array {
		// From object.
		$from_obj = array( 'email' => (string) ( $mail['from'] ?? '' ) );
		$from_name = (string) ( $mail['from_name'] ?? '' );
		if ( $from_name !== '' ) {
			$from_obj['name'] = $from_name;
		}

		// Personalisation: to / cc / bcc / reply_to.
		$personalisation = array(
			'to' => array_map(
				fn( $addr ) => array( 'email' => (string) $addr ),
				(array) ( $mail['to'] ?? array() )
			),
		);

		$cc = (array) ( $mail['cc'] ?? array() );
		if ( $cc !== array() ) {
			$personalisation['cc'] = array_map(
				fn( $addr ) => array( 'email' => (string) $addr ),
				$cc
			);
		}

		$bcc = (array) ( $mail['bcc'] ?? array() );
		if ( $bcc !== array() ) {
			$personalisation['bcc'] = array_map(
				fn( $addr ) => array( 'email' => (string) $addr ),
				$bcc
			);
		}

		$payload = array(
			'personalizations' => array( $personalisation ),
			'from'             => $from_obj,
			'subject'          => (string) ( $mail['subject'] ?? '' ),
		);

		$reply_to = (array) ( $mail['reply_to'] ?? array() );
		if ( $reply_to !== array() ) {
			$payload['reply_to'] = array( 'email' => (string) $reply_to[0] );
		}

		// Content: prefer HTML + plain text; plain text only otherwise.
		$body_html = (string) ( $mail['body_html'] ?? '' );
		$body_text = (string) ( $mail['body_text'] ?? '' );
		$content   = array();

		if ( $body_text !== '' ) {
			$content[] = array( 'type' => 'text/plain', 'value' => $body_text );
		}
		if ( $body_html !== '' ) {
			$content[] = array( 'type' => 'text/html', 'value' => $body_html );
		}
		if ( $content === array() ) {
			$content[] = array( 'type' => 'text/plain', 'value' => '' );
		}
		$payload['content'] = $content;

		// Custom headers (X-WPMgr-Site for SMTP-layer visibility).
		$site_id = (string) ( $mail['x_site_id'] ?? '' );
		if ( $site_id !== '' ) {
			$payload['headers'] = array( 'X-WPMgr-Site' => $site_id );
		}

		// custom_args: stable metadata for CP webhook fan-out (Phase 4a).
		// SendGrid includes these in every event webhook payload, letting the CP
		// resolve which site a bounce/complaint belongs to without parsing headers.
		$custom_args = array();
		if ( $site_id !== '' ) {
			$custom_args['wpmgr_site'] = $site_id;
		}
		$tenant_id = (string) ( $mail['x_tenant_id'] ?? '' );
		if ( $tenant_id !== '' ) {
			$custom_args['wpmgr_tenant'] = $tenant_id;
		}
		if ( $custom_args !== array() ) {
			$personalisation['custom_args'] = $custom_args;
			// Replace the personalisation in the payload with the updated copy.
			$payload['personalizations'] = array( $personalisation );
		}

		// Attachments.
		$attachments = (array) ( $mail['attachments'] ?? array() );
		if ( $attachments !== array() ) {
			$sg_attachments = array();
			foreach ( $attachments as $att ) {
				if ( ! is_array( $att ) || empty( $att['path'] ) ) {
					continue;
				}
				$data = @file_get_contents( (string) $att['path'] );
				if ( $data === false ) {
					continue;
				}
				$sg_attachments[] = array(
					'content'     => base64_encode( $data ),
					'type'        => (string) ( $att['mime'] ?? 'application/octet-stream' ),
					'filename'    => (string) ( $att['name'] ?? basename( (string) $att['path'] ) ),
					'disposition' => 'attachment',
				);
			}
			if ( $sg_attachments !== array() ) {
				$payload['attachments'] = $sg_attachments;
			}
		}

		return $payload;
	}

	/**
	 * Parse a SendGrid error JSON body to extract the first error message.
	 *
	 * @param string $body Response body.
	 * @return string Human-readable error.
	 */
	private function parse_error( string $body ): string {
		$decoded = json_decode( $body, true );
		if ( is_array( $decoded ) && isset( $decoded['errors'][0]['message'] ) ) {
			return (string) $decoded['errors'][0]['message'];
		}
		return substr( $body, 0, 300 );
	}

	/**
	 * Build a structured failure result.
	 *
	 * @param string $error Error message.
	 * @return array{ok:bool,message_id:string,error:string,provider_response:string}
	 */
	private function failure( string $error ): array {
		return array(
			'ok'                => false,
			'message_id'        => '',
			'error'             => $error,
			'provider_response' => $error,
		);
	}
}
