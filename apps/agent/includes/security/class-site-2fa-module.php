<?php
/**
 * Site2faModule — post-login 2FA interstitial for site users.
 *
 * ARCHITECTURE (§3.2 of the design):
 *
 * 1. Hook wp_login at priority -1000 (very early, after primary auth succeeds).
 * 2. Capture the just-issued auth session token via the auth_cookie filter,
 *    then IMMEDIATELY DESTROY it — so there is zero window with a half-auth cookie.
 * 3. Create a server-side signed interstitial session in user-meta:
 *       uuid (server-only secret) + user_id + created_at + redirect_to + remember_me
 *    The browser carries: user_id + session_meta_id + HMAC token.
 *    Expiry: 1 hour. The HMAC key is the agent's site secret (SECURE_AUTH_KEY).
 * 4. Render the chosen provider's form, die().
 * 5. On submit: verify the signed session, call provider validate().
 *    On success: wp_set_auth_cookie(), optional remember-device cookie, redirect.
 *    On failure: increment the per-session attempt counter; after 5 failures,
 *    expire the session (ties into the login-protection brute-force events).
 *
 * LOCKOUT-PROOFING:
 * - If define('WPMGR_DISABLE_SITE_2FA', true) is in wp-config, 2FA is fully disabled.
 * - The autologin path NEVER fires wp_login (it calls wp_set_auth_cookie directly).
 *   This means our wp_login hook never sees autologin traffic — bypass by construction.
 * - Default-OFF: a fresh or empty policy challenges nobody.
 * - Required-but-unenrolled users always get an email-code fallback (never a wall).
 * - Grace logins: allowed N logins before forced enrollment.
 *
 * @package WPMgr\Agent\Security
 */

declare(strict_types=1);

namespace WPMgr\Agent\Security;

/**
 * WordPress-hooks enforcer for site-user 2FA.
 */
final class Site2faModule
{
    /** User-meta key for the pending interstitial session. */
    public const META_SESSION = 'wpmgr_2fa_session';

    /** User-meta key for the trusted-device records. */
    public const META_DEVICES = 'wpmgr_2fa_devices';

    /** User-meta key for the grace-login counter. */
    public const META_GRACE_COUNT = 'wpmgr_2fa_grace_count';

    /** Cookie name for the trusted-device token. */
    public const COOKIE_DEVICE = 'wpmgr_2fa_device';

    /** Interstitial session TTL in seconds (1 hour). */
    private const SESSION_TTL = 3600;

    /** Maximum failed second-factor attempts per session before expiry. */
    private const MAX_ATTEMPTS = 5;

    /** Minimum cookie token length for trusted-device tokens. */
    private const DEVICE_TOKEN_BYTES = 32;

    private SecurityPolicy $policy;

    /** @var list<SiteTwoFactorProvider> */
    private array $providers;

    private static bool $verifying = false;

    /**
     * @param SecurityPolicy              $policy    The active site policy.
     * @param list<SiteTwoFactorProvider> $providers All registered providers.
     */
    public function __construct(SecurityPolicy $policy, array $providers)
    {
        $this->policy    = $policy;
        $this->providers = $providers;
    }

    /**
     * Register WordPress hooks. Call once on plugins_loaded.
     * Static guard makes it idempotent.
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

        // Recovery constant: fully disable 2FA enforcement.
        // @see ADR-059 §lockout-proofing invariant 6.
        if (defined('WPMGR_DISABLE_SITE_2FA') && WPMGR_DISABLE_SITE_2FA) {
            return;
        }

        if (!$this->policy->twoFactorEnabled) {
            return;
        }

        // Hook the post-primary-auth interstitial at very early priority.
        // Fires AFTER WP completes primary password auth; before the browser
        // has a live session. Priority -1000 beats other 2FA plugins' hooks.
        add_action('wp_login', [$this, 'onWpLogin'], -1000, 2);

        // Re-show the interstitial if the user lands on a login page while a
        // pending session exists (prevents navigating around it).
        add_action('login_init', [$this, 'maybeResumeInterstitial']);

        // XML-RPC block for 2FA users.
        if ($this->policy->blockXmlrpcFor2faUsers) {
            add_filter('xmlrpc_login_error', [$this, 'blockXmlrpcFor2faUser'], 10, 2);
            add_filter('authenticate', [$this, 'interceptXmlrpc2fa'], 100, 3);
        }
    }

    /**
     * Post-primary-auth hook: evaluate 2FA requirement and intercept if needed.
     * Called by WordPress after wp_authenticate() succeeds.
     *
     * BYPASS PATHS:
     *  - WPMGR_DISABLE_SITE_2FA constant (handled in install()).
     *  - The autologin command NEVER fires do_action('wp_login'), so it NEVER
     *    reaches this hook — bypass by construction (ADR-055 / autologin docblock).
     *  - $self::$verifying flag prevents re-entry when we fire wp_login ourselves
     *    after successful 2FA verification.
     *
     * @param string   $userLogin User's login name.
     * @param \WP_User $user      Authenticated user object.
     * @return void
     */
    public function onWpLogin(string $userLogin, \WP_User $user): void
    {
        // Prevent re-entrant triggering when we re-fire wp_login after verify.
        if (self::$verifying) {
            return;
        }

        try {
            $this->interceptIfRequired($user);
        } catch (\Throwable $e) {
            // Never block login due to a 2FA engine failure; fall through silently.
        }
    }

    /**
     * Detect pending interstitial sessions on login_init and re-show the form.
     * Prevents the user from navigating away from the interstitial.
     *
     * @return void
     */
    public function maybeResumeInterstitial(): void
    {
        // Only act on the verify-submit action or bare login page load.
        $action = isset($_GET['action']) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- no state change; read for routing
            ? sanitize_key(wp_unslash($_GET['action'])) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- same
            : '';

        if ($action === 'wpmgr_2fa_verify') {
            $this->handleVerifySubmit();
            return;
        }

        // Check if there is a pending session for a known user_id in POST/GET.
        $userId = isset($_POST['wpmgr_2fa_user_id']) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- interstitial session uses its own HMAC signing, not WP nonces (see §3.2: machine session, not browser nonce)
            ? absint(wp_unslash($_POST['wpmgr_2fa_user_id'])) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- same; the HMAC token field validates session integrity
            : 0;
        if ($userId <= 0) {
            return;
        }

        $user = function_exists('get_userdata') ? get_userdata($userId) : false;
        if (!$user instanceof \WP_User) {
            return;
        }

        $session = $this->loadSession($userId);
        if ($session === null) {
            return;
        }

        $this->renderInterstitial($user, $session);
    }

    /**
     * Handle the 2FA verify form submission.
     *
     * @return void
     */
    public function handleVerifySubmit(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- interstitial uses HMAC session signing, not WP nonces (§3.2 design; the nonce concept does not apply: no WP session exists yet to mint a nonce against)
        $userId    = isset($_POST['wpmgr_2fa_user_id']) ? absint(wp_unslash($_POST['wpmgr_2fa_user_id'])) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- same
        $sessionId = isset($_POST['wpmgr_2fa_session_id']) ? sanitize_text_field(wp_unslash($_POST['wpmgr_2fa_session_id'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- same
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- same
        $hmac      = isset($_POST['wpmgr_2fa_token']) ? sanitize_text_field(wp_unslash($_POST['wpmgr_2fa_token'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- same
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- same
        $providerKey = isset($_POST['wpmgr_2fa_provider']) ? sanitize_key(wp_unslash($_POST['wpmgr_2fa_provider'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- same
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- same
        $remember    = isset($_POST['wpmgr_2fa_remember']) && $_POST['wpmgr_2fa_remember'] === '1'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- same

        if ($userId <= 0 || $sessionId === '' || $hmac === '') {
            wp_die(esc_html__('Invalid 2FA session.', 'wpmgr-agent'), '', ['response' => 400]);
        }

        $user = function_exists('get_userdata') ? get_userdata($userId) : false;
        if (!$user instanceof \WP_User) {
            wp_die(esc_html__('User not found.', 'wpmgr-agent'), '', ['response' => 400]);
        }

        // Verify signed session.
        $session = $this->loadSession($userId);
        if ($session === null
            || !hash_equals($session['id'], $sessionId)
            || !$this->verifySessionHmac($userId, $sessionId, $session['created_at'], $session['uuid'], $hmac)
        ) {
            $this->clearSession($userId);
            wp_die(esc_html__('Session expired or invalid. Please log in again.', 'wpmgr-agent'), '', ['response' => 403]);
        }

        // Check TTL.
        if (time() - $session['created_at'] > self::SESSION_TTL) {
            $this->clearSession($userId);
            wp_die(esc_html__('2FA session expired. Please log in again.', 'wpmgr-agent'), '', ['response' => 403]);
        }

        // Brute-force guard: check attempt count.
        $attempts = (int) ($session['attempts'] ?? 0);
        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->clearSession($userId);
            wp_die(esc_html__('Too many failed attempts. Please log in again.', 'wpmgr-agent'), '', ['response' => 429]);
        }

        // Find and validate the chosen provider.
        $provider = $this->findProvider($providerKey);
        if ($provider === null || !$provider->isConfiguredFor($user)) {
            $this->renderInterstitial($user, $session, esc_html__('Invalid provider selected.', 'wpmgr-agent'));
            return;
        }

        // Collect and sanitize all provider input fields.
        $providerInput = $this->collectProviderInput();

        if (!$provider->validate($user, $providerInput)) {
            // Increment attempt counter.
            $session['attempts'] = $attempts + 1;
            $this->storeSession($userId, $session);
            $this->renderInterstitial($user, $session, esc_html__('Incorrect code. Please try again.', 'wpmgr-agent'));
            return;
        }

        // SUCCESS — clear the interstitial session and issue the real cookie.
        $this->clearSession($userId);
        $redirectTo = isset($session['redirect_to']) && is_string($session['redirect_to'])
            ? $session['redirect_to']
            : admin_url();

        // Handle trusted-device cookie.
        if ($remember && $this->policy->twoFactorRememberDeviceDays > 0) {
            $this->setDeviceCookie($userId);
        }

        // Issue the real WP auth cookie.
        wp_set_auth_cookie($userId, (bool) ($session['remember_me'] ?? false));

        // Re-fire wp_login with the "already verified" guard so normal side-effects
        // (activity log, WooCommerce session, etc.) run correctly — but our own
        // interstitial does not recurse because $verifying is true.
        self::$verifying = true;
        $user = function_exists('get_userdata') ? get_userdata($userId) : null;
        if ($user instanceof \WP_User) {
            do_action('wp_login', $user->user_login, $user); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- firing WP core's documented post-login action; not a custom hook
        }
        self::$verifying = false;

        wp_safe_redirect(esc_url_raw($redirectTo));
        exit;
    }

    /**
     * Intercept XML-RPC logins for users with 2FA configured.
     *
     * @param mixed    $user
     * @param string   $username
     * @param string   $password
     * @return mixed
     */
    public function interceptXmlrpc2fa(mixed $user, string $username, string $password): mixed
    {
        if (!is_a($user, 'WP_User')) {
            return $user;
        }
        if (!defined('XMLRPC_REQUEST') || !XMLRPC_REQUEST) {
            return $user;
        }
        // Block XML-RPC for users who have any 2FA method configured.
        foreach ($this->providers as $provider) {
            if ($provider->isConfiguredFor($user)) {
                return new \WP_Error(
                    'wpmgr_2fa_xmlrpc_blocked',
                    esc_html__('Two-factor authentication is required. XML-RPC password-only access is disabled for this account.', 'wpmgr-agent')
                );
            }
        }
        return $user;
    }

    /**
     * @param mixed    $error
     * @param \WP_User $user
     * @return mixed
     */
    public function blockXmlrpcFor2faUser(mixed $error, \WP_User $user): mixed
    {
        return $error;
    }

    // -------------------------------------------------------------------------
    // Trusted-device helpers
    // -------------------------------------------------------------------------

    /**
     * Check whether the current request carries a valid, user-bound trusted-device
     * cookie. Mirrors the B1-fix user-binding check from the operator 2FA design.
     *
     * @param int $userId
     * @return bool
     */
    public function hasTrustedDevice(int $userId): bool
    {
        if ($this->policy->twoFactorRememberDeviceDays <= 0) {
            return false;
        }
        if (!isset($_COOKIE[self::COOKIE_DEVICE]) || !is_string($_COOKIE[self::COOKIE_DEVICE])) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized on the next line
            return false;
        }
        $raw = sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE_DEVICE]));
        if ($raw === '' || strlen($raw) < self::DEVICE_TOKEN_BYTES * 2) {
            return false;
        }
        $tokenHash = hash('sha256', $raw);
        return $this->findDevice($userId, $tokenHash) !== null;
    }

    /**
     * Set a trusted-device cookie and record it in user-meta.
     *
     * @param int $userId
     * @return void
     */
    private function setDeviceCookie(int $userId): void
    {
        $token    = bin2hex(random_bytes(self::DEVICE_TOKEN_BYTES));
        $hash     = hash('sha256', $token);
        $expires  = time() + $this->policy->twoFactorRememberDeviceDays * 86400;

        $this->storeDevice($userId, $hash, $expires);

        if (!headers_sent()) {
            setcookie(
                self::COOKIE_DEVICE,
                $token,
                [
                    'expires'  => $expires,
                    'path'     => '/',
                    'httponly' => true,
                    'secure'   => is_ssl(),
                    'samesite' => 'Strict',
                ]
            );
        }
    }

    /**
     * Find a device record by token hash, asserting user-binding (B1 fix).
     *
     * @param int    $userId
     * @param string $tokenHash
     * @return array<string,mixed>|null
     */
    private function findDevice(int $userId, string $tokenHash): ?array
    {
        if (!function_exists('get_user_meta')) {
            return null;
        }
        $devices = get_user_meta($userId, self::META_DEVICES, true);
        if (!is_array($devices)) {
            return null;
        }
        $now = time();
        foreach ($devices as $device) {
            if (!is_array($device)) {
                continue;
            }
            // User-binding check: the device's recorded user_id must match the
            // authenticating user. This is the B1 fix mirror (twofa.go:262-276).
            if (!isset($device['user_id']) || (int) $device['user_id'] !== $userId) {
                continue;
            }
            if (!isset($device['hash']) || !hash_equals($device['hash'], $tokenHash)) {
                continue;
            }
            if (isset($device['expires']) && $device['expires'] < $now) {
                continue;
            }
            return $device;
        }
        return null;
    }

    /**
     * Store a new device record in user-meta.
     *
     * @param int    $userId
     * @param string $tokenHash
     * @param int    $expires
     * @return void
     */
    private function storeDevice(int $userId, string $tokenHash, int $expires): void
    {
        if (!function_exists('get_user_meta') || !function_exists('update_user_meta')) {
            return;
        }
        $devices   = get_user_meta($userId, self::META_DEVICES, true);
        $devices   = is_array($devices) ? $devices : [];
        $now       = time();
        // Prune expired devices.
        $devices   = array_values(array_filter($devices, static function ($d) use ($now): bool {
            return is_array($d) && isset($d['expires']) && $d['expires'] >= $now;
        }));
        $devices[] = ['user_id' => $userId, 'hash' => $tokenHash, 'expires' => $expires];
        update_user_meta($userId, self::META_DEVICES, $devices);
    }

    /**
     * Nuke all trusted devices on password change.
     *
     * @param int $userId
     * @return void
     */
    public function clearDevices(int $userId): void
    {
        if (function_exists('delete_user_meta')) {
            delete_user_meta($userId, self::META_DEVICES);
        }
    }

    // -------------------------------------------------------------------------
    // Core interstitial logic
    // -------------------------------------------------------------------------

    /**
     * Evaluate 2FA requirement for the user and intercept if needed.
     *
     * @param \WP_User $user
     * @return void
     */
    private function interceptIfRequired(\WP_User $user): void
    {
        $userId = (int) $user->ID;

        // Trusted-device check: skip the interstitial.
        if ($this->hasTrustedDevice($userId)) {
            return;
        }

        $isRequired = $this->policy->requires2fa($user);
        $providers  = $this->resolveProvidersFor($user);

        // 2FA is optional and user has nothing enrolled — no interstitial.
        if (!$isRequired && $providers === []) {
            return;
        }

        // Check grace logins for required-but-unenrolled users.
        if ($isRequired && $providers === []) {
            $graceCount = (int) get_user_meta($userId, self::META_GRACE_COUNT, true);
            $graceMax   = $this->policy->twoFactorGraceLogins;

            if ($graceMax > 0 && $graceCount < $graceMax) {
                update_user_meta($userId, self::META_GRACE_COUNT, $graceCount + 1);
                // Allow this login; the user still has grace logins remaining.
                return;
            }

            // Grace exhausted or grace = 0: enforce email fallback.
            foreach ($this->providers as $p) {
                if ($p->key() === 'email') {
                    $providers = [$p];
                    break;
                }
            }
        }

        if ($providers === []) {
            // No suitable provider available — fail open (never lock out).
            return;
        }

        // Destroy the just-issued auth session before showing the interstitial.
        // This ensures zero half-authenticated window.
        $this->destroyCurrentSession($userId);

        // Pick the first available provider as default.
        $redirectTo = $this->getCurrentRedirectTo();
        $rememberMe = isset($_POST['rememberme']); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- wp_login action; WP core has already verified credentials at this point

        $session = $this->createSession($userId, $redirectTo, $rememberMe);
        $this->renderInterstitial($user, $session);
    }

    /**
     * Capture and destroy the just-issued WP auth session cookie.
     * Uses the auth_cookie action (fires just after wp_set_auth_cookie) to
     * capture the session token, then destroys that specific token.
     *
     * @param int $userId
     * @return void
     */
    private function destroyCurrentSession(int $userId): void
    {
        // Destroy all existing sessions for this user (simpler and fully correct).
        if (function_exists('wp_destroy_all_sessions')) {
            wp_destroy_all_sessions();
        }
        if (function_exists('wp_clear_auth_cookie')) {
            wp_clear_auth_cookie();
        }
    }

    /**
     * Resolve the set of configured providers for this user, filtered by the
     * policy's allowed_methods for the user's role.
     *
     * @param \WP_User $user
     * @return list<SiteTwoFactorProvider>
     */
    private function resolveProvidersFor(\WP_User $user): array
    {
        $allowed = $this->policy->allowedMethodsFor($user);
        $out     = [];
        foreach ($this->providers as $provider) {
            if (in_array($provider->key(), $allowed, true) && $provider->isConfiguredFor($user)) {
                $out[] = $provider;
            }
        }
        return $out;
    }

    /**
     * Find a provider by key.
     *
     * @param string $key
     * @return SiteTwoFactorProvider|null
     */
    private function findProvider(string $key): ?SiteTwoFactorProvider
    {
        foreach ($this->providers as $p) {
            if ($p->key() === $key) {
                return $p;
            }
        }
        return null;
    }

    /**
     * Create a signed interstitial session in user-meta.
     *
     * @param int    $userId
     * @param string $redirectTo
     * @param bool   $rememberMe
     * @return array<string,mixed>
     */
    private function createSession(int $userId, string $redirectTo, bool $rememberMe): array
    {
        $uuid    = bin2hex(random_bytes(16));
        $id      = bin2hex(random_bytes(16));
        $created = time();
        $session = [
            'id'          => $id,
            'uuid'        => $uuid,
            'user_id'     => $userId,
            'created_at'  => $created,
            'redirect_to' => $redirectTo,
            'remember_me' => $rememberMe,
            'attempts'    => 0,
        ];
        $this->storeSession($userId, $session);
        return $session;
    }

    /**
     * Store the interstitial session in user-meta.
     *
     * @param int                  $userId
     * @param array<string,mixed>  $session
     * @return void
     */
    private function storeSession(int $userId, array $session): void
    {
        if (function_exists('update_user_meta')) {
            update_user_meta($userId, self::META_SESSION, $session);
        }
    }

    /**
     * Load the stored interstitial session, returning null if absent or expired.
     *
     * @param int $userId
     * @return array<string,mixed>|null
     */
    private function loadSession(int $userId): ?array
    {
        if (!function_exists('get_user_meta')) {
            return null;
        }
        $session = get_user_meta($userId, self::META_SESSION, true);
        if (!is_array($session)) {
            return null;
        }
        // Validate structure.
        if (!isset($session['id'], $session['uuid'], $session['user_id'], $session['created_at'])) {
            return null;
        }
        // TTL check.
        if (time() - (int) $session['created_at'] > self::SESSION_TTL) {
            $this->clearSession($userId);
            return null;
        }
        return $session;
    }

    /**
     * Clear the interstitial session.
     *
     * @param int $userId
     * @return void
     */
    private function clearSession(int $userId): void
    {
        if (function_exists('delete_user_meta')) {
            delete_user_meta($userId, self::META_SESSION);
        }
    }

    /**
     * Compute the HMAC token for a session (client-side field).
     * HMAC key: wp_salt('secure_auth') — site-specific, not the password.
     *
     * @param int    $userId
     * @param string $sessionId
     * @param int    $createdAt
     * @param string $uuid
     * @return string
     */
    private function computeSessionHmac(int $userId, string $sessionId, int $createdAt, string $uuid): string
    {
        $key     = function_exists('wp_salt') ? wp_salt('secure_auth') : 'wpmgr-fallback';
        $message = "{$userId}|{$sessionId}|{$createdAt}|{$uuid}";
        return hash_hmac('sha256', $message, $key);
    }

    /**
     * Verify the client-side HMAC token.
     *
     * @param int    $userId
     * @param string $sessionId
     * @param int    $createdAt
     * @param string $uuid
     * @param string $clientToken
     * @return bool
     */
    private function verifySessionHmac(int $userId, string $sessionId, int $createdAt, string $uuid, string $clientToken): bool
    {
        $expected = $this->computeSessionHmac($userId, $sessionId, $createdAt, $uuid);
        return hash_equals($expected, $clientToken);
    }

    /**
     * Render the 2FA interstitial page and die().
     *
     * @param \WP_User            $user
     * @param array<string,mixed> $session
     * @param string              $error   Optional error message to display.
     * @return void
     */
    private function renderInterstitial(\WP_User $user, array $session, string $error = ''): void
    {
        $providers = $this->resolveProvidersFor($user);

        // Grace fallback: inject email provider for required-but-unenrolled.
        if ($providers === []) {
            foreach ($this->providers as $p) {
                if ($p->key() === 'email') {
                    $providers = [$p];
                    break;
                }
            }
        }

        $activeProvider = $providers[0] ?? null;
        if ($activeProvider === null) {
            // Nothing to show — fail open.
            return;
        }

        $userId    = (int) $session['user_id'];
        $sessionId = (string) ($session['id'] ?? '');
        $createdAt = (int) ($session['created_at'] ?? 0);
        $uuid      = (string) ($session['uuid'] ?? '');
        $hmac      = $this->computeSessionHmac($userId, $sessionId, $createdAt, $uuid);

        $activeProvider->preRender($user);

        $loginUrl   = function_exists('wp_login_url') ? wp_login_url() : '/wp-login.php';
        $verifyUrl  = add_query_arg(['action' => 'wpmgr_2fa_verify'], $loginUrl);

        if (function_exists('login_header')) {
            login_header(
                esc_html__('Two-Factor Authentication', 'wpmgr-agent'),
                '',
                null
            );
        }

        // Build the form safely without heredocs.
        $formHtml = '<form name="wpmgr_2fa_form" id="loginform" action="' . esc_url($verifyUrl) . '" method="post">';
        $formHtml .= '<input type="hidden" name="wpmgr_2fa_user_id" value="' . esc_attr((string) $userId) . '">';
        $formHtml .= '<input type="hidden" name="wpmgr_2fa_session_id" value="' . esc_attr($sessionId) . '">';
        $formHtml .= '<input type="hidden" name="wpmgr_2fa_token" value="' . esc_attr($hmac) . '">';
        $formHtml .= '<input type="hidden" name="wpmgr_2fa_provider" value="' . esc_attr($activeProvider->key()) . '">';

        if ($error !== '') {
            $formHtml .= '<p class="message" style="color:#d63638">' . esc_html($error) . '</p>';
        }

        $formHtml .= $activeProvider->renderForm($user);

        if ($this->policy->twoFactorRememberDeviceDays > 0) {
            $days      = $this->policy->twoFactorRememberDeviceDays;
            $formHtml .= '<p>';
            $formHtml .= '<input type="checkbox" name="wpmgr_2fa_remember" id="wpmgr_2fa_remember" value="1">';
            $formHtml .= ' <label for="wpmgr_2fa_remember">';
            $formHtml .= esc_html(
                sprintf(
                    // translators: %d is the number of days.
                    __('Remember this device for %d days', 'wpmgr-agent'),
                    $days
                )
            );
            $formHtml .= '</label></p>';
        }

        // Provider switcher tabs (if multiple providers available).
        if (count($providers) > 1) {
            $formHtml .= '<p>' . esc_html__('Or use:', 'wpmgr-agent') . ' ';
            foreach ($providers as $p) {
                if ($p->key() === $activeProvider->key()) {
                    continue;
                }
                $switchUrl = add_query_arg(
                    ['action' => 'wpmgr_2fa_verify', 'wpmgr_2fa_provider' => $p->key()],
                    $loginUrl
                );
                $formHtml .= '<a href="' . esc_url($switchUrl) . '">' . esc_html($p->label()) . '</a> ';
            }
            $formHtml .= '</p>';
        }

        $formHtml .= '<p class="submit">';
        $formHtml .= '<input type="submit" name="wp-submit" id="wp-submit" class="button button-primary button-large" value="';
        $formHtml .= esc_attr__('Verify', 'wpmgr-agent') . '">';
        $formHtml .= '</p>';
        $formHtml .= '</form>';

        echo $formHtml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fully escaped above; each component escaped with esc_html/esc_attr/esc_url individually

        if (function_exists('login_footer')) {
            login_footer();
        }

        exit;
    }

    /**
     * Get the current redirect_to target from the request.
     *
     * @return string
     */
    private function getCurrentRedirectTo(): string
    {
        if (isset($_POST['redirect_to']) && is_string($_POST['redirect_to'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- reading redirect target after primary auth; WP core already verified credentials at this point
            $raw = sanitize_text_field(wp_unslash($_POST['redirect_to'])); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- same
            if ($raw !== '') {
                return $raw;
            }
        }
        return function_exists('admin_url') ? admin_url() : '/wp-admin/';
    }

    /**
     * Collect and sanitize all known provider input fields from $_POST.
     *
     * @return array<string,string>
     */
    private function collectProviderInput(): array
    {
        $fields = [
            'wpmgr_totp_code',
            'wpmgr_email_code',
            'wpmgr_backup_code',
        ];
        $out = [];
        foreach ($fields as $field) {
            if (isset($_POST[$field]) && is_string($_POST[$field])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- 2FA interstitial uses HMAC session token, not WP nonces (no WP session exists yet to mint a nonce against; see §3.2)
                $out[$field] = sanitize_text_field(wp_unslash($_POST[$field])); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- same
            }
        }
        return $out;
    }
}
