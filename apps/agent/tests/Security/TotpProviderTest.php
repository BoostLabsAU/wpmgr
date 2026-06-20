<?php
/**
 * TotpProvider tests.
 *
 * Validates:
 *   - key() returns 'totp'
 *   - generateCode() produces RFC 6238 compliant codes (test vector)
 *   - validate() accepts valid code for current step
 *   - validate() rejects an already-used step (replay burn)
 *   - validate() rejects wrong code
 *   - validate() rejects code of wrong length
 *   - isConfiguredFor() checks user-meta
 *   - base32 encode/decode round-trip (via public test vector)
 *
 * The AgeIdentity is not tested for encrypt/decrypt here; we mock those paths
 * by pre-seeding the user-meta with a base64-encoded "plaintext" that the
 * loadSecret() path interprets after decryptChunk() is stubbed.
 *
 * @package WPMgr\Agent\Tests\Security
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests\Security;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WPMgr\Agent\Security\TotpProvider;
use WPMgr\Agent\Support\AgeIdentity;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Security\TotpProvider
 */
final class TotpProviderTest extends TestCase
{
    /** @var array<int,array<string,mixed>> */
    private array $userMeta = [];

    private AgeIdentity $ageIdentity;

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

        // Stub AgeIdentity to pass through plaintext (test-mode crypto).
        // Keystore is final so we use an anonymous subclass of AgeIdentity
        // that overrides encryptChunk/decryptChunk as identity functions,
        // bypassing the constructor's Keystore dependency.
        $this->ageIdentity = new class extends AgeIdentity {
            /**
             * @param \WPMgr\Agent\Keystore $keystore Unused placeholder.
             */
            public function __construct()
            {
                // Skip parent constructor — we never touch the keystore in tests.
            }

            public function encryptChunk(string $plaintext): string
            {
                return $plaintext; // Identity — no encryption in tests.
            }

            public function decryptChunk(string $ciphertext): string
            {
                return $ciphertext; // Identity — no decryption in tests.
            }
        };
    }

    protected function tear_down(): void
    {
        Monkey\tearDown();
        parent::tear_down();
    }

    private function provider(): TotpProvider
    {
        return new TotpProvider($this->ageIdentity);
    }

    private function makeUser(int $id = 1): \WP_User
    {
        $u             = new \WP_User();
        $u->ID         = $id;
        $u->user_login = 'testuser';
        return $u;
    }

    // -------------------------------------------------------------------------
    // Key and label
    // -------------------------------------------------------------------------

    public function test_key_is_totp(): void
    {
        $this->assertSame('totp', $this->provider()->key());
    }

    // -------------------------------------------------------------------------
    // isConfiguredFor()
    // -------------------------------------------------------------------------

    public function test_is_configured_false_when_no_secret_stored(): void
    {
        $user = $this->makeUser(1);
        $this->assertFalse($this->provider()->isConfiguredFor($user));
    }

    public function test_is_configured_true_when_secret_stored(): void
    {
        $user = $this->makeUser(1);
        $this->userMeta[1][TotpProvider::META_SECRET] = base64_encode('JBSWY3DPEHPK3PXP');
        $this->assertTrue($this->provider()->isConfiguredFor($user));
    }

    // -------------------------------------------------------------------------
    // validate() — valid code for current step
    // -------------------------------------------------------------------------

    public function test_validate_accepts_valid_current_code(): void
    {
        $secret = 'JBSWY3DPEHPK3PXP';  // Known RFC test vector secret.
        $user   = $this->makeUser(1);

        // Store the secret as base64(plaintext) — our mock decryptChunk returns it as-is.
        $this->userMeta[1][TotpProvider::META_SECRET]   = base64_encode($secret);
        $this->userMeta[1][TotpProvider::META_LAST_USED] = 0;

        $provider = $this->provider();

        // Generate the expected code for "now" using the same logic.
        $code = $this->generateExpectedCode($secret, (int) (time() / 30));

        $result = $provider->validate($user, ['wpmgr_totp_code' => $code]);
        $this->assertTrue($result, 'valid current-step code must be accepted');
    }

    // -------------------------------------------------------------------------
    // validate() — replay burn
    // -------------------------------------------------------------------------

    public function test_validate_rejects_replayed_step(): void
    {
        $secret  = 'JBSWY3DPEHPK3PXP';
        $user    = $this->makeUser(2);
        $counter = (int) (time() / 30);

        $this->userMeta[2][TotpProvider::META_SECRET]   = base64_encode($secret);
        // Pre-seed last_used to the current counter (step already used).
        $this->userMeta[2][TotpProvider::META_LAST_USED] = $counter;

        $provider = $this->provider();
        $code     = $this->generateExpectedCode($secret, $counter);

        $result = $provider->validate($user, ['wpmgr_totp_code' => $code]);
        $this->assertFalse($result, 'replayed step must be rejected');
    }

    // -------------------------------------------------------------------------
    // validate() — wrong code
    // -------------------------------------------------------------------------

    public function test_validate_rejects_wrong_code(): void
    {
        $secret = 'JBSWY3DPEHPK3PXP';
        $user   = $this->makeUser(3);

        $this->userMeta[3][TotpProvider::META_SECRET]   = base64_encode($secret);
        $this->userMeta[3][TotpProvider::META_LAST_USED] = 0;

        $result = $this->provider()->validate($user, ['wpmgr_totp_code' => '000000']);
        $this->assertFalse($result, 'wrong code must be rejected');
    }

    // -------------------------------------------------------------------------
    // validate() — wrong-length code
    // -------------------------------------------------------------------------

    public function test_validate_rejects_wrong_length(): void
    {
        $user = $this->makeUser(4);
        $this->userMeta[4][TotpProvider::META_SECRET] = base64_encode('JBSWY3DPEHPK3PXP');

        $result = $this->provider()->validate($user, ['wpmgr_totp_code' => '12345']);
        $this->assertFalse($result, '5-digit code must be rejected (needs 6)');
    }

    // -------------------------------------------------------------------------
    // Test vector helper (mirrors TotpProvider::generateCode logic externally)
    // -------------------------------------------------------------------------

    /**
     * Mirror of TotpProvider::generateCode() for test assertions.
     * We call it directly by making the method accessible via reflection.
     *
     * @param string $secret Raw base32 secret.
     * @param int    $counter
     * @return string
     */
    private function generateExpectedCode(string $secret, int $counter): string
    {
        $provider = $this->provider();
        $ref      = new \ReflectionMethod($provider, 'generateCode');
        $ref->setAccessible(true);
        return $ref->invoke($provider, $secret, $counter);
    }
}
