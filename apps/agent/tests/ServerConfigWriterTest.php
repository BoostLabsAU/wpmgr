<?php
/**
 * ServerConfigWriter tests: block generation, idempotency, toggle-specific
 * directives, ban rules, block removal, and nginx detection.
 *
 * Pure in-memory tests on the renderInto() transform (no disk access). The
 * isNginx() tests mutate $_SERVER.
 *
 * @package WPMgr\Agent\Tests
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests;

use WPMgr\Agent\Security\HardeningConfig;
use WPMgr\Agent\Security\ServerConfigWriter;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Security\ServerConfigWriter
 */
final class ServerConfigWriterTest extends TestCase
{
    protected function tear_down(): void
    {
        unset($_SERVER['SERVER_SOFTWARE']);
        parent::tear_down();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function writer(): ServerConfigWriter
    {
        return new ServerConfigWriter();
    }

    /**
     * Build a HardeningConfig with only the specified keys overriding defaults.
     *
     * @param array<string,mixed> $overrides
     * @return HardeningConfig
     */
    private function config(array $overrides = []): HardeningConfig
    {
        return HardeningConfig::fromArray(['config' => $overrides]);
    }

    // -------------------------------------------------------------------------
    // No-op when all toggles off
    // -------------------------------------------------------------------------

    public function test_empty_config_produces_no_block(): void
    {
        $existing = "# BEGIN WordPress\nRewriteRule . /index.php [L]\n# END WordPress\n";
        $result   = $this->writer()->renderInto($existing, $this->config());
        // Block should NOT appear when nothing is active.
        $this->assertStringNotContainsString(ServerConfigWriter::BEGIN, $result);
        $this->assertStringNotContainsString(ServerConfigWriter::END, $result);
        // Original WordPress block is preserved.
        $this->assertStringContainsString('# BEGIN WordPress', $result);
    }

    // -------------------------------------------------------------------------
    // Idempotency
    // -------------------------------------------------------------------------

    public function test_block_is_idempotent(): void
    {
        $cfg      = $this->config(['disable_directory_browsing' => true]);
        $writer   = $this->writer();
        $existing = "# BEGIN WordPress\nRewriteRule . /index.php [L]\n# END WordPress\n";

        $once  = $writer->renderInto($existing, $cfg);
        $twice = $writer->renderInto($once, $cfg);

        $this->assertSame(1, substr_count($twice, ServerConfigWriter::BEGIN));
        $this->assertSame(1, substr_count($twice, ServerConfigWriter::END));
        $this->assertSame($once, $twice, 'second render must equal the first');
    }

    // -------------------------------------------------------------------------
    // force_ssl
    // -------------------------------------------------------------------------

    public function test_force_ssl_emits_redirect_and_hsts(): void
    {
        $out = $this->writer()->renderInto('', $this->config(['force_ssl' => true]));
        $this->assertStringContainsString('RewriteCond %{HTTPS} off', $out);
        $this->assertStringContainsString('https://%{HTTP_HOST}%{REQUEST_URI}', $out);
        $this->assertStringContainsString('Strict-Transport-Security', $out);
    }

    // -------------------------------------------------------------------------
    // disable_directory_browsing
    // -------------------------------------------------------------------------

    public function test_disable_directory_browsing_emits_options_indexes(): void
    {
        $out = $this->writer()->renderInto('', $this->config(['disable_directory_browsing' => true]));
        $this->assertStringContainsString('Options -Indexes', $out);
    }

    // -------------------------------------------------------------------------
    // disable_php_in_uploads
    // -------------------------------------------------------------------------

    public function test_disable_php_in_uploads_emits_rewrite_rule(): void
    {
        $out = $this->writer()->renderInto('', $this->config(['disable_php_in_uploads' => true]));
        $this->assertStringContainsString('/wp-content/uploads/', $out);
        $this->assertStringContainsString('\.php$', $out);
        $this->assertStringContainsString('[F,L]', $out);
    }

    // -------------------------------------------------------------------------
    // protect_system_files
    // -------------------------------------------------------------------------

    public function test_protect_system_files_blocks_known_filenames(): void
    {
        $out = $this->writer()->renderInto('', $this->config(['protect_system_files' => true]));
        $this->assertStringContainsString('wp-config\.php', $out);
        $this->assertStringContainsString('\.htaccess', $out);
        $this->assertStringContainsString('readme\.html', $out);
        $this->assertStringContainsString('debug\.log', $out);
        $this->assertStringContainsString('Deny from all', $out);
    }

    // -------------------------------------------------------------------------
    // xmlrpc_mode: off
    // -------------------------------------------------------------------------

    public function test_xmlrpc_off_blocks_xmlrpc_php(): void
    {
        $out = $this->writer()->renderInto('', $this->config(['xmlrpc_mode' => 'off']));
        $this->assertStringContainsString('xmlrpc.php', $out);
        $this->assertStringContainsString('Deny from all', $out);
    }

    public function test_xmlrpc_on_produces_no_xmlrpc_block(): void
    {
        $out = $this->writer()->renderInto('', $this->config(['xmlrpc_mode' => 'on']));
        // 'on' is a no-op — do not emit a block just for this.
        // (Other toggles are also off so the whole block is absent.)
        $this->assertStringNotContainsString('xmlrpc.php', $out);
    }

    // -------------------------------------------------------------------------
    // IP/range bans in server config
    // -------------------------------------------------------------------------

    public function test_ip_ban_emits_deny_rule(): void
    {
        $raw = [
            'bans' => [['id' => 'x', 'type' => 'ip', 'value' => '203.0.113.5']],
        ];
        $cfg = HardeningConfig::fromArray($raw);
        $out = $this->writer()->renderInto('', $cfg);
        $this->assertStringContainsString('203.0.113.5', $out);
        $this->assertStringContainsString('Require not ip 203.0.113.5', $out);
    }

    public function test_range_ban_emits_cidr_deny_rule(): void
    {
        $raw = [
            'bans' => [['id' => 'x', 'type' => 'range', 'value' => '198.51.100.0/24']],
        ];
        $cfg = HardeningConfig::fromArray($raw);
        $out = $this->writer()->renderInto('', $cfg);
        $this->assertStringContainsString('198.51.100.0/24', $out);
    }

    // -------------------------------------------------------------------------
    // User-agent bans in server config
    // -------------------------------------------------------------------------

    public function test_ua_ban_emits_rewrite_condition(): void
    {
        $raw = [
            'bans' => [['id' => 'u', 'type' => 'user_agent', 'value' => 'EvilBot/2.0']],
        ];
        $cfg = HardeningConfig::fromArray($raw);
        $out = $this->writer()->renderInto('', $cfg);
        $this->assertStringContainsString('HTTP_USER_AGENT', $out);
        $this->assertStringContainsString('EvilBot', $out);
    }

    // -------------------------------------------------------------------------
    // Block removal when all toggles off
    // -------------------------------------------------------------------------

    public function test_all_toggles_off_removes_prior_block(): void
    {
        $existing = ServerConfigWriter::BEGIN . "\nOptions -Indexes\n" . ServerConfigWriter::END . "\n"
            . "# BEGIN WordPress\nRewriteRule . /index.php [L]\n# END WordPress\n";

        $result = $this->writer()->renderInto($existing, $this->config());
        $this->assertStringNotContainsString(ServerConfigWriter::BEGIN, $result);
        $this->assertStringNotContainsString(ServerConfigWriter::END, $result);
        // WordPress block still there.
        $this->assertStringContainsString('# BEGIN WordPress', $result);
    }

    // -------------------------------------------------------------------------
    // Markers are always present when block is written
    // -------------------------------------------------------------------------

    public function test_markers_are_written_when_block_is_active(): void
    {
        $out = $this->writer()->renderInto('', $this->config(['force_ssl' => true]));
        $this->assertStringContainsString(ServerConfigWriter::BEGIN, $out);
        $this->assertStringContainsString(ServerConfigWriter::END, $out);
    }

    // -------------------------------------------------------------------------
    // nginx detection
    // -------------------------------------------------------------------------

    public function test_is_nginx_detected(): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'nginx/1.24.0';
        $this->assertTrue($this->writer()->isNginx());
    }

    public function test_apache_not_detected_as_nginx(): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.57 (Ubuntu)';
        $this->assertFalse($this->writer()->isNginx());
    }

    public function test_no_server_software_not_detected_as_nginx(): void
    {
        unset($_SERVER['SERVER_SOFTWARE']);
        $this->assertFalse($this->writer()->isNginx());
    }
}
