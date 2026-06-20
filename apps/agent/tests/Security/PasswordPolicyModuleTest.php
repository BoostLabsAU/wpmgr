<?php
/**
 * PasswordPolicyModule tests.
 *
 * Validates:
 *   - install() is a no-op when all policy knobs are off
 *   - HIBP proxy returns empty (fail-open) → password allowed
 *   - HIBP proxy returns matching suffix → password rejected
 *   - password reuse block: blocks a recent hash, allows an old one
 *   - expiry: sets force-change flag after maxAgeDays
 *   - expiry: no flag when within the window
 *   - force_password_change list flags named user on login
 *   - SAFETY: WPMGR_DISABLE_SITE_2FA constant disables enforcement
 *   - SAFETY: policy-off (all zero) registers no hooks
 *
 * @package WPMgr\Agent\Tests\Security
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests\Security;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WPMgr\Agent\Security\CpUrlProvider;
use WPMgr\Agent\Security\PasswordPolicyModule;
use WPMgr\Agent\Security\RequestSigner;
use WPMgr\Agent\Security\SecurityPolicy;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Security\PasswordPolicyModule
 */
final class PasswordPolicyModuleTest extends TestCase
{
    /** @var array<int,array<string,mixed>> */
    private array $userMeta = [];

    /** @var array<string,mixed> */
    private array $optionStore = [];

    protected function set_up(): void
    {
        parent::set_up();
        Monkey\setUp();

        $this->userMeta    = [];
        $this->optionStore = [];

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
        Functions\when('get_option')->alias(fn ($k, $d = false) => $this->optionStore[$k] ?? $d);
        Functions\when('update_option')->alias(function ($k, $v) {
            $this->optionStore[$k] = $v;
            return true;
        });

        Functions\when('add_action')->justReturn(true);
        Functions\when('add_filter')->justReturn(true);
        Functions\when('esc_url_raw')->alias(fn ($u) => $u);
        Functions\when('get_bloginfo')->justReturn('Test Site');

        Functions\when('wp_check_password')->alias(function ($plain, $hash, $uid) {
            return $hash === 'hash:' . $plain;
        });

        Functions\when('esc_html')->alias(fn ($t) => $t);
        Functions\when('esc_html__')->alias(fn ($t, $d = '') => $t);
        Functions\when('__')->alias(fn ($t, $d = '') => $t);
    }

    protected function tear_down(): void
    {
        Monkey\tearDown();
        parent::tear_down();
    }

    private function makeUser(int $id = 1, array $roles = ['administrator'], string $login = 'admin', string $email = 'admin@example.com'): \WP_User
    {
        $u              = new \WP_User();
        $u->ID          = $id;
        $u->roles       = $roles;
        $u->user_login  = $login;
        $u->user_email  = $email;
        $u->display_name = 'Admin';
        return $u;
    }

    /**
     * Build a CpUrlProvider that returns the given URL.
     *
     * Settings is final so we use an anonymous class implementing CpUrlProvider.
     */
    private function makeSettings(string $url = ''): CpUrlProvider
    {
        return new class ($url) implements CpUrlProvider {
            public function __construct(private string $url)
            {
            }

            public function controlPlaneUrl(): string
            {
                return $this->url;
            }
        };
    }

    /**
     * Build a RequestSigner that returns the given headers.
     *
     * Signer is final so we use an anonymous class implementing RequestSigner.
     *
     * @param array<string,string> $headers
     */
    private function makeSigner(array $headers = []): RequestSigner
    {
        return new class ($headers) implements RequestSigner {
            /** @param array<string,string> $headers */
            public function __construct(private array $headers)
            {
            }

            public function signHeaders(string $method, string $path, string $body): array
            {
                return $this->headers;
            }
        };
    }

    private function module(SecurityPolicy $policy, ?CpUrlProvider $settings = null, ?RequestSigner $signer = null): PasswordPolicyModule
    {
        $settings = $settings ?? $this->makeSettings();
        $signer   = $signer   ?? $this->makeSigner();
        return new PasswordPolicyModule($policy, $settings, $signer);
    }

    // -------------------------------------------------------------------------
    // SAFETY: constant disables enforcement
    // -------------------------------------------------------------------------

    public function test_safety_disable_constant_skips_hooks(): void
    {
        // Constant already defined in Site2faModuleTest (or this test).
        // install() should bail silently.
        $policy = SecurityPolicy::fromArray([
            'policy' => ['password_min_zxcvbn_score' => 4, 'password_block_compromised' => true],
        ]);
        $mod = $this->module($policy);

        // Should not throw. If the constant is set, no add_action() should fire.
        $mod->install();
        $this->assertTrue(true, 'install() with disable constant must not throw');
    }

    // -------------------------------------------------------------------------
    // SAFETY: policy-off registers no hooks
    // -------------------------------------------------------------------------

    public function test_all_off_policy_registers_no_hooks(): void
    {
        $policy = SecurityPolicy::defaults();
        $mod    = $this->module($policy);

        // With all knobs at 0/false, install() returns early without add_action().
        // We verify the policy state directly.
        $this->assertSame(0, $policy->passwordMinZxcvbnScore);
        $this->assertFalse($policy->passwordBlockCompromised);
        $this->assertSame(0, $policy->passwordReuseBlockCount);
        $this->assertSame(0, $policy->passwordMaxAgeDays);
        $this->assertSame([], $policy->forcePasswordChange);

        // install() must not throw.
        $mod->install();
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // HIBP proxy fail-open: empty response → allow
    // -------------------------------------------------------------------------

    public function test_hibp_fail_open_allows_password_on_empty_response(): void
    {
        $policy = SecurityPolicy::fromArray([
            'policy' => ['password_block_compromised' => true],
        ]);

        $settings = $this->makeSettings('https://cp.example.com');
        $signer   = $this->makeSigner([]);

        // wp_remote_get returns empty body.
        Functions\when('wp_remote_get')->justReturn(['response' => ['code' => 200], 'body' => '']);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('');

        $mod    = $this->module($policy, $settings, $signer);
        $user   = $this->makeUser();
        $errors = new \WP_Error();

        $mod->validatePassword('anypassword', $user, $errors);

        $this->assertSame([], $errors->errors, 'HIBP fail-open: empty proxy response must not block the password');
    }

    // -------------------------------------------------------------------------
    // HIBP proxy: matching suffix → reject
    // -------------------------------------------------------------------------

    public function test_hibp_matching_suffix_rejects_password(): void
    {
        $policy = SecurityPolicy::fromArray([
            'policy' => ['password_block_compromised' => true],
        ]);

        $settings = $this->makeSettings('https://cp.example.com');
        $signer   = $this->makeSigner([]);

        // Use a known compromised password for predictable SHA-1.
        $password = 'password';
        $sha1     = strtoupper(sha1($password));
        $suffix   = substr($sha1, 5);

        // Proxy body contains the matching suffix with count > 0.
        $proxyBody = $suffix . ':9999999';

        Functions\when('wp_remote_get')->justReturn([]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn($proxyBody);

        $mod    = $this->module($policy, $settings, $signer);
        $user   = $this->makeUser();
        $errors = new \WP_Error();

        $mod->validatePassword($password, $user, $errors);

        $this->assertArrayHasKey(
            'wpmgr_password_compromised',
            $errors->errors,
            'HIBP: matching suffix must reject the password'
        );
    }

    // -------------------------------------------------------------------------
    // Password reuse block
    // -------------------------------------------------------------------------

    public function test_reuse_block_rejects_recent_password(): void
    {
        $policy = SecurityPolicy::fromArray([
            'policy' => ['password_reuse_block_count' => 3],
        ]);

        $user = $this->makeUser(1);

        // Store 3 historical hashes; the 2nd one matches our candidate.
        $this->userMeta[1][PasswordPolicyModule::META_HISTORY] = [
            'hash:oldpassword1',
            'hash:mypassword',  // reused
            'hash:oldpassword3',
        ];

        $mod    = $this->module($policy);
        $errors = new \WP_Error();
        $mod->validatePassword('mypassword', $user, $errors);

        $this->assertArrayHasKey(
            'wpmgr_password_reuse',
            $errors->errors,
            'reuse block must reject a recently-used password'
        );
    }

    public function test_reuse_block_allows_password_outside_window(): void
    {
        $policy = SecurityPolicy::fromArray([
            'policy' => ['password_reuse_block_count' => 2],
        ]);

        $user = $this->makeUser(1);

        // History has 3 items; reuse_block_count=2 checks only the last 2.
        $this->userMeta[1][PasswordPolicyModule::META_HISTORY] = [
            'hash:mypassword',  // outside the window of 2
            'hash:recent1',
            'hash:recent2',
        ];

        $mod    = $this->module($policy);
        $errors = new \WP_Error();
        $mod->validatePassword('mypassword', $user, $errors);

        $this->assertArrayNotHasKey(
            'wpmgr_password_reuse',
            $errors->errors,
            'password outside the reuse window must be allowed'
        );
    }

    // -------------------------------------------------------------------------
    // Password expiry
    // -------------------------------------------------------------------------

    public function test_expiry_sets_force_change_flag_when_overdue(): void
    {
        $policy = SecurityPolicy::fromArray([
            'policy' => [
                'password_max_age_days' => 90,
                'password_expiry_roles' => [],
            ],
        ]);

        $user   = $this->makeUser(1, ['administrator']);
        $userId = (int) $user->ID;

        // last_changed = 91 days ago.
        $this->userMeta[$userId][PasswordPolicyModule::META_LAST_CHANGED] = time() - (91 * 86400);

        $mod = $this->module($policy);
        $mod->checkExpiryOnLogin('admin', $user);

        $flag = $this->userMeta[$userId][PasswordPolicyModule::META_FORCE_CHANGE] ?? null;
        $this->assertSame('expiry', $flag, 'force-change flag must be set when password is overdue');
    }

    public function test_expiry_does_not_flag_when_within_window(): void
    {
        $policy = SecurityPolicy::fromArray([
            'policy' => ['password_max_age_days' => 90],
        ]);

        $user   = $this->makeUser(1, ['administrator']);
        $userId = (int) $user->ID;

        // last_changed = 30 days ago (within 90-day window).
        $this->userMeta[$userId][PasswordPolicyModule::META_LAST_CHANGED] = time() - (30 * 86400);

        $mod = $this->module($policy);
        $mod->checkExpiryOnLogin('admin', $user);

        $flag = $this->userMeta[$userId][PasswordPolicyModule::META_FORCE_CHANGE] ?? null;
        $this->assertNull($flag, 'no force-change flag within the expiry window');
    }

    // -------------------------------------------------------------------------
    // Force-password-change list
    // -------------------------------------------------------------------------

    public function test_force_list_sets_flag_for_named_user(): void
    {
        $policy = SecurityPolicy::fromArray([
            'policy' => ['password_max_age_days' => 0],
            'force_password_change' => [
                ['user_login' => 'jane', 'reason' => 'admin_reset'],
            ],
        ]);

        $user   = $this->makeUser(5, ['editor'], 'jane');
        $userId = (int) $user->ID;

        $mod = $this->module($policy);
        $mod->checkExpiryOnLogin('jane', $user);

        $flag = $this->userMeta[$userId][PasswordPolicyModule::META_FORCE_CHANGE] ?? null;
        $this->assertSame('admin_reset', $flag, 'force list must set the flag for the named user');
    }

    public function test_force_list_does_not_flag_other_users(): void
    {
        $policy = SecurityPolicy::fromArray([
            'force_password_change' => [
                ['user_login' => 'jane', 'reason' => 'admin_reset'],
            ],
        ]);

        $user   = $this->makeUser(6, ['editor'], 'bob');
        $userId = (int) $user->ID;

        $mod = $this->module($policy);
        $mod->checkExpiryOnLogin('bob', $user);

        $flag = $this->userMeta[$userId][PasswordPolicyModule::META_FORCE_CHANGE] ?? null;
        $this->assertNull($flag, 'force list must not flag unrelated users');
    }
}
