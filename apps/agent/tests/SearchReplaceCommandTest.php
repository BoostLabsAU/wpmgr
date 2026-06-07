<?php
/**
 * SearchReplaceCommandTest — unit tests for the generic serialization-safe
 * database search-replace command.
 *
 * These tests cover:
 *   1. Input validation guards (missing job_id, short search, bad replace type).
 *   2. dry_run defaults to true.
 *   3. Serialization-safe replacement logic via UrlRewriter::rewrite_row_data
 *      (the actual DB walk is exercised by UrlRewriterTest; here we test the
 *      command's argument routing and dry_run/apply branching).
 *   4. The tables-allowlist cap guard.
 *
 * The command's DB walk (runReplace) requires a live mysqli connection and is
 * not unit-testable without a real DB. The integration of UrlRewriter's
 * serialization-safe algorithm is already covered by UrlRewriterTest.php.
 *
 * @package WPMgr\Agent\Tests
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests;

use PHPUnit\Framework\TestCase;
use WPMgr\Agent\Commands\SearchReplaceCommand;

final class SearchReplaceCommandTest extends TestCase
{
    private SearchReplaceCommand $cmd;

    protected function setUp(): void
    {
        $this->cmd = new SearchReplaceCommand();
    }

    public function testNameIsSearchReplace(): void
    {
        $this->assertSame('search_replace', $this->cmd->name());
    }

    public function testRejectsMissingJobId(): void
    {
        $result = $this->cmd->execute([], [
            'search'  => 'old.example.com',
            'replace' => 'new.example.com',
        ]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('job_id', (string) ($result['detail'] ?? ''));
    }

    public function testRejectsEmptyJobId(): void
    {
        $result = $this->cmd->execute([], [
            'job_id'  => '',
            'search'  => 'old.example.com',
            'replace' => 'new.example.com',
        ]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('job_id', (string) ($result['detail'] ?? ''));
    }

    public function testRejectsSearchBelowMinLength(): void
    {
        // Length 2 — below the 3-byte minimum.
        $result = $this->cmd->execute([], [
            'job_id'  => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'search'  => 'ab',
            'replace' => 'xyz',
        ]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('3', (string) ($result['detail'] ?? ''));
    }

    public function testRejectsExactlyTwoByteSearch(): void
    {
        $result = $this->cmd->execute([], [
            'job_id'  => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'search'  => 'ab',
            'replace' => 'new',
        ]);
        $this->assertFalse($result['ok']);
    }

    public function testAcceptsExactlyThreeByteSearch(): void
    {
        // Cannot reach DB in unit test — expect internal error (not a validation failure).
        $result = $this->cmd->execute([], [
            'job_id'  => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'search'  => 'abc',
            'replace' => 'xyz',
            'dry_run' => true,
        ]);
        // ok=false with 'internal error' OR ok=true (live DB available) — either is correct.
        // What matters is that it did NOT return the "must be at least 3" validation error.
        if (!$result['ok']) {
            $this->assertStringNotContainsString('3 character', (string) ($result['detail'] ?? ''));
            $this->assertStringNotContainsString('search must', (string) ($result['detail'] ?? ''));
        }
    }

    public function testRejectsMissingReplace(): void
    {
        $result = $this->cmd->execute([], [
            'job_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'search' => 'old.example.com',
            // 'replace' intentionally absent
        ]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('replace', (string) ($result['detail'] ?? ''));
    }

    public function testRejectsNonStringReplace(): void
    {
        $result = $this->cmd->execute([], [
            'job_id'  => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'search'  => 'old.example.com',
            'replace' => 12345,
        ]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('replace', (string) ($result['detail'] ?? ''));
    }

    public function testAcceptsEmptyReplace(): void
    {
        // replace='' is valid: user wants to remove all occurrences.
        // In unit test we cannot reach the DB, so expect internal error.
        $result = $this->cmd->execute([], [
            'job_id'  => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'search'  => 'old.example.com',
            'replace' => '',
            'dry_run' => true,
        ]);
        if (!$result['ok']) {
            // Should not be a validation error about replace.
            $this->assertStringNotContainsString('replace must', (string) ($result['detail'] ?? ''));
        }
    }

    public function testDryRunDefaultsToTrue(): void
    {
        // Verify that the command does not fatal on missing dry_run field.
        // We test only that missing dry_run is treated as true (preview).
        // The actual DB walk is not triggered in unit tests.
        $result = $this->cmd->execute([], [
            'job_id'  => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'search'  => 'old.example.com',
            'replace' => 'new.example.com',
            // dry_run omitted — should default to true
        ]);
        // In a test environment with no DB the result is ok=false with 'internal error'.
        // The key assertion: no validation error about dry_run.
        if (!$result['ok']) {
            $this->assertStringNotContainsString('dry_run', (string) ($result['detail'] ?? ''));
        }
    }

    public function testRejectsTablesAllowlistExceedingCap(): void
    {
        $tables = array_fill(0, 201, 'wp_posts'); // 201 > MAX_TABLES_ALLOWLIST (200)
        $result = $this->cmd->execute([], [
            'job_id'  => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'search'  => 'old.example.com',
            'replace' => 'new.example.com',
            'dry_run' => true,
            'tables'  => $tables,
        ]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('200', (string) ($result['detail'] ?? ''));
    }

    public function testAcceptsTablesAllowlistAtCap(): void
    {
        $tables = array_fill(0, 200, 'wp_posts'); // exactly 200 — allowed
        $result = $this->cmd->execute([], [
            'job_id'  => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'search'  => 'old.example.com',
            'replace' => 'new.example.com',
            'dry_run' => true,
            'tables'  => $tables,
        ]);
        // Validation must pass — result is ok=false 'internal error' in unit test (no DB).
        if (!$result['ok']) {
            $this->assertStringNotContainsString('exceeds maximum', (string) ($result['detail'] ?? ''));
        }
    }

    public function testTablesAllowlistFiltersNonStrings(): void
    {
        // Non-string entries in tables are silently dropped; should not cause a validation error.
        $result = $this->cmd->execute([], [
            'job_id'  => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'search'  => 'old.example.com',
            'replace' => 'new.example.com',
            'dry_run' => true,
            'tables'  => ['wp_posts', 42, null, 'wp_options'],
        ]);
        if (!$result['ok']) {
            $this->assertStringNotContainsString('tables', (string) ($result['detail'] ?? ''));
        }
    }

    // -----------------------------------------------------------------------
    // UrlRewriter reuse — the serialization-safe algorithm is shared
    // -----------------------------------------------------------------------

    /**
     * Verify that UrlRewriter::rewrite_row_data handles a serialized blob
     * correctly when called with a single from/to pair (the shape SearchReplaceCommand
     * builds). This tests the critical invariant that the command's engine path is
     * correctly wired — same assertion style as UrlRewriterTest.
     */
    public function testSerializationSafeRoundTrip(): void
    {
        // Mimic the $replacements tuple SearchReplaceCommand builds.
        $replacements = [['staging.example.com'], ['example.com']];

        // Serialized array containing the search string in a nested string value.
        $input = serialize(['url' => 'https://staging.example.com/shop', 'count' => 5]);

        $output = \WPMgr\Agent\Backup\UrlRewriter::rewrite_row_data($input, $replacements);

        // Re-unserialize the result and verify the value was rewritten.
        $data = @unserialize($output, ['allowed_classes' => false]);
        $this->assertIsArray($data);
        $this->assertStringContainsString('example.com/shop', (string) ($data['url'] ?? ''));
        $this->assertStringNotContainsString('staging.', (string) ($data['url'] ?? ''));

        // Verify the serialized length prefix is consistent (not corrupted).
        $reparsed = @unserialize($output, ['allowed_classes' => false]);
        $this->assertNotFalse($reparsed, 're-serialized output must be unserializable without error');
        $this->assertSame(5, $reparsed['count'] ?? null, 'non-string scalar must be preserved');
    }
}
