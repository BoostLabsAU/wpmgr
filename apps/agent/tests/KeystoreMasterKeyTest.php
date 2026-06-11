<?php
/**
 * Tests for portable, deterministic master-key acquisition in the keystore:
 * salt derivation, file fallback (writable-candidate selection + hardening),
 * legacy-file reuse, and source pinning for cross-request determinism.
 *
 * Each test runs in a separate process because master-key resolution depends on
 * process-global constants (WPMGR_AGENT_KEY_FILE, ABSPATH, WP salts) that can
 * only be defined once per process.
 *
 * Design note: bootstrap.php defines ABSPATH before any test code runs. Tests
 * in this class therefore operate on the fixed bootstrap ABSPATH rather than
 * trying to redefine it. dirname(ABSPATH) is a controlled subdirectory of
 * sys_get_temp_dir() created by bootstrap — not sys_get_temp_dir() itself —
 * so its writability and contents can be managed per-test.
 *
 * @package WPMgr\Agent\Tests
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use WPMgr\Agent\Keystore;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Keystore
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class KeystoreMasterKeyTest extends TestCase
{
    /** @var array<string,mixed> In-memory wp-option store. */
    private array $options = [];

    /** @var list<string> Temp paths/dirs to clean up. */
    private array $cleanup = [];

    protected function set_up(): void
    {
        parent::set_up();
        Monkey\setUp();

        $this->options = [];
        $this->cleanup = [];

        Functions\when('update_option')->alias(function ($name, $value) {
            $this->options[$name] = $value;
            return true;
        });
        Functions\when('get_option')->alias(function ($name, $default = false) {
            return $this->options[$name] ?? $default;
        });
        Functions\when('delete_option')->alias(function ($name) {
            unset($this->options[$name]);
            return true;
        });
    }

    protected function tear_down(): void
    {
        foreach ($this->cleanup as $path) {
            if (is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                @array_map('unlink', glob($path . '/*') ?: []);
                @rmdir($path);
            }
        }
        Monkey\tearDown();
        parent::tear_down();
    }

    /**
     * Resolve dirname(ABSPATH) — the first legacy-file and key-candidate path
     * the keystore checks.
     */
    private function absParent(): string
    {
        return rtrim(dirname(rtrim((string) ABSPATH, '/\\')), '/\\');
    }

    /** A set of realistic, high-entropy salt values (64 chars each). */
    private function defineRealSalts(): void
    {
        foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY',
                  'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'] as $i => $name) {
            if (!defined($name)) {
                define($name, str_repeat(chr(97 + $i), 8) . bin2hex(random_bytes(28)));
            }
        }
    }

    public function test_salt_derivation_is_stable_and_32_bytes(): void
    {
        // Remove any legacy key file that might have been left by a prior run.
        // The keystore checks for a legacy file at dirname(ABSPATH) before it
        // attempts salt derivation, so a stale file would cause a false 'file'
        // source result instead of the expected 'salts'.
        $legacyPath = $this->absParent() . '/.wpmgr-agent-master.key';
        @unlink($legacyPath);

        $this->defineRealSalts();

        $keystore = new Keystore();

        // Round-trip exercises masterKey() twice (encrypt + decrypt).
        $envelope = $keystore->encrypt('payload-under-salts');
        $this->assertSame('payload-under-salts', $keystore->decrypt($envelope));

        // Source must be pinned to salts.
        $this->assertSame(['source' => 'salts'], $this->options[Keystore::OPTION_MASTER_KEY_SOURCE]);

        // A fresh instance (new request) must derive the SAME key and decrypt.
        $fresh = new Keystore();
        $this->assertSame('payload-under-salts', $fresh->decrypt($envelope));
    }

    public function test_placeholder_salts_are_rejected_and_fall_through_to_file(): void
    {
        // Placeholder salt poisons salt-derivation; the key falls through to
        // the first writable candidate (dirname(ABSPATH) created by bootstrap).
        define('AUTH_KEY', 'put your unique phrase here');
        define('SECURE_AUTH_KEY', str_repeat('z', 64));
        define('LOGGED_IN_KEY', str_repeat('y', 64));

        // Register cleanup of the key file that will be written at dirname(ABSPATH).
        $this->cleanup[] = $this->absParent() . '/.wpmgr-agent-master.key';

        $keystore = new Keystore();
        $envelope = $keystore->encrypt('payload');
        $this->assertSame('payload', $keystore->decrypt($envelope));

        // Must have fallen through to a FILE source (not salts).
        $marker = $this->options[Keystore::OPTION_MASTER_KEY_SOURCE];
        $this->assertSame('file', $marker['source']);
        $this->assertFileExists($marker['path']);
        $this->assertSame(32, strlen((string) file_get_contents($marker['path'])));
        // 0600 perms.
        $this->assertSame('0600', substr(sprintf('%o', fileperms($marker['path'])), -4));
    }

    public function test_short_salts_are_rejected(): void
    {
        // Too little combined material -> salts unusable; fall to file.
        define('AUTH_KEY', 'short');
        define('NONCE_SALT', 'tiny');

        $this->cleanup[] = $this->absParent() . '/.wpmgr-agent-master.key';

        $keystore = new Keystore();
        $envelope = $keystore->encrypt('p');
        $this->assertSame('p', $keystore->decrypt($envelope));
        $this->assertSame('file', $this->options[Keystore::OPTION_MASTER_KEY_SOURCE]['source']);
    }

    public function test_file_fallback_picks_first_writable_and_hardens_webroot(): void
    {
        // No salts defined. dirname(ABSPATH) is the first key-file candidate.
        // Make it unwritable so the keystore skips it and falls back to
        // WP_CONTENT_DIR (the second candidate, in-webroot, hardened).
        $absParent = $this->absParent();

        // Remove any legacy key file so the legacy-reuse path does not fire
        // before the first-writable selection logic is exercised.
        @unlink($absParent . '/.wpmgr-agent-master.key');

        chmod($absParent, 0500);

        // WP_CONTENT_DIR is not defined by bootstrap; define it to a fresh
        // writable directory so the keystore picks it as the second candidate.
        $content = sys_get_temp_dir() . '/wpmgr-content-' . bin2hex(random_bytes(6));
        mkdir($content, 0700, true);
        define('WP_CONTENT_DIR', $content);

        $this->cleanup[] = $content . '/wpmgr-agent';
        $this->cleanup[] = $content;

        // Restore dirname(ABSPATH) permissions when this child process exits so
        // subsequent test-suite runs can create and clean up the directory.
        register_shutdown_function(static function () use ($absParent): void {
            @chmod($absParent, 0755);
        });

        $keystore = new Keystore();
        $envelope = $keystore->encrypt('webroot-payload');
        $this->assertSame('webroot-payload', $keystore->decrypt($envelope));

        $marker = $this->options[Keystore::OPTION_MASTER_KEY_SOURCE];
        $this->assertSame('file', $marker['source']);
        $this->assertStringContainsString('/wpmgr-agent/master.key', $marker['path']);

        // Web-root directory must be hardened.
        $dir = dirname($marker['path']);
        $this->assertFileExists($dir . '/index.php');
        $this->assertFileExists($dir . '/.htaccess');
        $this->assertStringContainsString('Require all denied', (string) file_get_contents($dir . '/.htaccess'));

        // Restore permissions before tearDown so cleanup can remove the dir.
        @chmod($absParent, 0755);
    }

    public function test_preexisting_legacy_key_file_is_reused(): void
    {
        // No salts. Plant a legacy key file at the path legacyKeyFilePaths()
        // checks: dirname(ABSPATH)/.wpmgr-agent-master.key. The keystore must
        // detect it and pin source='file' pointing to that exact path.
        $legacyPath = $this->absParent() . '/.wpmgr-agent-master.key';
        $knownKey   = random_bytes(32);
        file_put_contents($legacyPath, $knownKey);

        $this->cleanup[] = $legacyPath;

        $keystore = new Keystore();
        $envelope = $keystore->encrypt('legacy-payload');

        // Pinned to the legacy file path; no new key generated.
        $marker = $this->options[Keystore::OPTION_MASTER_KEY_SOURCE];
        $this->assertSame('file', $marker['source']);
        $this->assertSame($legacyPath, $marker['path']);
        $this->assertSame($knownKey, (string) file_get_contents($legacyPath));

        // A fresh instance reads the same legacy file and decrypts.
        $fresh = new Keystore();
        $this->assertSame('legacy-payload', $fresh->decrypt($envelope));
    }

    public function test_constant_path_takes_priority_and_creates_file(): void
    {
        $path = sys_get_temp_dir() . '/wpmgr-const-' . bin2hex(random_bytes(6)) . '.key';
        define('WPMGR_AGENT_KEY_FILE', $path);
        $this->cleanup[] = $path;

        // Salts also present, but the constant wins.
        $this->defineRealSalts();

        $keystore = new Keystore();
        $envelope = $keystore->encrypt('const-payload');
        $this->assertSame('const-payload', $keystore->decrypt($envelope));

        $this->assertFileExists($path);
        $this->assertSame(32, strlen((string) file_get_contents($path)));
        $this->assertSame(['source' => 'constant'], $this->options[Keystore::OPTION_MASTER_KEY_SOURCE]);
    }
}
