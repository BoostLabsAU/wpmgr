<?php
/**
 * M1 restore-side regression: wpmgr-object-cache-config.php must be excluded
 * at extract time by FilesRestorer::isExcluded() so a backup that somehow
 * contained the credential file cannot overwrite the live credential on restore.
 *
 * @package WPMgr\Agent\Tests\Backup
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests\Backup;

use ReflectionClass;
use WPMgr\Agent\Backup\FilesRestorer;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Backup\FilesRestorer
 */
final class ObjectCacheConfigRestoreExclusionTest extends TestCase
{
    /**
     * Reach the private FilesRestorer::isExcluded() through reflection and
     * assert that the credential config file is excluded at restore time.
     */
    public function test_files_restorer_excludes_object_cache_config(): void
    {
        $rc     = new ReflectionClass( FilesRestorer::class );
        $method = $rc->getMethod( 'isExcluded' );
        // setAccessible() is a no-op since PHP 8.1 and deprecated in 8.5.

        // The method is static — invoke with null instance.
        $result = $method->invoke( null, 'wpmgr-object-cache-config.php' );
        $this->assertTrue(
            $result,
            'FilesRestorer::isExcluded must return true for wpmgr-object-cache-config.php'
        );
    }

    /**
     * Verify the substring match also catches the filename when it appears
     * inside a subdirectory path (belt-and-suspenders; e.g. a zip packed with
     * the file under an unusual prefix).
     */
    public function test_files_restorer_excludes_config_in_subpath(): void
    {
        $rc     = new ReflectionClass( FilesRestorer::class );
        $method = $rc->getMethod( 'isExcluded' );
        // setAccessible() is a no-op since PHP 8.1 and deprecated in 8.5.

        $result = $method->invoke( null, 'some/subdir/wpmgr-object-cache-config.php' );
        $this->assertTrue(
            $result,
            'FilesRestorer::isExcluded must catch wpmgr-object-cache-config.php in subdirectories'
        );
    }

    /**
     * Confirm that normal files are NOT excluded (isExcluded must not be
     * overly broad and start blocking legitimate restore entries).
     */
    public function test_files_restorer_does_not_exclude_normal_files(): void
    {
        $rc     = new ReflectionClass( FilesRestorer::class );
        $method = $rc->getMethod( 'isExcluded' );
        // setAccessible() is a no-op since PHP 8.1 and deprecated in 8.5.

        $result = $method->invoke( null, 'plugins/my-plugin/main.php' );
        $this->assertFalse(
            $result,
            'FilesRestorer::isExcluded must not exclude normal plugin files'
        );
    }
}
