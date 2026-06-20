<?php
/**
 * Site2faModule - post-login 2FA interstitial and forced-change enforcement
 * for site users.
 *
 * ARCHITECTURE (section 3.2 of the design):
 *
 * 1. Hook wp_login at priority -1000 (very early, after primary auth succeeds).
 * 2. Capture the just-issued auth session token via the auth_cookie filter,
 *    then IMMEDIATELY DESTROY it -- so there is zero window with a half-auth cookie.
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
 * APPLICATION-PASSWORD BYPASS BLOCK (H1 fix):
 * WordPress Application Passwords authenticate via HTTP Basic or the
 * wp_authenticate_application_password filter WITHOUT firing wp_login,
 * so the interstitial hook above never sees them. For any user who requires
 * 2FA (or who has a non-email method enrolled), application-password auth is
 * rejected outright via the wp_authenticate_application_password filter.
 * The agent's own /wpmgr/v1 REST channel and the autologin path are
 * explicitly exempted -- they carry their own Ed25519 credential and never
 * rely on application passwords.
 *
 * FORCED-CHANGE INTERSTITIAL (H2 fix):
 * When PasswordPolicyModule sets META_FORCE_CHANGE on a user, onWpLogin
 * checks for the flag BEFORE the 2FA check. If set, the user sees a
 * password-change form; META_FORCE_CHANGE is cleared only after a validated
 * new password is submitted (meeting strength + compromised + reuse policy
 * via PasswordPolicyModule::validatePassword). The same WPMGR_DISABLE_SITE_2FA
 * escape hatch disables this enforcement.
 *
 * FORCED-CHANGE ATTEMPT LIMITING (N1 fix):
 * handleVerifySubmit() runs the cross-request cap and per-session MAX_ATTEMPTS
 * guard BEFORE routing to either the 2FA handler or the forced-change handler.
 * Both paths are therefore subject to the same bound. handleForcedChangeSubmit()
 * increments both counters on every validation failure and clears them on success,
 * exactly mirroring the 2FA failure/success paths. A legitimate user who changes
 * their password successfully is NOT counted as a failure.
 *
 * LOCKOUT-PROOFING:
 * - If define('WPMGR_DISABLE_SITE_2FA', true) is in wp-config, 2FA and
 *   forced-change enforcement are fully disabled.
 * - The autologin path NEVER fires do_action('wp_login'), so it NEVER
 *   reaches this hook -- bypass by construction (ADR-055 / autologin docblock).
 * - Default-OFF: a fresh or empty policy challenges nobody.
 * - Required-but-unenrolled users always get an email-code fallback (never a wall).
 * - Grace logins: allowed N logins before forced enrollment.
 *
 * @package WPMgr\Agent\Security
 */

declare(strict_types=1);

namespace WPMgr\Agent\Security;

use WPMgr\Agent\Security\PasswordPolicyModule;

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

    /**
     * User-meta key for the cross-request per-user 2FA attempt counter.
     * Sliding window TTL in seconds equals SESSION_TTL. Used alongside the
     * per-session counter so that session destruction cannot reset the count.
     */
    public const META_ATTEMPT_COUNT = 'wpmgr_2fa_attempt_count';

    /** Cookie name for the trusted-device token. */
    public const COOKIE_DEVICE = 'wpmgr_2fa_device';

    /** Interstitial session TTL in seconds (1 hour). */
    private const SESSION_TTL = 3600;

    /** Maximum failed second-factor attempts per session before expiry. */
    private const MAX_ATTEMPTS = 5;

    /**
     * Maximum cumulative cross-request second-factor attempts per user within
     * the sliding SESSION_TTL window. Prevents session-recreation resets.
     */
    private const MAX_CROSS_REQUEST_ATTEMPTS = 15;

    /** Minimum cookie token length for trusted-device tokens. */
    private const DEVICE_TOKEN_BYTES = 32;

    /**
     * Interstitial session type identifier for the standard 2FA challenge.
     * Stored in session['type'] to distinguish from FORCED_CHANGE sessions.
     */
    private const SESSION_TYPE_2FA = '2fa';

    /**
     * Interstitial session type identifier for forced password-change sessions.
     */
    private const SESSION_TYPE_FORCED_CHANGE = 'forced_change';

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
        // @see ADR-059 section lockout-proofing invariant 6.
        if (defined('WPMGR_DISABLE_SITE_2FA') && WPMGR_DISABLE_SITE_2FA) {
            return;
        }

        // Forced-change interstitial and 2FA both gate on the same wp_login hook.
        // Register when either feature has active rules.
        $has2fa         = $this->policy->twoFactorEnabled;
        $hasForcedChange = $this->policy->passwordMaxAgeDays > 0
            || $this->policy->forcePasswordChange !== [];

        if ($has2fa || $hasForcedChange) {
            // Hook the post-primary-auth interstitial at very early priority.
            // Fires AFTER WP completes primary password auth; before the browser
            // has a live session. Priority -1000 beats other 2FA plugins' hooks.
            add_action('wp_login', [$this, 'onWpLogin'], -1000, 2);

            // Re-show the interstitial if the user lands on a login page while a
            // pending session exists (prevents navigating around it).
            add_action('login_init', [$this, 'maybeResumeInterstitial']);
        }

        // H1 fix: block application-password auth for 2FA-required users.
        // This filter fires when WP resolves an HTTP-Basic / app-password credential.
        // We gate it on twoFactorEnabled to avoid overhead when 2FA is off.
        if ($has2fa) {
            add_filter('wp_authenticate_application_password', [$this, 'blockAppPasswordFor2faUser'], 10, 5);
        }

        // XML-RPC block for 2FA users.
        if ($has2fa && $this->policy->blockXmlrpcFor2faUsers) {
            add_filter('xmlrpc_login_error', [$this, 'blockXmlrpcFor2faUser'], 10, 2);
            add_filter('authenticate', [$this, 'interceptXmlrpc2fa'], 100, 3);
        }
    }

    // -------------------------------------------------------------------------
    // H1 fix: Application Password block
    // -------------------------------------------------------------------------

    /**
     * Reject application-password authentication for users who require 2FA
     * or who have a non-email (real) 2FA method enrolled.
     *
     * EXEMPTED (must not be blocked):
     *  - The agent's own /wpmgr/v1 REST routes (Ed25519-signed; never use app passwords).
     *  - The autologin path (POST /wp-json/wpmgr/v1/autologin; also REST, also Ed25519).
     *  - Any user who does NOT require 2FA and has no non-email method enrolled.
     *
     * Application passwords do NOT satisfy the second factor: they carry only
     * password-equivalent credentials and bypass wp_login entirely, so the 2FA
     * interstitial never fires for them.
     *
     * @param \WP_Error|\WP_User|null $user        Result from earlier authenticate filters.
     * @param \WP_User|false          $inputUser   Resolved user (or false).
     * @param string                  $appPassword The raw application password.
     * @param array<mixed>|null       $item        Application-password DB record.
     * @param \WP_REST_Request|null   $request     The current REST request.
     * @return \WP_Error|\WP_User|null
     */
    public function blockAppPasswordFor2faUser(
        mixed $user,
        mixed $inputUser,
        string $appPassword,
        ?array $item,
        mixed $request
    ): mixed {
        // If a prior filter already produced an error, pass it through.
        if (is_a($user, 'WP_Error')) {
            return $user;
        }

        // Resolve the user we will be acting on.
        $resolvedUser = ($user instanceof \WP_User) ? $user : $inputUser;
        if (!($resolvedUser instanceof \WP_User)) {
            return $user;
        }

        // Exempt the agent's own REST namespace (/wpmgr/v1/*). Those routes
        // are authenticated via Ed25519 signatures at the REST permission callback
        // and never reach application-password resolution.
        if ($this->isAgentRestRequest($request)) {
            return $user;
        }

        // Block if the user requires 2FA.
        if ($this->policy->requires2fa($resolvedUser)) {
            return new \WP_Error(
                'wpmgr_2fa_app_password_blocked',
                esc_html__('Application passwords are disabled for accounts that require two-factor authentication. Use an alternative authentication method.', 'wpmgr-agent')
            );
        }

        // Block if the user has any non-email 2FA method enrolled.
        // Email is intentionally excluded: it is always "configured" for any user
        // with an email address and therefore cannot indicate deliberate 2FA enrollment.
        if ($this->hasNonEmailMethodConfigured($resolvedUser)) {
            return new \WP_Error(
                'wpmgr_2fa_app_password_blocked',
                esc_html__('Application passwords are disabled for accounts with two-factor authentication enrolled. Use an alternative authentication method.', 'wpmgr-agent')
            );
        }

        return $user;
    }

    /**
     * Check whether the current REST request targets the agent's own namespace.
     * The agent's /wpmgr/v1 routes are exempted from app-password blocking.
     *
     * @param mixed $request The WP_REST_Request or null.
     * @return bool
     */
    private function isAgentRestRequest(mixed $request): bool
    {
        // WP_REST_Request carries the route; check it if available.
        if ($request instanceof \WP_REST_Request) {
            $route = '';
            if (method_exists($request, 'get_route')) {
                $route = (string) $request->get_route();
            }
            if (str_contains($route, '/wpmgr/v1/')) {
                return true;
            }
        }

        // Fallback: inspect the request URI directly (for edge cases where
        // WP_REST_Request is not passed through by the caller).
        if (isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI'])) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only check; sanitized on next line
            $uri = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI']));
            if (str_contains($uri, '/wpmgr/v1/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check whether the user has any non-email 2FA method enrolled.
     * EmailCodeProvider is excluded: it is always "configured" for any user with
     * an email, so including it would make the check meaningless (M1 fix context).
     *
     * @param \WP_User $user
     * @return bool
     */
    private function hasNonEmailMethodConfigured(\WP_User $user): bool
    {
        foreach ($this->providers as $provider) {
            if ($provider->key() === 'email') {
                continue;
            }
            if ($provider->isConfiguredFor($user)) {
                return true;
            }
        }
        return false;
    }

    // -------------------------------------------------------------------------
    // Post-login hook
    // -------------------------------------------------------------------------

    /**
     * Post-primary-auth hook: evaluate forced-change and 2FA requirement.
     * Called by WordPress after wp_authenticate() succeeds.
     *
     * FORCED-CHANGE check runs at priority -2000 (via PasswordPolicyModule) to
     * set the meta flag, then THIS hook at -1000 reads that flag and intercepts
     * BEFORE the normal 2FA interstitial. This means:
     *   forced-change + 2FA-required user: forced-change form fires first, then
     *   on next login 2FA interstitial fires normally.
     *
     * BYPASS PATHS:
     *  - WPMGR_DISABLE_SITE_2FA constant (handled in install()).
     *  - The autologin command NEVER fires do_action('wp_login'), so it NEVER
     *    reaches this hook -- bypass by construction (ADR-055 / autologin docblock).
     *  - $self::$verifying flag prevents re-entry when we re-fire wp_login
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
            // Forced-change check: priority before 2FA.
            if ($this->interceptIfForcedChange($user)) {
                return;
            }

            // 2FA interstitial check.
            if ($this->policy->twoFactorEnabled) {
                $this->interceptIfRequired($user);
            }
        } catch (\Throwable $e) {
            // Never block login due to an engine failure; fall through silently.
        }
    }

    // -------------------------------------------------------------------------
    // H2 fix: Forced-change interstitial
    // -------------------------------------------------------------------------

    /**
     * Check whether the user has a forced-change flag set and, if so, destroy
     * the current session and render the password-change interstitial.
     *
     * Returns true if the flow was intercepted (caller must return immediately).
     * Returns false if the user may continue to the 2FA check or normal login.
     *
     * @param \WP_User $user
     * @return bool True if the forced-change interstitial was triggered.
     */
    private function interceptIfForcedChange(\WP_User $user): bool
    {
        $userId = (int) $user->ID;

        if (!function_exists('get_user_meta')) {
            return false;
        }

        $forceReason = get_user_meta($userId, PasswordPolicyModule::META_FORCE_CHANGE, true);
        if (!is_string($forceReason) || $forceReason === '') {
            return false;
        }

        // Destroy the just-issued auth session before rendering the form.
        $this->destroyCurrentSession($userId);

        $session = $this->createSession($userId, $this->getCurrentRedirectTo(), false, self::SESSION_TYPE_FORCED_CHANGE);
        $this->renderForcedChangeForm($user, $session, $forceReason, '');
        return true;
    }

    /**
     * Render the forced password-change form and die().
     *
     * @param \WP_User            $user
     * @param array<string,mixed> $session
     * @param string              $reason  Reason code from META_FORCE_CHANGE.
     * @param string              $error   Optional validation error message.
     * @return void
     */
    private function renderForcedChangeForm(\WP_User $user, array $session, string $reason, string $error): void
    {
        $userId    = (int) $session['user_id'];
        $sessionId = (string) ($session['id'] ?? '');
        $createdAt = (int) ($session['created_at'] ?? 0);
        $uuid      = (string) ($session['uuid'] ?? '');
        $hmac      = $this->computeSessionHmac($userId, $sessionId, $createdAt, $uuid);

        $loginUrl  = function_exists('wp_login_url') ? wp_login_url() : '/wp-login.php';
        $verifyUrl = add_query_arg(['action' => 'wpmgr_2fa_verify'], $loginUrl);

        $heading = $reason === 'expiry'
            ? esc_html__('Your password has expired. Please choose a new password.', 'wpmgr-agent')
            : esc_html__('You must change your password before continuing.', 'wpmgr-agent');

        if (function_exists('login_header')) {
            login_header(esc_html__('Change Your Password', 'wpmgr-agent'), '', null);
        }

        $formHtml  = '<form name="wpmgr_fc_form" id="loginform" action="' . esc_url($verifyUrl) . '" method="post">';
        $formHtml .= '<p>' . esc_html($heading) . '</p>';
        $formHtml .= '<input type="hidden" name="wpmgr_2fa_user_id" value="' . esc_attr((string) $userId) . '">';
        $formHtml .= '<input type="hidden" name="wpmgr_2fa_session_id" value="' . esc_attr($sessionId) . '">';
        $formHtml .= '<input type="hidden" name="wpmgr_2fa_token" value="' . esc_attr($hmac) . '">';
        $formHtml .= '<input type="hidden" name="wpmgr_2fa_provider" value="forced_change">';

        if ($error !== '') {
            $formHtml .= '<p class="message" style="color:#d63638">' . esc_html($error) . '</p>';
        }

        $formHtml .= '<p><label for="wpmgr_fc_pass1">' . esc_html__('New password:', 'wpmgr-agent') . '</label>';
        $formHtml .= '<br><input type="password" id="wpmgr_fc_pass1" name="wpmgr_fc_pass1" autocomplete="new-password" required></p>';
        $formHtml .= '<p><label for="wpmgr_fc_pass2">' . esc_html__('Confirm new password:', 'wpmgr-agent') . '</label>';
        $formHtml .= '<br><input type="password" id="wpmgr_fc_pass2" name="wpmgr_fc_pass2" autocomplete="new-password" required></p>';
        $formHtml .= '<p class="submit">';
        $formHtml .= '<input type="submit" id="wp-submit" class="button button-primary button-large" value="';
        $formHtml .= esc_attr__('Change Password', 'wpmgr-agent') . '">';
        $formHtml .= '</p></form>';

        echo $formHtml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fully escaped above; each component escaped with esc_html/esc_attr/esc_url individually

        if (function_exists('login_footer')) {
            login_footer();
        }

        exit;
    }

    /**
     * Handle a forced-change form submission.
     * Validates the new password against the policy, clears META_FORCE_CHANGE,
     * and issues the real auth cookie on success.
     *
     * Attempt counting (N1 fix): every validation failure increments both the
     * per-session counter and the cross-request counter, exactly as the 2FA path
     * does. The shared guards in handleVerifySubmit already ran before this is
     * called; here we only need to persist the updated count on failure and clear
     * it on success.
     *
     * @param int                 $userId
     * @param array<string,mixed> $session
     * @param int                 $currentAttempts Current value of session['attempts'] (already read by caller).
     * @return void
     */
    private function handleForcedChangeSubmit(int $userId, array $session, int $currentAttempts): void
    {
        $user = function_exists('get_userdata') ? get_userdata($userId) : false;
        if (!($user instanceof \WP_User)) {
            wp_die(esc_html__('User not found.', 'wpmgr-agent'), '', ['response' => 400]);
        }

        // Retrieve and validate the new password fields.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- HMAC session signing; no WP session exists to mint a nonce against (section 3.2)
        $pass1 = isset($_POST['wpmgr_fc_pass1']) && is_string($_POST['wpmgr_fc_pass1']) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- same
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing -- plaintext required for strength validation; never stored or echoed
            ? wp_unslash($_POST['wpmgr_fc_pass1'])
            : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- same
        $pass2 = isset($_POST['wpmgr_fc_pass2']) && is_string($_POST['wpmgr_fc_pass2']) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- same
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing -- plaintext required for match check; never stored or echoed
            ? wp_unslash($_POST['wpmgr_fc_pass2'])
            : '';

        $forceReason = '';
        if (function_exists('get_user_meta')) {
            $raw = get_user_meta($userId, PasswordPolicyModule::META_FORCE_CHANGE, true);
            $forceReason = is_string($raw) ? $raw : '';
        }

        if (!is_string($pass1) || $pass1 === '') {
            // Failure: increment attempt counters before re-rendering the form (N1 fix).
            $session['attempts'] = $currentAttempts + 1;
            $this->storeSession($userId, $session);
            $this->incrementCrossRequestAttempts($userId);
            $this->renderForcedChangeForm($user, $session, $forceReason, esc_html__('Please enter a new password.', 'wpmgr-agent'));
            return;
        }

        if (!is_string($pass2) || $pass1 !== $pass2) {
            // Failure: increment attempt counters before re-rendering the form (N1 fix).
            $session['attempts'] = $currentAttempts + 1;
            $this->storeSession($userId, $session);
            $this->incrementCrossRequestAttempts($userId);
            $this->renderForcedChangeForm($user, $session, $forceReason, esc_html__('Passwords do not match.', 'wpmgr-agent'));
            return;
        }

        // Validate against the password policy.
        if (class_exists(PasswordPolicyModule::class) && function_exists('class_exists')) {
            $errors = new \WP_Error();
            // We need a PasswordPolicyModule instance to call validatePassword.
            // Build a temporary one with the current policy and stub deps.
            $stubSettings = new class implements CpUrlProvider {
                public function controlPlaneUrl(): string
                {
                    return '';
                }
            };
            $stubSigner = new class implements RequestSigner {
                public function signHeaders(string $method, string $path, string $body): array
                {
                    return [];
                }
            };
            $pwMod = new PasswordPolicyModule($this->policy, $stubSettings, $stubSigner);
            $pwMod->validatePassword($pass1, $user, $errors);

            if (!empty($errors->errors)) {
                // Failure: increment attempt counters before re-rendering the form (N1 fix).
                $session['attempts'] = $currentAttempts + 1;
                $this->storeSession($userId, $session);
                $this->incrementCrossRequestAttempts($userId);
                $messages = implode(' ', array_map(fn ($m) => is_string($m) ? $m : '', array_column(array_values($errors->errors), 0)));
                $this->renderForcedChangeForm($user, $session, $forceReason, $messages);
                return;
            }
        }

        // All checks passed -- update the password.
        if (!function_exists('wp_set_password')) {
            wp_die(esc_html__('Password change is not available.', 'wpmgr-agent'), '', ['response' => 500]);
        }
        wp_set_password($pass1, $userId);

        // Clear the force-change flag and update last-changed timestamp.
        if (function_exists('delete_user_meta')) {
            delete_user_meta($userId, PasswordPolicyModule::META_FORCE_CHANGE);
        }
        if (function_exists('update_user_meta')) {
            update_user_meta($userId, PasswordPolicyModule::META_LAST_CHANGED, time());
        }

        // Clear the interstitial session and the cross-request attempt counter on
        // success (N1 fix: mirrors the 2FA success path at line ~683).
        $this->clearSession($userId);
        $this->clearCrossRequestAttempts($userId);

        $redirectTo = isset($session['redirect_to']) && is_string($session['redirect_to'])
            ? $session['redirect_to']
            : (function_exists('admin_url') ? admin_url() : '/wp-admin/');

        // Issue the real auth cookie.
        wp_set_auth_cookie($userId, false);

        // Re-fire wp_login with the guard so side-effects (activity log, etc.) run.
        self::$verifying = true;
        $freshUser = function_exists('get_userdata') ? get_userdata($userId) : null;
        if ($freshUser instanceof \WP_User) {
            do_action('wp_login', $freshUser->user_login, $freshUser); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- firing WP core's documented post-login action; not a custom hook
        }
        self::$verifying = false;

        wp_safe_redirect(esc_url_raw($redirectTo));
        exit;
    }

    // -------------------------------------------------------------------------
    // Detect pending interstitial sessions
    // -------------------------------------------------------------------------

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
        $userId = isset($_POST['wpmgr_2fa_user_id']) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- interstitial session uses its own HMAC signing, not WP nonces (see section 3.2: machine session, not browser nonce)
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

        $sessionType = (string) ($session['type'] ?? self::SESSION_TYPE_2FA);
        if ($sessionType === self::SESSION_TYPE_FORCED_CHANGE) {
            $forceReason = '';
            if (function_exists('get_user_meta')) {
                $raw = get_user_meta($userId, PasswordPolicyModule::META_FORCE_CHANGE, true);
                $forceReason = is_string($raw) ? $raw : '';
            }
            $this->renderForcedChangeForm($user, $session, $forceReason, '');
            return;
        }

        $this->renderInterstitial($user, $session);
    }

    /**
     * Handle the 2FA verify form submission (both 2FA codes and forced-change).
     *
     * @return void
     */
    public function handleVerifySubmit(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- interstitial uses HMAC session signing, not WP nonces (section 3.2 design; the nonce concept does not apply: no WP session exists yet to mint a nonce against)
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

        // --- Shared brute-force guards (apply to BOTH 2FA and forced-change paths) ---

        // Cross-request guard: checked before routing so forced-change submits cannot
        // bypass the per-user cumulative cap by recreating a new session (N1 fix).
        if (!$this->checkCrossRequestAttempts($userId)) {
            $this->clearSession($userId);
            wp_die(esc_html__('Too many failed verification attempts. Please log in again.', 'wpmgr-agent'), '', ['response' => 429]);
        }

        // Per-session brute-force guard: also applies to both paths (N1 fix).
        $attempts = (int) ($session['attempts'] ?? 0);
        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->clearSession($userId);
            wp_die(esc_html__('Too many failed attempts. Please log in again.', 'wpmgr-agent'), '', ['response' => 429]);
        }

        // Route to the forced-change handler when the session type indicates it.
        $sessionType = (string) ($session['type'] ?? self::SESSION_TYPE_2FA);
        if ($sessionType === self::SESSION_TYPE_FORCED_CHANGE) {
            $this->handleForcedChangeSubmit($userId, $session, $attempts);
            return;
        }

        // --- Standard 2FA code verification path ---

        // Find and validate the chosen provider.
        $provider = $this->findProvider($providerKey);
        if ($provider === null || !$provider->isConfiguredFor($user)) {
            $this->renderInterstitial($user, $session, esc_html__('Invalid provider selected.', 'wpmgr-agent'));
            return;
        }

        // Collect and sanitize all provider input fields.
        $providerInput = $this->collectProviderInput();

        if (!$provider->validate($user, $providerInput)) {
            // Increment both the per-session counter and the cross-request counter.
            $session['attempts'] = $attempts + 1;
            $this->storeSession($userId, $session);
            $this->incrementCrossRequestAttempts($userId);
            $this->renderInterstitial($user, $session, esc_html__('Incorrect code. Please try again.', 'wpmgr-agent'));
            return;
        }

        // SUCCESS -- clear the interstitial session and issue the real cookie.
        $this->clearSession($userId);
        $this->clearCrossRequestAttempts($userId);
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
        // (activity log, WooCommerce session, etc.) run correctly -- but our own
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

    // -------------------------------------------------------------------------
    // M1 fix: XML-RPC intercept (corrected 2FA-required or non-email enrolled)
    // -------------------------------------------------------------------------

    /**
     * Intercept XML-RPC logins for users with 2FA actually configured.
     *
     * M1 fix: previously this blocked any user whose isConfiguredFor() returned
     * true, which included EmailCodeProvider for every user with an email address,
     * effectively blocking ALL authenticated XML-RPC even when 2FA was not in use.
     *
     * Corrected gate: block only when the user is role-required for 2FA OR has
     * a non-email provider (TOTP, backup codes) enrolled. The email provider is
     * always available as a fallback and does not constitute deliberate enrollment.
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

        // Block if the role requires 2FA for this user.
        if ($this->policy->requires2fa($user)) {
            return new \WP_Error(
                'wpmgr_2fa_xmlrpc_blocked',
                esc_html__('Two-factor authentication is required. XML-RPC password-only access is disabled for this account.', 'wpmgr-agent')
            );
        }

        // Block if the user has a non-email 2FA method enrolled (deliberate enrollment).
        if ($this->hasNonEmailMethodConfigured($user)) {
            return new \WP_Error(
                'wpmgr_2fa_xmlrpc_blocked',
                esc_html__('Two-factor authentication is required. XML-RPC password-only access is disabled for this account.', 'wpmgr-agent')
            );
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
    // Cross-request attempt limiter (LOW item)
    // -------------------------------------------------------------------------

    /**
     * Check whether the user is within the cross-request attempt limit.
     * Returns true if the attempt should be allowed, false if the limit is exceeded.
     *
     * @param int $userId
     * @return bool
     */
    private function checkCrossRequestAttempts(int $userId): bool
    {
        if (!function_exists('get_user_meta')) {
            return true;
        }
        $record = get_user_meta($userId, self::META_ATTEMPT_COUNT, true);
        if (!is_array($record)) {
            return true;
        }
        $count     = (int) ($record['count'] ?? 0);
        $windowEnd = (int) ($record['window_end'] ?? 0);
        // Reset if the window has expired.
        if (time() > $windowEnd) {
            return true;
        }
        return $count < self::MAX_CROSS_REQUEST_ATTEMPTS;
    }

    /**
     * Increment the cross-request attempt counter for the user.
     * Uses a sliding window equal to SESSION_TTL.
     *
     * @param int $userId
     * @return void
     */
    private function incrementCrossRequestAttempts(int $userId): void
    {
        if (!function_exists('get_user_meta') || !function_exists('update_user_meta')) {
            return;
        }
        $record = get_user_meta($userId, self::META_ATTEMPT_COUNT, true);
        if (!is_array($record) || time() > (int) ($record['window_end'] ?? 0)) {
            $record = ['count' => 1, 'window_end' => time() + self::SESSION_TTL];
        } else {
            $record['count'] = ((int) ($record['count'] ?? 0)) + 1;
        }
        update_user_meta($userId, self::META_ATTEMPT_COUNT, $record);
    }

    /**
     * Clear the cross-request attempt counter on successful verification.
     *
     * @param int $userId
     * @return void
     */
    private function clearCrossRequestAttempts(int $userId): void
    {
        if (function_exists('delete_user_meta')) {
            delete_user_meta($userId, self::META_ATTEMPT_COUNT);
        }
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

        // 2FA is optional and user has nothing enrolled -- no interstitial.
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
            // No suitable provider available -- fail open (never lock out).
            return;
        }

        // Destroy the just-issued auth session before showing the interstitial.
        // This ensures zero half-authenticated window.
        $this->destroyCurrentSession($userId);

        // Pick the first available provider as default.
        $redirectTo = $this->getCurrentRedirectTo();
        $rememberMe = isset($_POST['rememberme']); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- wp_login action; WP core has already verified credentials at this point

        $session = $this->createSession($userId, $redirectTo, $rememberMe, self::SESSION_TYPE_2FA);
        $this->renderInterstitial($user, $session);
    }

    /**
     * Capture and destroy the just-issued WP auth session cookie.
     * Uses wp_destroy_all_sessions + wp_clear_auth_cookie to ensure
     * zero half-authenticated window before the interstitial renders.
     *
     * SECURITY INVARIANT (C1): both calls MUST happen BEFORE the interstitial
     * page renders or exit fires. This is tested in Site2faModuleTest::
     * test_c1_session_destruction_before_interstitial().
     *
     * @param int $userId
     * @return void
     */
    public function destroyCurrentSession(int $userId): void
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
     * @param string $type       SESSION_TYPE_2FA or SESSION_TYPE_FORCED_CHANGE.
     * @return array<string,mixed>
     */
    private function createSession(int $userId, string $redirectTo, bool $rememberMe, string $type = self::SESSION_TYPE_2FA): array
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
            'type'        => $type,
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
     * HMAC key: wp_salt('secure_auth') -- site-specific, not the password.
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
            // Nothing to show -- fail open.
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
            if (isset($_POST[$field]) && is_string($_POST[$field])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- 2FA interstitial uses HMAC session token, not WP nonces (no WP session exists yet to mint a nonce against; see section 3.2)
                $out[$field] = sanitize_text_field(wp_unslash($_POST[$field])); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- same
            }
        }
        return $out;
    }
}
