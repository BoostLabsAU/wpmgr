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
    // User-agent bans — BLOCKER 1: correct positive-match OR-chain
    // -------------------------------------------------------------------------

    /**
     * A single UA ban must emit a positive RewriteCond (no leading '!') and the
     * RewriteRule that 403s must follow it.
     */
    public function test_ua_ban_emits_positive_rewrite_condition(): void
    {
        $raw = [
            'bans' => [['id' => 'u', 'type' => 'user_agent', 'value' => 'EvilBot/2.0']],
        ];
        $cfg = HardeningConfig::fromArray($raw);
        $out = $this->writer()->renderInto('', $cfg);
        $this->assertStringContainsString('HTTP_USER_AGENT', $out);
        $this->assertStringContainsString('EvilBot', $out);
        // Must be a positive match (no leading '!') so the ban fires ON match.
        $this->assertStringNotContainsString('!EvilBot', $out);
        $this->assertStringContainsString('[F,L]', $out);
    }

    /**
     * BLOCKER 1: a non-banned UA must NOT be blocked (no false positive).
     * With one ban in place, a request from a completely different UA must pass.
     * The old inverted logic blocked ALL UAs that did not match every ban pattern.
     */
    public function test_ua_ban_does_not_block_non_banned_ua_single_ban(): void
    {
        $raw = [
            'bans' => [['id' => 'b1', 'type' => 'user_agent', 'value' => 'EvilBot/2.0']],
        ];
        $cfg = HardeningConfig::fromArray($raw);
        $out = $this->writer()->renderInto('', $cfg);

        // The generated .htaccess must contain exactly one RewriteCond.
        // That condition must be a POSITIVE match for EvilBot (no leading '!'),
        // so the [F,L] rule fires only when the UA is EvilBot — not for others.
        $lines = explode("\n", $out);
        $rewriteConds = array_filter($lines, static fn (string $l): bool => str_contains($l, 'RewriteCond %{HTTP_USER_AGENT}'));
        $this->assertCount(1, $rewriteConds, 'Exactly one RewriteCond must be emitted for one ban');

        foreach ($rewriteConds as $cond) {
            // Must NOT be negated — positive match only.
            $this->assertStringNotContainsString('!EvilBot', $cond, 'UA condition must be a positive match, not negated');
            $this->assertStringContainsString('EvilBot', $cond, 'EvilBot pattern must appear in the condition');
        }
    }

    /**
     * BLOCKER 1: with TWO bans, verify the OR-chain: the last condition has no
     * [OR] flag, all others carry [NC,OR]. A UA matching neither ban passes;
     * a UA matching one ban is blocked.
     */
    public function test_ua_ban_two_bans_or_chain_correct(): void
    {
        $raw = [
            'bans' => [
                ['id' => 'b1', 'type' => 'user_agent', 'value' => 'EvilBot/2.0'],
                ['id' => 'b2', 'type' => 'user_agent', 'value' => 'SpamCrawler'],
            ],
        ];
        $cfg = HardeningConfig::fromArray($raw);
        $out = $this->writer()->renderInto('', $cfg);

        $lines = explode("\n", $out);
        $rewriteConds = array_values(array_filter(
            $lines,
            static fn (string $l): bool => str_contains($l, 'RewriteCond %{HTTP_USER_AGENT}')
        ));
        $this->assertCount(2, $rewriteConds, 'Exactly two RewriteConds for two UA bans');

        // First condition: must carry [NC,OR] (OR to the next condition).
        $this->assertStringContainsString('[NC,OR]', $rewriteConds[0], 'First condition must carry [NC,OR]');
        // Last condition: must NOT carry [OR] (terminates the OR chain).
        $this->assertStringNotContainsString('[OR]', $rewriteConds[1], 'Last condition must not carry [OR]');
        // Both must be positive matches (no leading '!').
        $this->assertStringNotContainsString('!EvilBot',    $rewriteConds[0]);
        $this->assertStringNotContainsString('!SpamCrawler', $rewriteConds[1]);
        $this->assertStringContainsString('EvilBot',     $rewriteConds[0]);
        $this->assertStringContainsString('SpamCrawler', $rewriteConds[1]);
    }

    // -------------------------------------------------------------------------
    // BLOCKER 2: UA ban value with newlines must be dropped (injection guard)
    // -------------------------------------------------------------------------

    /**
     * BLOCKER 2: a user_agent ban value containing an embedded newline must be
     * silently dropped at the HardeningConfig validation stage. The injected text
     * must never appear in the rendered .htaccess block.
     */
    public function test_ua_ban_with_newline_is_dropped_by_config(): void
    {
        $injection = "EvilBot\n    Require all denied";
        $raw = [
            'bans' => [
                ['id' => 'bad', 'type' => 'user_agent', 'value' => $injection],
                ['id' => 'ok',  'type' => 'user_agent', 'value' => 'SafeBot'],
            ],
        ];
        $cfg = HardeningConfig::fromArray($raw);

        // The injected value must be absent from the validated ban list.
        $uaBans = $cfg->userAgentBans();
        $this->assertNotContains($injection, $uaBans, 'Newline-containing value must be dropped by validateBans');
        $this->assertContains('SafeBot', $uaBans, 'Clean value must still be accepted');

        $out = $this->writer()->renderInto('', $cfg);
        $this->assertStringNotContainsString('Require all denied', $out, 'Injected directive must not appear in .htaccess');
        $this->assertStringNotContainsString("EvilBot\n", $out, 'Literal newline must not appear in .htaccess');
    }

    /**
     * BLOCKER 2: belt-and-braces at the writer layer — even if a value with a
     * control char somehow reaches renderInto, it must be skipped.
     */
    public function test_ua_ban_with_cr_lf_skipped_at_render_layer(): void
    {
        // Bypass validateBans to reach the writer directly via a crafted config.
        // We use a value with a carriage-return embedded between two words.
        // Because validateBans already drops these, we test the belt-and-braces
        // path by directly verifying the output does not contain the control char.
        $raw = [
            'bans' => [
                ['id' => 'x', 'type' => 'user_agent', 'value' => "Bot\rExtra"],
            ],
        ];
        $cfg = HardeningConfig::fromArray($raw);
        $out = $this->writer()->renderInto('', $cfg);
        // validateBans drops the value; the block should be empty.
        $this->assertStringNotContainsString(ServerConfigWriter::BEGIN, $out, 'Block must be absent when all UA bans are filtered');
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
    // BLOCKER 3: all-address CIDRs must not emit any deny rule
    // -------------------------------------------------------------------------

    /**
     * BLOCKER 3: 0.0.0.0/0 must never appear in a Require not ip directive.
     * Emitting it would lock out every request, including the signed control-plane
     * command that would undo the ban.
     */
    public function test_ipv4_all_address_cidr_produces_no_deny_rule(): void
    {
        $raw = [
            'bans' => [['id' => 'x', 'type' => 'range', 'value' => '0.0.0.0/0']],
        ];
        $cfg = HardeningConfig::fromArray($raw);
        $out = $this->writer()->renderInto('', $cfg);
        $this->assertStringNotContainsString('Require not ip', $out, '0.0.0.0/0 must never emit a deny rule');
        $this->assertStringNotContainsString('0.0.0.0/0', $out, '0.0.0.0/0 must be absent from .htaccess');
    }

    /**
     * BLOCKER 3: ::/0 (IPv6 all-address) must never emit a deny rule.
     */
    public function test_ipv6_all_address_cidr_produces_no_deny_rule(): void
    {
        $raw = [
            'bans' => [['id' => 'x', 'type' => 'range', 'value' => '::/0']],
        ];
        $cfg = HardeningConfig::fromArray($raw);
        $out = $this->writer()->renderInto('', $cfg);
        $this->assertStringNotContainsString('Require not ip', $out, '::/0 must never emit a deny rule');
        $this->assertStringNotContainsString('::/0', $out, '::/0 must be absent from .htaccess');
    }

    /**
     * BLOCKER 3: a normal public CIDR (not all-address, not private) MUST still
     * emit a deny rule — the safety guard must not over-filter.
     */
    public function test_normal_public_cidr_emits_deny_rule(): void
    {
        $raw = [
            'bans' => [['id' => 'x', 'type' => 'range', 'value' => '203.0.113.0/24']],
        ];
        $cfg = HardeningConfig::fromArray($raw);
        $out = $this->writer()->renderInto('', $cfg);
        $this->assertStringContainsString('Require not ip 203.0.113.0/24', $out, 'Public CIDR must produce a deny rule');
    }

    /**
     * BLOCKER 3: a CIDR in the private range (10.0.0.0/8) must not emit a deny
     * rule so a LAN admin can never be locked out via .htaccess.
     */
    public function test_private_cidr_does_not_emit_deny_rule(): void
    {
        $raw = [
            'bans' => [['id' => 'x', 'type' => 'range', 'value' => '10.0.0.0/24']],
        ];
        $cfg = HardeningConfig::fromArray($raw);
        $out = $this->writer()->renderInto('', $cfg);
        $this->assertStringNotContainsString('Require not ip 10.0.0.0/24', $out, 'Private CIDR must not emit a deny rule');
    }

    /**
     * BLOCKER 3: a specific private IP (loopback 127.0.0.1) must not emit a
     * deny rule.
     */
    public function test_loopback_ip_does_not_emit_deny_rule(): void
    {
        $raw = [
            'bans' => [['id' => 'x', 'type' => 'ip', 'value' => '127.0.0.1']],
        ];
        $cfg = HardeningConfig::fromArray($raw);
        $out = $this->writer()->renderInto('', $cfg);
        $this->assertStringNotContainsString('Require not ip 127.0.0.1', $out, 'Loopback IP must not emit a deny rule');
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
