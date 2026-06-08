<?php
/**
 * MediaCleanScanAbortTest — verifies the scan hard-abort when wp_upload_dir()
 * returns an empty baseurl/basedir (the MUST-FIX data-safety path from #190).
 *
 * This is a standalone file (PSR-4 requires one class per file for autoloading)
 * and uses Brain\Monkey to stub WP global functions precisely.
 *
 * @package WPMgr\Agent\Tests
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WPMgr\Agent\Commands\MediaCleanCommand;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Commands\MediaCleanCommand
 */
final class MediaCleanScanAbortTest extends TestCase
{
    protected function set_up(): void
    {
        parent::set_up();
        Monkey\setUp();
    }

    protected function tear_down(): void
    {
        global $wpdb;
        $wpdb = null; // @phpstan-ignore-line
        Monkey\tearDown();
        parent::tear_down();
    }

    /**
     * When wp_upload_dir() returns an empty baseurl, the scan must abort
     * immediately with ok=false and a descriptive detail — no candidates emitted.
     *
     * This is the MUST-FIX from #190: without a resolvable uploads base URL,
     * extractFromHtml() silently no-ops all URL/path surfaces, causing every image
     * referenced only via a raw uploads URL to be falsely flagged as unused, which
     * leads directly to data loss when the caller proceeds to quarantine/delete.
     */
    public function testScanAbortsWhenUploadsBaseIsEmpty(): void
    {
        global $wpdb;

        // Stub wpdb: return 5 attachments so we get past the total===0 early-exit.
        // If the abort guard is missing, the command would proceed to build() and
        // then return candidates (falsely).
        $wpdb = new class { // @phpstan-ignore-line
            public string $posts = 'wp_posts';

            public function get_var(string $sql): string
            {
                return '5'; // non-zero total — do NOT short-circuit on this guard
            }

            public function prepare(string $sql, ...$args): string
            {
                return $sql;
            }
        };

        // Stub wp_upload_dir with empty baseurl — this is the trigger condition.
        Functions\when('wp_upload_dir')->justReturn([
            'baseurl' => '',
            'basedir' => '',
        ]);

        $cmd    = new MediaCleanCommand();
        $result = $cmd->execute([], ['action' => 'scan']);

        // Must abort: ok=false, detail mentions uploads being unresolved.
        $this->assertFalse(
            $result['ok'],
            'scan must abort (ok=false) when uploads base URL is empty.'
        );

        $detail = (string)($result['detail'] ?? '');
        $this->assertStringContainsString(
            'uploads',
            $detail,
            'abort detail must mention uploads directory.'
        );
        $this->assertStringContainsString(
            'aborted',
            $detail,
            'abort detail must mention that the scan was aborted.'
        );

        // No candidates must be emitted — false positives = data loss.
        $this->assertEmpty(
            $result['candidates'] ?? [],
            'No candidates must be returned when the scan is aborted.'
        );
    }
}
