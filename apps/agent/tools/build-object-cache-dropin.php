<?php
/**
 * Build tool: generates assets/wpmgr-object-cache-dropin.php by concatenating
 * the three engine source files into a single self-contained drop-in.
 *
 * The generated file is the ONLY thing installed into wp-content/object-cache.php.
 * No path resolution, no require_once of plugin files — everything is inlined.
 *
 * Usage:
 *   php tools/build-object-cache-dropin.php [--check]
 *
 * With --check: verifies the committed artifact matches a fresh build; exits
 * non-zero when they diverge. Used by the determinism test.
 *
 * Exit codes:
 *   0  Success (or --check matched).
 *   1  Build failure or --check mismatch.
 */

declare(strict_types=1);

$pluginRoot = dirname(__DIR__);
$checkMode  = in_array('--check', $argv ?? [], true);

$sources = [
    'config'     => $pluginRoot . '/includes/object-cache/class-object-cache-config.php',
    'connection' => $pluginRoot . '/includes/object-cache/class-redis-connection.php',
    'engine'     => $pluginRoot . '/includes/object-cache/class-object-cache-engine.php',
];

$outputPath = $pluginRoot . '/assets/wpmgr-object-cache-dropin.php';

// ---------------------------------------------------------------------------
// Validate source files exist.
// ---------------------------------------------------------------------------
foreach ($sources as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "build-object-cache-dropin: source file missing: {$path}\n");
        exit(1);
    }
}

// ---------------------------------------------------------------------------
// Read source files.
// ---------------------------------------------------------------------------
$configSrc     = (string) file_get_contents($sources['config']);
$connectionSrc = (string) file_get_contents($sources['connection']);
$engineSrc     = (string) file_get_contents($sources['engine']);

// ---------------------------------------------------------------------------
// Helper: strip per-file boilerplate.
// ---------------------------------------------------------------------------

/**
 * Strip the boilerplate that appears at the top of every source file:
 *   - <?php open tag
 *   - declare(strict_types=1);
 *   - namespace Foo\Bar; declaration (non-bracketed)
 *   - if ( ! defined( 'ABSPATH' ) ) { exit; ... } guard
 *
 * The hoisted declare(strict_types=1) and the re-wrapped bracketed
 * namespace { } blocks in the generated file replace these.
 *
 * @param string $src Raw PHP source.
 * @return string Stripped source.
 */
function stripFileBoilerplate(string $src): string
{
    // Remove leading <?php (with optional trailing whitespace/newline).
    $src = preg_replace('/^\<\?php[ \t]*\r?\n?/s', '', $src) ?? $src;

    // Remove declare(strict_types=1); on its own line.
    $src = preg_replace('/^declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;\r?\n?/m', '', $src) ?? $src;

    // Remove a non-bracketed namespace declaration line.
    // e.g. "namespace WPMgr\Agent\ObjectCache;"
    $src = preg_replace('/^namespace\s+[\w\\\\]+\s*;\r?\n?/m', '', $src) ?? $src;

    // Remove the ABSPATH guard block. We match from the `if ( ! defined(` up
    // through the closing `}` and an optional trailing blank line.
    // The guard in our sources always uses this exact shape (allow flexible ws).
    $src = preg_replace(
        '/if\s*\(\s*!\s*defined\s*\(\s*[\'"]ABSPATH[\'"]\s*\)\s*\)\s*\{[^}]*\}\s*\r?\n?/s',
        '',
        $src
    ) ?? $src;

    return $src;
}

// ---------------------------------------------------------------------------
// Prepare each section.
// ---------------------------------------------------------------------------

$configStripped     = stripFileBoilerplate($configSrc);
$connectionStripped = stripFileBoilerplate($connectionSrc);

// For the engine we need to do additional processing:
// 1. Remove the supporting-class loader loop (classes are inlined above).
// 2. Separate the "boot code + functions" section from the WPMgr_Object_Cache class.
//    The class definition starts with "class WPMgr_Object_Cache".

$engineStripped = stripFileBoilerplate($engineSrc);

// Strip the supporting-class loader block (foreach … unset …).
$engineStripped = preg_replace(
    '/\/\/ -+\s*\/\/ Load supporting classes[\s\S]*?unset\s*\(\s*\$wpmgr_oc_dep\s*,\s*\$wpmgr_oc_dep_path\s*\)\s*;\s*\r?\n?/s',
    '',
    $engineStripped
) ?? $engineStripped;

// Split the engine into:
//   Part A: everything BEFORE the WPMgr_Object_Cache class definition
//           (wpmgr_get_object_cache(), boot code, shutdown, wp_cache_* functions)
//   Part B: the WPMgr_Object_Cache class definition itself
//
// The class definition begins at "class WPMgr_Object_Cache".
// We split at the line containing that token.
if (!preg_match('/^([\s\S]*?)(\/\/ ---+\s*\/\/ WPMgr_Object_Cache class[\s\S]*$)/m', $engineStripped, $m)) {
    // Fallback: split on the class keyword.
    if (!preg_match('/^([\s\S]*?)(\/\*\*\s*\n\s*\* WPMgr persistent object cache[\s\S]*$)/m', $engineStripped, $m)) {
        fwrite(STDERR, "build-object-cache-dropin: could not locate WPMgr_Object_Cache class boundary in engine source\n");
        exit(1);
    }
}

$engineFunctionsAndBoot = rtrim($m[1]);  // functions + boot code
$engineClassDefinition  = rtrim($m[2]);  // class WPMgr_Object_Cache { ... }

// ---------------------------------------------------------------------------
// Build the generated file.
// ---------------------------------------------------------------------------
//
// PHP requires that when bracketed namespace blocks are used, ALL code (except
// declare statements) lives inside a namespace block.
//
// Layout:
//   <?php / file header / declare(strict_types=1)
//   namespace { ... }                          — preamble + bail gates + breadcrumb
//   namespace WPMgr\Agent\ObjectCache { ... }  — ObjectCacheConfig + RedisConnection
//   namespace { ... }                          — WPMgr_Object_Cache class (class_exists guard)
//   namespace { ... }                          — boot code + wp_cache_* functions (function_exists guards)

// NOTE: declare(strict_types=1) is intentionally ABSENT from the generated artifact.
// The artifact is a WordPress compatibility surface (object-cache.php drop-in).
// WordPress core's cache.php API is loose-typed by design; callers may pass int as
// $group, numeric strings as $expire, etc. Strict types would cause TypeError fatals
// on valid WP caller code (e.g. Action Scheduler: wp_cache_set($k, $v, 3600)).
// Source files keep their own declares; only the generated drop-in omits it.
$fileHeader = <<<'HDR'
<?php
/**
 * WPMgr Object Cache drop-in
 * Version: 2.1.0
 *
 * Self-contained object-cache.php drop-in for WordPress. All engine classes are
 * inlined; no external file resolution can fail after installation.
 *
 * @package WPMgr\Agent\ObjectCache
 */
HDR;

// Block 1: preamble in global namespace.
$block1 = <<<'B1'

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		exit; // No direct access.
	}

	// Breadcrumb: set immediately after ABSPATH guard so the heartbeat can detect
	// whether the drop-in was executed at all and identify early-bail causes.
	$GLOBALS['wpmgr_oc_stub'] = [ 'v' => '2.1.0', 'bail' => null ];

	// PHP floor: the engine uses PHP 8.1 features.
	if ( PHP_VERSION_ID < 80100 ) {
		$GLOBALS['wpmgr_oc_stub']['bail'] = 'php_floor';
		return;
	}

	// H6: WP setup-config bail — the DB is not ready during the initial config wizard.
	// The old installing bail has been removed: wp_upgrade() flushes and
	// wp-activate.php invalidations now reach Redis during normal install mode.
	if ( defined( 'WP_SETUP_CONFIG' ) ) {
		$GLOBALS['wpmgr_oc_stub']['bail'] = 'setup_config';
		return;
	}

	// H6: Env kill-switch — operator or host can disable the OC without removing the file.
	if ( getenv( 'WPMGR_OBJECT_CACHE_DISABLED' ) !== false && (bool) getenv( 'WPMGR_OBJECT_CACHE_DISABLED' ) ) {
		$GLOBALS['wpmgr_oc_stub']['bail'] = 'killswitch_env';
		return;
	}

	// Kill-switch: constant-based disable (pre-existing).
	if ( defined( 'WPMGR_OBJECT_CACHE_DISABLED' ) && WPMGR_OBJECT_CACHE_DISABLED ) {
		$GLOBALS['wpmgr_oc_stub']['bail'] = 'killswitch';
		return;
	}

	// Success path: all engine classes are inlined below.
	$GLOBALS['wpmgr_oc_stub']['bail'] = 'engine_inline';

} // end namespace (preamble)
B1;

// Block 2: namespaced classes.
$block2 = "namespace WPMgr\\Agent\\ObjectCache {\n\n"
    . "\tif ( ! class_exists( 'WPMgr\\\\Agent\\\\ObjectCache\\\\ObjectCacheConfig', false ) ) {\n"
    . "\t\t" . ltrim($configStripped) . "\n"
    . "\t}\n\n"
    . "\tif ( ! class_exists( 'WPMgr\\\\Agent\\\\ObjectCache\\\\RedisConnection', false ) ) {\n"
    . "\t\t" . ltrim($connectionStripped) . "\n"
    . "\t}\n\n"
    . "} // end namespace WPMgr\\Agent\\ObjectCache\n";

// Block 3: WPMgr_Object_Cache class in global namespace.
$block3 = "namespace {\n\n"
    . "\tif ( ! class_exists( 'WPMgr_Object_Cache', false ) ) {\n"
    . "\t\t" . ltrim($engineClassDefinition) . "\n"
    . "\t}\n\n"
    . "} // end namespace (WPMgr_Object_Cache class)\n";

// Inject the 'booted' breadcrumb flag immediately after the top-level engine global
// assignment (the unconditional boot block, NOT inside wpmgr_get_object_cache()).
// This lets the heartbeat distinguish a complete engine boot (global assigned) from
// an incomplete boot where an exception cut short the boot code. The flag is set
// AFTER $wp_object_cache = \WPMgr_Object_Cache::boot(); so it only appears when
// the boot call itself returned without throwing.
//
// The comment anchor (phpcs:ignore … MUST assign $wp_object_cache) uniquely
// identifies the top-level boot call — wpmgr_get_object_cache() has a different
// inline comment ("object-cache drop-ins MUST assign"), so the pattern is specific.
$booted_needle   = "// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- object-cache drop-ins MUST assign \$wp_object_cache; this is the required WP pattern\n\$wp_object_cache = \\WPMgr_Object_Cache::boot();";
$booted_replacement = "// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- object-cache drop-ins MUST assign \$wp_object_cache; this is the required WP pattern\n\$wp_object_cache = \\WPMgr_Object_Cache::boot();\n\$GLOBALS['wpmgr_oc_stub']['booted'] = true;";
// Count occurrences: there should be exactly one in $engineFunctionsAndBoot.
if (substr_count($engineFunctionsAndBoot, $booted_needle) === 1) {
    $engineFunctionsAndBoot = str_replace($booted_needle, $booted_replacement, $engineFunctionsAndBoot);
} else {
    // Fallback: use preg_replace with limit=1 on the last occurrence (top-level boot).
    $engineFunctionsAndBoot = preg_replace(
        '/(\$wp_object_cache\s*=\s*\\\\WPMgr_Object_Cache::boot\(\)\s*;)(?!\s*\n[^}]*\n\s*\})/',
        '$1' . "\n\$GLOBALS['wpmgr_oc_stub']['booted'] = true;",
        $engineFunctionsAndBoot,
        1
    ) ?? $engineFunctionsAndBoot;
}

// Block 4: boot code + wp_cache_* functions.
// Wrap each top-level function in function_exists guard for double-inclusion safety.
// The boot code (global $wp_object_cache = ...; register_shutdown_function) runs
// unconditionally when the file loads (consistent with the original engine design).
$block4 = "namespace {\n\n"
    . ltrim($engineFunctionsAndBoot) . "\n\n"
    . "} // end namespace (boot + wp_cache_* functions)\n";

$output = $fileHeader . "\n"
    . $block1 . "\n"
    . $block2 . "\n"
    . $block3 . "\n"
    . $block4;

// ---------------------------------------------------------------------------
// Validate with php -l.
// ---------------------------------------------------------------------------
$tmpFile = sys_get_temp_dir() . '/wpmgr_oc_dropin_build_' . getmypid() . '.php';
file_put_contents($tmpFile, $output);

exec('php -l ' . escapeshellarg($tmpFile) . ' 2>&1', $lintOut, $lintCode);
unlink($tmpFile);

if ($lintCode !== 0) {
    fwrite(STDERR, "build-object-cache-dropin: php -l FAILED:\n");
    fwrite(STDERR, implode("\n", $lintOut) . "\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Check mode: compare against committed artifact.
// ---------------------------------------------------------------------------
if ($checkMode) {
    if (!is_file($outputPath)) {
        fwrite(STDERR, "build-object-cache-dropin: committed artifact missing at {$outputPath}\n");
        fwrite(STDERR, "Run: php tools/build-object-cache-dropin.php\n");
        exit(1);
    }
    $committed = (string) file_get_contents($outputPath);
    if ($committed === $output) {
        echo "build-object-cache-dropin: artifact is current (matches fresh build).\n";
        exit(0);
    }
    fwrite(STDERR, "build-object-cache-dropin: committed artifact is STALE.\n");
    fwrite(STDERR, "An engine source file was modified without regenerating the drop-in.\n");
    fwrite(STDERR, "Run: php tools/build-object-cache-dropin.php\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Write the artifact.
// ---------------------------------------------------------------------------
$written = file_put_contents($outputPath, $output, LOCK_EX);
if ($written === false) {
    fwrite(STDERR, "build-object-cache-dropin: failed to write {$outputPath}\n");
    exit(1);
}

echo "build-object-cache-dropin: wrote " . $written . " bytes to {$outputPath}\n";
exit(0);
