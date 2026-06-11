<?php
/**
 * WPMgr Object Cache drop-in stub (object-cache.php).
 *
 * This file is a locator stub. WordPress loads it very early from
 * wp-content/object-cache.php. Its sole job is to find the real engine inside
 * the WPMgr agent plugin directory and include it. The engine itself is never
 * written to wp-content; only this stub is.
 *
 * At install time the ObjectCacheDropinInstaller replaces the placeholder token
 * __WPMGR_OC_ENGINE_PATH__ with the var_export'd absolute path to the engine
 * file, giving the stub a reliable first-probe candidate that works even before
 * WordPress has set up plugin-directory constants.
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
 * Version: 1.2.0
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

// Locate the engine entry file.
//
// Probe order:
//   1. Stamped absolute path (replaced by installer at install time from the
//      agent plugin's own WPMGR_AGENT_DIR — defined at install time even though
//      it is undefined this early during wp_start_object_cache()). This is the
//      authoritative path for any correctly installed stub.
//   2. WP_CONTENT_DIR fallback — WP_CONTENT_DIR IS defined before drop-ins load;
//      resilient to the plugin directory being moved after install.
//   3. WPMGR_AGENT_DIR probe — only defined when the agent plugin is fully loaded;
//      harmless fallback for any edge case.
$wpmgr_oc_engine = '';

// Probe 1: stamped absolute path (placeholder replaced at install time).
// The comparison token is concatenated so the installer's str_replace of the
// quoted placeholder cannot rewrite this guard into a self-comparison.
$wpmgr_oc_stamped = '__WPMGR_OC_ENGINE_PATH__';
if ( $wpmgr_oc_stamped !== '' && $wpmgr_oc_stamped !== '__WPMGR' . '_OC_ENGINE_PATH__' ) {
	if ( @is_file( $wpmgr_oc_stamped ) ) {
		$wpmgr_oc_engine = $wpmgr_oc_stamped;
	}
}

// Probe 2: WP_CONTENT_DIR (always defined before drop-ins load).
if ( $wpmgr_oc_engine === '' && defined( 'WP_CONTENT_DIR' ) ) {
	$wpmgr_oc_candidate = rtrim( (string) constant( 'WP_CONTENT_DIR' ), '/\\' )
		. '/plugins/wpmgr-agent/includes/object-cache/class-object-cache-engine.php';
	if ( @is_file( $wpmgr_oc_candidate ) ) {
		$wpmgr_oc_engine = $wpmgr_oc_candidate;
	}
}

// Probe 3: WPMGR_AGENT_DIR (defined only after plugin load; edge-case fallback).
if ( $wpmgr_oc_engine === '' && defined( 'WPMGR_AGENT_DIR' ) ) {
	$wpmgr_oc_candidate = rtrim( (string) constant( 'WPMGR_AGENT_DIR' ), '/\\' )
		. '/includes/object-cache/class-object-cache-engine.php';
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

unset( $wpmgr_oc_engine, $wpmgr_oc_candidate, $wpmgr_oc_stamped );
