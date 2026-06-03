<?php
/**
 * LoggedInRoles — maintains the non-HTTPOnly `wpmgr_logged_in_roles` cookie.
 *
 * When logged-in caching is enabled, cached pages are segmented by the user's
 * role(s) so an editor and a subscriber get different cached variants. The web
 * server / serving drop-in cannot run a DB query to learn a user's role, so on
 * login we drop a small non-HTTPOnly cookie carrying a "-"-joined, slugified
 * role list. The drop-in and the CacheKey read it directly.
 *
 * Cleared on logout. Non-HTTPOnly is intentional and safe: it carries only the
 * role slug list (no secret), exactly like the role segment in the cache key.
 *
 * Original implementation (standard role-cookie technique).
 *
 * @package WPMgr\Agent\Cache
 */

declare(strict_types=1);

namespace WPMgr\Agent\Cache;

/**
 * Sets/clears the role cookie on auth-cookie lifecycle events.
 */
final class LoggedInRoles
{
    /** Cookie name (shared with CacheKey::ROLE_COOKIE). */
    public const COOKIE = CacheKey::ROLE_COOKIE;

    /** Cookie lifetime: 14 days, in seconds. */
    private const TTL = 14 * 86400;

    /**
     * Bind the login/logout hooks. Safe without WP.
     *
     * @return void
     */
    public function registerHooks(): void
    {
        if (!function_exists('add_action')) {
            return;
        }
        add_action('set_logged_in_cookie', [$this, 'onSetLoggedInCookie'], 10, 4);
        add_action('clear_auth_cookie', [$this, 'onClearAuthCookie']);
    }

    /**
     * On WP setting the logged-in cookie, mirror the user's roles into our cookie.
     *
     * @param string $loggedInCookie The auth cookie value (unused).
     * @param int    $expire         Cookie expiry (unused; we set our own TTL).
     * @param int    $expiration     Expiration ts (unused).
     * @param int    $userId         The user ID.
     * @return void
     */
    public function onSetLoggedInCookie($loggedInCookie = '', $expire = 0, $expiration = 0, $userId = 0): void
    {
        $userId = (int) $userId;
        if ($userId <= 0 || !function_exists('get_userdata')) {
            return;
        }
        $user = get_userdata($userId);
        if (!is_object($user) || !property_exists($user, 'roles') || !is_array($user->roles)) {
            return;
        }

        // Normalise to a clean list<string> regardless of the source array shape.
        $roles = array_values(array_filter(
            $user->roles,
            static fn ($r): bool => is_string($r)
        ));

        $value = self::encodeRoles($roles);
        $this->setCookie($value);
    }

    /**
     * On logout, clear the role cookie.
     *
     * @return void
     */
    public function onClearAuthCookie(): void
    {
        $this->setCookie('', time() - 3600);
    }

    /**
     * Encode a role list into the cookie value: lowercase, slugified, "-"-joined.
     * Pure and unit-testable.
     *
     * @param list<string> $roles Role names.
     * @return string
     */
    public static function encodeRoles(array $roles): string
    {
        $clean = [];
        foreach ($roles as $role) {
            if (!is_string($role)) {
                continue;
            }
            $slug = preg_replace('/[^a-z0-9_]/', '', strtolower($role)) ?? '';
            if ($slug !== '') {
                $clean[] = $slug;
            }
        }
        return implode('-', $clean);
    }

    /**
     * Write the cookie via setcookie() (guarded; no-op if headers already sent).
     *
     * @param string $value  Cookie value.
     * @param int|null $expire Optional explicit expiry timestamp.
     * @return void
     */
    private function setCookie(string $value, ?int $expire = null): void
    {
        if (headers_sent()) {
            return;
        }
        $expire = $expire ?? (time() + self::TTL);
        $path   = defined('COOKIEPATH') ? (string) constant('COOKIEPATH') : '/';
        $domain = defined('COOKIE_DOMAIN') ? (string) constant('COOKIE_DOMAIN') : '';
        $secure = function_exists('is_ssl') ? (bool) is_ssl() : false;

        // HTTPOnly = false on purpose: the serving drop-in reads this client-side
        // value pre-WordPress. It carries only the role slug list (no secret).
        setcookie(self::COOKIE, $value, [
            'expires'  => $expire,
            'path'     => $path === '' ? '/' : $path,
            'domain'   => $domain,
            'secure'   => $secure,
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }
}
