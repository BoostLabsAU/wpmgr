<?php
/**
 * SuppressionCache — local cache of suppressed email addresses, populated by
 * pulling deltas from the CP on a 15-minute wp-cron schedule.
 *
 * Architecture:
 *   - On each pull tick the agent calls:
 *       GET {cp_url}/agent/v1/email/suppression?since=<cursor>
 *     signed with the same Ed25519 auth headers used by EmailLogReporter and
 *     PerfReporter.
 *   - The CP returns a JSON array of { email_hash: string, active: bool }
 *     objects representing new/updated suppression entries since the cursor.
 *   - The agent stores the full suppression set in a wp-option (compact JSON
 *     array of sha256 hashes of lowercased email addresses).
 *   - The cursor is a Unix timestamp (integer) advanced to the `pull_time`
 *     returned by the CP, or to the current time when the CP omits it.
 *
 * Usage — pre-send check:
 *   $suppression = new SuppressionCache($settings, $signer);
 *   if ($suppression->is_suppressed('user@example.com')) { ... }
 *
 * The hash used for storage and comparison is sha256(strtolower(email)) so
 * plaintext addresses are never persisted locally.
 *
 * @package WPMgr\Agent\Email
 */

declare(strict_types=1);

namespace WPMgr\Agent\Email;

use WPMgr\Agent\Settings;
use WPMgr\Agent\Signer;

/**
 * Pulls suppression-list deltas from the CP and provides a local is_suppressed check.
 */
class SuppressionCache implements SuppressionCheckerInterface {

	/** wp-cron hook for the 15-minute pull. */
	public const HOOK_PULL = 'wpmgr_email_suppression_pull';

	/** wp-option: Unix timestamp cursor — last successful pull time. */
	public const OPTION_CURSOR = 'wpmgr_email_suppression_cursor';

	/** wp-option: JSON-encoded array of sha256 hashes (compact, autoload=false). */
	public const OPTION_HASHES = 'wpmgr_email_suppression_hashes';

	/** CP endpoint path. */
	private const PATH = '/agent/v1/email/suppression';

	/** Maximum entries kept locally; oldest entries evicted beyond this. */
	private const MAX_HASHES = 50000;

	private Settings $settings;

	private Signer $signer;

	/**
	 * @param Settings $settings Enrollment / CP-URL state.
	 * @param Signer   $signer   Agent Ed25519 signer (same as EmailLogReporter).
	 */
	public function __construct( Settings $settings, Signer $signer ) {
		$this->settings = $settings;
		$this->signer   = $signer;
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Pull suppression deltas from the CP and update the local hash store.
	 * Fire-and-forget: never throws; errors are silently skipped.
	 *
	 * Bound to HOOK_PULL (every 15 min) from Plugin::registerHooks().
	 *
	 * @return void
	 */
	public function pull(): void {
		if ( ! $this->settings->isEnrolled() ) {
			return;
		}
		if ( ! function_exists( 'wp_remote_get' ) ) {
			return;
		}

		try {
			$this->doPull();
		} catch ( \Throwable $e ) {
			// Fire-and-forget.
		}
	}

	/**
	 * Check whether a recipient email address is in the local suppression cache.
	 *
	 * Comparison is done by sha256(strtolower(email)) so the plaintext address
	 * is never persisted.
	 *
	 * @param string $email Recipient email address.
	 * @return bool True when the address is suppressed and should not be sent to.
	 */
	public function is_suppressed( string $email ): bool {
		if ( $email === '' ) {
			return false;
		}

		$hashes = $this->load_hashes();
		if ( $hashes === array() ) {
			return false;
		}

		$hash = hash( 'sha256', strtolower( $email ) );
		return isset( $hashes[ $hash ] );
	}

	/**
	 * Schedule the 15-minute pull cron event if not already scheduled.
	 * Called from Plugin::activate() and Plugin::maybeRescheduleCron().
	 *
	 * @param int $now Current Unix timestamp.
	 * @return void
	 */
	public static function schedule_pull( int $now ): void {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
			return;
		}
		if ( wp_next_scheduled( self::HOOK_PULL ) !== false ) {
			return;
		}
		wp_schedule_event( $now + 60, \WPMgr\Agent\Scheduler::SCHEDULE_15MIN, self::HOOK_PULL );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Inner pull: GET suppression?since=<cursor>, parse, merge, persist.
	 *
	 * @return void
	 */
	private function doPull(): void {
		$base = $this->settings->controlPlaneUrl();
		if ( $base === '' ) {
			return;
		}

		$cursor = (int) ( function_exists( 'get_option' )
			? get_option( self::OPTION_CURSOR, 0 )
			: 0 );

		$path = self::PATH . '?since=' . $cursor;

		try {
			$auth = $this->signer->signHeaders( 'GET', $path, '' );
		} catch ( \Throwable $e ) {
			return;
		}

		$headers = array_merge(
			array( 'Accept' => 'application/json' ),
			$auth
		);

		$response = wp_remote_get(
			$base . $path,
			array(
				'timeout'   => 10,
				'headers'   => $headers,
				'sslverify' => true,
			)
		);

		if ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) {
			return;
		}

		$status = function_exists( 'wp_remote_retrieve_response_code' )
			? (int) wp_remote_retrieve_response_code( $response )
			: 0;

		if ( $status < 200 || $status >= 300 ) {
			return;
		}

		$raw_body = function_exists( 'wp_remote_retrieve_body' )
			? (string) wp_remote_retrieve_body( $response )
			: '';

		$decoded = json_decode( $raw_body, true );
		if ( ! is_array( $decoded ) ) {
			return;
		}

		// CP response shape: { entries: [{email_hash: string, active: bool}], pull_time: int }
		$entries    = isset( $decoded['entries'] ) && is_array( $decoded['entries'] )
			? $decoded['entries'] : array();
		$pull_time  = isset( $decoded['pull_time'] ) ? (int) $decoded['pull_time'] : time();

		if ( $entries !== array() ) {
			$this->merge_entries( $entries );
		}

		// Advance the cursor regardless of whether entries were present.
		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION_CURSOR, $pull_time, false );
		}
	}

	/**
	 * Merge delta entries into the local hash store.
	 *
	 * @param array<int,array<string,mixed>> $entries Delta from the CP.
	 * @return void
	 */
	private function merge_entries( array $entries ): void {
		$hashes = $this->load_hashes();

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$email_hash = isset( $entry['email_hash'] ) ? (string) $entry['email_hash'] : '';
			$active     = ! empty( $entry['active'] );

			// Only store valid 64-char hex sha256 hashes.
			if ( strlen( $email_hash ) !== 64 || ! ctype_xdigit( $email_hash ) ) {
				continue;
			}

			if ( $active ) {
				$hashes[ $email_hash ] = 1;
			} else {
				unset( $hashes[ $email_hash ] );
			}
		}

		// Enforce MAX_HASHES: if over the limit, evict by slice (oldest keys
		// are the ones that were added earliest — array insertion order).
		if ( count( $hashes ) > self::MAX_HASHES ) {
			$hashes = array_slice( $hashes, -self::MAX_HASHES, null, true );
		}

		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION_HASHES, wp_json_encode( array_keys( $hashes ) ), false );
		}
	}

	/**
	 * Load the in-memory hash map (hash => 1) from the stored wp-option.
	 *
	 * @return array<string,int>
	 */
	private function load_hashes(): array {
		if ( ! function_exists( 'get_option' ) ) {
			return array();
		}

		$stored = get_option( self::OPTION_HASHES, '' );
		if ( ! is_string( $stored ) || $stored === '' ) {
			return array();
		}

		$keys = json_decode( $stored, true );
		if ( ! is_array( $keys ) ) {
			return array();
		}

		$map = array();
		foreach ( $keys as $k ) {
			if ( is_string( $k ) ) {
				$map[ $k ] = 1;
			}
		}
		return $map;
	}
}
