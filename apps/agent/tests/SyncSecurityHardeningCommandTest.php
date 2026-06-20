<?php
/**
 * SyncSecurityHardeningCommand tests:
 *   - command name is "sync_security_hardening"
 *   - valid payload persists config + returns {ok:true,detail:"applied"}
 *   - invalid 'config' type returns a clear error
 *   - invalid 'bans' type returns a clear error
 *   - missing keys are tolerated (all-off defaults)
 *   - each mode for xmlrpc/rest/login-identifier is stored correctly
 *   - bans are persisted in the stored option
 *
 * The HardeningModule is constructed with its wp-options calls stubbed via
 * Brain Monkey. Server-config writes (ServerConfigWriter) are no-ops in tests
 * because isNginx() short-circuits (no SERVER_SOFTWARE set).
 *
 * @package WPMgr\Agent\Tests
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WPMgr\Agent\Commands\SyncSecurityHardeningCommand;
use WPMgr\Agent\Security\HardeningModule;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Commands\SyncSecurityHardeningCommand
 * @covers \WPMgr\Agent\Security\HardeningModule
 * @covers \WPMgr\Agent\Security\HardeningConfig
 */
final class SyncSecurityHardeningCommandTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $optionStore = [];

    protected function set_up(): void
    {
        parent::set_up();
        Monkey\setUp();

        $this->optionStore = [];

        Functions\when('get_option')->alias(fn ($k, $d = false) => $this->optionStore[$k] ?? $d);
        Functions\when('update_option')->alias(function ($k, $v) {
            $this->optionStore[$k] = $v;
            return true;
        });
        Functions\when('delete_option')->alias(function ($k) {
            unset($this->optionStore[$k]);
            return true;
        });
        Functions\when('add_filter')->justReturn(true);
        Functions\when('add_action')->justReturn(true);
        Functions\when('remove_filter')->justReturn(true);
        Functions\when('is_user_logged_in')->justReturn(false);
        Functions\when('wp_json_encode')->alias(fn ($v) => json_encode($v));
        Functions\when('sanitize_text_field')->alias(fn ($s) => $s);
        Functions\when('wp_unslash')->alias(fn ($s) => $s);
    }

    protected function tear_down(): void
    {
        unset($_SERVER['SERVER_SOFTWARE']);
        Monkey\tearDown();
        parent::tear_down();
    }

    private function command(): SyncSecurityHardeningCommand
    {
        return new SyncSecurityHardeningCommand(new HardeningModule());
    }

    // -------------------------------------------------------------------------
    // Command contract
    // -------------------------------------------------------------------------

    public function test_command_name_is_sync_security_hardening(): void
    {
        $this->assertSame('sync_security_hardening', $this->command()->name());
    }

    // -------------------------------------------------------------------------
    // Validation errors
    // -------------------------------------------------------------------------

    public function test_config_must_be_array(): void
    {
        $result = $this->command()->execute([], ['config' => 'not-an-array']);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('config', $result['detail']);
    }

    public function test_bans_must_be_array(): void
    {
        $result = $this->command()->execute([], ['bans' => 'not-an-array']);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('bans', $result['detail']);
    }

    // -------------------------------------------------------------------------
    // Happy path: full valid payload
    // -------------------------------------------------------------------------

    public function test_full_valid_payload_returns_ok_and_persists(): void
    {
        $params = [
            'config' => [
                'disable_file_editor'        => true,
                'xmlrpc_mode'                => 'off',
                'restrict_rest_api'          => 'restricted',
                'restrict_login_identifier'  => 'email',
                'force_unique_nickname'      => true,
                'disable_author_archive_enum' => true,
                'force_ssl'                  => false,
                'disable_directory_browsing' => true,
                'disable_php_in_uploads'     => true,
                'protect_system_files'       => true,
            ],
            'bans' => [
                ['id' => 'b1', 'type' => 'ip',    'value' => '203.0.113.5', 'comment' => 'spammer'],
                ['id' => 'b2', 'type' => 'range',  'value' => '10.0.0.0/8',  'comment' => ''],
            ],
        ];

        $result = $this->command()->execute([], $params);

        $this->assertTrue($result['ok']);
        $this->assertArrayHasKey('detail', $result);

        // Option was stored.
        $stored = $this->optionStore[HardeningModule::OPTION_CONFIG] ?? null;
        $this->assertNotNull($stored, 'hardening option must be written');

        $decoded = json_decode($stored, true);
        $this->assertIsArray($decoded);
        $this->assertTrue($decoded['config']['disable_file_editor']);
        $this->assertSame('off', $decoded['config']['xmlrpc_mode']);
        $this->assertSame('restricted', $decoded['config']['restrict_rest_api']);
        $this->assertCount(2, $decoded['bans']);
    }

    // -------------------------------------------------------------------------
    // Missing keys: defaults to off
    // -------------------------------------------------------------------------

    public function test_missing_config_key_defaults_to_off(): void
    {
        $result = $this->command()->execute([], []);
        $this->assertTrue($result['ok']);

        $stored  = $this->optionStore[HardeningModule::OPTION_CONFIG] ?? '{}';
        $decoded = json_decode($stored, true);
        $this->assertFalse($decoded['config']['disable_file_editor']);
        $this->assertSame('on', $decoded['config']['xmlrpc_mode']);
    }

    // -------------------------------------------------------------------------
    // xmlrpc modes
    // -------------------------------------------------------------------------

    /** @dataProvider xmlrpcModeProvider */
    public function test_xmlrpc_mode_stored_correctly(string $mode): void
    {
        $result = $this->command()->execute([], ['config' => ['xmlrpc_mode' => $mode]]);
        $this->assertTrue($result['ok']);

        $decoded = json_decode($this->optionStore[HardeningModule::OPTION_CONFIG], true);
        $this->assertSame($mode, $decoded['config']['xmlrpc_mode']);
    }

    /**
     * @return array<string,array<string>>
     */
    public static function xmlrpcModeProvider(): array
    {
        return [
            'on'      => ['on'],
            'off'     => ['off'],
            'limited' => ['limited'],
        ];
    }

    // -------------------------------------------------------------------------
    // login identifier modes
    // -------------------------------------------------------------------------

    /** @dataProvider loginIdentifierProvider */
    public function test_login_identifier_stored_correctly(string $mode): void
    {
        $result = $this->command()->execute([], ['config' => ['restrict_login_identifier' => $mode]]);
        $this->assertTrue($result['ok']);

        $decoded = json_decode($this->optionStore[HardeningModule::OPTION_CONFIG], true);
        $this->assertSame($mode, $decoded['config']['restrict_login_identifier']);
    }

    /**
     * @return array<string,array<string>>
     */
    public static function loginIdentifierProvider(): array
    {
        return [
            'both'     => ['both'],
            'username' => ['username'],
            'email'    => ['email'],
        ];
    }

    // -------------------------------------------------------------------------
    // Bans: invalid entries dropped
    // -------------------------------------------------------------------------

    public function test_malformed_ban_entries_are_dropped(): void
    {
        $params = [
            'bans' => [
                ['id' => '',  'type' => 'ip',   'value' => '1.2.3.4'],    // empty id
                ['id' => 'x', 'type' => 'ip',   'value' => 'not-an-ip'], // bad value
                ['id' => 'y', 'type' => 'range', 'value' => '10.0.0.0'], // missing prefix
                ['id' => 'z', 'type' => 'user_agent', 'value' => 'EvilBot'], // valid UA
            ],
        ];

        $result = $this->command()->execute([], $params);
        $this->assertTrue($result['ok']);

        $decoded = json_decode($this->optionStore[HardeningModule::OPTION_CONFIG], true);
        $this->assertCount(1, $decoded['bans'], 'only the valid UA ban should survive');
        $this->assertSame('EvilBot', $decoded['bans'][0]['value']);
    }

    // -------------------------------------------------------------------------
    // WAF deny_cidrs sync
    // -------------------------------------------------------------------------

    public function test_ip_bans_are_synced_into_waf_hardening_deny_cidrs(): void
    {
        // ITEM 5 FIX: hardening bans now go into 'hardening_deny_cidrs' (their
        // own key) rather than being merged into 'deny_cidrs'. This allows the
        // WAF to evaluate them mode-independently: an explicit operator ban blocks
        // regardless of whether login-protection protect mode is enabled.
        //
        // Pre-seed a minimal wpmgr_security_config (WAF config).
        $this->optionStore['wpmgr_security_config'] = json_encode([
            'mode'        => 'protect',
            'deny_cidrs'  => ['5.5.5.5/32'],
            'allow_cidrs' => [],
        ]);

        $params = [
            'bans' => [
                ['id' => 'b1', 'type' => 'ip',    'value' => '203.0.113.10'],
                ['id' => 'b2', 'type' => 'range',  'value' => '198.51.100.0/24'],
            ],
        ];

        $result = $this->command()->execute([], $params);
        $this->assertTrue($result['ok']);

        $wafRaw     = $this->optionStore['wpmgr_security_config'] ?? '{}';
        $wafDecoded = json_decode($wafRaw, true);

        // The brute-force deny_cidrs must be UNCHANGED — hardening bans no longer
        // bleed into the brute-force deny list.
        $denyCidrs = $wafDecoded['deny_cidrs'] ?? [];
        $this->assertContains('5.5.5.5/32', $denyCidrs, 'original deny_cidrs must be preserved');
        $this->assertNotContains('203.0.113.10', $denyCidrs, 'hardening ban must not be in deny_cidrs');
        $this->assertNotContains('198.51.100.0/24', $denyCidrs, 'hardening range must not be in deny_cidrs');

        // Hardening bans go into their own dedicated key so they enforce in all modes.
        $hardeningCidrs = $wafDecoded['hardening_deny_cidrs'] ?? [];
        $this->assertContains('203.0.113.10', $hardeningCidrs,    'new IP ban must be in hardening_deny_cidrs');
        $this->assertContains('198.51.100.0/24', $hardeningCidrs, 'new range ban must be in hardening_deny_cidrs');
    }

    // -------------------------------------------------------------------------
    // No WAF config: sync is a no-op (does not create waf config)
    // -------------------------------------------------------------------------

    public function test_waf_sync_is_noop_when_no_waf_config_exists(): void
    {
        $params = [
            'bans' => [['id' => 'b1', 'type' => 'ip', 'value' => '203.0.113.10']],
        ];

        $result = $this->command()->execute([], $params);
        $this->assertTrue($result['ok']);

        // wpmgr_security_config must NOT have been created from scratch.
        $this->assertArrayNotHasKey('wpmgr_security_config', $this->optionStore);
    }
}
