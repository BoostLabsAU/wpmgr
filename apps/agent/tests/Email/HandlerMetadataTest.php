<?php
/**
 * HandlerMetadataTest — verifies that every API provider handler (SendGrid,
 * Mailgun, Postmark, SES) injects the site/tenant metadata used by the Phase-4a
 * CP webhook fan-out to resolve which site a bounce/complaint belongs to.
 *
 * Keys injected per provider:
 *   SendGrid : custom_args.wpmgr_site / custom_args.wpmgr_tenant (in personalizations[0])
 *   Mailgun  : v:wpmgr_site / v:wpmgr_tenant (form fields)
 *   Postmark : Metadata.wpmgr_site / Metadata.wpmgr_tenant (JSON body)
 *   SES      : Tags.member.1.Name=wpmgr_site / Tags.member.2.Name=wpmgr_tenant (form-encoded)
 *
 * SMTP has no API webhook and therefore carries no metadata.
 *
 * @package WPMgr\Agent\Tests\Email
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests\Email;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WPMgr\Agent\Email\Handlers\SendgridHandler;
use WPMgr\Agent\Email\Handlers\MailgunHandler;
use WPMgr\Agent\Email\Handlers\PostmarkHandler;
use WPMgr\Agent\Email\Handlers\SesHandler;

/**
 * @covers \WPMgr\Agent\Email\Handlers\SendgridHandler
 * @covers \WPMgr\Agent\Email\Handlers\MailgunHandler
 * @covers \WPMgr\Agent\Email\Handlers\PostmarkHandler
 * @covers \WPMgr\Agent\Email\Handlers\SesHandler
 */
class HandlerMetadataTest extends TestCase
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

    private function base_mail(string $site_id = 'site-xyz', string $tenant_id = 'tenant-abc'): array
    {
        return [
            'to'          => ['to@example.com'],
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
            'x_site_id'   => $site_id,
            'x_tenant_id' => $tenant_id,
        ];
    }

    // -------------------------------------------------------------------------
    // SendGrid
    // -------------------------------------------------------------------------

    public function test_sendgrid_injects_custom_args_site_and_tenant(): void
    {
        /** @var array<string,mixed>|null $captured_body */
        $captured_body = null;

        Functions\when('wp_json_encode')->alias('json_encode');
        Functions\when('wp_remote_post')->alias(
            function (string $url, array $args) use (&$captured_body) {
                $captured_body = json_decode((string) $args['body'], true);
                return ['response' => ['code' => 202], 'body' => '', 'headers' => []];
            }
        );
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(202);
        Functions\when('wp_remote_retrieve_body')->justReturn('');
        Functions\when('wp_remote_retrieve_header')->justReturn('');

        $handler = new SendgridHandler();
        $handler->send($this->base_mail(), [], 'SG.key');

        $this->assertIsArray($captured_body);
        $custom_args = $captured_body['personalizations'][0]['custom_args'] ?? null;
        $this->assertIsArray($custom_args, 'custom_args must be present in personalizations[0]');
        $this->assertSame('site-xyz', $custom_args['wpmgr_site'] ?? null, 'wpmgr_site must match x_site_id');
        $this->assertSame('tenant-abc', $custom_args['wpmgr_tenant'] ?? null, 'wpmgr_tenant must match x_tenant_id');
    }

    public function test_sendgrid_omits_custom_args_when_site_id_empty(): void
    {
        /** @var array<string,mixed>|null $captured_body */
        $captured_body = null;

        Functions\when('wp_json_encode')->alias('json_encode');
        Functions\when('wp_remote_post')->alias(
            function (string $url, array $args) use (&$captured_body) {
                $captured_body = json_decode((string) $args['body'], true);
                return ['response' => ['code' => 202], 'body' => '', 'headers' => []];
            }
        );
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(202);
        Functions\when('wp_remote_retrieve_body')->justReturn('');
        Functions\when('wp_remote_retrieve_header')->justReturn('');

        $handler = new SendgridHandler();
        $handler->send($this->base_mail('', ''), [], 'SG.key');

        $this->assertIsArray($captured_body);
        $custom_args = $captured_body['personalizations'][0]['custom_args'] ?? null;
        $this->assertNull($custom_args, 'custom_args must be absent when site_id is empty');
    }

    // -------------------------------------------------------------------------
    // Mailgun
    // -------------------------------------------------------------------------

    public function test_mailgun_injects_v_vars_site_and_tenant(): void
    {
        /** @var array<string,mixed>|null $captured_body */
        $captured_body = null;

        Functions\when('wp_remote_post')->alias(
            function (string $url, array $args) use (&$captured_body) {
                $captured_body = $args['body'] ?? [];
                return [
                    'response' => ['code' => 200],
                    'body'     => '{"id":"<test@mg.example.com>","message":"Queued"}',
                ];
            }
        );
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn(
            '{"id":"<test@mg.example.com>","message":"Queued"}'
        );

        $handler = new MailgunHandler();
        $handler->send($this->base_mail(), ['domain_name' => 'mg.example.com', 'region' => 'us'], 'mg-key');

        $this->assertIsArray($captured_body);
        $this->assertSame('site-xyz', $captured_body['v:wpmgr_site'] ?? null, 'v:wpmgr_site must be present');
        $this->assertSame('tenant-abc', $captured_body['v:wpmgr_tenant'] ?? null, 'v:wpmgr_tenant must be present');
    }

    public function test_mailgun_omits_v_vars_when_site_id_empty(): void
    {
        /** @var array<string,mixed>|null $captured_body */
        $captured_body = null;

        Functions\when('wp_remote_post')->alias(
            function (string $url, array $args) use (&$captured_body) {
                $captured_body = $args['body'] ?? [];
                return [
                    'response' => ['code' => 200],
                    'body'     => '{"id":"<test@mg.example.com>","message":"Queued"}',
                ];
            }
        );
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn(
            '{"id":"<test@mg.example.com>","message":"Queued"}'
        );

        $handler = new MailgunHandler();
        $handler->send($this->base_mail('', ''), ['domain_name' => 'mg.example.com'], 'mg-key');

        $this->assertIsArray($captured_body);
        $this->assertArrayNotHasKey('v:wpmgr_site', $captured_body);
        $this->assertArrayNotHasKey('v:wpmgr_tenant', $captured_body);
    }

    // -------------------------------------------------------------------------
    // Postmark
    // -------------------------------------------------------------------------

    public function test_postmark_injects_metadata_site_and_tenant(): void
    {
        /** @var array<string,mixed>|null $captured_body */
        $captured_body = null;

        Functions\when('wp_json_encode')->alias('json_encode');
        Functions\when('wp_remote_post')->alias(
            function (string $url, array $args) use (&$captured_body) {
                $captured_body = json_decode((string) $args['body'], true);
                return [
                    'response' => ['code' => 200],
                    'body'     => '{"MessageID":"pm-001"}',
                ];
            }
        );
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"MessageID":"pm-001"}');

        $handler = new PostmarkHandler();
        $handler->send($this->base_mail(), [], 'pm-token');

        $this->assertIsArray($captured_body);
        $metadata = $captured_body['Metadata'] ?? null;
        $this->assertIsArray($metadata, 'Metadata must be present in the Postmark payload');
        $this->assertSame('site-xyz', $metadata['wpmgr_site'] ?? null);
        $this->assertSame('tenant-abc', $metadata['wpmgr_tenant'] ?? null);
    }

    public function test_postmark_omits_metadata_when_site_id_empty(): void
    {
        /** @var array<string,mixed>|null $captured_body */
        $captured_body = null;

        Functions\when('wp_json_encode')->alias('json_encode');
        Functions\when('wp_remote_post')->alias(
            function (string $url, array $args) use (&$captured_body) {
                $captured_body = json_decode((string) $args['body'], true);
                return [
                    'response' => ['code' => 200],
                    'body'     => '{"MessageID":"pm-002"}',
                ];
            }
        );
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"MessageID":"pm-002"}');

        $handler = new PostmarkHandler();
        $handler->send($this->base_mail('', ''), [], 'pm-token');

        $this->assertIsArray($captured_body);
        $this->assertArrayNotHasKey('Metadata', $captured_body);
    }

    // -------------------------------------------------------------------------
    // SES
    // -------------------------------------------------------------------------

    public function test_ses_injects_tags_site_and_tenant(): void
    {
        /** @var string|null $captured_post_body */
        $captured_post_body = null;

        // wp_parse_url is defined in bootstrap.php; do not re-stub.
        Functions\when('wp_remote_post')->alias(
            function (string $url, array $args) use (&$captured_post_body) {
                $captured_post_body = (string) ($args['body'] ?? '');
                return [
                    'response' => ['code' => 200],
                    'body'     => '<SendRawEmailResponse><SendRawEmailResult><MessageId>ses-001</MessageId></SendRawEmailResult></SendRawEmailResponse>',
                ];
            }
        );
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn(
            '<SendRawEmailResponse><SendRawEmailResult><MessageId>ses-001</MessageId></SendRawEmailResult></SendRawEmailResponse>'
        );

        $handler = new SesHandler();
        $handler->send(
            $this->base_mail(),
            ['access_key' => 'AKIATEST', 'region' => 'us-east-1'],
            'secret-key'
        );

        $this->assertNotNull($captured_post_body);

        // PHP parse_str() converts '.' in keys to '_'.
        // So 'Tags.member.1.Name' → 'Tags_member_1_Name' when decoded.
        // Verify by asserting the raw body string contains the expected key=value pairs.
        $this->assertStringContainsString('Tags.member.1.Name=wpmgr_site', urldecode($captured_post_body));
        $this->assertStringContainsString('Tags.member.1.Value=site-xyz', urldecode($captured_post_body));
        $this->assertStringContainsString('Tags.member.2.Name=wpmgr_tenant', urldecode($captured_post_body));
        $this->assertStringContainsString('Tags.member.2.Value=tenant-abc', urldecode($captured_post_body));
    }

    public function test_ses_omits_tags_when_site_id_empty(): void
    {
        /** @var string|null $captured_post_body */
        $captured_post_body = null;

        // wp_parse_url is defined in bootstrap.php; do not re-stub.
        Functions\when('wp_remote_post')->alias(
            function (string $url, array $args) use (&$captured_post_body) {
                $captured_post_body = (string) ($args['body'] ?? '');
                return [
                    'response' => ['code' => 200],
                    'body'     => '<SendRawEmailResponse><SendRawEmailResult><MessageId>ses-002</MessageId></SendRawEmailResult></SendRawEmailResponse>',
                ];
            }
        );
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn(
            '<SendRawEmailResponse><SendRawEmailResult><MessageId>ses-002</MessageId></SendRawEmailResult></SendRawEmailResponse>'
        );

        $handler = new SesHandler();
        $handler->send(
            $this->base_mail('', ''),
            ['access_key' => 'AKIATEST', 'region' => 'us-east-1'],
            'secret-key'
        );

        $this->assertNotNull($captured_post_body);

        $this->assertStringNotContainsString('Tags.member', urldecode($captured_post_body), 'Tags must be absent when site_id is empty');
    }
}
