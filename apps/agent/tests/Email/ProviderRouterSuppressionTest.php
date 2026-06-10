<?php
/**
 * ProviderRouterSuppressionTest — verifies the Phase-4b suppression check
 * wired into ProviderRouter::send():
 *
 *   - All recipients suppressed → send fails with detail='recipient suppressed',
 *     log row written with status='suppressed', provider handler never called.
 *   - Some recipients suppressed → filtered out, remainder sent normally.
 *   - No SuppressionCache wired → behaviour unchanged (all recipients pass).
 *
 * @package WPMgr\Agent\Tests\Email
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
use WPMgr\Agent\Email\SuppressionCheckerInterface;

/**
 * @covers \WPMgr\Agent\Email\ProviderRouter
 */
class ProviderRouterSuppressionTest extends TestCase
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
    // Helpers
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

    /**
     * Build a SuppressionCheckerInterface stub that marks specific addresses as suppressed.
     *
     * @param string[] $suppressed Email addresses that should return true from is_suppressed().
     */
    private function make_suppression_cache(array $suppressed): SuppressionCheckerInterface
    {
        $cache = $this->createMock(SuppressionCheckerInterface::class);
        $cache->method('is_suppressed')->willReturnCallback(
            fn (string $email) => in_array(strtolower($email), array_map('strtolower', $suppressed), true)
        );
        return $cache;
    }

    private function make_logger(): EmailLogger
    {
        $logger = $this->createMock(EmailLogger::class);
        $logger->method('write')->willReturn(1);
        return $logger;
    }

    private function base_mail(array $to = ['to@example.com']): array
    {
        return [
            'to'          => $to,
            'cc'          => [],
            'bcc'         => [],
            'reply_to'    => [],
            'from'        => 'from@example.com',
            'from_name'   => 'Sender',
            'subject'     => 'Test',
            'body_text'   => 'Hello',
            'body_html'   => '',
            'charset'     => 'UTF-8',
            'headers'     => [],
            'attachments' => [],
            'return_path' => false,
            'x_site_id'   => 'site-123',
            'x_tenant_id' => 'tenant-456',
        ];
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    /**
     * When ALL To recipients are suppressed, send() must:
     *   - return ok=false, detail='recipient suppressed'
     *   - never call the provider handler
     *   - log a row with status='suppressed' when log_emails=true
     */
    public function test_all_suppressed_recipients_fails_without_calling_handler(): void
    {
        Functions\when('current_time')->justReturn('2026-06-10 00:00:00');

        $cfg = new EmailConfig(['provider' => 'sendgrid', 'log_emails' => false]);

        $handler = $this->make_handler('sendgrid', [
            'ok' => true, 'message_id' => 'sg-001', 'error' => '', 'provider_response' => '202',
        ]);
        $handler->expects($this->never())->method('send');

        $cache  = $this->make_suppression_cache(['to@example.com']);
        $router = new ProviderRouter($this->make_keystore(), $this->make_logger(), $cache);
        $router->register($handler);

        $result = $router->send($this->base_mail(['to@example.com']), $cfg);

        $this->assertFalse($result['ok']);
        $this->assertSame('recipient suppressed', $result['detail']);
        $this->assertSame('', $result['message_id']);
    }

    /**
     * A 'suppressed' log row is written when log_emails=true and all recipients
     * are suppressed.
     */
    public function test_suppressed_send_writes_log_row_with_suppressed_status(): void
    {
        Functions\when('current_time')->justReturn('2026-06-10 00:00:00');

        $cfg = new EmailConfig(['provider' => 'sendgrid', 'log_emails' => true]);

        $handler = $this->make_handler('sendgrid', [
            'ok' => true, 'message_id' => '', 'error' => '', 'provider_response' => '',
        ]);

        $logger = $this->createMock(EmailLogger::class);
        $logger->expects($this->once())
            ->method('write')
            ->with(
                $this->isType('array'),
                $this->isType('string'),
                'suppressed',
                '',
                'recipient suppressed',
                '',
                0,
                $this->isInstanceOf(EmailConfig::class)
            )
            ->willReturn(1);

        $cache  = $this->make_suppression_cache(['to@example.com']);
        $router = new ProviderRouter($this->make_keystore(), $logger, $cache);
        $router->register($handler);

        $router->send($this->base_mail(['to@example.com']), new EmailConfig(['provider' => 'sendgrid', 'log_emails' => true]));
    }

    /**
     * When one of two recipients is suppressed, the remaining non-suppressed
     * recipient is still sent to and the call succeeds.
     */
    public function test_partial_suppression_sends_to_non_suppressed_recipients(): void
    {
        Functions\when('current_time')->justReturn('2026-06-10 00:00:00');

        $cfg = new EmailConfig(['provider' => 'sendgrid', 'log_emails' => false]);

        /** @var array<string,mixed>|null $captured_mail */
        $captured_mail = null;
        $handler = $this->createMock(ProviderHandlerInterface::class);
        $handler->method('provider')->willReturn('sendgrid');
        $handler->method('send')->willReturnCallback(
            function (array $mail) use (&$captured_mail) {
                $captured_mail = $mail;
                return ['ok' => true, 'message_id' => 'sg-xyz', 'error' => '', 'provider_response' => '202'];
            }
        );

        // Suppress only the first recipient; second must pass through.
        $cache  = $this->make_suppression_cache(['suppressed@example.com']);
        $router = new ProviderRouter($this->make_keystore(), $this->make_logger(), $cache);
        $router->register($handler);

        $result = $router->send(
            $this->base_mail(['suppressed@example.com', 'allowed@example.com']),
            $cfg
        );

        $this->assertTrue($result['ok']);
        $this->assertNotNull($captured_mail);
        // The suppressed address must have been removed.
        $this->assertSame(['allowed@example.com'], $captured_mail['to']);
    }

    /**
     * When no SuppressionCache is wired (null), all recipients pass through.
     */
    public function test_no_suppression_cache_sends_all_recipients(): void
    {
        Functions\when('current_time')->justReturn('2026-06-10 00:00:00');

        $cfg = new EmailConfig(['provider' => 'sendgrid', 'log_emails' => false]);

        $handler = $this->make_handler('sendgrid', [
            'ok' => true, 'message_id' => 'sg-no-cache', 'error' => '', 'provider_response' => '202',
        ]);

        // No SuppressionCache passed — third parameter defaults to null.
        $router = new ProviderRouter($this->make_keystore(), $this->make_logger());
        $router->register($handler);

        $result = $router->send(
            $this->base_mail(['to@example.com']),
            $cfg
        );

        $this->assertTrue($result['ok']);
        $this->assertSame('sg-no-cache', $result['message_id']);
    }
}
