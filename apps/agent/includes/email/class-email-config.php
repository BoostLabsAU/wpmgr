<?php
/**
 * EmailConfig — typed value object for the per-site email configuration.
 *
 * Loaded from the wp-option wpmgr_email_config (autoload false). The secret
 * field is NEVER stored in this option; it lives in the keystore under
 * Keystore::OPTION_EMAIL_SECRET. This class carries only non-secret state.
 *
 * @package WPMgr\Agent\Email
 */

declare(strict_types=1);

namespace WPMgr\Agent\Email;

/**
 * Immutable per-site email configuration value object.
 */
final class EmailConfig {

	/** wp-option key for non-secret email config (autoload false). */
	public const OPTION = 'wpmgr_email_config';

	/** Valid provider slugs. */
	public const PROVIDERS = array( 'smtp', 'ses', 'sendgrid', 'mailgun', 'postmark' );

	/** Provider slug (smtp|ses|sendgrid|mailgun|postmark). */
	public string $provider;

	/** Default From: email address. */
	public string $from_address;

	/** Default From: display name. */
	public string $from_name;

	/** When true, override WP's generated From address with from_address. */
	public bool $force_from_email;

	/** When true, override WP's generated display name with from_name. */
	public bool $force_from_name;

	/** When true, set the Return-Path / bounce address. */
	public bool $return_path;

	/**
	 * Non-secret provider settings. Shape by provider:
	 *   smtp:      host, port, encryption(none|ssl|tls), auth(bool), username, auto_tls(bool)
	 *   ses:       access_key, region, return_path
	 *   sendgrid:  (none — secret is the sole configuration)
	 *   mailgun:   domain_name, region(us|eu)
	 *   postmark:  message_stream, track_opens(bool), track_links(bool)
	 *
	 * @var array<string,mixed>
	 */
	public array $config;

	/**
	 * Per-FROM routing map: from_address => connection_key (string).
	 * Old agents sent inline arrays here; ProviderRouter keeps the is_array()
	 * branch for backward compatibility.
	 *
	 * @var array<string,mixed>
	 */
	public array $mappings;

	/**
	 * Named connection registry (keyed by connection slug).
	 * Sent by CP as a replace-all payload; absent/empty = no named connections.
	 * Each value is a wire shape: {provider, config, from_address, from_name}.
	 * Secrets are NOT stored here — they live in the keystore connection map.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $connections;

	/**
	 * The default connection key to use when no FROM mapping resolves.
	 * '' or 'default' both mean "use the primary (top-level config row)".
	 */
	public string $default_connection;

	/**
	 * Fallback connection key used for exactly one retry when the primary send fails.
	 * '' means no fallback is configured.
	 */
	public string $fallback_connection;

	/** Whether the agent logs each send to the local wpmgr_email_log table. */
	public bool $log_emails;

	/** Whether the log row includes the full message body (default false). */
	public bool $store_body;

	/** Maximum age in days of local log rows. Default 14. */
	public int $retention_days;

	/**
	 * Build from a raw associative array (the decoded wp-option value).
	 * Unknown keys are dropped; missing keys fall back to safe defaults.
	 *
	 * @param array<string,mixed> $raw Raw option array.
	 */
	public function __construct( array $raw = array() ) {
		$provider = isset( $raw['provider'] ) && is_string( $raw['provider'] ) ? $raw['provider'] : '';
		$this->provider = in_array( $provider, self::PROVIDERS, true ) ? $provider : '';

		$this->from_address = isset( $raw['from_address'] ) && is_string( $raw['from_address'] )
			? sanitize_email( $raw['from_address'] ) : '';

		$this->from_name = isset( $raw['from_name'] ) && is_string( $raw['from_name'] )
			? sanitize_text_field( $raw['from_name'] ) : '';

		$this->force_from_email = ! empty( $raw['force_from_email'] );
		$this->force_from_name  = ! empty( $raw['force_from_name'] );
		$this->return_path      = ! empty( $raw['return_path'] );

		$this->config = ( isset( $raw['config'] ) && is_array( $raw['config'] ) )
			? $raw['config'] : array();

		$this->mappings = ( isset( $raw['mappings'] ) && is_array( $raw['mappings'] ) )
			? $raw['mappings'] : array();

		// Connections registry: keyed by slug, values are wire shape arrays.
		// Per-connection secrets are intentionally NOT stored here.
		if ( isset( $raw['connections'] ) && is_array( $raw['connections'] ) ) {
			$connections = array();
			foreach ( $raw['connections'] as $key => $wire ) {
				if ( is_string( $key ) && is_array( $wire ) ) {
					$connections[ $key ] = $wire;
				}
			}
			$this->connections = $connections;
		} else {
			$this->connections = array();
		}

		$dc = isset( $raw['default_connection'] ) && is_string( $raw['default_connection'] )
			? $raw['default_connection'] : '';
		$this->default_connection = $dc;

		$fc = isset( $raw['fallback_connection'] ) && is_string( $raw['fallback_connection'] )
			? $raw['fallback_connection'] : '';
		$this->fallback_connection = $fc;

		$this->log_emails = ! empty( $raw['log_emails'] );
		$this->store_body = ! empty( $raw['store_body'] );

		$days = isset( $raw['retention_days'] ) ? (int) $raw['retention_days'] : 14;
		$this->retention_days = max( 1, min( 365, $days ) );
	}

	/**
	 * Serialize to an array suitable for update_option().
	 * The secret field is intentionally excluded.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'provider'           => $this->provider,
			'from_address'       => $this->from_address,
			'from_name'          => $this->from_name,
			'force_from_email'   => $this->force_from_email,
			'force_from_name'    => $this->force_from_name,
			'return_path'        => $this->return_path,
			'config'             => $this->config,
			'mappings'           => $this->mappings,
			'connections'        => $this->connections,
			'default_connection' => $this->default_connection,
			'fallback_connection' => $this->fallback_connection,
			'log_emails'         => $this->log_emails,
			'store_body'         => $this->store_body,
			'retention_days'     => $this->retention_days,
		);
	}

	/**
	 * Whether a provider has been configured (has a non-empty provider slug).
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return $this->provider !== '';
	}

	/**
	 * Load from wp-options. Returns a default (unconfigured) instance when
	 * the option is absent or malformed.
	 *
	 * @return self
	 */
	public static function load(): self {
		if ( ! function_exists( 'get_option' ) ) {
			return new self();
		}
		$stored = get_option( self::OPTION );
		if ( ! is_array( $stored ) ) {
			return new self();
		}
		return new self( $stored );
	}
}
