<?php
/**
 * Cache DropinInstaller (page-cache advanced-cache.php): lifecycle and S5 tests.
 *
 * @package WPMgr\Agent\Tests\Cache
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests\Cache;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WPMgr\Agent\Cache\DropinInstaller;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Cache\DropinInstaller
 */
final class CacheDropinInstallerTest extends TestCase
{
    private string $tmpDir;

    private string $templatePath;

    protected function set_up(): void
    {
        parent::set_up();
        Monkey\setUp();
        Functions\when( 'wp_delete_file' )->alias(
            static function ( string $file ): void {
                @unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test stub
            }
        );
        $this->tmpDir = sys_get_temp_dir() . '/wpmgr_cache_dropin_test_' . uniqid( '', true );
        mkdir( $this->tmpDir, 0755, true );

        // Minimal template containing the signature and the CONFIG_TO_REPLACE placeholder.
        $this->templatePath = $this->tmpDir . '/template.php';
        file_put_contents(
            $this->templatePath,
            "<?php\n// " . DropinInstaller::SIGNATURE . "\n// CONFIG_TO_REPLACE\n"
        );
    }

    protected function tear_down(): void
    {
        $files = glob( $this->tmpDir . '/*' );
        if ( is_array( $files ) ) {
            foreach ( $files as $file ) {
                @chmod( $file, 0644 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- test cleanup: ensure all files are deletable
                @unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test cleanup
            }
        }
        @rmdir( $this->tmpDir );
        Monkey\tearDown();
        parent::tear_down();
    }

    public function test_install_creates_drop_in(): void
    {
        $installer = new DropinInstaller( $this->tmpDir, $this->templatePath );
        $result    = $installer->install( [] );

        $this->assertTrue( $result, 'install() must return true on a fresh write' );
        $this->assertFileExists( $this->tmpDir . '/' . DropinInstaller::CANONICAL );
    }

    public function test_install_idempotent_for_our_own_dropin(): void
    {
        $installer = new DropinInstaller( $this->tmpDir, $this->templatePath );
        $installer->install( [] );

        $result = $installer->install( [] );
        $this->assertTrue( $result, 'install() must return true when the file is already current' );
    }

    public function test_foreign_dropin_not_overwritten(): void
    {
        $path = $this->tmpDir . '/' . DropinInstaller::CANONICAL;
        file_put_contents( $path, "<?php\n// some other cache plugin drop-in.\n" );

        $installer = new DropinInstaller( $this->tmpDir, $this->templatePath );
        $result    = $installer->install( [] );

        $this->assertFalse( $result, 'install() must refuse a foreign drop-in' );
        // File unchanged.
        $this->assertStringContainsString( 'some other cache plugin', (string) file_get_contents( $path ) );
    }

    /**
     * S5: an existing drop-in that cannot be read (mode 000) must be treated
     * as foreign and refused, not silently overwritten.
     */
    public function test_unreadable_dropin_refused_as_foreign(): void
    {
        if ( PHP_OS_FAMILY === 'Windows' ) {
            $this->markTestSkipped( 'chmod 000 has no effect on Windows' );
        }
        if ( function_exists( 'posix_getuid' ) && posix_getuid() === 0 ) {
            $this->markTestSkipped( 'Running as root: file permission restrictions do not apply' );
        }

        $path = $this->tmpDir . '/' . DropinInstaller::CANONICAL;
        file_put_contents( $path, "<?php\n// something.\n" );
        @chmod( $path, 0000 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- test-only: simulate unreadable file for S5 check

        $installer = new DropinInstaller( $this->tmpDir, $this->templatePath );
        $result    = $installer->install( [] );

        @chmod( $path, 0644 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- test-only: restore perms

        $this->assertFalse( $result, 'install() must return false when the existing drop-in is unreadable' );
    }

    public function test_uninstall_removes_our_dropin(): void
    {
        $installer = new DropinInstaller( $this->tmpDir, $this->templatePath );
        $installer->install( [] );

        $result = $installer->uninstall();
        $this->assertTrue( $result );
        $this->assertFileDoesNotExist( $this->tmpDir . '/' . DropinInstaller::CANONICAL );
    }

    public function test_uninstall_leaves_foreign_dropin(): void
    {
        $path = $this->tmpDir . '/' . DropinInstaller::CANONICAL;
        file_put_contents( $path, "<?php\n// foreign content.\n" );

        $installer = new DropinInstaller( $this->tmpDir, $this->templatePath );
        $installer->uninstall(); // Must be a no-op.

        $this->assertFileExists( $path );
        $this->assertStringContainsString( 'foreign content', (string) file_get_contents( $path ) );
    }
}
