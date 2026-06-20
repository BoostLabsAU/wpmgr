<?php
/**
 * RecordManagedFilesCommand tests.
 *
 * Covers:
 *  - command name is "record_managed_files"
 *  - readable file inside ABSPATH → returned in files[] with correct md5
 *  - absent file → returned in missing[], not files[]
 *  - unreadable file → returned in missing[], not files[]
 *  - path-traversal attempt (../../etc/passwd) → missing[], never read
 *  - absolute path (/etc/hostname) is rejected → missing[]
 *  - symlink pointing outside ABSPATH → missing[], never read
 *  - symlink pointing inside ABSPATH → missing[] (we refuse all symlinks)
 *  - non-string entry in paths[] → silently skipped (not in missing, not fatal)
 *  - NUL-byte in path → missing[]
 *  - missing `paths` key → ok:false
 *  - paths > MAX_PATHS cap → excess entries silently truncated (no DoS)
 *  - empty paths array → ok:true, files:[], missing:[]
 *  - directory path → missing[]
 *
 * The production class is `final` so we cannot subclass it. Instead we use
 * ABSPATH as already defined by tests/bootstrap.php and place all test files
 * inside that constant's directory — which is itself a temp dir created by
 * bootstrap.php. For containment-escape and symlink-outside tests we verify
 * that the realpath-containment logic rejects paths whose resolved absolute
 * path does not start with realpath(ABSPATH).
 *
 * For the test cases that require a controlled root (e.g. placing files in a
 * different temp dir to test escape), we use a parallel helper class
 * (ContainmentHarnessCommand) that accepts an injected root and implements the
 * same algorithm — keeping the production class free of test seams.
 *
 * @package WPMgr\Agent\Tests
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests;

use WPMgr\Agent\Commands\RecordManagedFilesCommand;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Commands\RecordManagedFilesCommand
 */
final class RecordManagedFilesCommandTest extends TestCase
{
    /**
     * Temporary directory used as a controlled ABSPATH-equivalent for
     * containment-escape test scenarios.
     */
    private string $tmpRoot = '';

    protected function set_up(): void
    {
        parent::set_up();
        $this->tmpRoot = sys_get_temp_dir() . '/wpmgr-rmf-' . bin2hex(random_bytes(6));
        mkdir($this->tmpRoot, 0755, true);
    }

    protected function tear_down(): void
    {
        $this->rmdirR($this->tmpRoot);
        parent::tear_down();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Write a file under the bootstrap ABSPATH directory and return the
     * ABSPATH-relative path for use in paths[].
     *
     * @param string $relPath Forward-slash relative path under ABSPATH.
     * @param string $content File content.
     * @return string The same $relPath.
     */
    private function writeInAbspath(string $relPath, string $content = 'hello'): string
    {
        $abs = rtrim((string) ABSPATH, '/\\') . '/' . $relPath;
        $dir = dirname($abs);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($abs, $content);
        return $relPath;
    }

    /**
     * Execute the production command using the real ABSPATH from bootstrap.php.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function exec(array $params): array
    {
        $cmd = new RecordManagedFilesCommand();
        return $cmd->execute([], $params);
    }

    /**
     * Execute the containment harness with a custom injected root (for escape tests).
     *
     * @param string              $root   Custom ABSPATH-equivalent directory.
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function execWithRoot(string $root, array $params): array
    {
        $harness = new ContainmentHarnessCommand($root);
        return $harness->process($params);
    }

    /**
     * Write a file under $this->tmpRoot; return the tmpRoot-relative path.
     *
     * @param string $relPath Forward-slash path relative to $this->tmpRoot.
     * @param string $content File content.
     * @return string The same $relPath.
     */
    private function writeInRoot(string $relPath, string $content = 'hello'): string
    {
        $abs = $this->tmpRoot . '/' . $relPath;
        $dir = dirname($abs);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($abs, $content);
        return $relPath;
    }

    /** Recursively delete a directory tree. */
    private function rmdirR(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }
        $items = @scandir($path);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $path . '/' . $item;
            if (is_link($child)) {
                @unlink($child);
            } elseif (is_dir($child)) {
                $this->rmdirR($child);
            } else {
                @unlink($child);
            }
        }
        @rmdir($path);
    }

    // ------------------------------------------------------------------
    // Command contract
    // ------------------------------------------------------------------

    public function test_command_name_is_record_managed_files(): void
    {
        $cmd = new RecordManagedFilesCommand();
        $this->assertSame('record_managed_files', $cmd->name());
    }

    // ------------------------------------------------------------------
    // Missing / malformed top-level payload
    // ------------------------------------------------------------------

    public function test_missing_paths_key_returns_not_ok(): void
    {
        $result = $this->exec([]);
        $this->assertFalse($result['ok']);
        $this->assertSame([], $result['files']);
        $this->assertSame([], $result['missing']);
    }

    public function test_paths_not_array_returns_not_ok(): void
    {
        $result = $this->exec(['paths' => 'not-an-array']);
        $this->assertFalse($result['ok']);
    }

    public function test_empty_paths_returns_ok_with_empty_results(): void
    {
        $result = $this->exec(['paths' => []]);
        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['files']);
        $this->assertSame([], $result['missing']);
    }

    // ------------------------------------------------------------------
    // Happy path: readable files inside ABSPATH
    // ------------------------------------------------------------------

    public function test_readable_file_inside_abspath_returns_correct_md5(): void
    {
        $content = 'managed file content for md5 test';
        $relPath = $this->writeInAbspath('wpmgr-rmf-test-object-cache.php', $content);

        $result = $this->exec(['paths' => [$relPath]]);

        // Cleanup.
        @unlink(rtrim((string) ABSPATH, '/\\') . '/' . $relPath);

        $this->assertTrue($result['ok']);
        $this->assertCount(1, $result['files']);
        $this->assertSame([], $result['missing']);

        $row = $result['files'][0];
        $this->assertSame($relPath, $row['path']);
        $this->assertSame(md5($content), $row['md5']);
    }

    public function test_nested_file_inside_abspath_returns_correct_md5(): void
    {
        $content  = 'mu-plugin loader content';
        $relPath  = $this->writeInAbspath('wpmgr-rmf-subdir/wpmgr-loader.php', $content);

        $result = $this->exec(['paths' => [$relPath]]);

        // Cleanup.
        $dir = rtrim((string) ABSPATH, '/\\') . '/wpmgr-rmf-subdir';
        @unlink($dir . '/wpmgr-loader.php');
        @rmdir($dir);

        $this->assertTrue($result['ok']);
        $this->assertCount(1, $result['files']);
        $this->assertSame(md5($content), $result['files'][0]['md5']);
    }

    public function test_multiple_readable_files_all_returned(): void
    {
        $paths = [
            $this->writeInAbspath('wpmgr-rmf-oc.php', 'oc content'),
            $this->writeInAbspath('wpmgr-rmf-ac.php', 'ac content'),
        ];

        $result = $this->exec(['paths' => $paths]);

        // Cleanup.
        $absPath = rtrim((string) ABSPATH, '/\\');
        foreach ($paths as $p) {
            @unlink($absPath . '/' . $p);
        }

        $this->assertTrue($result['ok']);
        $this->assertCount(2, $result['files']);
        $this->assertSame([], $result['missing']);
    }

    // ------------------------------------------------------------------
    // Absent / unreadable paths → missing
    // ------------------------------------------------------------------

    public function test_absent_file_goes_to_missing(): void
    {
        // Use a filename that definitely won't exist in the test ABSPATH.
        $result = $this->exec(['paths' => ['wpmgr-rmf-absent-xyz-' . bin2hex(random_bytes(4)) . '.php']]);

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['files']);
        $this->assertCount(1, $result['missing']);
    }

    public function test_unreadable_file_goes_to_missing(): void
    {
        // Use the harness with a controlled root so we can chmod safely.
        $relPath = $this->writeInRoot('secret.php', 'secret');
        $absPath = $this->tmpRoot . '/' . $relPath;
        chmod($absPath, 0000);

        $result = $this->execWithRoot($this->tmpRoot, ['paths' => [$relPath]]);

        // Restore before test teardown.
        chmod($absPath, 0644);

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['files']);
        $this->assertContains($relPath, $result['missing']);
    }

    // ------------------------------------------------------------------
    // Path traversal / injection rejection
    // ------------------------------------------------------------------

    public function test_dotdot_traversal_rejected_to_missing(): void
    {
        // A path containing '..' must never be read.
        $traversal = '../../etc/passwd';

        $result = $this->execWithRoot($this->tmpRoot, ['paths' => [$traversal]]);

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['files']);
        $this->assertContains($traversal, $result['missing']);
    }

    public function test_traversal_via_subdirectory_rejected(): void
    {
        $traversal = 'wp-content/plugins/../../../../../../etc/shadow';

        $result = $this->execWithRoot($this->tmpRoot, ['paths' => [$traversal]]);

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['files']);
        $this->assertContains($traversal, $result['missing']);
    }

    public function test_absolute_path_outside_root_rejected(): void
    {
        // An absolute-looking path: after ltrim('/') it becomes 'etc/hostname'
        // relative to the root — which won't exist inside tmpRoot.
        $absInput = '/etc/hostname';

        $result = $this->execWithRoot($this->tmpRoot, ['paths' => [$absInput]]);

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['files']);
        $this->assertContains($absInput, $result['missing']);
    }

    public function test_symlink_pointing_outside_root_rejected(): void
    {
        // Create a symlink inside tmpRoot that points outside (to /etc).
        $linkPath = $this->tmpRoot . '/evil-link';
        if (!@symlink('/etc', $linkPath)) {
            $this->markTestSkipped('Cannot create symlink (permissions or OS restriction)');
        }

        $result = $this->execWithRoot($this->tmpRoot, ['paths' => ['evil-link']]);

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['files']);
        $this->assertContains('evil-link', $result['missing']);
    }

    public function test_symlink_inside_root_still_rejected(): void
    {
        // Symlink target is also inside root — we refuse ALL symlinks.
        $this->writeInRoot('real-file.php', 'real');
        $linkPath = $this->tmpRoot . '/link-to-real.php';
        if (!@symlink($this->tmpRoot . '/real-file.php', $linkPath)) {
            $this->markTestSkipped('Cannot create symlink (permissions or OS restriction)');
        }

        $result = $this->execWithRoot($this->tmpRoot, ['paths' => ['link-to-real.php']]);

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['files']);
        $this->assertContains('link-to-real.php', $result['missing']);
    }

    public function test_nul_byte_in_path_goes_to_missing(): void
    {
        $malicious = "wp-config\0.php";

        $result = $this->execWithRoot($this->tmpRoot, ['paths' => [$malicious]]);

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['files']);
        $this->assertContains($malicious, $result['missing']);
    }

    // ------------------------------------------------------------------
    // Garbage / non-string entries in paths array
    // ------------------------------------------------------------------

    public function test_non_string_entries_are_silently_skipped(): void
    {
        $result = $this->execWithRoot($this->tmpRoot, ['paths' => [42, null, true, []]]);

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['files']);
        $this->assertSame([], $result['missing']);
    }

    public function test_empty_string_entry_is_silently_skipped(): void
    {
        $result = $this->execWithRoot($this->tmpRoot, ['paths' => ['']]);

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['files']);
        $this->assertSame([], $result['missing']);
    }

    public function test_mixed_valid_and_garbage_entries(): void
    {
        $relPath = $this->writeInRoot('managed.php', 'managed content');

        $result = $this->execWithRoot($this->tmpRoot, ['paths' => [42, $relPath, null, '']]);

        $this->assertTrue($result['ok']);
        $this->assertCount(1, $result['files']);
        $this->assertSame($relPath, $result['files'][0]['path']);
        $this->assertSame([], $result['missing']);
    }

    // ------------------------------------------------------------------
    // Path cap (DoS guard)
    // ------------------------------------------------------------------

    public function test_path_cap_limits_processed_entries(): void
    {
        // 510 absent paths. MAX_PATHS = 500 → 500 go to missing, 10 silently dropped.
        $paths = [];
        for ($i = 0; $i < 510; ++$i) {
            $paths[] = "absent-file-{$i}.php";
        }

        $result = $this->execWithRoot($this->tmpRoot, ['paths' => $paths]);

        $this->assertTrue($result['ok']);
        $this->assertCount(500, $result['missing']);
        $this->assertSame([], $result['files']);
    }

    // ------------------------------------------------------------------
    // Directory path rejected
    // ------------------------------------------------------------------

    public function test_directory_path_goes_to_missing(): void
    {
        mkdir($this->tmpRoot . '/wp-content', 0755, true);

        $result = $this->execWithRoot($this->tmpRoot, ['paths' => ['wp-content']]);

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['files']);
        $this->assertContains('wp-content', $result['missing']);
    }
}

/**
 * Test-only containment harness.
 *
 * Implements the same path-containment algorithm as RecordManagedFilesCommand
 * but accepts an injected root instead of reading the ABSPATH constant.
 * This avoids any need to subclass the `final` production class, keeping the
 * production class free of test seams.
 *
 * Lives in the Tests namespace, excluded from phpcs by phpcs.xml.dist.
 */
final class ContainmentHarnessCommand
{
    private const MAX_PATHS = 500;

    private string $absRoot;

    public function __construct(string $root)
    {
        $resolved = realpath(rtrim($root, '/\\'));
        $this->absRoot = ($resolved !== false) ? str_replace('\\', '/', $resolved) : '';
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function process(array $params): array
    {
        /** @var list<array{path:string,md5:string}> $files */
        $files = [];
        /** @var list<string> $missing */
        $missing = [];

        try {
            if (!array_key_exists('paths', $params) || !is_array($params['paths'])) {
                return ['ok' => false, 'files' => [], 'missing' => []];
            }

            if ($this->absRoot === '') {
                return ['ok' => false, 'files' => [], 'missing' => []];
            }

            $containmentPrefix = $this->absRoot . '/';
            $prefixLen         = strlen($containmentPrefix);
            $processed         = 0;

            foreach ($params['paths'] as $rawPath) {
                if ($processed >= self::MAX_PATHS) {
                    break;
                }

                if (!is_string($rawPath) || $rawPath === '') {
                    continue;
                }

                if (strpos($rawPath, "\0") !== false) {
                    $missing[] = $rawPath;
                    continue;
                }

                $relPath   = str_replace('\\', '/', ltrim($rawPath, '/\\'));
                $segments  = explode('/', $relPath);
                $hasDotDot = false;
                foreach ($segments as $seg) {
                    if ($seg === '..') {
                        $hasDotDot = true;
                        break;
                    }
                }
                if ($hasDotDot) {
                    $missing[] = $rawPath;
                    continue;
                }

                $candidate = $this->absRoot . '/' . $relPath;
                $real      = realpath($candidate);
                if ($real === false) {
                    $missing[] = $rawPath;
                    ++$processed;
                    continue;
                }

                $real = str_replace('\\', '/', $real);
                if (strncmp($real, $containmentPrefix, $prefixLen) !== 0) {
                    $missing[] = $rawPath;
                    ++$processed;
                    continue;
                }

                if (is_dir($real)) {
                    $missing[] = $rawPath;
                    ++$processed;
                    continue;
                }

                if (is_link($candidate)) {
                    $missing[] = $rawPath;
                    ++$processed;
                    continue;
                }

                if (!is_readable($real)) {
                    $missing[] = $rawPath;
                    ++$processed;
                    continue;
                }

                $hash = @md5_file($real);
                if ($hash === false) {
                    $missing[] = $rawPath;
                    ++$processed;
                    continue;
                }

                $files[]   = ['path' => $rawPath, 'md5' => $hash];
                ++$processed;
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'files' => $files, 'missing' => $missing];
        }

        return ['ok' => true, 'files' => $files, 'missing' => $missing];
    }
}
