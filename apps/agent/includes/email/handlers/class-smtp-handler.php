<?php
/**
 * SmtpHandler — sends via WordPress's bundled PHPMailer over SMTP.
 *
 * PHPMailer is loaded by WP in wp-includes/PHPMailer/. We construct a fresh
 * instance (avoiding any global state from the `phpmailer_init` filter used
 * by WP's own wp_mail() path, since we are intercepting before that path).
 *
 * Config shape (non-secret, from EmailConfig::$config):
 *   host        string  SMTP hostname.
 *   port        int     SMTP port (default 587).
 *   encryption  string  'none'|'ssl'|'tls' (default 'tls').
 *   auth        bool    Whether to use SMTP AUTH (default true).
 *   username    string  SMTP username.
 *   auto_tls    bool    Whether PHPMailer auto-negotiates TLS (default true).
 *
 * Secret (from keystore): SMTP password.
 *
 * @package WPMgr\Agent\Email\Handlers
 */

declare(strict_types=1);

namespace WPMgr\Agent\Email\Handlers;

use WPMgr\Agent\Email\ProviderHandlerInterface;

/**
 * SMTP provider handler using WP's bundled PHPMailer.
 */
final class SmtpHandler implements ProviderHandlerInterface {

	/** @inheritDoc */
	public function provider(): string {
		return 'smtp';
	}

	/** @inheritDoc */
	public function send( array $mail, array $config, string $secret ): array {
		// Ensure PHPMailer is available (WP loads it in wp-includes/PHPMailer/).
		if ( ! class_exists( 'PHPMailer\\PHPMailer\\PHPMailer' ) ) {
			if ( defined( 'ABSPATH' ) ) {
				$src = ABSPATH . 'wp-includes/PHPMailer/PHPMailer.php';
				if ( is_file( $src ) ) {
					require_once $src;
					require_once ABSPATH . 'wp-includes/PHPMailer/Exception.php';
					require_once ABSPATH . 'wp-includes/PHPMailer/SMTP.php';
				}
			}
			if ( ! class_exists( 'PHPMailer\\PHPMailer\\PHPMailer' ) ) {
				return $this->failure( 'PHPMailer class not available' );
			}
		}

		try {
			$phpmailer = new \PHPMailer\PHPMailer\PHPMailer( true );
			$phpmailer->isSMTP();
			$phpmailer->CharSet = isset( $mail['charset'] ) ? (string) $mail['charset'] : 'UTF-8';

			// -- SMTP connection settings ------------------------------------
			$host = isset( $config['host'] ) && is_string( $config['host'] ) ? trim( $config['host'] ) : '';
			if ( $host === '' ) {
				return $this->failure( 'SMTP host not configured' );
			}
			$phpmailer->Host = $host;
			$phpmailer->Port = isset( $config['port'] ) ? (int) $config['port'] : 587;

			$encryption = isset( $config['encryption'] ) && is_string( $config['encryption'] )
				? strtolower( $config['encryption'] ) : 'tls';

			switch ( $encryption ) {
				case 'ssl':
					$phpmailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
					$phpmailer->SMTPAutoTLS = false;
					break;
				case 'none':
					$phpmailer->SMTPSecure = '';
					$phpmailer->SMTPAutoTLS = false;
					break;
				default: // 'tls'
					$phpmailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
					$auto_tls = ! isset( $config['auto_tls'] ) || (bool) $config['auto_tls'];
					$phpmailer->SMTPAutoTLS = $auto_tls;
					break;
			}

			$use_auth = ! isset( $config['auth'] ) || (bool) $config['auth'];
			if ( $use_auth ) {
				$phpmailer->SMTPAuth = true;
				$phpmailer->Username = isset( $config['username'] ) && is_string( $config['username'] )
					? $config['username'] : '';
				$phpmailer->Password = $secret;
			}

			// -- From ---------------------------------------------------------
			$phpmailer->setFrom( (string) ( $mail['from'] ?? '' ), (string) ( $mail['from_name'] ?? '' ) );

			if ( ! empty( $mail['return_path'] ) ) {
				$phpmailer->Sender = (string) ( $mail['from'] ?? '' );
			}

			// -- Recipients ---------------------------------------------------
			foreach ( (array) ( $mail['to'] ?? array() ) as $addr ) {
				$phpmailer->addAddress( (string) $addr );
			}
			foreach ( (array) ( $mail['cc'] ?? array() ) as $addr ) {
				$phpmailer->addCC( (string) $addr );
			}
			foreach ( (array) ( $mail['bcc'] ?? array() ) as $addr ) {
				$phpmailer->addBCC( (string) $addr );
			}
			foreach ( (array) ( $mail['reply_to'] ?? array() ) as $addr ) {
				$phpmailer->addReplyTo( (string) $addr );
			}

			// -- Subject + body -----------------------------------------------
			$phpmailer->Subject = (string) ( $mail['subject'] ?? '' );

			$body_html = (string) ( $mail['body_html'] ?? '' );
			$body_text = (string) ( $mail['body_text'] ?? '' );

			if ( $body_html !== '' ) {
				$phpmailer->isHTML( true );
				$phpmailer->Body    = $body_html;
				$phpmailer->AltBody = $body_text;
			} else {
				$phpmailer->isHTML( false );
				$phpmailer->Body = $body_text;
			}

			// -- Correlation header -------------------------------------------
			$site_id = (string) ( $mail['x_site_id'] ?? '' );
			if ( $site_id !== '' ) {
				$phpmailer->addCustomHeader( 'X-WPMgr-Site', $site_id );
			}

			// -- Attachments --------------------------------------------------
			foreach ( (array) ( $mail['attachments'] ?? array() ) as $att ) {
				if ( ! is_array( $att ) || empty( $att['path'] ) ) {
					continue;
				}
				$phpmailer->addAttachment(
					(string) $att['path'],
					(string) ( $att['name'] ?? '' ),
					'base64',
					(string) ( $att['mime'] ?? 'application/octet-stream' )
				);
			}

			$phpmailer->send();

			$message_id = $phpmailer->getLastMessageID();

			return array(
				'ok'                => true,
				'message_id'        => (string) $message_id,
				'error'             => '',
				'provider_response' => 'SMTP send OK',
			);
		} catch ( \Throwable $e ) {
			return $this->failure( $e->getMessage() );
		}
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
