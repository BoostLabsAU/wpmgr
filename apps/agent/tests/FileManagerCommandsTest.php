<?php
/**
 * Tests for the P1 read-only file manager commands:
 *   FileListCommand, FileReadCommand, FileDownloadPrepareCommand.
 *
 * Covers:
 *
 *  FileListCommand (file_list):
 *   - positive: lists a known directory (dirs first, then files, sorted)
 *   - positive: empty directory returns empty entries array
 *   - positive: cursor round-trip via hand-crafted cursor at offset 1
 *   - negative: path traversal (../../) → outside_root
 *   - negative: NUL byte (foo\0.php) → invalid_path
 *   - negative: path not found → not_found / not_readable
 *
 *  FileReadCommand (file_read):
 *   - positive: read a small file, content round-trips via base64
 *   - positive: truncated=false when file fits in max_bytes
 *   - positive: sensitive file allowed with confirm_sensitive=true
 *   - negative: sensitive file denied without confirm_sensitive → sensitive_denied
 *   - negative: path traversal (../../wp-config.php) → outside_root
 *   - negative: URL/percent-encoded traversal treated as literal name → not_found/not_readable
 *   - negative: NUL byte → invalid_path
 *   - negative: symlink that escapes the jail → outside_root / not_readable
 *   - negative: directory-as-file → is_directory
 *   - negative: missing path parameter → invalid_path
 *
 *  FileDownloadPrepareCommand (file_download_prepare):
 *   - negative: path traversal → outside_root
 *   - negative: NUL byte → invalid_path
 *   - negative: directory-as-file → is_directory
 *   - negative: sensitive file without confirm_sensitive → sensitive_denied
 *   - positive: sensitive file with confirm_sensitive=true + upload mock → ok manifest
 *
 *  FileReadCommand::isSensitive():
 *   - exhaustive pattern coverage for the deny-list
 *
 *  FileListCommand::jailPath() — direct unit tests:
 *   - root path (empty rel) → ok
 *   - dot-dot segment → outside_root
 *   - NUL byte → invalid_path
 *   - symlink pointing outside jail → outside_root
 *
 * Strategy: ABSPATH is defined in bootstrap.php as sys_get_temp_dir()/wpmgr_wp_abspath/site/.
 * Since WPMGR_FILE_JAIL_ROOT is NOT defined, FileListCommand::resolveJailRoot() falls back to
 * ABSPATH. All test files are created inside a unique per-test subdirectory under ABSPATH
 * so that every command call uses real paths within the resolved jail.
 *
 * @package WPMgr\Agent\Tests
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WPMgr\Agent\Commands\FileDownloadPrepareCommand;
use WPMgr\Agent\Commands\FileListCommand;
use WPMgr\Agent\Commands\FileReadCommand;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Commands\FileListCommand
 * @covers \WPMgr\Agent\Commands\FileReadCommand
 * @covers \WPMgr\Agent\Commands\FileDownloadPrepareCommand
 */
final class FileManagerCommandsTest extends TestCase
{
    /**
     * Unique subdirectory under ABSPATH created per test.
     * All test files live here; paths passed to commands are relative to ABSPATH
     * (the jail root), so they're like "fm-test-<hex>/filename.txt".
     */
    private string $testSubDir = '';

    /** Absolute path of the per-test subdirectory. */
    private string $testAbsDir = '';

    /** The absolute jail root (= ABSPATH, resolved). */
    private string $jailRoot = '';

    protected function set_up(): void
    {
        parent::set_up();
        Monkey\setUp();

        // wp_json_encode: needed for cursor encoding (base64(json(...))).
        Functions\when('wp_json_encode')->alias(static function ($data): string {
            return (string) json_encode($data);
        });

        // Resolve the jail root the same way resolveJailRoot() does.
        $abspath = defined('ABSPATH') ? rtrim((string) constant('ABSPATH'), '/\\') : '';
        $resolved = realpath($abspath);
        $this->jailRoot = $resolved !== false ? str_replace('\\', '/', $resolved) : $abspath;

        // Create ABSPATH if it doesn't exist yet (bootstrap only creates the parent).
        if (!is_dir($this->jailRoot)) {
            mkdir($this->jailRoot, 0755, true);
        }

        // Unique per-test subdirectory within the jail.
        $this->testSubDir = 'fm-test-' . bin2hex(random_bytes(6));
        $this->testAbsDir = $this->jailRoot . '/' . $this->testSubDir;
        mkdir($this->testAbsDir, 0755, true);
    }

    protected function tear_down(): void
    {
        $this->rmdir_r($this->testAbsDir);
        Monkey\tearDown();
        parent::tear_down();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Write a file at a path relative to the per-test subdirectory.
     * Returns the JAIL-RELATIVE path (suitable for passing to commands).
     *
     * @param string $name    Filename (no slashes — always in testSubDir).
     * @param string $content File content.
     * @return string Jail-relative path, e.g. "fm-test-abc123/hello.txt".
     */
    private function writeFile(string $name, string $content = 'hello'): string
    {
        file_put_contents($this->testAbsDir . '/' . $name, $content);
        return $this->testSubDir . '/' . $name;
    }

    /**
     * Create a directory inside the per-test subdirectory.
     * Returns the jail-relative path.
     *
     * @param string $name Directory name.
     * @return string Jail-relative path.
     */
    private function makeDir(string $name): string
    {
        mkdir($this->testAbsDir . '/' . $name, 0755, true);
        return $this->testSubDir . '/' . $name;
    }

    /** Recursively delete a directory tree. */
    private function rmdir_r(string $path): void
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
                $this->rmdir_r($child);
            } else {
                @unlink($child);
            }
        }
        @rmdir($path);
    }

    // ------------------------------------------------------------------
    // FileListCommand — positive cases
    // ------------------------------------------------------------------

    public function test_file_list_lists_known_directory(): void
    {
        mkdir($this->testAbsDir . '/subdir', 0755);
        $this->writeFile('file-a.txt', 'aaa');
        $this->writeFile('file-b.txt', 'bbb');

        $cmd    = new FileListCommand();
        $result = $cmd->execute([], ['path' => $this->testSubDir]);

        $this->assertArrayNotHasKey('error', $result, 'Expected success, got error: ' . json_encode($result));
        $this->assertSame($this->testSubDir, $result['path']);
        $this->assertIsArray($result['entries']);
        $this->assertFalse($result['truncated']);
        $this->assertSame(3, $result['total']);

        $names = array_column($result['entries'], 'name');
        $this->assertContains('subdir', $names);
        $this->assertContains('file-a.txt', $names);
        $this->assertContains('file-b.txt', $names);
    }

    public function test_file_list_dirs_come_before_files(): void
    {
        mkdir($this->testAbsDir . '/z-dir', 0755);
        $this->writeFile('a-file.txt', 'a');

        $cmd    = new FileListCommand();
        $result = $cmd->execute([], ['path' => $this->testSubDir]);

        $this->assertArrayNotHasKey('error', $result, json_encode($result));
        $firstEntry = $result['entries'][0] ?? null;
        $this->assertNotNull($firstEntry, 'entries must not be empty');
        $this->assertTrue($firstEntry['is_dir'], 'First entry should be the directory');
    }

    public function test_file_list_empty_directory(): void
    {
        // testAbsDir is already empty.
        $cmd    = new FileListCommand();
        $result = $cmd->execute([], ['path' => $this->testSubDir]);

        $this->assertArrayNotHasKey('error', $result, json_encode($result));
        $this->assertSame([], $result['entries']);
        $this->assertSame(0, $result['total']);
        $this->assertFalse($result['truncated']);
    }

    public function test_file_list_entry_shape(): void
    {
        $this->writeFile('shape-test.txt', 'hello');

        $cmd    = new FileListCommand();
        $result = $cmd->execute([], ['path' => $this->testSubDir]);

        $entry = null;
        foreach ($result['entries'] as $e) {
            if ($e['name'] === 'shape-test.txt') {
                $entry = $e;
                break;
            }
        }
        $this->assertNotNull($entry, 'shape-test.txt should appear in entries');
        $this->assertIsString($entry['name']);
        $this->assertIsInt($entry['size']);
        $this->assertIsInt($entry['mtime']);
        $this->assertIsString($entry['mode']);
        $this->assertMatchesRegularExpression('/^\d{4}$/', $entry['mode']);
        $this->assertIsBool($entry['is_dir']);
        $this->assertIsBool($entry['is_link']);
        $this->assertIsBool($entry['is_writable']);
        $this->assertFalse($entry['is_dir']);
        $this->assertFalse($entry['is_link']);
    }

    public function test_file_list_cursor_round_trip(): void
    {
        // Write 3 files so we have exactly 3 entries to page through.
        $this->writeFile('aa-first.txt', 'a');
        $this->writeFile('bb-second.txt', 'b');
        $this->writeFile('cc-third.txt', 'c');

        $cmd    = new FileListCommand();
        $result = $cmd->execute([], ['path' => $this->testSubDir]);

        $this->assertArrayNotHasKey('error', $result, json_encode($result));
        $this->assertSame(3, $result['total']);
        $this->assertFalse($result['truncated']); // 3 < 1000 cap, no truncation.

        // Build a cursor for offset=1 on this directory and verify continuation.
        // Cursor format: base64(json({p: $resolvedRel, o: 1})).
        $cursor  = base64_encode((string) json_encode(['p' => $this->testSubDir, 'o' => 1]));
        $result2 = $cmd->execute([], ['path' => $this->testSubDir, 'cursor' => $cursor]);

        $this->assertArrayNotHasKey('error', $result2, json_encode($result2));
        // Offset 1 → should return entries 1 and 2 (the last 2 after sort).
        $this->assertCount(2, $result2['entries'], 'Cursor at offset=1 should skip first entry');

        // Combined: the first entry from result + all entries from result2 = 3 distinct.
        $combined = array_merge(
            [$result['entries'][0]['name']],
            array_column($result2['entries'], 'name')
        );
        sort($combined);
        $this->assertSame(['aa-first.txt', 'bb-second.txt', 'cc-third.txt'], $combined);
    }

    // ------------------------------------------------------------------
    // FileListCommand — negative / jail cases
    // ------------------------------------------------------------------

    public function test_file_list_dot_dot_traversal_is_rejected(): void
    {
        $cmd    = new FileListCommand();
        $result = $cmd->execute([], ['path' => '../../etc']);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('outside_root', $result['error']['code']);
    }

    public function test_file_list_nul_byte_is_rejected(): void
    {
        $cmd    = new FileListCommand();
        $result = $cmd->execute([], ['path' => "foo\0.php"]);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('invalid_path', $result['error']['code']);
    }

    public function test_file_list_path_not_found(): void
    {
        $cmd    = new FileListCommand();
        $result = $cmd->execute([], ['path' => 'nonexistent-dir-xyz-' . bin2hex(random_bytes(4))]);

        $this->assertArrayHasKey('error', $result);
        $this->assertContains($result['error']['code'], ['not_found', 'not_readable']);
    }

    // ------------------------------------------------------------------
    // FileReadCommand — positive cases
    // ------------------------------------------------------------------

    public function test_file_read_content_round_trips_via_base64(): void
    {
        $content  = 'Hello, WPMgr file manager!';
        $jailPath = $this->writeFile('hello.txt', $content);

        $cmd    = new FileReadCommand();
        $result = $cmd->execute([], ['path' => $jailPath]);

        $this->assertArrayNotHasKey('error', $result, json_encode($result));
        $this->assertSame($jailPath, $result['path']);
        $this->assertSame(strlen($content), $result['size']);
        $this->assertSame('base64', $result['encoding']);
        $this->assertSame($content, base64_decode($result['content_base64']));
        $this->assertFalse($result['truncated']);
        $this->assertIsInt($result['mtime']);
        $this->assertIsString($result['mode']);
        $this->assertMatchesRegularExpression('/^\d{4}$/', $result['mode']);
    }

    public function test_file_read_truncated_false_when_file_fits(): void
    {
        $content  = 'short';
        $jailPath = $this->writeFile('short.txt', $content);

        $cmd    = new FileReadCommand();
        $result = $cmd->execute([], ['path' => $jailPath, 'max_bytes' => 1000]);

        $this->assertArrayNotHasKey('error', $result, json_encode($result));
        $this->assertFalse($result['truncated']);
    }

    public function test_file_read_sensitive_file_denied_without_confirm(): void
    {
        // Write wp-config.php inside the jail.
        file_put_contents($this->testAbsDir . '/wp-config.php', '<?php // sensitive');
        $jailPath = $this->testSubDir . '/wp-config.php';

        $cmd    = new FileReadCommand();
        $result = $cmd->execute([], ['path' => $jailPath]);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('sensitive_denied', $result['error']['code']);
    }

    public function test_file_read_sensitive_file_allowed_with_confirm_sensitive(): void
    {
        $content  = '<?php // config';
        file_put_contents($this->testAbsDir . '/wp-config.php', $content);
        $jailPath = $this->testSubDir . '/wp-config.php';

        $cmd    = new FileReadCommand();
        $result = $cmd->execute([], ['path' => $jailPath, 'confirm_sensitive' => true]);

        $this->assertArrayNotHasKey('error', $result, json_encode($result));
        $this->assertSame($content, base64_decode($result['content_base64']));
    }

    // ------------------------------------------------------------------
    // FileReadCommand — negative / jail cases
    // ------------------------------------------------------------------

    public function test_file_read_dot_dot_traversal_is_rejected(): void
    {
        $cmd    = new FileReadCommand();
        $result = $cmd->execute([], ['path' => '../../wp-config.php']);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('outside_root', $result['error']['code']);
    }

    public function test_file_read_percent_encoded_traversal_treated_as_literal(): void
    {
        // %2e%2e%2f is NOT URL-decoded; it's treated as a literal filename component.
        // The path should not exist and should NOT return content.
        $cmd    = new FileReadCommand();
        $result = $cmd->execute([], ['path' => '%2e%2e%2fwp-config.php']);

        $this->assertArrayHasKey('error', $result);
        // Either not_found (correct — literal path) or outside_root.
        $this->assertContains(
            $result['error']['code'],
            ['not_found', 'outside_root', 'not_readable'],
            'Percent-encoded traversal must not succeed'
        );
        $this->assertArrayNotHasKey('content_base64', $result);
    }

    public function test_file_read_nul_byte_is_rejected(): void
    {
        $cmd    = new FileReadCommand();
        $result = $cmd->execute([], ['path' => "foo\0.php"]);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('invalid_path', $result['error']['code']);
    }

    public function test_file_read_absolute_path_treated_as_jail_relative(): void
    {
        // An absolute path like '/etc/passwd' is treated as jail-relative after
        // ltrim('/'), becoming 'etc/passwd' — it won't exist inside our jail.
        $cmd    = new FileReadCommand();
        $result = $cmd->execute([], ['path' => '/etc/passwd']);

        // Result must be an error (path not inside jail or not found).
        $this->assertArrayHasKey('error', $result);
        // Must NOT return file content of the real /etc/passwd.
        $this->assertArrayNotHasKey('content_base64', $result);
    }

    public function test_file_read_symlink_escaping_jail_is_rejected(): void
    {
        // Create a file OUTSIDE the jail.
        $outside = sys_get_temp_dir() . '/wpmgr-esc-' . bin2hex(random_bytes(4));
        mkdir($outside, 0755, true);
        file_put_contents($outside . '/secret.txt', 'top secret');

        // Create a symlink inside the jail pointing outside.
        $linkAbsPath = $this->testAbsDir . '/escape-link';
        symlink($outside . '/secret.txt', $linkAbsPath);
        $jailPath = $this->testSubDir . '/escape-link';

        $cmd    = new FileReadCommand();
        $result = $cmd->execute([], ['path' => $jailPath]);

        // Cleanup.
        @unlink($linkAbsPath);
        @unlink($outside . '/secret.txt');
        @rmdir($outside);

        $this->assertArrayHasKey('error', $result, 'Symlink escaping jail must be rejected');
        $this->assertNotSame('sensitive_denied', $result['error']['code']);
        $this->assertArrayNotHasKey('content_base64', $result);
    }

    public function test_file_read_directory_as_file_rejected(): void
    {
        $jailDirPath = $this->makeDir('adir');

        $cmd    = new FileReadCommand();
        $result = $cmd->execute([], ['path' => $jailDirPath]);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('is_directory', $result['error']['code']);
    }

    public function test_file_read_missing_path_param_rejected(): void
    {
        $cmd    = new FileReadCommand();
        $result = $cmd->execute([], []);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('invalid_path', $result['error']['code']);
    }

    // ------------------------------------------------------------------
    // FileDownloadPrepareCommand — jail + sensitive checks
    // ------------------------------------------------------------------

    public function test_file_download_prepare_dot_dot_traversal_is_rejected(): void
    {
        $cmd    = new FileDownloadPrepareCommand();
        $result = $cmd->execute([], [
            'path'           => '../../etc/passwd',
            'presigned_puts' => [['index' => 0, 'url' => 'https://example.com/put']],
            'part_size'      => 1048576,
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('outside_root', $result['error']['code']);
    }

    public function test_file_download_prepare_nul_byte_is_rejected(): void
    {
        $cmd    = new FileDownloadPrepareCommand();
        $result = $cmd->execute([], [
            'path'           => "foo\0bar.php",
            'presigned_puts' => [['index' => 0, 'url' => 'https://example.com/put']],
            'part_size'      => 1048576,
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('invalid_path', $result['error']['code']);
    }

    public function test_file_download_prepare_directory_is_rejected(): void
    {
        $jailDirPath = $this->makeDir('dldir');

        $cmd    = new FileDownloadPrepareCommand();
        $result = $cmd->execute([], [
            'path'           => $jailDirPath,
            'presigned_puts' => [['index' => 0, 'url' => 'https://example.com/put']],
            'part_size'      => 1048576,
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('is_directory', $result['error']['code']);
    }

    public function test_file_download_prepare_sensitive_file_denied_without_confirm(): void
    {
        file_put_contents($this->testAbsDir . '/.env', 'SECRET=abc');
        $jailPath = $this->testSubDir . '/.env';

        $cmd    = new FileDownloadPrepareCommand();
        $result = $cmd->execute([], [
            'path'           => $jailPath,
            'presigned_puts' => [['index' => 0, 'url' => 'https://example.com/put']],
            'part_size'      => 1048576,
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('sensitive_denied', $result['error']['code']);
    }

    public function test_file_download_prepare_sensitive_file_proceeds_with_confirm_sensitive(): void
    {
        file_put_contents($this->testAbsDir . '/.env', 'SECRET=abc');
        $jailPath = $this->testSubDir . '/.env';

        // Stub WP HTTP functions to simulate a successful presigned PUT.
        Functions\when('wp_remote_request')->justReturn([
            'response' => ['code' => 200, 'message' => 'OK'],
            'headers'  => ['etag' => '"abc123"'],
            'body'     => '',
        ]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_header')->justReturn('"abc123"');
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_parse_url')->alias(static function (string $url): array {
            return (array) parse_url($url);
        });

        $cmd    = new FileDownloadPrepareCommand();
        $result = $cmd->execute([], [
            'path'              => $jailPath,
            'presigned_puts'    => [['index' => 0, 'url' => 'https://s3.example.com/bucket/key?sig=x']],
            'part_size'         => 1048576,
            'confirm_sensitive' => true,
        ]);

        $this->assertArrayNotHasKey('error', $result, json_encode($result));
        $this->assertArrayHasKey('object_key', $result);
        $this->assertArrayHasKey('size', $result);
        $this->assertArrayHasKey('chunk_count', $result);
        $this->assertArrayHasKey('parts', $result);
        $this->assertSame(1, $result['chunk_count']);
        $this->assertCount(1, $result['parts']);
        $this->assertSame(0, $result['parts'][0]['index']);
        $this->assertSame('abc123', $result['parts'][0]['etag']);
    }

    // ------------------------------------------------------------------
    // FileReadCommand::isSensitive() — exhaustive deny-list coverage
    // ------------------------------------------------------------------

    public function test_is_sensitive_wp_config(): void
    {
        $this->assertTrue(FileReadCommand::isSensitive('wp-config.php', 'wp-config.php'));
    }

    public function test_is_sensitive_wp_config_variant(): void
    {
        $this->assertTrue(FileReadCommand::isSensitive('wp-config-extra.php', 'wp-config-extra.php'));
    }

    public function test_is_sensitive_env_file(): void
    {
        $this->assertTrue(FileReadCommand::isSensitive('.env', '.env'));
    }

    public function test_is_sensitive_env_local(): void
    {
        $this->assertTrue(FileReadCommand::isSensitive('.env.local', '.env.local'));
    }

    public function test_is_sensitive_pem(): void
    {
        $this->assertTrue(FileReadCommand::isSensitive('ssl/cert.pem', 'cert.pem'));
    }

    public function test_is_sensitive_key(): void
    {
        $this->assertTrue(FileReadCommand::isSensitive('ssl/priv.key', 'priv.key'));
    }

    public function test_is_sensitive_id_rsa(): void
    {
        $this->assertTrue(FileReadCommand::isSensitive('id_rsa', 'id_rsa'));
    }

    public function test_is_sensitive_id_rsa_pub(): void
    {
        $this->assertTrue(FileReadCommand::isSensitive('id_rsa.pub', 'id_rsa.pub'));
    }

    public function test_is_sensitive_htpasswd(): void
    {
        $this->assertTrue(FileReadCommand::isSensitive('.htpasswd', '.htpasswd'));
    }

    public function test_is_sensitive_auth_json(): void
    {
        $this->assertTrue(FileReadCommand::isSensitive('auth.json', 'auth.json'));
    }

    public function test_is_sensitive_file_under_git_dir(): void
    {
        $this->assertTrue(FileReadCommand::isSensitive('.git/config', 'config'));
    }

    public function test_is_sensitive_nested_under_git(): void
    {
        $this->assertTrue(FileReadCommand::isSensitive('submodule/.git/config', 'config'));
    }

    public function test_is_not_sensitive_regular_php(): void
    {
        $this->assertFalse(FileReadCommand::isSensitive('index.php', 'index.php'));
    }

    public function test_is_not_sensitive_readme(): void
    {
        $this->assertFalse(FileReadCommand::isSensitive('readme.txt', 'readme.txt'));
    }

    public function test_is_not_sensitive_functions_php(): void
    {
        $this->assertFalse(
            FileReadCommand::isSensitive(
                'wp-content/themes/my-theme/functions.php',
                'functions.php'
            )
        );
    }

    // ------------------------------------------------------------------
    // Fix 1: case-fold bypass prevention (WP-CONFIG.PHP / .ENV / ID_RSA)
    // ------------------------------------------------------------------

    public function test_is_sensitive_wp_config_uppercase(): void
    {
        $this->assertTrue(FileReadCommand::isSensitive('WP-CONFIG.PHP', 'WP-CONFIG.PHP'));
    }

    public function test_is_sensitive_env_uppercase(): void
    {
        $this->assertTrue(FileReadCommand::isSensitive('.ENV', '.ENV'));
    }

    public function test_is_sensitive_id_rsa_uppercase(): void
    {
        $this->assertTrue(FileReadCommand::isSensitive('ID_RSA', 'ID_RSA'));
    }

    // ------------------------------------------------------------------
    // Fix 2: expanded deny-list — wp-config.php backup variants
    // ------------------------------------------------------------------

    public function test_is_sensitive_wp_config_bak(): void
    {
        $this->assertTrue(FileReadCommand::isSensitive('wp-config.php.bak', 'wp-config.php.bak'));
    }

    public function test_is_sensitive_wp_config_tilde(): void
    {
        $this->assertTrue(FileReadCommand::isSensitive('wp-config.php~', 'wp-config.php~'));
    }

    public function test_is_sensitive_wp_config_save(): void
    {
        $this->assertTrue(FileReadCommand::isSensitive('wp-config.php.save', 'wp-config.php.save'));
    }

    public function test_is_sensitive_wp_config_orig(): void
    {
        $this->assertTrue(FileReadCommand::isSensitive('wp-config.php.orig', 'wp-config.php.orig'));
    }

    public function test_is_sensitive_wp_config_old(): void
    {
        $this->assertTrue(FileReadCommand::isSensitive('wp-config.php.old', 'wp-config.php.old'));
    }

    public function test_is_sensitive_wp_config_swp(): void
    {
        $this->assertTrue(FileReadCommand::isSensitive('wp-config.php.swp', 'wp-config.php.swp'));
    }

    public function test_is_sensitive_wp_config_swo(): void
    {
        $this->assertTrue(FileReadCommand::isSensitive('wp-config.php.swo', 'wp-config.php.swo'));
    }

    // ------------------------------------------------------------------
    // Fix 2: expanded deny-list — new SSH key prefixes
    // ------------------------------------------------------------------

    public function test_is_sensitive_id_dsa(): void
    {
        $this->assertTrue(FileReadCommand::isSensitive('id_dsa', 'id_dsa'));
    }

    public function test_is_sensitive_id_ecdsa(): void
    {
        $this->assertTrue(FileReadCommand::isSensitive('id_ecdsa', 'id_ecdsa'));
    }

    public function test_is_sensitive_id_ed25519(): void
    {
        $this->assertTrue(FileReadCommand::isSensitive('id_ed25519', 'id_ed25519'));
    }

    public function test_is_sensitive_id_ed25519_pub(): void
    {
        $this->assertTrue(FileReadCommand::isSensitive('id_ed25519.pub', 'id_ed25519.pub'));
    }

    // ------------------------------------------------------------------
    // Fix 2: expanded deny-list — certificate/key extensions
    // ------------------------------------------------------------------

    public function test_is_sensitive_crt(): void
    {
        $this->assertTrue(FileReadCommand::isSensitive('ssl/server.crt', 'server.crt'));
    }

    public function test_is_sensitive_p12(): void
    {
        $this->assertTrue(FileReadCommand::isSensitive('certs/bundle.p12', 'bundle.p12'));
    }

    public function test_is_sensitive_pfx(): void
    {
        $this->assertTrue(FileReadCommand::isSensitive('certs/bundle.pfx', 'bundle.pfx'));
    }

    public function test_is_sensitive_ppk(): void
    {
        $this->assertTrue(FileReadCommand::isSensitive('putty.ppk', 'putty.ppk'));
    }

    // ------------------------------------------------------------------
    // Fix 2: expanded deny-list — exact-match additions
    // ------------------------------------------------------------------

    public function test_is_sensitive_npmrc(): void
    {
        $this->assertTrue(FileReadCommand::isSensitive('.npmrc', '.npmrc'));
    }

    public function test_is_sensitive_git_credentials(): void
    {
        $this->assertTrue(FileReadCommand::isSensitive('.git-credentials', '.git-credentials'));
    }

    // ------------------------------------------------------------------
    // Fix 2: expanded deny-list — .aws/credentials segment pair
    // ------------------------------------------------------------------

    public function test_is_sensitive_aws_credentials(): void
    {
        $this->assertTrue(FileReadCommand::isSensitive('.aws/credentials', 'credentials'));
    }

    public function test_is_sensitive_aws_credentials_nested(): void
    {
        $this->assertTrue(FileReadCommand::isSensitive('home/.aws/credentials', 'credentials'));
    }

    public function test_is_not_sensitive_credentials_without_aws_parent(): void
    {
        // A file literally named 'credentials' NOT under .aws/ must not be blocked.
        $this->assertFalse(FileReadCommand::isSensitive('secrets/credentials', 'credentials'));
    }

    // ------------------------------------------------------------------
    // Fix 3: both file_read and file_download_prepare honour the expanded list
    // ------------------------------------------------------------------

    /**
     * file_read must deny ID_RSA (case-fold) at the command level.
     */
    public function test_file_read_denies_id_rsa_uppercase(): void
    {
        // Write a file named "ID_RSA" inside the jail.
        file_put_contents($this->testAbsDir . '/ID_RSA', 'fake key');
        $jailPath = $this->testSubDir . '/ID_RSA';

        $cmd    = new FileReadCommand();
        $result = $cmd->execute([], ['path' => $jailPath]);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('sensitive_denied', $result['error']['code']);
    }

    /**
     * file_read must deny .ENV (case-fold) at the command level.
     */
    public function test_file_read_denies_dot_env_uppercase(): void
    {
        file_put_contents($this->testAbsDir . '/.ENV', 'SECRET=1');
        $jailPath = $this->testSubDir . '/.ENV';

        $cmd    = new FileReadCommand();
        $result = $cmd->execute([], ['path' => $jailPath]);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('sensitive_denied', $result['error']['code']);
    }

    /**
     * file_read must deny server.crt (new extension) at the command level.
     */
    public function test_file_read_denies_crt_file(): void
    {
        file_put_contents($this->testAbsDir . '/server.crt', 'cert data');
        $jailPath = $this->testSubDir . '/server.crt';

        $cmd    = new FileReadCommand();
        $result = $cmd->execute([], ['path' => $jailPath]);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('sensitive_denied', $result['error']['code']);
    }

    /**
     * file_read must deny .npmrc at the command level.
     */
    public function test_file_read_denies_npmrc(): void
    {
        file_put_contents($this->testAbsDir . '/.npmrc', '//registry.npmjs.org/:_authToken=secret');
        $jailPath = $this->testSubDir . '/.npmrc';

        $cmd    = new FileReadCommand();
        $result = $cmd->execute([], ['path' => $jailPath]);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('sensitive_denied', $result['error']['code']);
    }

    /**
     * file_read must deny .git-credentials at the command level.
     */
    public function test_file_read_denies_git_credentials(): void
    {
        file_put_contents($this->testAbsDir . '/.git-credentials', 'https://user:token@github.com');
        $jailPath = $this->testSubDir . '/.git-credentials';

        $cmd    = new FileReadCommand();
        $result = $cmd->execute([], ['path' => $jailPath]);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('sensitive_denied', $result['error']['code']);
    }

    /**
     * file_read must deny id_ed25519 at the command level.
     */
    public function test_file_read_denies_id_ed25519(): void
    {
        file_put_contents($this->testAbsDir . '/id_ed25519', '-----BEGIN OPENSSH PRIVATE KEY-----');
        $jailPath = $this->testSubDir . '/id_ed25519';

        $cmd    = new FileReadCommand();
        $result = $cmd->execute([], ['path' => $jailPath]);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('sensitive_denied', $result['error']['code']);
    }

    /**
     * file_download_prepare must deny WP-CONFIG.PHP (case-fold) using the shared isSensitive().
     */
    public function test_file_download_prepare_denies_wp_config_uppercase(): void
    {
        file_put_contents($this->testAbsDir . '/WP-CONFIG.PHP', '<?php // config');
        $jailPath = $this->testSubDir . '/WP-CONFIG.PHP';

        $cmd    = new FileDownloadPrepareCommand();
        $result = $cmd->execute([], [
            'path'           => $jailPath,
            'presigned_puts' => [['index' => 0, 'url' => 'https://s3.example.com/put']],
            'part_size'      => 1048576,
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('sensitive_denied', $result['error']['code']);
    }

    /**
     * file_download_prepare must deny .npmrc using the shared isSensitive().
     */
    public function test_file_download_prepare_denies_npmrc(): void
    {
        file_put_contents($this->testAbsDir . '/.npmrc', '//registry.npmjs.org/:_authToken=secret');
        $jailPath = $this->testSubDir . '/.npmrc';

        $cmd    = new FileDownloadPrepareCommand();
        $result = $cmd->execute([], [
            'path'           => $jailPath,
            'presigned_puts' => [['index' => 0, 'url' => 'https://s3.example.com/put']],
            'part_size'      => 1048576,
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('sensitive_denied', $result['error']['code']);
    }

    /**
     * file_download_prepare must deny id_ed25519 using the shared isSensitive().
     */
    public function test_file_download_prepare_denies_id_ed25519(): void
    {
        file_put_contents($this->testAbsDir . '/id_ed25519', '-----BEGIN OPENSSH PRIVATE KEY-----');
        $jailPath = $this->testSubDir . '/id_ed25519';

        $cmd    = new FileDownloadPrepareCommand();
        $result = $cmd->execute([], [
            'path'           => $jailPath,
            'presigned_puts' => [['index' => 0, 'url' => 'https://s3.example.com/put']],
            'part_size'      => 1048576,
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('sensitive_denied', $result['error']['code']);
    }

    /**
     * Positive: regular .php file is still readable (not blocked by expanded deny-list).
     */
    public function test_file_read_allows_regular_plugin_php(): void
    {
        $content  = '<?php echo "hello";';
        $jailPath = $this->writeFile('my-plugin.php', $content);

        $cmd    = new FileReadCommand();
        $result = $cmd->execute([], ['path' => $jailPath]);

        $this->assertArrayNotHasKey('error', $result, json_encode($result));
        $this->assertSame($content, base64_decode($result['content_base64']));
    }

    /**
     * Positive: readme.txt is still readable.
     */
    public function test_file_read_allows_readme_txt(): void
    {
        $jailPath = $this->writeFile('readme.txt', 'A readme.');

        $cmd    = new FileReadCommand();
        $result = $cmd->execute([], ['path' => $jailPath]);

        $this->assertArrayNotHasKey('error', $result, json_encode($result));
    }

    /**
     * Positive: functions.php (non-config) is still readable.
     */
    public function test_file_read_allows_functions_php(): void
    {
        $jailPath = $this->writeFile('functions.php', '<?php // theme functions');

        $cmd    = new FileReadCommand();
        $result = $cmd->execute([], ['path' => $jailPath]);

        $this->assertArrayNotHasKey('error', $result, json_encode($result));
    }

    // ------------------------------------------------------------------
    // FileListCommand::jailPath() — direct unit tests
    // ------------------------------------------------------------------

    public function test_jail_path_root_is_ok(): void
    {
        $result = FileListCommand::jailPath($this->jailRoot, '');
        $this->assertTrue($result['ok']);
        $this->assertSame($this->jailRoot, $result['abs']);
        $this->assertSame('', $result['rel']);
    }

    public function test_jail_path_dot_dot_rejected(): void
    {
        $result = FileListCommand::jailPath($this->jailRoot, '../sibling');
        $this->assertFalse($result['ok']);
        $this->assertSame('outside_root', $result['code']);
    }

    public function test_jail_path_nul_rejected(): void
    {
        $result = FileListCommand::jailPath($this->jailRoot, "valid\0path");
        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_path', $result['code']);
    }

    public function test_jail_path_valid_subpath(): void
    {
        // testSubDir is a valid existing subpath within the jail.
        $result = FileListCommand::jailPath($this->jailRoot, $this->testSubDir);
        $this->assertTrue($result['ok']);
        $this->assertStringContainsString(
            $this->testSubDir,
            str_replace('\\', '/', $result['abs'])
        );
    }

    public function test_jail_path_symlink_outside_jail_rejected(): void
    {
        $outside = sys_get_temp_dir() . '/wpmgr-oj-' . bin2hex(random_bytes(4));
        mkdir($outside, 0755, true);

        $linkAbsPath = $this->testAbsDir . '/escape';
        symlink($outside, $linkAbsPath);

        $result = FileListCommand::jailPath($this->jailRoot, $this->testSubDir . '/escape');

        @unlink($linkAbsPath);
        @rmdir($outside);

        // After realpath resolves the symlink, the target starts with $outside
        // (not $jailRoot), so containment fails.
        $this->assertFalse($result['ok']);
        $this->assertSame('outside_root', $result['code']);
    }
}
