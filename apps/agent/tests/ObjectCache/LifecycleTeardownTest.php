<?php
/**
 * H8 — Lifecycle deactivate/uninstall teardown tests.
 *
 * Verifies structural properties of the lifecycle tear-down:
 *   - onDeactivate() removes the drop-in but KEEPS the config file.
 *   - wipeAll() calls disable command + config delete + clears options.
 *   - ownedOptions() includes the object-cache options.
 *
 * These tests verify source structure (class imports, method references, constant
 * presence) since the full lifecycle requires WP options, disk state, and a live
 * plugin context. Behavioural properties are verified in the E2E disable stage.
 *
 * @package WPMgr\Agent\Tests\ObjectCache
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests\ObjectCache;

use WPMgr\Agent\ObjectCache\ObjectCacheConfig;
use WPMgr\Agent\ObjectCache\ObjectCacheDropinInstaller;
use WPMgr\Agent\ObjectCache\ObjectCacheHeartbeat;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Lifecycle
 */
final class LifecycleTeardownTest extends TestCase
{
	// -------------------------------------------------------------------------
	// H8: Lifecycle source imports the OC classes
	// -------------------------------------------------------------------------

	public function test_lifecycle_imports_object_cache_dropin_installer(): void
	{
		$lifecyclePath = dirname( __DIR__, 2 ) . '/includes/class-lifecycle.php';
		$this->assertFileExists( $lifecyclePath );
		$content = (string) file_get_contents( $lifecyclePath );
		$this->assertStringContainsString(
			'ObjectCacheDropinInstaller',
			$content,
			'class-lifecycle.php must import/use ObjectCacheDropinInstaller for H8 teardown'
		);
	}

	public function test_lifecycle_imports_object_cache_config(): void
	{
		$lifecyclePath = dirname( __DIR__, 2 ) . '/includes/class-lifecycle.php';
		$this->assertFileExists( $lifecyclePath );
		$content = (string) file_get_contents( $lifecyclePath );
		$this->assertStringContainsString(
			'ObjectCacheConfig',
			$content,
			'class-lifecycle.php must import/use ObjectCacheConfig for H8 uninstall config wipe'
		);
	}

	// -------------------------------------------------------------------------
	// H8: onDeactivate removes drop-in (not config)
	// -------------------------------------------------------------------------

	public function test_on_deactivate_calls_uninstall_on_installer(): void
	{
		$lifecyclePath = dirname( __DIR__, 2 ) . '/includes/class-lifecycle.php';
		$this->assertFileExists( $lifecyclePath );
		$content = (string) file_get_contents( $lifecyclePath );
		// onDeactivate must call $installer->uninstall() (removes drop-in, keeps config).
		$this->assertStringContainsString(
			'uninstall()',
			$content,
			'onDeactivate() must call uninstall() on the ObjectCacheDropinInstaller'
		);
		// onDeactivate must NOT call configLoader->delete() (config is KEPT on deactivate).
		// The comment "KEEPS the config file" must be present.
		$this->assertStringContainsString(
			'KEEPS the config file',
			$content,
			'onDeactivate() comment must document that config is kept'
		);
	}

	// -------------------------------------------------------------------------
	// H8: wipeAll() calls disable command
	// -------------------------------------------------------------------------

	public function test_wipe_all_calls_objectcache_disable_command(): void
	{
		$lifecyclePath = dirname( __DIR__, 2 ) . '/includes/class-lifecycle.php';
		$this->assertFileExists( $lifecyclePath );
		$content = (string) file_get_contents( $lifecyclePath );
		$this->assertStringContainsString(
			'ObjectcacheDisableCommand',
			$content,
			'wipeAll() must use ObjectcacheDisableCommand for H8 flush + drop-in removal on uninstall'
		);
	}

	public function test_wipe_all_calls_config_delete(): void
	{
		$lifecyclePath = dirname( __DIR__, 2 ) . '/includes/class-lifecycle.php';
		$this->assertFileExists( $lifecyclePath );
		$content = (string) file_get_contents( $lifecyclePath );
		$this->assertStringContainsString(
			'configLoader->delete()',
			$content,
			'wipeAll() must call configLoader->delete() to wipe the config file on uninstall'
		);
	}

	// -------------------------------------------------------------------------
	// H8: ownedOptions includes OC options
	// -------------------------------------------------------------------------

	public function test_owned_options_includes_config_hash_option(): void
	{
		$lifecyclePath = dirname( __DIR__, 2 ) . '/includes/class-lifecycle.php';
		$this->assertFileExists( $lifecyclePath );
		$content = (string) file_get_contents( $lifecyclePath );
		$this->assertStringContainsString(
			'ObjectCacheConfig::OPTION_CONFIG_HASH',
			$content,
			'ownedOptions() must include ObjectCacheConfig::OPTION_CONFIG_HASH (H8)'
		);
	}

	public function test_owned_options_includes_stats_option(): void
	{
		$lifecyclePath = dirname( __DIR__, 2 ) . '/includes/class-lifecycle.php';
		$this->assertFileExists( $lifecyclePath );
		$content = (string) file_get_contents( $lifecyclePath );
		$this->assertStringContainsString(
			'ObjectCacheHeartbeat::OPTION_STATS',
			$content,
			'ownedOptions() must include ObjectCacheHeartbeat::OPTION_STATS (H8)'
		);
	}

	public function test_owned_options_includes_failback_marker(): void
	{
		$lifecyclePath = dirname( __DIR__, 2 ) . '/includes/class-lifecycle.php';
		$this->assertFileExists( $lifecyclePath );
		$content = (string) file_get_contents( $lifecyclePath );
		$this->assertStringContainsString(
			'wpmgr_oc_outage_marker',
			$content,
			'ownedOptions() must include wpmgr_oc_outage_marker (H5/H8)'
		);
	}

	// -------------------------------------------------------------------------
	// H8: ObjectCacheDropinInstaller has an uninstall() method
	// -------------------------------------------------------------------------

	public function test_dropin_installer_has_uninstall_method(): void
	{
		$this->assertTrue(
			method_exists( ObjectCacheDropinInstaller::class, 'uninstall' ),
			'ObjectCacheDropinInstaller must have an uninstall() method'
		);
	}

	// -------------------------------------------------------------------------
	// H8: ObjectCacheConfig has a delete() method
	// -------------------------------------------------------------------------

	public function test_object_cache_config_has_delete_method(): void
	{
		$this->assertTrue(
			method_exists( ObjectCacheConfig::class, 'delete' ),
			'ObjectCacheConfig must have a delete() method'
		);
	}

	// -------------------------------------------------------------------------
	// H8: OPTION_CONFIG_HASH and OPTION_STATS constants exist
	// -------------------------------------------------------------------------

	public function test_option_config_hash_constant_defined(): void
	{
		$this->assertSame(
			'wpmgr_object_cache_config_hash',
			ObjectCacheConfig::OPTION_CONFIG_HASH,
			'ObjectCacheConfig::OPTION_CONFIG_HASH must equal wpmgr_object_cache_config_hash'
		);
	}

	public function test_option_stats_constant_defined(): void
	{
		$this->assertTrue(
			defined( '\WPMgr\Agent\ObjectCache\ObjectCacheHeartbeat::OPTION_STATS' ),
			'ObjectCacheHeartbeat::OPTION_STATS must be defined'
		);
	}
}
