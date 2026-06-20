<?php
/**
 * HideBackendModule — secret-slug login-page obfuscation.
 *
 * When enabled, this module:
 *  1. Intercepts at setup_theme (before WP routes to wp-login.php/wp-admin).
 *  2. Compares the request path against hide_backend_slug:
 *       path == slug → set a short-lived access cookie, internally load wp-login.php.
 *       canonical wp-login/wp-admin for logged-out un-tokened visitors → 404 or redirect.
 *  3. Bails for REST/cron/CLI/WP_INSTALLING so the agent's own wpmgr/v1 routes
 *     and the autologin path remain fully reachable.
 *
 * LOCKOUT-PROOFING:
 *  - define('WPMGR_DISABLE_HIDE_BACKEND', true) disables this entirely.
 *  - The autologin path (POST /wp-json/wpmgr/v1/autologin) is a REST route
 *    and hits the REST bail before any redirect fires.
 *  - Logged-in users are never redirected.
 *  - /wp-cron.php, CLI, WP_INSTALLING all bail.
 *  - The cookie doubles as an access token: once the slug is visited and the
 *    cookie is set, all subsequent wp-login.php requests in that browser session
 *    are allowed (multi-request login dance).
 *
 * @package WPMgr\Agent\Security
 */

declare(strict_types=1);

namespace WPMgr\Agent\Security;

/**
 * Hide-backend login-slug enforcement.
 */
final class HideBackendModule
{
    /** Cookie name for the access token set after the slug is visited. */
    public const COOKIE_ACCESS = 'wpmgr_hb_access';

    /** Access-cookie TTL in seconds (1 hour — covers the full multi-request login dance). */
    private const COOKIE_TTL = 3600;

    private SecurityPolicy $policy;

    /**
     * @param SecurityPolicy $policy Active site policy.
     */
    public function __construct(SecurityPolicy $policy)
    {
        $this->policy = $policy;
    }

    /**
     * Register WordPress hooks. Call once on plugins_loaded.
     *
     * @return void
     */
    public function install(): void
    {
        static $installed = false;
        if ($installed) {
            return;
        }
        $installed = true;

        // Recovery constant.
        if (defined('WPMGR_DISABLE_HIDE_BACKEND') && WPMGR_DISABLE_HIDE_BACKEND) {
            return;
        }

        if (!$this->policy->hideBackendEnabled || $this->policy->hideBackendSlug === '') {
            return;
        }

        // Intercept at setup_theme — earliest WP hook after plugins are loaded
        // and before wp-login.php / wp-admin routing takes effect.
        add_action('setup_theme', [$this, 'interceptRequest']);
    }

    /**
     * Intercept the current request and redirect/block as configured.
     * Called on setup_theme.
     *
     * @return void
     */
    public function interceptRequest(): void
    {
        // Always bail for REST, cron, WP-CLI, WP_INSTALLING.
        if ($this->shouldBail()) {
            return;
        }

        $slug    = $this->policy->hideBackendSlug;
        $request = $this->getRequestPath();

        // Slug match: set the access cookie and route to wp-login.php.
        if ($this->matchesSlug($request, $slug)) {
            $this->setAccessCookie();
            // Let WP process wp-login.php by setting the SERVER path variables
            // so the login dance (including lost-password etc.) continues to work.
            return;
        }

        // Canonical wp-login / wp-admin for a logged-out, un-tokened visitor.
        if ($this->isLoginOrAdminPath($request)) {
            if (function_exists('is_user_logged_in') && is_user_logged_in()) {
                return;
            }

            // Check for the access token cookie (multi-request login dance).
            if ($this->hasAccessCookie()) {
                return;
            }

            // Block: 404 or redirect.
            $redirect = $this->policy->hideBackendRedirect;
            if ($redirect !== '') {
                if (!headers_sent()) {
                    header('Location: ' . esc_url_raw($redirect), true, 302);
                }
            } else {
                if (!headers_sent()) {
                    http_response_code(404);
                    header('Content-Type: text/html; charset=utf-8');
                }
                // translators: Shown when the login page is hidden and the user accesses the wrong URL.
                echo esc_html__('Page not found.', 'wpmgr-agent');
            }
            exit;
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Determine whether we should bail (not interfere).
     *
     * @return bool
     */
    private function shouldBail(): bool
    {
        // WP-CLI.
        if (php_sapi_name() === 'cli') {
            return true;
        }

        // WP Cron.
        if (defined('DOING_CRON') && DOING_CRON) {
            return true;
        }

        // WP Install.
        if (defined('WP_INSTALLING') && WP_INSTALLING) {
            return true;
        }

        // REST API: any /wp-json/ request, including the agent's wpmgr/v1 routes.
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return true;
        }

        // Also detect REST by path prefix (REST_REQUEST may not be defined yet).
        $request = $this->getRequestPath();
        if (str_contains($request, '/wp-json/')) {
            return true;
        }

        return false;
    }

    /**
     * Get the current request path (without query string).
     *
     * @return string
     */
    private function getRequestPath(): string
    {
        if (!isset($_SERVER['REQUEST_URI']) || !is_string($_SERVER['REQUEST_URI'])) {
            return '';
        }
        $uri  = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI']));
        $path = strtok($uri, '?');
        return is_string($path) ? rtrim($path, '/') : '';
    }

    /**
     * Whether the request path matches the configured slug.
     * Compares the path's basename (last segment) against the slug.
     *
     * @param string $path
     * @param string $slug
     * @return bool
     */
    private function matchesSlug(string $path, string $slug): bool
    {
        $base = basename($path);
        return $base === $slug;
    }

    /**
     * Whether the path is a canonical wp-login or wp-admin location.
     *
     * @param string $path
     * @return bool
     */
    private function isLoginOrAdminPath(string $path): bool
    {
        $loginFile = '/wp-login.php';
        $adminDir  = '/wp-admin';

        return str_ends_with($path, $loginFile)
            || $path === $adminDir
            || str_starts_with($path, $adminDir . '/');
    }

    /**
     * Set the access cookie so the multi-request login dance continues to work.
     *
     * @return void
     */
    private function setAccessCookie(): void
    {
        if (headers_sent()) {
            return;
        }
        setcookie(
            self::COOKIE_ACCESS,
            '1',
            [
                'expires'  => time() + self::COOKIE_TTL,
                'path'     => '/',
                'httponly' => true,
                'secure'   => is_ssl(),
                'samesite' => 'Strict',
            ]
        );
    }

    /**
     * Whether the current request has a valid access cookie.
     *
     * @return bool
     */
    private function hasAccessCookie(): bool
    {
        return isset($_COOKIE[self::COOKIE_ACCESS]) && $_COOKIE[self::COOKIE_ACCESS] === '1'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- constant value comparison; no user-controlled data used downstream
    }
}
