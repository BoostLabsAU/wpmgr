<?php
/**
 * ObjectCacheConfigOwnershipTest — root-uid ownership alignment for save() and
 * writeCooldownState().
 *
 * The E2E harness provisions the object-cache config via WP-CLI running as root,
 * so the config file ends up 0600 root:root. Apache (www-data) cannot read it,
 * causing the web engine to boot in array mode (config_unreadable) while CLI is
 * connected. This test pins the fix.
 *
 * Two-part design (running as non-root cannot exercise chown):
 *
 *   (a) Behavioral guard — save() and writeCooldownState() on a normal user still
 *       succeed and the config/state files are 0600. Pins no regression.
 *
 *   (b) Source-structure assertion — the root-uid chown branch exists in save()
 *       AND in writeCooldownState() with fileowner(dirname(...)) as the target.
 *       The E2E harness (root provisioning) is the real behavioral proof.
 *
 * @package WPMgr\Agent\Tests\ObjectCache
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests\ObjectCache;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WPMgr\Agent\ObjectCache\ObjectCacheConfig;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\ObjectCache\ObjectCacheConfig::save
 */
final class ObjectCacheConfigOwnershipTest extends TestCase
{
	/** @var string Temporary directory for config file writes. */
	private string $tmpDir;

	protected function set_up(): void
	{
		parent::set_up();
		Monkey\setUp();
		Functions\when( 'update_option' )->justReturn( true );
		$this->tmpDir = sys_get_temp_dir() . '/wpmgr_oc_own_test_' . uniqid( '', true );
		mkdir( $this->tmpDir, 0755, true );
	}

	protected function tear_down(): void
	{
		$files = glob( $this->tmpDir . '/*' );
		if ( is_array( $files ) ) {
			foreach ( $files as $file ) {
				@unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test cleanup
			}
		}
		@rmdir( $this->tmpDir );
		Monkey\tearDown();
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Part (a): behavioral guard — save() works and is 0600 for normal users.
	// -------------------------------------------------------------------------

	/**
	 * save() must succeed and produce a 0600 config file when called as a
	 * non-root user (the common case). This pins that the ownership branch
	 * does not break normal operation.
	 */
	public function test_save_succeeds_and_is_0600_for_normal_user(): void
	{
		if ( PHP_OS_FAMILY === 'Windows' ) {
			$this->markTestSkipped( 'POSIX permissions do not apply on Windows' );
		}

		$cfg    = new ObjectCacheConfig( $this->tmpDir );
		$config = ObjectCacheConfig::fromParams( [ 'host' => '127.0.0.1', 'password' => 'secret' ] );

		$result = $cfg->save( $config );
		$this->assertTrue( $result, 'save() must return true on a writable directory' );

		$path  = $this->tmpDir . '/' . ObjectCacheConfig::FILENAME;
		$this->assertFileExists( $path );

		$perms = fileperms( $path );
		$this->assertNotFalse( $perms, 'fileperms() must succeed on the written config file' );
		$this->assertSame(
			0600,
			$perms & 0777,
			'config file must be 0600 regardless of the current umask or ownership path'
		);
	}

	/**
	 * save() must load back the same config it wrote (round-trip), confirming
	 * the ownership alignment block does not corrupt the file content.
	 */
	public function test_save_round_trip_preserves_content(): void
	{
		$cfg    = new ObjectCacheConfig( $this->tmpDir );
		$config = ObjectCacheConfig::fromParams( [
			'host'   => '10.0.0.1',
			'port'   => 6380,
			'prefix' => 'mytest',
		] );

		$this->assertTrue( $cfg->save( $config ) );

		// Use a fresh instance so the memoized load is not served.
		$cfg2   = new ObjectCacheConfig( $this->tmpDir );
		$loaded = $cfg2->load();

		$this->assertSame( '10.0.0.1', $loaded['host'] );
		$this->assertSame( 6380, $loaded['port'] );
		$this->assertSame( 'mytest', $loaded['prefix'] );
	}

	// -------------------------------------------------------------------------
	// Part (b): source-structure assertion — chown branch exists in both methods.
	// -------------------------------------------------------------------------

	/**
	 * save() source must contain the ownership-alignment chown branch
	 * that targets the owner of the WordPress core entry file.
	 *
	 * The E2E harness (WP-CLI running as root) is the real behavioral proof; this
	 * assertion ensures the branch is never accidentally removed by a refactor.
	 */
	public function test_save_source_contains_root_uid_chown_branch(): void
	{
		$src = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/includes/object-cache/class-object-cache-config.php'
		);

		$this->assertStringContainsString(
			"'index.php'",
			$src,
			'save() source must reference the core entry file as the ownership target'
		);

		$this->assertStringContainsString(
			'fileowner',
			$src,
			'save() source must call fileowner() to read the reference owner'
		);

		$this->assertStringContainsString(
			'chown',
			$src,
			'save() source must call chown() to align the config file owner'
		);

		$this->assertStringContainsString(
			'chgrp',
			$src,
			'save() source must call chgrp() to align the config file group'
		);
	}

	/**
	 * writeCooldownState() source in the engine must contain the same root-uid
	 * chown branch with fileowner(dirname(...)) as the target.
	 *
	 * The state file (.wpmgr-oc-state.json) is written by the same boot path and
	 * can suffer the same ownership trap when the process runs as root.
	 */
	public function test_write_cooldown_state_source_contains_root_uid_chown_branch(): void
	{
		$src = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/includes/object-cache/class-object-cache-engine.php'
		);

		// The chown block must be inside / near writeCooldownState. We locate
		// the method definition and assert the key identifiers appear after it.
		$methodPos = strpos( $src, 'function writeCooldownState' );
		$this->assertNotFalse(
			$methodPos,
			'writeCooldownState() must be present in class-object-cache-engine.php'
		);

		// Grab source from the method onwards (up to next public/private function).
		$methodSrc = substr( $src, (int) $methodPos, 3500 );


		$this->assertStringContainsString(
			'fileowner',
			$methodSrc,
			'writeCooldownState() must call fileowner() to read the parent directory owner'
		);

		$this->assertStringContainsString(
			'dirname(',
			$methodSrc,
			'writeCooldownState() must derive the parent directory from the state-file path'
		);

		$this->assertStringContainsString(
			'chown',
			$methodSrc,
			'writeCooldownState() must call chown() to align the state file owner'
		);

		$this->assertStringContainsString(
			'chgrp',
			$methodSrc,
			'writeCooldownState() must call chgrp() to align the state file group'
		);
	}

	/**
	 * The drop-in artifact must also contain the ownership branch, since
	 * save() is inlined into it. This ensures the artifact rebuild
	 * propagated the fix.
	 */
	public function test_dropin_artifact_contains_ownership_branch(): void
	{
		$artifactPath = dirname( __DIR__, 2 ) . '/assets/wpmgr-object-cache-dropin.php';
		if ( ! is_file( $artifactPath ) ) {
			$this->markTestSkipped( 'Drop-in artifact not found; run php tools/build-object-cache-dropin.php first' );
		}

		$src = (string) file_get_contents( $artifactPath );

		$this->assertStringContainsString(
			"'index.php'",
			$src,
			'Drop-in artifact must reference the core entry file as the ownership target'
		);

		$this->assertStringContainsString(
			'fileowner',
			$src,
			'Drop-in artifact must contain the fileowner() call for ownership alignment'
		);
	}
}
