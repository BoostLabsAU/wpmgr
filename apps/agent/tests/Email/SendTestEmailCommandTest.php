<?php
/**
 * Tests for SendTestEmailCommand: validates `to`, reads EmailConfig,
 * dispatches to ProviderRouter with disable_fallback=true, returns structured result.
 *
 * @package WPMgr\Agent\Tests
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests\Email;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WPMgr\Agent\Commands\SendTestEmailCommand;
use WPMgr\Agent\Email\EmailConfig;
use WPMgr\Agent\Email\ProviderRouter;

/**
 * @covers \WPMgr\Agent\Commands\SendTestEmailCommand
 */
class SendTestEmailCommandTest extends TestCase
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

    private function make_router(bool $ok, string $message_id = '', string $detail = ''): ProviderRouter
    {
        $router = $this->createMock(ProviderRouter::class);
        $router->method('send_via')->willReturn([
            'ok'         => $ok,
            'message_id' => $message_id,
            'detail'     => $detail,
        ]);
        return $router;
    }

    public function test_name_is_correct(): void
    {
        $cmd = new SendTestEmailCommand($this->make_router(true));
        $this->assertSame('send_test_email', $cmd->name());
    }

    public function test_rejects_missing_to_field(): void
    {
        $cmd    = new SendTestEmailCommand($this->make_router(true));
        $result = $cmd->execute([], []);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('to', $result['detail']);
    }

    public function test_rejects_empty_to(): void
    {
        // sanitize_email is defined in bootstrap.php and returns '' for empty input.
        $cmd    = new SendTestEmailCommand($this->make_router(true));
        $result = $cmd->execute([], ['to' => '']);

        $this->assertFalse($result['ok']);
    }

    public function test_rejects_invalid_email(): void
    {
        // sanitize_email is defined in bootstrap.php and returns '' for invalid addresses.
        $cmd    = new SendTestEmailCommand($this->make_router(true));
        $result = $cmd->execute([], ['to' => 'not-an-email']);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('invalid', $result['detail']);
    }

    public function test_returns_not_configured_when_no_email_config(): void
    {
        Functions\when('get_option')->justReturn(false);

        $cmd    = new SendTestEmailCommand($this->make_router(true));
        $result = $cmd->execute([], ['to' => 'admin@example.com']);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('sync_email_config', $result['detail']);
    }

    public function test_send_success_returns_ok_and_message_id(): void
    {
        Functions\when('get_option')->alias(
            fn($key) => $key === EmailConfig::OPTION
                ? ['provider' => 'sendgrid', 'from_address' => 'from@example.com']
                : false
        );

        $router = $this->createMock(ProviderRouter::class);
        $router->expects($this->once())
            ->method('send_via')
            ->with(
                $this->callback(fn($mail) => $mail['to'] === ['admin@example.com']),
                $this->isInstanceOf(EmailConfig::class),
                '', // connection_key defaults to ''
                true // disable_fallback must be true
            )
            ->willReturn(['ok' => true, 'message_id' => 'sg-test-001', 'detail' => '']);

        $cmd    = new SendTestEmailCommand($router);
        $result = $cmd->execute([], ['to' => 'admin@example.com']);

        $this->assertTrue($result['ok']);
        $this->assertSame('sg-test-001', $result['message_id']);
        $this->assertStringContainsString('success', $result['detail']);
    }

    public function test_send_failure_returns_provider_error_detail(): void
    {
        Functions\when('get_option')->alias(
            fn($key) => $key === EmailConfig::OPTION
                ? ['provider' => 'mailgun', 'from_address' => 'hello@example.com']
                : false
        );

        $router = $this->createMock(ProviderRouter::class);
        $router->method('send_via')->willReturn([
            'ok'         => false,
            'message_id' => '',
            'detail'     => 'Mailgun error 401: Forbidden',
        ]);

        $cmd    = new SendTestEmailCommand($router);
        $result = $cmd->execute([], ['to' => 'admin@example.com']);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Mailgun error 401', $result['detail']);
    }

    public function test_defaults_subject_when_not_provided(): void
    {
        Functions\when('get_option')->alias(
            fn($key) => $key === EmailConfig::OPTION
                ? ['provider' => 'postmark', 'from_address' => 'from@example.com']
                : false
        );

        $router = $this->createMock(ProviderRouter::class);
        $router->expects($this->once())
            ->method('send_via')
            ->with(
                $this->callback(fn($mail) => $mail['subject'] === 'Test Email from WPMgr'),
                $this->anything(),
                '',   // connection_key
                true  // disable_fallback
            )
            ->willReturn(['ok' => true, 'message_id' => '', 'detail' => '']);

        $cmd = new SendTestEmailCommand($router);
        $cmd->execute([], ['to' => 'admin@example.com']);
    }

    public function test_uses_custom_subject_when_provided(): void
    {
        Functions\when('get_option')->alias(
            fn($key) => $key === EmailConfig::OPTION
                ? ['provider' => 'ses', 'from_address' => 'from@example.com']
                : false
        );

        $router = $this->createMock(ProviderRouter::class);
        $router->expects($this->once())
            ->method('send_via')
            ->with(
                $this->callback(fn($mail) => $mail['subject'] === 'My Custom Subject'),
                $this->anything(),
                '',   // connection_key
                true  // disable_fallback
            )
            ->willReturn(['ok' => true, 'message_id' => '', 'detail' => '']);

        $cmd = new SendTestEmailCommand($router);
        $cmd->execute([], ['to' => 'admin@example.com', 'subject' => 'My Custom Subject']);
    }
}
