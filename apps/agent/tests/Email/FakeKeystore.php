<?php
/**
 * FakeKeystore — lightweight test double for EmailKeystoreInterface.
 *
 * Used by ProviderRouter and SyncEmailConfigCommand tests. Captures all
 * storeEmailSecret() calls in the public $stored array for assertions.
 *
 * @package WPMgr\Agent\Tests\Email
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests\Email;

use WPMgr\Agent\Email\EmailKeystoreInterface;

/**
 * Minimal Keystore stand-in for email-layer unit tests.
 *
 * We cannot extend Keystore (it is final) and we cannot createMock() it
 * (same reason). Instead, tests that need to control get_email_secret() /
 * storeEmailSecret() use this class directly. Tests that only call these
 * two methods and nothing else work with this substitute.
 */
final class FakeKeystore implements EmailKeystoreInterface
{
    private string $secret;

    /** @var array<string> Captured storeEmailSecret() calls. */
    public array $stored = [];

    public function __construct(string $initial_secret = '')
    {
        $this->secret = $initial_secret;
    }

    public function get_email_secret(): string
    {
        return $this->secret;
    }

    public function storeEmailSecret(string $secret): void
    {
        $this->stored[] = $secret;
        $this->secret   = $secret;
    }
}
