<?php
/**
 * WPMgr Object Cache drop-in stub (object-cache.php).
 *
 * This file is a static locator stub. WordPress loads it very early from
 * wp-content/object-cache.php. Its sole job is to find the real engine inside
 * the WPMgr agent plugin directory and include it. The engine itself is never
 * written to wp-content; only this stub is.
 *
 * Bail-outs applied here (before the engine loads):
 *   - Direct web request (no ABSPATH).
 *   - PHP version below our 8.1 floor.
 *   - WP install mode (wp_installing() true).
 *   - Kill-switch constant WPMGR_OBJECT_CACHE_DISABLED.
 *
 * Coexistence with advanced-cache.php: this file is included from
 * wp-settings.php AFTER advanced-cache.php, so both drop-ins can be active
 * simultaneously. Neither calls exit() on a miss, so the other is never
 * silenced.
 *
 * WPMgr Object Cache drop-in
 * Version: 1.0.0
 *
 * @package WPMgr\Agent\ObjectCache
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

// PHP floor: the engine uses PHP 8.1 features.
if ( PHP_VERSION_ID < 80100 ) {
	return;
}

// WP install-mode bail-out: during install the DB is not ready.
if ( function_exists( 'wp_installing' ) && wp_installing() ) {
	return;
}

// Kill-switch: operator or host can disable without removing the file.
if ( defined( 'WPMGR_OBJECT_CACHE_DISABLED' ) && WPMGR_OBJECT_CACHE_DISABLED ) {
	return;
}

// Locate the engine entry file. We probe our plugin dir (when the agent is
// installed as a normal plugin) and the mu-plugin loader path. We do NOT probe
// arbitrary directories; we control both paths.
$wpmgr_oc_engine = '';

if ( defined( 'WPMGR_AGENT_DIR' ) ) {
	$wpmgr_oc_candidate = rtrim( (string) constant( 'WPMGR_AGENT_DIR' ), '/\\' )
		. '/includes/object-cache/class-object-cache-engine.php';
	if ( @is_file( $wpmgr_oc_candidate ) ) {
		$wpmgr_oc_engine = $wpmgr_oc_candidate;
	}
}

if ( $wpmgr_oc_engine === '' && defined( 'WP_PLUGIN_DIR' ) ) {
	$wpmgr_oc_candidate = rtrim( (string) constant( 'WP_PLUGIN_DIR' ), '/\\' )
		. '/wpmgr-agent/includes/object-cache/class-object-cache-engine.php';
	if ( @is_file( $wpmgr_oc_candidate ) ) {
		$wpmgr_oc_engine = $wpmgr_oc_candidate;
	}
}

if ( $wpmgr_oc_engine === '' ) {
	// Engine not found — do NOT fatal; leave the native DB-backed cache in place.
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG-gated diagnostic; not a data leak
		error_log( 'WPMgr Object Cache: engine file not found — falling back to DB object cache.' );
	}
	return;
}

require_once $wpmgr_oc_engine;

unset( $wpmgr_oc_engine, $wpmgr_oc_candidate );
