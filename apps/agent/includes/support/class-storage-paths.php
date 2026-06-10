<?php
/**
 * StoragePaths: shared resolver for user-data storage directories.
 *
 * Returns an uploads-first base path for plugin-generated user data, honoring
 * the relocatable upload_path option and multisite per-site subdirectories.
 * Falls back to WP_CONTENT_DIR only when the uploads directory is unavailable.
 *
 * Convention (wp.org Guideline compliance):
 *   - User-generated data (quarantine, snapshots, local backups) -> uploads/wpmgr-<purpose>
 *   - Page cache, optimizer assets, config drop-ins              -> wp-content/cache/wpmgr
 *     (conventional; matches WP Super Cache / W3TC / WP Rocket; kept as-is)
 *
 * @package WPMgr\Agent\Support
 */

declare(strict_types=1);

namespace WPMgr\Agent\Support;

/**
 * Provides the canonical storage-path resolver for user-data directories.
 */
final class StoragePaths {

	/**
	 * Resolve the absolute base path for a named user-data purpose.
	 *
	 * The returned path is uploads-first:
	 *   wp_upload_dir()['basedir'] / wpmgr-<purpose>   (preferred)
	 *   WP_CONTENT_DIR / wpmgr-<purpose>               (fallback)
	 *
	 * Returns '' when neither base is available; callers MUST check for '' and
	 * throw/bail before any write.
	 *
	 * @param string $purpose Lowercase slug describing the data purpose
	 *                        (e.g. 'quarantine', 'snapshots', 'backups').
	 * @return string Absolute path WITHOUT a trailing slash, or '' on failure.
	 */
	public static function dataBase( string $purpose ): string {
		if ( function_exists( 'wp_upload_dir' ) ) {
			$upload = wp_upload_dir();
			if ( is_array( $upload )
				&& isset( $upload['basedir'] )
				&& is_string( $upload['basedir'] )
				&& $upload['basedir'] !== ''
			) {
				return rtrim( $upload['basedir'], '/\\' ) . '/wpmgr-' . $purpose;
			}
		}

		// Fallback: wp-content (for hosts where uploads is not yet configured or
		// is read-only at storage-path resolution time).
		if ( defined( 'WP_CONTENT_DIR' ) && is_string( WP_CONTENT_DIR ) && WP_CONTENT_DIR !== '' ) {
			return rtrim( WP_CONTENT_DIR, '/\\' ) . '/wpmgr-' . $purpose;
		}

		return '';
	}

	/**
	 * Legacy path for a purpose (wp-content/wpmgr-<purpose>).
	 *
	 * Used by callers that need to read from the old pre-uploads location so
	 * that existing self-hosted installs are not orphaned after the directory
	 * is relocated under uploads/. Returns '' when WP_CONTENT_DIR is absent.
	 *
	 * @param string $purpose Lowercase slug (same value as passed to dataBase()).
	 * @return string Absolute legacy path without trailing slash, or '' on failure.
	 */
	public static function legacyBase( string $purpose ): string {
		if ( defined( 'WP_CONTENT_DIR' ) && is_string( WP_CONTENT_DIR ) && WP_CONTENT_DIR !== '' ) {
			return rtrim( WP_CONTENT_DIR, '/\\' ) . '/wpmgr-' . $purpose;
		}
		return '';
	}
}
