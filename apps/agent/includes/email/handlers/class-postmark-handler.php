<?php
/**
 * PostmarkHandler — sends via the Postmark Email API.
 *
 * Endpoint: POST https://api.postmarkapp.com/email
 * Auth:     X-Postmark-Server-Token header.
 * Success:  HTTP 200 (with JSON body containing 'MessageID').
 *
 * Config shape (non-secret, from EmailConfig::$config):
 *   message_stream  string  Postmark message stream (default 'outbound').
 *   track_opens     bool    Whether to track email opens.
 *   track_links     string  'None'|'HtmlAndText'|'HtmlOnly'|'TextOnly'
 *                           (default 'None').
 *
 * Secret (from keystore): Postmark Server API Token.
 *
 * @package WPMgr\Agent\Email\Handlers
 */

declare(strict_types=1);

namespace WPMgr\Agent\Email\Handlers;

use WPMgr\Agent\Email\ProviderHandlerInterface;

/**
 * Postmark Email API provider handler.
 */
final class PostmarkHandler implements ProviderHandlerInterface {

	private const ENDPOINT = 'https://api.postmarkapp.com/email';

	/** Valid track_links values. */
	private const TRACK_LINKS_VALUES = array( 'None', 'HtmlAndText', 'HtmlOnly', 'TextOnly' );

	/** @inheritDoc */
	public function provider(): string {
		return 'postmark';
	}

	/** @inheritDoc */
	public function send( array $mail, array $config, string $secret ): array {
		if ( $secret === '' ) {
			return $this->failure( 'Postmark Server Token not configured' );
		}

		$message_stream = isset( $config['message_stream'] ) && is_string( $config['message_stream'] )
			? trim( $config['message_stream'] ) : 'outbound';
		if ( $message_stream === '' ) {
			$message_stream = 'outbound';
		}

		$track_opens = ! empty( $config['track_opens'] );

		$track_links_raw = isset( $config['track_links'] ) && is_string( $config['track_links'] )
			? $config['track_links'] : 'None';
		$track_links = in_array( $track_links_raw, self::TRACK_LINKS_VALUES, true )
			? $track_links_raw : 'None';

		// -- Build payload ---------------------------------------------------
		$from_str  = (string) ( $mail['from'] ?? '' );
		$from_name = (string) ( $mail['from_name'] ?? '' );
		if ( $from_name !== '' ) {
			$from_str = $from_name . ' <' . $from_str . '>';
		}

		$payload = array(
			'From'          => $from_str,
			'To'            => implode( ',', (array) ( $mail['to'] ?? array() ) ),
			'Subject'       => (string) ( $mail['subject'] ?? '' ),
			'MessageStream' => $message_stream,
			'TrackOpens'    => $track_opens,
			'TrackLinks'    => $track_links,
		);

		$cc = (array) ( $mail['cc'] ?? array() );
		if ( $cc !== array() ) {
			$payload['Cc'] = implode( ',', $cc );
		}

		$bcc = (array) ( $mail['bcc'] ?? array() );
		if ( $bcc !== array() ) {
			$payload['Bcc'] = implode( ',', $bcc );
		}

		$reply_to = (array) ( $mail['reply_to'] ?? array() );
		if ( $reply_to !== array() ) {
			$payload['ReplyTo'] = implode( ',', $reply_to );
		}

		$body_html = (string) ( $mail['body_html'] ?? '' );
		$body_text = (string) ( $mail['body_text'] ?? '' );

		if ( $body_html !== '' ) {
			$payload['HtmlBody'] = $body_html;
		}
		if ( $body_text !== '' ) {
			$payload['TextBody'] = $body_text;
		}

		// Return-Path: Postmark sets bounce address from the inbound stream;
		// the ReplyTo field is the closest equivalent for sender indication.
		// No extra field needed beyond what MessageStream configures.

		// Custom headers — X-WPMgr-Site correlation.
		$site_id = (string) ( $mail['x_site_id'] ?? '' );
		if ( $site_id !== '' ) {
			$payload['Headers'] = array(
				array(
					'Name'  => 'X-WPMgr-Site',
					'Value' => $site_id,
				),
			);
		}

		// Postmark Metadata — included in every webhook event payload, letting
		// the CP webhook fan-out resolve the originating site/tenant without
		// inspecting MIME headers.
		$metadata = array();
		if ( $site_id !== '' ) {
			$metadata['wpmgr_site'] = $site_id;
		}
		$tenant_id = (string) ( $mail['x_tenant_id'] ?? '' );
		if ( $tenant_id !== '' ) {
			$metadata['wpmgr_tenant'] = $tenant_id;
		}
		if ( $metadata !== array() ) {
			$payload['Metadata'] = $metadata;
		}

		// Attachments.
		$attachments = (array) ( $mail['attachments'] ?? array() );
		if ( $attachments !== array() ) {
			$pm_attachments = array();
			foreach ( $attachments as $att ) {
				if ( ! is_array( $att ) || empty( $att['path'] ) ) {
					continue;
				}
				$data = @file_get_contents( (string) $att['path'] );
				if ( $data === false ) {
					continue;
				}
				$pm_attachments[] = array(
					'Name'        => (string) ( $att['name'] ?? basename( (string) $att['path'] ) ),
					'Content'     => base64_encode( $data ),
					'ContentType' => (string) ( $att['mime'] ?? 'application/octet-stream' ),
				);
			}
			if ( $pm_attachments !== array() ) {
				$payload['Attachments'] = $pm_attachments;
			}
		}

		$json = wp_json_encode( $payload );
		if ( $json === false ) {
			return $this->failure( 'Failed to encode Postmark request body' );
		}

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => 30,
				'headers' => array(
					'Accept'                   => 'application/json',
					'Content-Type'             => 'application/json',
					'X-Postmark-Server-Token'  => $secret,
				),
				'body'    => $json,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->failure( $response->get_error_message() );
		}

		$code     = (int) wp_remote_retrieve_response_code( $response );
		$body_str = (string) wp_remote_retrieve_body( $response );

		if ( $code !== 200 ) {
			$msg = $this->parse_error( $body_str );
			return $this->failure( 'Postmark error ' . $code . ': ' . $msg );
		}

		$decoded    = json_decode( $body_str, true );
		$message_id = '';
		if ( is_array( $decoded ) && isset( $decoded['MessageID'] ) ) {
			$message_id = (string) $decoded['MessageID'];
		}

		return array(
			'ok'                => true,
			'message_id'        => $message_id,
			'error'             => '',
			'provider_response' => substr( $body_str, 0, 500 ),
		);
	}

	/**
	 * Parse a Postmark error JSON body.
	 *
	 * @param string $body Response body.
	 * @return string Human-readable error.
	 */
	private function parse_error( string $body ): string {
		$decoded = json_decode( $body, true );
		if ( is_array( $decoded ) && isset( $decoded['Message'] ) ) {
			return (string) $decoded['Message'];
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
