<?php
/**
 * BackupCodesProvider tests.
 *
 * Validates:
 *   - key() returns 'backup'
 *   - generateAndStore() produces exactly CODE_COUNT codes
 *   - isConfiguredFor() checks that hashes exist in user-meta
 *   - validate() accepts a valid backup code
 *   - validate() burns the used code (delete-on-use), other codes remain
 *   - validate() rejects a wrong code
 *   - validate() rejects a code of wrong length
 *   - remainingCount() reflects the correct count after use
 *
 * @package WPMgr\Agent\Tests\Security
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests\Security;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WPMgr\Agent\Security\BackupCodesProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Security\BackupCodesProvider
 */
final class BackupCodesProviderTest extends TestCase
{
    /** @var array<int,array<string,mixed>> */
    private array $userMeta = [];

    protected function set_up(): void
    {
        parent::set_up();
        Monkey\setUp();

        $this->userMeta = [];

        Functions\when('get_user_meta')->alias(function ($uid, $key, $single) {
            return $this->userMeta[$uid][$key] ?? '';
        });
        Functions\when('update_user_meta')->alias(function ($uid, $key, $value) {
            $this->userMeta[$uid][$key] = $value;
            return true;
        });
        Functions\when('delete_user_meta')->alias(function ($uid, $key) {
            unset($this->userMeta[$uid][$key]);
            return true;
        });

        // wp_hash_password stores the code as-is for test purposes (plaintext "hash").
        Functions\when('wp_hash_password')->alias(fn ($v) => 'hash:' . $v);
        // wp_check_password: accept if hash equals 'hash:code'.
        Functions\when('wp_check_password')->alias(function ($plain, $hash, $userId) {
            return $hash === 'hash:' . $plain;
        });

        Functions\when('esc_html__')->alias(fn ($t, $d = '') => $t);
        Functions\when('esc_attr')->alias(fn ($t) => $t);
    }

    protected function tear_down(): void
    {
        Monkey\tearDown();
        parent::tear_down();
    }

    private function makeUser(int $id = 1): \WP_User
    {
        $u     = new \WP_User();
        $u->ID = $id;
        return $u;
    }

    private function provider(): BackupCodesProvider
    {
        return new BackupCodesProvider();
    }

    // -------------------------------------------------------------------------
    // Key
    // -------------------------------------------------------------------------

    public function test_key_is_backup(): void
    {
        $this->assertSame('backup', $this->provider()->key());
    }

    // -------------------------------------------------------------------------
    // generateAndStore()
    // -------------------------------------------------------------------------

    public function test_generate_and_store_produces_code_count_codes(): void
    {
        $user   = $this->makeUser(1);
        $codes  = $this->provider()->generateAndStore($user);

        $this->assertCount(BackupCodesProvider::CODE_COUNT, $codes, 'must generate exactly CODE_COUNT codes');
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^\d{10}$/', $code, 'each code must be 10 digits');
        }

        $stored = $this->userMeta[1][BackupCodesProvider::META_CODES] ?? null;
        $this->assertIsArray($stored);
        $this->assertCount(BackupCodesProvider::CODE_COUNT, $stored);
    }

    // -------------------------------------------------------------------------
    // isConfiguredFor()
    // -------------------------------------------------------------------------

    public function test_is_configured_false_when_no_codes(): void
    {
        $user = $this->makeUser(1);
        $this->assertFalse($this->provider()->isConfiguredFor($user));
    }

    public function test_is_configured_true_when_codes_stored(): void
    {
        $user = $this->makeUser(1);
        $this->provider()->generateAndStore($user);
        $this->assertTrue($this->provider()->isConfiguredFor($user));
    }

    // -------------------------------------------------------------------------
    // validate() — valid code accepted
    // -------------------------------------------------------------------------

    public function test_validate_accepts_valid_code(): void
    {
        $user   = $this->makeUser(1);
        $codes  = $this->provider()->generateAndStore($user);
        $code   = $codes[0]; // Use first code.

        $result = $this->provider()->validate($user, ['wpmgr_backup_code' => $code]);
        $this->assertTrue($result);
    }

    // -------------------------------------------------------------------------
    // validate() — single-use burn: used code removed, others remain
    // -------------------------------------------------------------------------

    public function test_validate_burns_used_code_only(): void
    {
        $user  = $this->makeUser(1);
        $codes = $this->provider()->generateAndStore($user);
        $code  = $codes[2]; // Use code at index 2.

        $this->provider()->validate($user, ['wpmgr_backup_code' => $code]);

        $remaining = $this->userMeta[1][BackupCodesProvider::META_CODES] ?? [];
        $this->assertCount(
            BackupCodesProvider::CODE_COUNT - 1,
            $remaining,
            'exactly one code must be removed after use'
        );

        // The used code's hash must not be in the remaining set.
        $usedHash = 'hash:' . $code;
        $this->assertNotContains($usedHash, $remaining, 'used code hash must be removed');
    }

    // -------------------------------------------------------------------------
    // validate() — wrong code rejected
    // -------------------------------------------------------------------------

    public function test_validate_rejects_wrong_code(): void
    {
        $user  = $this->makeUser(1);
        $this->provider()->generateAndStore($user);

        $result = $this->provider()->validate($user, ['wpmgr_backup_code' => '0000000000']);
        $this->assertFalse($result, 'wrong code must be rejected');
    }

    // -------------------------------------------------------------------------
    // validate() — wrong-length code rejected
    // -------------------------------------------------------------------------

    public function test_validate_rejects_wrong_length(): void
    {
        $user = $this->makeUser(1);
        $this->provider()->generateAndStore($user);

        $result = $this->provider()->validate($user, ['wpmgr_backup_code' => '123456789']);  // 9 digits
        $this->assertFalse($result, '9-digit code must be rejected (needs 10)');
    }

    // -------------------------------------------------------------------------
    // remainingCount()
    // -------------------------------------------------------------------------

    public function test_remaining_count_decrements_after_use(): void
    {
        $user   = $this->makeUser(1);
        $codes  = $this->provider()->generateAndStore($user);
        $p      = $this->provider();

        $this->assertSame(BackupCodesProvider::CODE_COUNT, $p->remainingCount($user));

        $p->validate($user, ['wpmgr_backup_code' => $codes[0]]);

        $this->assertSame(BackupCodesProvider::CODE_COUNT - 1, $p->remainingCount($user));
    }
}
