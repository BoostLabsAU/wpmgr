<?php
/**
 * EmailKeystoreInterface — thin seam over the agent Keystore for the email layer.
 *
 * Extracted so ProviderRouter and SyncEmailConfigCommand are testable without
 * depending on the concrete (final) Keystore class directly.
 *
 * @package WPMgr\Agent\Email
 */

declare(strict_types=1);

namespace WPMgr\Agent\Email;

/**
 * Minimal email-secret storage contract.
 */
interface EmailKeystoreInterface
{
    /**
     * Retrieve and decrypt the per-site email provider secret.
     * Returns an empty string when no secret has been stored.
     *
     * @return string
     */
    public function get_email_secret(): string;

    /**
     * Persist the per-site email provider secret, encrypted.
     * Passing an empty string removes any stored secret.
     *
     * @param string $secret Plaintext secret.
     * @return void
     */
    public function storeEmailSecret(string $secret): void;
}
