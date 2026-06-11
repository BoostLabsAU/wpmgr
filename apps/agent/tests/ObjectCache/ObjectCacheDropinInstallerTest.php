<?php
/**
 * ObjectCacheDropinInstaller: lifecycle tests.
 *
 * @package WPMgr\Agent\Tests\ObjectCache
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests\ObjectCache;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WPMgr\Agent\ObjectCache\ObjectCacheDropinInstaller;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\ObjectCache\ObjectCacheDropinInstaller
 */
final class ObjectCacheDropinInstallerTest extends TestCase
{
	private string $tmpDir;

	private string $stubPath;

	protected function set_up(): void
	{
		parent::set_up();
		Monkey\setUp();
		// Brain Monkey defines this when absent; a bootstrap.php definition
		// would break Patchwork redefinition for every other test.
		Functions\when( 'wp_delete_file' )->alias(
			static function ( $file ) {
				@unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test stub
			}
		);

		$this->tmpDir = sys_get_temp_dir() . '/wpmgr_dropin_test_' . uniqid( '', true );
		mkdir( $this->tmpDir, 0755, true );

		// Create a minimal stub file that contains our signature.
		$this->stubPath = $this->tmpDir . '/stub.php';
		file_put_contents(
			$this->stubPath,
			"<?php\n// " . ObjectCacheDropinInstaller::SIGNATURE . "\n// Version: 1.0.0\n"
		);
	}

	protected function tear_down(): void
	{
		Monkey\tearDown();
		$files = glob( $this->tmpDir . '/*' );
		if ( is_array( $files ) ) {
			foreach ( $files as $file ) {
				@unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test cleanup
			}
		}
		@rmdir( $this->tmpDir );
		parent::tear_down();
	}

	public function test_state_missing_when_no_file(): void
	{
		$installer = new ObjectCacheDropinInstaller( $this->tmpDir, $this->stubPath );
		$this->assertSame( ObjectCacheDropinInstaller::STATE_MISSING, $installer->state() );
	}

	public function test_install_creates_drop_in(): void
	{
		$installer = new ObjectCacheDropinInstaller( $this->tmpDir, $this->stubPath );
		$result    = $installer->install();

		$this->assertTrue( $result['ok'], $result['detail'] );
		$this->assertFalse( $result['foreign_dropin'] );
		$this->assertFileExists( $this->tmpDir . '/' . ObjectCacheDropinInstaller::CANONICAL );
	}

	public function test_state_ours_current_after_install(): void
	{
		$installer = new ObjectCacheDropinInstaller( $this->tmpDir, $this->stubPath );
		$installer->install();
		$this->assertSame( ObjectCacheDropinInstaller::STATE_OURS_CURRENT, $installer->state() );
	}

	public function test_install_idempotent(): void
	{
		$installer = new ObjectCacheDropinInstaller( $this->tmpDir, $this->stubPath );
		$installer->install();

		$result = $installer->install();
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'already current', $result['detail'] );
	}

	public function test_uninstall_removes_ours(): void
	{
		$installer = new ObjectCacheDropinInstaller( $this->tmpDir, $this->stubPath );
		$installer->install();

		$removed = $installer->uninstall();
		$this->assertTrue( $removed );
		$this->assertSame( ObjectCacheDropinInstaller::STATE_MISSING, $installer->state() );
	}

	public function test_foreign_dropin_not_overwritten_without_force(): void
	{
		// Write a foreign drop-in (no WPMgr signature).
		$path = $this->tmpDir . '/' . ObjectCacheDropinInstaller::CANONICAL;
		file_put_contents( $path, "<?php\n// Some other cache plugin drop-in.\n" );

		$installer = new ObjectCacheDropinInstaller( $this->tmpDir, $this->stubPath );
		$result    = $installer->install();

		$this->assertFalse( $result['ok'] );
		$this->assertTrue( $result['foreign_dropin'] );
		// File should still be the foreign content.
		$this->assertStringContainsString( 'Some other cache plugin', (string) file_get_contents( $path ) );
	}

	public function test_foreign_dropin_overwritten_with_force(): void
	{
		$path = $this->tmpDir . '/' . ObjectCacheDropinInstaller::CANONICAL;
		file_put_contents( $path, "<?php\n// Some other cache plugin drop-in.\n" );

		$installer = new ObjectCacheDropinInstaller( $this->tmpDir, $this->stubPath );
		$result    = $installer->install( true );

		$this->assertTrue( $result['ok'] );
		$this->assertStringContainsString(
			ObjectCacheDropinInstaller::SIGNATURE,
			(string) file_get_contents( $path )
		);
	}

	public function test_uninstall_leaves_foreign_drop_in(): void
	{
		$path = $this->tmpDir . '/' . ObjectCacheDropinInstaller::CANONICAL;
		file_put_contents( $path, "<?php\n// Foreign plugin.\n" );

		$installer = new ObjectCacheDropinInstaller( $this->tmpDir, $this->stubPath );
		$installer->uninstall(); // Should no-op.

		$this->assertFileExists( $path );
		$this->assertStringContainsString( 'Foreign plugin', (string) file_get_contents( $path ) );
	}

	public function test_state_foreign_for_unknown_file(): void
	{
		$path = $this->tmpDir . '/' . ObjectCacheDropinInstaller::CANONICAL;
		file_put_contents( $path, "<?php\n// Not ours.\n" );

		$installer = new ObjectCacheDropinInstaller( $this->tmpDir, $this->stubPath );
		$this->assertSame( ObjectCacheDropinInstaller::STATE_FOREIGN, $installer->state() );
	}

	public function test_is_writable_with_temp_dir(): void
	{
		$installer = new ObjectCacheDropinInstaller( $this->tmpDir, $this->stubPath );
		$this->assertTrue( $installer->isWritable() );
	}

	/**
	 * S5: an existing drop-in that is UNREADABLE (permissions deny read) must be
	 * treated as FOREIGN and refused without $force, not overwritten.
	 *
	 * We simulate an unreadable file by writing a file, then chmod 000 before
	 * calling install(). The test is skipped when running as root (root ignores
	 * file permissions) or on Windows (chmod has no effect).
	 */
	public function test_unreadable_existing_dropin_treated_as_foreign_without_force(): void
	{
		if ( PHP_OS_FAMILY === 'Windows' ) {
			$this->markTestSkipped( 'chmod 000 has no effect on Windows' );
		}
		if ( function_exists( 'posix_getuid' ) && posix_getuid() === 0 ) {
			$this->markTestSkipped( 'Running as root: file permission restrictions do not apply' );
		}

		$path = $this->tmpDir . '/' . ObjectCacheDropinInstaller::CANONICAL;
		// Write a non-empty file with no WPMgr signature.
		file_put_contents( $path, "<?php\n// unknown content.\n" );
		@chmod( $path, 0000 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- test-only: simulate unreadable file for S5 check

		$installer = new ObjectCacheDropinInstaller( $this->tmpDir, $this->stubPath );
		$result    = $installer->install();

		// Restore perms before any assertions so tear_down can clean up.
		@chmod( $path, 0644 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- test-only: restore perms after S5 check

		$this->assertFalse( $result['ok'], 'install() must refuse to overwrite an unreadable file without force' );
		$this->assertTrue( $result['foreign_dropin'], 'unreadable file must be classified as foreign_dropin' );
	}

	/**
	 * S5: with $force=true the foreign guard is bypassed, but a 0000-perms file
	 * cannot be overwritten at the OS level (file_put_contents fails). The key
	 * invariant is that the forced install ATTEMPTS the write (i.e. does not
	 * refuse with 'foreign_dropin') — the write itself may fail if the OS
	 * prevents it. We verify that foreign_dropin is false (guard bypassed) even
	 * if ok is false (write blocked by OS perms).
	 */
	public function test_unreadable_existing_dropin_force_bypasses_foreign_guard(): void
	{
		if ( PHP_OS_FAMILY === 'Windows' ) {
			$this->markTestSkipped( 'chmod 000 has no effect on Windows' );
		}
		if ( function_exists( 'posix_getuid' ) && posix_getuid() === 0 ) {
			$this->markTestSkipped( 'Running as root: file permission restrictions do not apply' );
		}

		$path = $this->tmpDir . '/' . ObjectCacheDropinInstaller::CANONICAL;
		file_put_contents( $path, "<?php\n// unknown content.\n" );
		@chmod( $path, 0000 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- test-only: simulate unreadable file for S5 check

		$installer = new ObjectCacheDropinInstaller( $this->tmpDir, $this->stubPath );
		$result    = $installer->install( true );

		@chmod( $path, 0644 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- test-only: restore perms after S5 check

		// The foreign guard must be bypassed (foreign_dropin=false).
		// The write may still fail if the OS denies the 0000 file, which is fine
		// — the guard bypass is the security property being tested.
		$this->assertFalse(
			$result['foreign_dropin'],
			'install(force=true) must bypass the foreign guard even for an unreadable file'
		);
	}

	/**
	 * S5: state() classifies an unreadable file as STATE_FOREIGN (the
	 * existing state() already does this correctly; this is a regression guard).
	 */
	public function test_state_foreign_for_unreadable_file(): void
	{
		if ( PHP_OS_FAMILY === 'Windows' ) {
			$this->markTestSkipped( 'chmod 000 has no effect on Windows' );
		}
		if ( function_exists( 'posix_getuid' ) && posix_getuid() === 0 ) {
			$this->markTestSkipped( 'Running as root: file permission restrictions do not apply' );
		}

		$path = $this->tmpDir . '/' . ObjectCacheDropinInstaller::CANONICAL;
		file_put_contents( $path, "<?php\n// some content.\n" );
		@chmod( $path, 0000 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- test-only: simulate unreadable file

		$installer = new ObjectCacheDropinInstaller( $this->tmpDir, $this->stubPath );
		$state     = $installer->state();

		@chmod( $path, 0644 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- test-only: restore perms

		$this->assertSame( ObjectCacheDropinInstaller::STATE_FOREIGN, $state );
	}
}
