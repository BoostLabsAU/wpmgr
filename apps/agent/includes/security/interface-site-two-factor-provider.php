<?php
/**
 * SiteTwoFactorProvider — thin interface for site-user 2FA providers.
 *
 * Each provider handles one second-factor type (TOTP, email code, backup code).
 * The interstitial module owns the flow; providers are responsible only for:
 *   - reporting configuration state for a user
 *   - rendering the code-entry HTML form fragment
 *   - sending any pre-render side-effects (email dispatch)
 *   - validating the submitted code and burning it
 *
 * Clean-room design; no third-party plugin code or identifiers referenced.
 *
 * @package WPMgr\Agent\Security
 */

declare(strict_types=1);

namespace WPMgr\Agent\Security;

/**
 * Contract for a pluggable second-factor provider used by the 2FA interstitial.
 */
interface SiteTwoFactorProvider
{
    /**
     * Stable provider key used as an identifier and stored in user-meta.
     * One of: 'totp', 'email', 'backup'.
     *
     * @return string
     */
    public function key(): string;

    /**
     * Human-readable label shown in the interstitial UI.
     *
     * @return string
     */
    public function label(): string;

    /**
     * Whether this provider is currently configured (enrolled) for the user.
     *
     * @param \WP_User $user
     * @return bool
     */
    public function isConfiguredFor(\WP_User $user): bool;

    /**
     * Pre-render hook: called once before the form is shown. The email provider
     * uses this to dispatch the one-time code. TOTP/backup are no-ops here.
     *
     * @param \WP_User $user
     * @return void
     */
    public function preRender(\WP_User $user): void;

    /**
     * Render the code-entry form HTML fragment (no wrapping <form>).
     * Must escape all output; no heredocs.
     *
     * @param \WP_User $user
     * @return string Safe HTML.
     */
    public function renderForm(\WP_User $user): string;

    /**
     * Validate the submitted second-factor input for the user.
     * Returns true on success; false on failure.
     * On success, the code MUST be invalidated / burned to prevent replay.
     *
     * @param \WP_User             $user
     * @param array<string,string> $input POST fields (already wp_unslash'd + sanitized by the module).
     * @return bool
     */
    public function validate(\WP_User $user, array $input): bool;
}
