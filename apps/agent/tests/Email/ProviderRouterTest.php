<?php
/**
 * Tests for ProviderRouter: FROM-address -> connection -> default -> fallback resolution
 * and send dispatch (with a stub provider handler).
 *
 * @package WPMgr\Agent\Tests
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests\Email;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WPMgr\Agent\Email\EmailConfig;
use WPMgr\Agent\Email\EmailLogger;
use WPMgr\Agent\Email\ProviderHandlerInterface;
use WPMgr\Agent\Email\ProviderRouter;
use WPMgr\Agent\Tests\Email\FakeKeystore;

/**
 * @covers \WPMgr\Agent\Email\ProviderRouter
 */
class ProviderRouterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Helper factories
    // -------------------------------------------------------------------------

    /** @param array{ok:bool,message_id:string,error:string,provider_response:string} $result */
    private function make_handler(string $provider, array $result): ProviderHandlerInterface
    {
        $handler = $this->createMock(ProviderHandlerInterface::class);
        $handler->method('provider')->willReturn($provider);
        $handler->method('send')->willReturn($result);
        return $handler;
    }

    private function make_keystore(string $secret = 'test-secret'): FakeKeystore
    {
        return new FakeKeystore($secret);
    }

    private function make_logger(): EmailLogger
    {
        // EmailLogger is not final; create a partial mock that stubs write().
        $logger = $this->createMock(EmailLogger::class);
        $logger->method('write')->willReturn(1);
        return $logger;
    }

    private function base_mail(string $from = 'a@example.com'): array
    {
        return [
            'to'          => ['to@example.com'],
            'cc'          => [],
            'bcc'         => [],
            'reply_to'    => [],
            'from'        => $from,
            'from_name'   => 'Sender',
            'subject'     => 'Test',
            'body_text'   => 'Hello',
            'body_html'   => '',
            'charset'     => 'UTF-8',
            'headers'     => [],
            'attachments' => [],
            'return_path' => false,
            'x_site_id'   => 'site-123',
        ];
    }

    // -------------------------------------------------------------------------
    // Connection resolution tests
    // -------------------------------------------------------------------------

    public function test_resolve_connection_uses_default_when_no_mapping(): void
    {
        $cfg            = new EmailConfig(['provider' => 'sendgrid', 'config' => ['key' => 'val']]);
        $keystore       = $this->make_keystore();
        $logger         = $this->make_logger();
        $router         = new ProviderRouter($keystore, $logger);

        $connection = $router->resolve_connection('any@example.com', $cfg);

        $this->assertSame('sendgrid', $connection['provider']);
        $this->assertSame(['key' => 'val'], $connection['config']);
    }

    public function test_resolve_connection_uses_mapped_provider_when_from_matches(): void
    {
        $cfg = new EmailConfig([
            'provider' => 'smtp',
            'config'   => ['host' => 'default-smtp.example.com'],
            'mappings' => [
                'newsletter@example.com' => [
                    'provider' => 'sendgrid',
                    'config'   => [],
                ],
            ],
        ]);
        $router = new ProviderRouter($this->make_keystore(), $this->make_logger());

        $connection = $router->resolve_connection('newsletter@example.com', $cfg);
        $this->assertSame('sendgrid', $connection['provider']);

        // Non-mapped address falls back to default.
        $default = $router->resolve_connection('other@example.com', $cfg);
        $this->assertSame('smtp', $default['provider']);
    }

    public function test_resolve_connection_falls_back_to_default_when_mapping_malformed(): void
    {
        $cfg = new EmailConfig([
            'provider' => 'postmark',
            'mappings' => [
                'bad@example.com' => 'not-an-array',
            ],
        ]);
        $router = new ProviderRouter($this->make_keystore(), $this->make_logger());

        $connection = $router->resolve_connection('bad@example.com', $cfg);
        $this->assertSame('postmark', $connection['provider']);
    }

    // -------------------------------------------------------------------------
    // Send dispatch tests
    // -------------------------------------------------------------------------

    public function test_send_success_returns_ok_true(): void
    {
        Functions\when('current_time')->justReturn('2026-01-01 00:00:00');

        $cfg     = new EmailConfig(['provider' => 'sendgrid', 'log_emails' => false]);
        $handler = $this->make_handler('sendgrid', [
            'ok'                => true,
            'message_id'        => 'sg-abc123',
            'error'             => '',
            'provider_response' => '202 Accepted',
        ]);

        $router = new ProviderRouter($this->make_keystore(), $this->make_logger());
        $router->register($handler);

        $result = $router->send($this->base_mail(), $cfg);

        $this->assertTrue($result['ok']);
        $this->assertSame('sg-abc123', $result['message_id']);
        $this->assertSame('', $result['detail']);
    }

    public function test_send_failure_without_fallback_returns_ok_false(): void
    {
        Functions\when('current_time')->justReturn('2026-01-01 00:00:00');

        $cfg     = new EmailConfig(['provider' => 'sendgrid', 'log_emails' => false]);
        $handler = $this->make_handler('sendgrid', [
            'ok'                => false,
            'message_id'        => '',
            'error'             => 'API key invalid',
            'provider_response' => '401',
        ]);

        $router = new ProviderRouter($this->make_keystore(), $this->make_logger());
        $router->register($handler);

        $result = $router->send($this->base_mail(), $cfg, true); // disable_fallback=true

        $this->assertFalse($result['ok']);
        $this->assertSame('API key invalid', $result['detail']);
    }

    public function test_send_retries_fallback_on_primary_failure(): void
    {
        Functions\when('current_time')->justReturn('2026-01-01 00:00:00');

        $cfg = new EmailConfig([
            'provider' => 'sendgrid',
            'config'   => [
                'fallback_provider' => 'smtp',
                'fallback_config'   => ['host' => 'smtp.fallback.com', 'port' => 587],
            ],
            'log_emails' => false,
        ]);

        $failing_handler = $this->make_handler('sendgrid', [
            'ok'                => false,
            'message_id'        => '',
            'error'             => 'primary failed',
            'provider_response' => '',
        ]);

        $fallback_handler = $this->make_handler('smtp', [
            'ok'                => true,
            'message_id'        => 'smtp-xyz',
            'error'             => '',
            'provider_response' => 'SMTP send OK',
        ]);

        $router = new ProviderRouter($this->make_keystore(), $this->make_logger());
        $router->register($failing_handler);
        $router->register($fallback_handler);

        $result = $router->send($this->base_mail(), $cfg, false);

        $this->assertTrue($result['ok']);
        $this->assertSame('smtp-xyz', $result['message_id']);
    }

    public function test_send_returns_error_when_no_handler_registered(): void
    {
        Functions\when('current_time')->justReturn('2026-01-01 00:00:00');

        $cfg    = new EmailConfig(['provider' => 'mailgun', 'log_emails' => false]);
        $router = new ProviderRouter($this->make_keystore(), $this->make_logger());
        // No handlers registered.

        $result = $router->send($this->base_mail(), $cfg);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('no handler registered', $result['detail']);
    }

    public function test_send_writes_log_row_when_log_emails_true(): void
    {
        Functions\when('current_time')->justReturn('2026-01-01 00:00:00');

        $cfg     = new EmailConfig(['provider' => 'postmark', 'log_emails' => true, 'store_body' => false]);
        $handler = $this->make_handler('postmark', [
            'ok'                => true,
            'message_id'        => 'pm-001',
            'error'             => '',
            'provider_response' => '200 OK',
        ]);

        $logger = $this->createMock(EmailLogger::class);
        $logger->expects($this->once())
            ->method('write')
            ->with(
                $this->isType('array'),
                'postmark',
                'sent',
                'pm-001',
                '',
                $this->isType('string'),
                0,
                $this->isInstanceOf(EmailConfig::class)
            )
            ->willReturn(1);

        $router = new ProviderRouter($this->make_keystore(), $logger);
        $router->register($handler);

        $router->send($this->base_mail(), $cfg);
    }

    public function test_send_does_not_write_log_when_log_emails_false(): void
    {
        Functions\when('current_time')->justReturn('2026-01-01 00:00:00');

        $cfg     = new EmailConfig(['provider' => 'sendgrid', 'log_emails' => false]);
        $handler = $this->make_handler('sendgrid', [
            'ok'                => true,
            'message_id'        => 'sg-no-log',
            'error'             => '',
            'provider_response' => '202',
        ]);

        $logger = $this->createMock(EmailLogger::class);
        $logger->expects($this->never())->method('write');

        $router = new ProviderRouter($this->make_keystore(), $logger);
        $router->register($handler);

        $router->send($this->base_mail(), $cfg);
    }
}
