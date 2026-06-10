<?php
/**
 * ResendEmailCommandTest — verifies the resend_email command contract:
 *
 *   - body_stored=1 → ProviderRouter::send() called, row updated, ok=true
 *   - body_stored=0 → ok=false, detail='body_not_stored', handler never called
 *   - row not found → ok=false, detail='log_row_not_found'
 *   - missing agent_seq → ok=false, detail contains 'agent_seq'
 *   - no email config → ok=false, detail='no email config …'
 *   - provider send fails → ok=false, detail from provider error
 *
 * Uses a FakeResendWpdb (defined at the bottom) to control get_row() / query()
 * without requiring a real database.
 *
 * @package WPMgr\Agent\Tests\Email
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests\Email;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WPMgr\Agent\Commands\ResendEmailCommand;
use WPMgr\Agent\Email\EmailConfig;
use WPMgr\Agent\Email\EmailLogger;
use WPMgr\Agent\Email\ProviderHandlerInterface;
use WPMgr\Agent\Email\ProviderRouter;

/**
 * @covers \WPMgr\Agent\Commands\ResendEmailCommand
 */
class ResendEmailCommandTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $optionStore = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->optionStore = [];

        Functions\when('get_option')->alias(
            fn ($k, $d = false) => $this->optionStore[$k] ?? $d
        );
        Functions\when('update_option')->alias(function ($k, $v) {
            $this->optionStore[$k] = $v;
            return true;
        });
        Functions\when('get_site_option')->justReturn('__wpmgr_settings_missing__');
        Functions\when('is_multisite')->justReturn(false);
        // sanitize_email is already defined in bootstrap.php; do not re-stub.
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        Monkey\tearDown();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function make_router_with_result(array $result): ProviderRouter
    {
        $handler = $this->createMock(ProviderHandlerInterface::class);
        $handler->method('provider')->willReturn('sendgrid');
        $handler->method('send')->willReturn($result);

        $logger = $this->createMock(EmailLogger::class);
        $logger->method('write')->willReturn(1);

        $keystore = new FakeKeystore('test-secret');
        $router   = new ProviderRouter($keystore, $logger);
        $router->register($handler);
        return $router;
    }

    private function make_log_row(int $id, bool $body_stored, string $body = ''): array
    {
        return [
            'id'           => (string) $id,
            'mail_to'      => 'recipient@example.com',
            'mail_from'    => 'Sender Name <sender@example.com>',
            'subject'      => 'Original subject',
            'provider'     => 'sendgrid',
            'body_stored'  => $body_stored ? '1' : '0',
            'body'         => $body,
            'resent_count' => '0',
        ];
    }

    private function install_wpdb(?array $row): FakeResendWpdb
    {
        $wpdb            = new FakeResendWpdb($row);
        $GLOBALS['wpdb'] = $wpdb;
        return $wpdb;
    }

    private function install_email_config(): void
    {
        $this->optionStore[EmailConfig::OPTION] = [
            'provider'    => 'sendgrid',
            'from_address' => 'from@example.com',
            'from_name'   => 'WPMgr',
            'log_emails'  => false,
            'store_body'  => false,
        ];
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    public function test_name_is_correct(): void
    {
        $router = $this->make_router_with_result(['ok' => true, 'message_id' => '', 'error' => '', 'provider_response' => '']);
        $cmd    = new ResendEmailCommand($router);
        $this->assertSame('resend_email', $cmd->name());
    }

    public function test_missing_agent_seq_returns_error(): void
    {
        $router = $this->make_router_with_result(['ok' => true, 'message_id' => '', 'error' => '', 'provider_response' => '']);
        $cmd    = new ResendEmailCommand($router);
        $result = $cmd->execute([], []);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('agent_seq', $result['detail']);
    }

    public function test_invalid_agent_seq_zero_returns_error(): void
    {
        $router = $this->make_router_with_result(['ok' => true, 'message_id' => '', 'error' => '', 'provider_response' => '']);
        $cmd    = new ResendEmailCommand($router);
        $result = $cmd->execute([], ['agent_seq' => 0]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('agent_seq', $result['detail']);
    }

    public function test_returns_no_email_config_when_unconfigured(): void
    {
        // No email config in option store.
        $this->install_wpdb($this->make_log_row(1, true, '<p>body</p>'));

        $router = $this->make_router_with_result(['ok' => true, 'message_id' => '', 'error' => '', 'provider_response' => '']);
        $cmd    = new ResendEmailCommand($router);
        $result = $cmd->execute([], ['agent_seq' => 1]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('no email config', $result['detail']);
    }

    public function test_returns_log_row_not_found_when_row_absent(): void
    {
        $this->install_email_config();
        $this->install_wpdb(null); // get_row() returns null

        $router = $this->make_router_with_result(['ok' => true, 'message_id' => '', 'error' => '', 'provider_response' => '']);
        $cmd    = new ResendEmailCommand($router);
        $result = $cmd->execute([], ['agent_seq' => 99]);

        $this->assertFalse($result['ok']);
        $this->assertSame('log_row_not_found', $result['detail']);
    }

    public function test_returns_body_not_stored_when_body_not_captured(): void
    {
        $this->install_email_config();
        $this->install_wpdb($this->make_log_row(5, false)); // body_stored=0

        $router = $this->make_router_with_result(['ok' => true, 'message_id' => '', 'error' => '', 'provider_response' => '']);
        // handler must NOT be called — verify via a strict mock expectation.
        $handler = $this->createMock(ProviderHandlerInterface::class);
        $handler->method('provider')->willReturn('sendgrid');
        $handler->expects($this->never())->method('send');

        $logger   = $this->createMock(EmailLogger::class);
        $logger->method('write')->willReturn(1);
        $keystore = new FakeKeystore('secret');
        $router   = new ProviderRouter($keystore, $logger);
        $router->register($handler);

        $cmd    = new ResendEmailCommand($router);
        $result = $cmd->execute([], ['agent_seq' => 5]);

        $this->assertFalse($result['ok']);
        $this->assertSame('body_not_stored', $result['detail']);
    }

    public function test_resend_succeeds_when_body_stored(): void
    {
        Functions\when('current_time')->justReturn('2026-06-10 00:00:00');

        $this->install_email_config();
        $wpdb = $this->install_wpdb($this->make_log_row(7, true, '<p>Hello world</p>'));

        $router = $this->make_router_with_result([
            'ok'                => true,
            'message_id'        => 'sg-resent-001',
            'error'             => '',
            'provider_response' => '202',
        ]);

        $cmd    = new ResendEmailCommand($router);
        $result = $cmd->execute([], ['agent_seq' => 7]);

        $this->assertTrue($result['ok']);
        $this->assertSame('resent', $result['detail']);
        $this->assertSame('sg-resent-001', $result['message_id']);

        // DB UPDATE must have been called to increment resent_count.
        $this->assertTrue($wpdb->update_called, 'UPDATE query must be executed after a successful resend');
    }

    public function test_resend_does_not_update_row_when_provider_fails(): void
    {
        Functions\when('current_time')->justReturn('2026-06-10 00:00:00');

        $this->install_email_config();
        $wpdb = $this->install_wpdb($this->make_log_row(8, true, 'plain text body'));

        $router = $this->make_router_with_result([
            'ok'                => false,
            'message_id'        => '',
            'error'             => 'API quota exceeded',
            'provider_response' => '429',
        ]);

        $cmd    = new ResendEmailCommand($router);
        $result = $cmd->execute([], ['agent_seq' => 8]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('quota', $result['detail']);
        $this->assertFalse($wpdb->update_called, 'UPDATE must NOT be called when the provider send failed');
    }
}

// ---------------------------------------------------------------------------
// Lightweight wpdb double for ResendEmailCommand tests.
// Simulates get_row() and query() (UPDATE) without a real database.
// ---------------------------------------------------------------------------

/**
 * Minimal wpdb double for ResendEmailCommand tests.
 */
class FakeResendWpdb
{
    public string $prefix = 'wp_';

    public bool $update_called = false;

    /** @var array<string,mixed>|null */
    private ?array $row;

    public function __construct(?array $row)
    {
        $this->row = $row;
    }

    public function prepare(string $query, ...$args): string
    {
        return $query;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function get_row(string $query, string $output = 'ARRAY_A'): ?array
    {
        return $this->row;
    }

    /**
     * Simulate an UPDATE query (resent_count increment).
     *
     * @return int|false
     */
    public function query(string $query)
    {
        if (stripos($query, 'UPDATE') !== false) {
            $this->update_called = true;
            return 1;
        }
        return false;
    }
}
