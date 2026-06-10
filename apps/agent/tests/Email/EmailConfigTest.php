<?php
/**
 * Tests for EmailConfig value object.
 *
 * @package WPMgr\Agent\Tests
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests\Email;

use PHPUnit\Framework\TestCase;
use WPMgr\Agent\Email\EmailConfig;

/**
 * @covers \WPMgr\Agent\Email\EmailConfig
 */
class EmailConfigTest extends TestCase
{
    public function test_defaults_when_constructed_empty(): void
    {
        $cfg = new EmailConfig();

        $this->assertSame('', $cfg->provider);
        $this->assertSame('', $cfg->from_address);
        $this->assertSame('', $cfg->from_name);
        $this->assertFalse($cfg->force_from_email);
        $this->assertFalse($cfg->force_from_name);
        $this->assertFalse($cfg->return_path);
        $this->assertSame([], $cfg->config);
        $this->assertSame([], $cfg->mappings);
        $this->assertFalse($cfg->log_emails);
        $this->assertFalse($cfg->store_body);
        $this->assertSame(14, $cfg->retention_days);
        $this->assertFalse($cfg->is_configured());
    }

    public function test_valid_provider_is_accepted(): void
    {
        foreach (EmailConfig::PROVIDERS as $provider) {
            $cfg = new EmailConfig(['provider' => $provider]);
            $this->assertSame($provider, $cfg->provider, "Expected provider $provider to be accepted");
            $this->assertTrue($cfg->is_configured());
        }
    }

    public function test_invalid_provider_is_rejected(): void
    {
        $cfg = new EmailConfig(['provider' => 'unknown_provider']);
        $this->assertSame('', $cfg->provider);
        $this->assertFalse($cfg->is_configured());
    }

    public function test_retention_days_clamped(): void
    {
        $cfg_low = new EmailConfig(['retention_days' => 0]);
        $this->assertSame(1, $cfg_low->retention_days);

        $cfg_high = new EmailConfig(['retention_days' => 9999]);
        $this->assertSame(365, $cfg_high->retention_days);

        $cfg_normal = new EmailConfig(['retention_days' => 30]);
        $this->assertSame(30, $cfg_normal->retention_days);
    }

    public function test_to_array_round_trips(): void
    {
        $input = [
            'provider'        => 'smtp',
            'from_address'    => 'sender@example.com',
            'from_name'       => 'My Site',
            'force_from_email' => true,
            'force_from_name' => true,
            'return_path'     => false,
            'config'          => ['host' => 'smtp.example.com', 'port' => 587],
            'mappings'        => [],
            'log_emails'      => true,
            'store_body'      => false,
            'retention_days'  => 21,
        ];

        $cfg    = new EmailConfig($input);
        $output = $cfg->to_array();

        $this->assertSame('smtp', $output['provider']);
        $this->assertSame('sender@example.com', $output['from_address']);
        $this->assertSame('My Site', $output['from_name']);
        $this->assertTrue($output['force_from_email']);
        $this->assertTrue($output['force_from_name']);
        $this->assertFalse($output['return_path']);
        $this->assertSame(['host' => 'smtp.example.com', 'port' => 587], $output['config']);
        $this->assertSame(21, $output['retention_days']);
        $this->assertTrue($output['log_emails']);
        $this->assertFalse($output['store_body']);
    }

    public function test_boolean_fields_parsed_from_truthy_values(): void
    {
        $cfg = new EmailConfig([
            'force_from_email' => 1,
            'force_from_name'  => '1',
            'return_path'      => true,
            'log_emails'       => 1,
            'store_body'       => true,
        ]);

        $this->assertTrue($cfg->force_from_email);
        $this->assertTrue($cfg->force_from_name);
        $this->assertTrue($cfg->return_path);
        $this->assertTrue($cfg->log_emails);
        $this->assertTrue($cfg->store_body);
    }

    public function test_config_and_mappings_default_to_empty_arrays(): void
    {
        $cfg = new EmailConfig(['provider' => 'smtp']);
        $this->assertSame([], $cfg->config);
        $this->assertSame([], $cfg->mappings);
    }
}
