<?php
/**
 * ProviderHandlerInterface — contract for per-provider outgoing-mail handlers.
 *
 * Every handler receives a normalised mail payload built from the wp_mail()
 * arguments (to, subject, message, headers, attachments) plus the resolved
 * connection config and the decrypted secret. It returns a structured result
 * envelope carrying the provider HTTP status, message-id (when available), and
 * a human error string on failure.
 *
 * @package WPMgr\Agent\Email
 */

declare(strict_types=1);

namespace WPMgr\Agent\Email;

/**
 * Contract implemented by each per-provider send handler.
 */
interface ProviderHandlerInterface {

	/**
	 * Send one email via the provider.
	 *
	 * @param array<string,mixed> $mail   Normalised mail payload:
	 *   to         string[]    Recipients.
	 *   cc         string[]    CC recipients.
	 *   bcc        string[]    BCC recipients.
	 *   reply_to   string[]    Reply-To addresses.
	 *   from       string      Resolved From address (after force-from logic).
	 *   from_name  string      Resolved From display name.
	 *   subject    string      Subject line.
	 *   body_text  string      Plain-text body part (may be empty).
	 *   body_html  string      HTML body part (may be empty).
	 *   headers    string[]    Extra raw headers (already applied; informational).
	 *   attachments list<array{name:string,path:string,mime:string}> Attachments.
	 *   return_path bool       Whether to set a Return-Path / bounce address.
	 *   x_site_id  string      Site-ID correlation header value.
	 * @param array<string,mixed> $config Non-secret provider settings from EmailConfig.
	 * @param string              $secret Decrypted provider secret (password/API key).
	 * @return array{ok:bool,message_id:string,error:string,provider_response:string}
	 */
	public function send( array $mail, array $config, string $secret ): array;

	/**
	 * Provider slug (smtp|ses|sendgrid|mailgun|postmark).
	 *
	 * @return string
	 */
	public function provider(): string;
}
