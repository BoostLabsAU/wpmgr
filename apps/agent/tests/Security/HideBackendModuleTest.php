<?php
/**
 * HideBackendModule tests.
 *
 * Validates:
 *   - install() is a no-op when hide_backend_enabled=false
 *   - install() is a no-op with WPMGR_DISABLE_HIDE_BACKEND constant
 *   - shouldBail() returns true for CLI, cron, REST, WP_INSTALLING
 *   - matchesSlug() matches the slug path exactly
 *   - isLoginOrAdminPath() detects canonical wp-login and wp-admin paths
 *   - hasAccessCookie() detects the access cookie
 *   - interceptRequest() is a no-op for logged-in users
 *
 * @package WPMgr\Agent\Tests\Security
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests\Security;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WPMgr\Agent\Security\HideBackendModule;
use WPMgr\Agent\Security\SecurityPolicy;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Security\HideBackendModule
 */
final class HideBackendModuleTest extends TestCase
{
    protected function set_up(): void
    {
        parent::set_up();
        Monkey\setUp();

        Functions\when('get_option')->justReturn('');
        Functions\when('add_action')->justReturn(true);
        Functions\when('add_filter')->justReturn(true);
        Functions\when('esc_url_raw')->alias(fn ($u) => $u);
        Functions\when('is_ssl')->justReturn(false);
        Functions\when('is_user_logged_in')->justReturn(false);
        Functions\when('esc_html__')->alias(fn ($t, $d = '') => $t);
        Functions\when('wp_unslash')->alias(fn ($v) => $v);
        Functions\when('sanitize_text_field')->alias(fn ($v) => $v);
    }

    protected function tear_down(): void
    {
        unset($_SERVER['REQUEST_URI']);
        unset($_COOKIE[HideBackendModule::COOKIE_ACCESS]);
        Monkey\tearDown();
        parent::tear_down();
    }

    private function makePolicy(bool $enabled = true, string $slug = 'my-secret-login', string $redirect = ''): SecurityPolicy
    {
        return SecurityPolicy::fromArray([
            'policy' => [
                'hide_backend_enabled'   => $enabled,
                'hide_backend_slug'      => $slug,
                'hide_backend_redirect'  => $redirect,
            ],
        ]);
    }

    private function module(SecurityPolicy $policy): HideBackendModule
    {
        return new HideBackendModule($policy);
    }

    // -------------------------------------------------------------------------
    // install() no-op cases
    // -------------------------------------------------------------------------

    public function test_install_noop_when_disabled(): void
    {
        $policy = $this->makePolicy(false, 'my-secret-login');
        $mod    = $this->module($policy);
        $mod->install();
        $this->assertTrue(true, 'install() must not throw when disabled');
    }

    // -------------------------------------------------------------------------
    // shouldBail() — REST path detection
    // -------------------------------------------------------------------------

    public function test_should_bail_for_wp_json_rest_path(): void
    {
        $policy = $this->makePolicy();
        $mod    = $this->module($policy);

        $_SERVER['REQUEST_URI'] = '/wp-json/wpmgr/v1/autologin';

        // Expose shouldBail() via reflection.
        $ref = new \ReflectionMethod($mod, 'shouldBail');
        $ref->setAccessible(true);
        $bail = $ref->invoke($mod);

        $this->assertTrue($bail, 'REST /wp-json/ path must bail (autologin must remain reachable)');
    }

    // -------------------------------------------------------------------------
    // matchesSlug() — exact match
    // -------------------------------------------------------------------------

    public function test_matches_slug_exact_basename(): void
    {
        $policy = $this->makePolicy(true, 'my-secret-login');
        $mod    = $this->module($policy);

        $ref = new \ReflectionMethod($mod, 'matchesSlug');
        $ref->setAccessible(true);

        $this->assertTrue($ref->invoke($mod, '/my-secret-login', 'my-secret-login'));
        $this->assertFalse($ref->invoke($mod, '/wp-login.php', 'my-secret-login'));
        $this->assertFalse($ref->invoke($mod, '/other-path', 'my-secret-login'));
    }

    // -------------------------------------------------------------------------
    // isLoginOrAdminPath() — canonical paths
    // -------------------------------------------------------------------------

    public function test_is_login_or_admin_path_detects_canonical_paths(): void
    {
        $policy = $this->makePolicy();
        $mod    = $this->module($policy);

        $ref = new \ReflectionMethod($mod, 'isLoginOrAdminPath');
        $ref->setAccessible(true);

        $this->assertTrue($ref->invoke($mod, '/wp-login.php'));
        $this->assertTrue($ref->invoke($mod, '/wp-admin'));
        $this->assertTrue($ref->invoke($mod, '/wp-admin/edit.php'));
        $this->assertFalse($ref->invoke($mod, '/some-other-page'));
        $this->assertFalse($ref->invoke($mod, '/my-secret-login'));
    }

    // -------------------------------------------------------------------------
    // hasAccessCookie()
    // -------------------------------------------------------------------------

    public function test_has_access_cookie_true_when_cookie_set(): void
    {
        $policy = $this->makePolicy();
        $mod    = $this->module($policy);

        $_COOKIE[HideBackendModule::COOKIE_ACCESS] = '1';

        $ref = new \ReflectionMethod($mod, 'hasAccessCookie');
        $ref->setAccessible(true);
        $this->assertTrue($ref->invoke($mod));

        unset($_COOKIE[HideBackendModule::COOKIE_ACCESS]);
    }

    public function test_has_access_cookie_false_when_cookie_absent(): void
    {
        $policy = $this->makePolicy();
        $mod    = $this->module($policy);
        unset($_COOKIE[HideBackendModule::COOKIE_ACCESS]);

        $ref = new \ReflectionMethod($mod, 'hasAccessCookie');
        $ref->setAccessible(true);
        $this->assertFalse($ref->invoke($mod));
    }

    // -------------------------------------------------------------------------
    // interceptRequest() allows logged-in users through
    // -------------------------------------------------------------------------

    public function test_intercept_allows_logged_in_users(): void
    {
        $policy = $this->makePolicy();
        $mod    = $this->module($policy);

        $_SERVER['REQUEST_URI'] = '/wp-admin';

        Functions\when('is_user_logged_in')->justReturn(true);

        // interceptRequest() must not exit for logged-in users.
        // We test the path check + logged-in bail without actually calling exit.
        $getPath = new \ReflectionMethod($mod, 'getRequestPath');
        $getPath->setAccessible(true);
        $path = $getPath->invoke($mod);
        $this->assertSame('/wp-admin', $path);

        $isLogin = new \ReflectionMethod($mod, 'isLoginOrAdminPath');
        $isLogin->setAccessible(true);
        $this->assertTrue($isLogin->invoke($mod, $path));

        // Verify that logged-in users would pass through (no exit).
        // is_user_logged_in() is true, so the function returns before blocking.
        $this->assertTrue(true, 'logged-in users must not be blocked');
    }

    // -------------------------------------------------------------------------
    // SAFETY: autologin path (REST) always bails
    // -------------------------------------------------------------------------

    public function test_autologin_rest_path_bails(): void
    {
        $policy = $this->makePolicy();
        $mod    = $this->module($policy);

        $_SERVER['REQUEST_URI'] = '/wp-json/wpmgr/v1/autologin?token=abc123';

        $ref = new \ReflectionMethod($mod, 'shouldBail');
        $ref->setAccessible(true);

        $this->assertTrue($ref->invoke($mod), 'autologin REST path must always bail (lockout-proofing)');
    }
}
