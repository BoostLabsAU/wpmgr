<?php
/**
 * HardeningConfig unit tests: fromArray() validation, toArray() round-trip,
 * safe defaults for missing/invalid fields, ban list validation, and the
 * ipRangeBans / userAgentBans projections.
 *
 * Pure in-memory tests; no disk access, no WP function stubs needed.
 *
 * @package WPMgr\Agent\Tests
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests;

use WPMgr\Agent\Security\HardeningConfig;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Security\HardeningConfig
 */
final class HardeningConfigTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Defaults
    // -------------------------------------------------------------------------

    public function test_defaults_are_all_off(): void
    {
        $c = HardeningConfig::defaults();

        $this->assertFalse($c->disableFileEditor);
        $this->assertSame(HardeningConfig::XMLRPC_ON, $c->xmlrpcMode);
        $this->assertSame(HardeningConfig::REST_DEFAULT, $c->restrictRestApi);
        $this->assertSame(HardeningConfig::LOGIN_BOTH, $c->restrictLoginIdentifier);
        $this->assertFalse($c->forceUniqueNickname);
        $this->assertFalse($c->disableAuthorArchiveEnum);
        $this->assertFalse($c->forceSsl);
        $this->assertFalse($c->disableDirectoryBrowsing);
        $this->assertFalse($c->disablePhpInUploads);
        $this->assertFalse($c->protectSystemFiles);
        $this->assertSame([], $c->bans);
    }

    // -------------------------------------------------------------------------
    // fromArray() — valid full payload
    // -------------------------------------------------------------------------

    public function test_from_array_accepts_full_valid_payload(): void
    {
        $raw = [
            'config' => [
                'disable_file_editor'        => true,
                'xmlrpc_mode'                => 'off',
                'restrict_rest_api'          => 'restricted',
                'restrict_login_identifier'  => 'email',
                'force_unique_nickname'      => true,
                'disable_author_archive_enum' => true,
                'force_ssl'                  => true,
                'disable_directory_browsing' => true,
                'disable_php_in_uploads'     => true,
                'protect_system_files'       => true,
            ],
            'bans' => [
                ['id' => 'a1', 'type' => 'ip',         'value' => '1.2.3.4',      'comment' => 'bad'],
                ['id' => 'a2', 'type' => 'range',      'value' => '10.0.0.0/8',   'comment' => ''],
                ['id' => 'a3', 'type' => 'user_agent', 'value' => 'BadBot/1.0',   'comment' => 'scraper'],
            ],
        ];

        $c = HardeningConfig::fromArray($raw);

        $this->assertTrue($c->disableFileEditor);
        $this->assertSame('off', $c->xmlrpcMode);
        $this->assertSame('restricted', $c->restrictRestApi);
        $this->assertSame('email', $c->restrictLoginIdentifier);
        $this->assertTrue($c->forceUniqueNickname);
        $this->assertTrue($c->disableAuthorArchiveEnum);
        $this->assertTrue($c->forceSsl);
        $this->assertTrue($c->disableDirectoryBrowsing);
        $this->assertTrue($c->disablePhpInUploads);
        $this->assertTrue($c->protectSystemFiles);
        $this->assertCount(3, $c->bans);
    }

    // -------------------------------------------------------------------------
    // fromArray() — missing keys default to off
    // -------------------------------------------------------------------------

    public function test_missing_config_key_defaults_to_off(): void
    {
        $c = HardeningConfig::fromArray([]);
        $this->assertSame(HardeningConfig::defaults()->disableFileEditor, $c->disableFileEditor);
        $this->assertSame(HardeningConfig::XMLRPC_ON, $c->xmlrpcMode);
    }

    public function test_empty_bans_array_is_accepted(): void
    {
        $c = HardeningConfig::fromArray(['bans' => []]);
        $this->assertSame([], $c->bans);
    }

    // -------------------------------------------------------------------------
    // fromArray() — enum coercion
    // -------------------------------------------------------------------------

    public function test_invalid_xmlrpc_mode_falls_back_to_on(): void
    {
        $c = HardeningConfig::fromArray(['config' => ['xmlrpc_mode' => 'garbage']]);
        $this->assertSame(HardeningConfig::XMLRPC_ON, $c->xmlrpcMode);
    }

    public function test_invalid_rest_api_mode_falls_back_to_default(): void
    {
        $c = HardeningConfig::fromArray(['config' => ['restrict_rest_api' => 'nope']]);
        $this->assertSame(HardeningConfig::REST_DEFAULT, $c->restrictRestApi);
    }

    public function test_invalid_login_identifier_falls_back_to_both(): void
    {
        $c = HardeningConfig::fromArray(['config' => ['restrict_login_identifier' => 'pin']]);
        $this->assertSame(HardeningConfig::LOGIN_BOTH, $c->restrictLoginIdentifier);
    }

    // -------------------------------------------------------------------------
    // Ban validation
    // -------------------------------------------------------------------------

    public function test_ban_with_empty_id_is_skipped(): void
    {
        $raw = ['bans' => [['id' => '', 'type' => 'ip', 'value' => '1.2.3.4']]];
        $c   = HardeningConfig::fromArray($raw);
        $this->assertSame([], $c->bans);
    }

    public function test_ban_with_empty_value_is_skipped(): void
    {
        $raw = ['bans' => [['id' => 'x', 'type' => 'ip', 'value' => '']]];
        $c   = HardeningConfig::fromArray($raw);
        $this->assertSame([], $c->bans);
    }

    public function test_ban_with_unknown_type_is_skipped(): void
    {
        $raw = ['bans' => [['id' => 'x', 'type' => 'hostname', 'value' => 'evil.com']]];
        $c   = HardeningConfig::fromArray($raw);
        $this->assertSame([], $c->bans);
    }

    public function test_ban_ip_invalid_value_is_skipped(): void
    {
        $raw = ['bans' => [['id' => 'x', 'type' => 'ip', 'value' => 'not-an-ip']]];
        $c   = HardeningConfig::fromArray($raw);
        $this->assertSame([], $c->bans);
    }

    public function test_ban_range_invalid_value_is_skipped(): void
    {
        $raw = ['bans' => [['id' => 'x', 'type' => 'range', 'value' => '10.0.0.0']]]; // missing prefix
        $c   = HardeningConfig::fromArray($raw);
        $this->assertSame([], $c->bans);
    }

    public function test_ban_range_valid_cidr_is_accepted(): void
    {
        $raw = ['bans' => [['id' => 'x', 'type' => 'range', 'value' => '192.168.1.0/24']]];
        $c   = HardeningConfig::fromArray($raw);
        $this->assertCount(1, $c->bans);
        $this->assertSame('192.168.1.0/24', $c->bans[0]['value']);
    }

    public function test_non_array_ban_entry_is_skipped(): void
    {
        $raw = ['bans' => ['not-an-array']];
        $c   = HardeningConfig::fromArray($raw);
        $this->assertSame([], $c->bans);
    }

    // -------------------------------------------------------------------------
    // Projections
    // -------------------------------------------------------------------------

    public function test_ip_range_bans_returns_only_ip_and_range_values(): void
    {
        $raw = [
            'bans' => [
                ['id' => '1', 'type' => 'ip',         'value' => '5.5.5.5'],
                ['id' => '2', 'type' => 'range',      'value' => '10.0.0.0/8'],
                ['id' => '3', 'type' => 'user_agent', 'value' => 'BadBot'],
            ],
        ];
        $c = HardeningConfig::fromArray($raw);
        $this->assertSame(['5.5.5.5', '10.0.0.0/8'], $c->ipRangeBans());
    }

    public function test_user_agent_bans_returns_only_ua_values(): void
    {
        $raw = [
            'bans' => [
                ['id' => '1', 'type' => 'ip',         'value' => '5.5.5.5'],
                ['id' => '2', 'type' => 'user_agent', 'value' => 'EvilBot/2.0'],
            ],
        ];
        $c = HardeningConfig::fromArray($raw);
        $this->assertSame(['EvilBot/2.0'], $c->userAgentBans());
    }

    // -------------------------------------------------------------------------
    // Round-trip
    // -------------------------------------------------------------------------

    public function test_to_array_round_trips_through_from_array(): void
    {
        $raw = [
            'config' => [
                'disable_file_editor'        => true,
                'xmlrpc_mode'                => 'limited',
                'restrict_rest_api'          => 'restricted',
                'restrict_login_identifier'  => 'username',
                'force_unique_nickname'      => false,
                'disable_author_archive_enum' => true,
                'force_ssl'                  => true,
                'disable_directory_browsing' => false,
                'disable_php_in_uploads'     => true,
                'protect_system_files'       => true,
            ],
            'bans' => [
                ['id' => 'b1', 'type' => 'ip', 'value' => '203.0.113.5', 'comment' => 'spammer'],
            ],
        ];

        $original = HardeningConfig::fromArray($raw);
        $exported  = $original->toArray();
        $restored  = HardeningConfig::fromArray($exported);

        $this->assertSame($original->disableFileEditor,        $restored->disableFileEditor);
        $this->assertSame($original->xmlrpcMode,               $restored->xmlrpcMode);
        $this->assertSame($original->restrictRestApi,          $restored->restrictRestApi);
        $this->assertSame($original->restrictLoginIdentifier,  $restored->restrictLoginIdentifier);
        $this->assertSame($original->forceUniqueNickname,      $restored->forceUniqueNickname);
        $this->assertSame($original->disableAuthorArchiveEnum, $restored->disableAuthorArchiveEnum);
        $this->assertSame($original->forceSsl,                 $restored->forceSsl);
        $this->assertSame($original->disableDirectoryBrowsing, $restored->disableDirectoryBrowsing);
        $this->assertSame($original->disablePhpInUploads,      $restored->disablePhpInUploads);
        $this->assertSame($original->protectSystemFiles,       $restored->protectSystemFiles);
        $this->assertSame($original->bans,                     $restored->bans);
    }
}
