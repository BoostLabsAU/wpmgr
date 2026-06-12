<?php
/**
 * ObjectCacheConfigHashParityTest — PHP side of the cross-language config hash contract.
 *
 * Loads the shared fixture apps/agent/tests/fixtures/object-cache-config-hash.json
 * and asserts that ObjectCacheConfig::computeHash() produces the expected hash
 * byte-for-byte for every case in the fixture.
 *
 * The Go CP side must assert against the same fixture file to prove that both
 * sides produce identical hashes for identical inputs. This contract ensures that
 * when the agent pushes a config and the CP compares the stored hash, they agree.
 *
 * Canonical format: SHA-256 of JSON-encoded config with:
 *   - password key excluded
 *   - keys sorted alphabetically (ksort in PHP, sort keys alphabetically in Go)
 *   - JSON booleans as true/false, integers as numbers
 *
 * @package WPMgr\Agent\Tests\ObjectCache
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests\ObjectCache;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WPMgr\Agent\ObjectCache\ObjectCacheConfig;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\ObjectCache\ObjectCacheConfig::computeHash
 */
final class ObjectCacheConfigHashParityTest extends TestCase
{
    private string $fixturePath;

    protected function set_up(): void
    {
        parent::set_up();
        Monkey\setUp();
        $this->fixturePath = dirname( __DIR__ ) . '/fixtures/object-cache-config-hash.json';
    }

    protected function tear_down(): void
    {
        Monkey\tearDown();
        parent::tear_down();
    }

    /**
     * The fixture file must exist.
     */
    public function test_fixture_file_exists(): void
    {
        $this->assertFileExists(
            $this->fixturePath,
            'Cross-language hash fixture must exist at tests/fixtures/object-cache-config-hash.json'
        );
    }

    /**
     * For every case in the fixture, computeHash() must produce the expected hash.
     */
    public function test_compute_hash_matches_fixture_for_all_cases(): void
    {
        $this->assertFileExists( $this->fixturePath );

        $json    = (string) file_get_contents( $this->fixturePath );
        $fixture = json_decode( $json, true );

        $this->assertIsArray( $fixture, 'Fixture file must contain valid JSON' );
        $this->assertArrayHasKey( 'cases', $fixture, 'Fixture must have a "cases" array' );
        $this->assertIsArray( $fixture['cases'], 'cases must be an array' );
        $this->assertNotEmpty( $fixture['cases'], 'cases must not be empty' );

        $cfg = new ObjectCacheConfig();

        foreach ( $fixture['cases'] as $i => $case ) {
            $label        = (string) ( $case['label'] ?? "case {$i}" );
            $params       = $case['params'] ?? [];
            $expectedHash = (string) ( $case['expected_hash'] ?? '' );

            $this->assertNotEmpty(
                $expectedHash,
                "Fixture case '{$label}' must have a non-empty expected_hash"
            );

            // Build the config array from the fixture params (already the full
            // config array, not raw wire params — skip fromParams to avoid
            // clamping/sanitizing fixture values).
            $actualHash = $cfg->computeHash( $params );

            $this->assertSame(
                $expectedHash,
                $actualHash,
                "computeHash() must match fixture for case '{$label}'. " .
                'If the hash changed, update the fixture AND the Go CP test.'
            );
        }
    }

    /**
     * debug_header_enabled must affect the hash (it is included in the canonical form).
     */
    public function test_debug_header_enabled_affects_hash(): void
    {
        $cfg = new ObjectCacheConfig();

        $configWithFlagOff = [
            'host'                 => '127.0.0.1',
            'port'                 => 6379,
            'debug_header_enabled' => false,
            'analytics_enabled'    => true,
        ];
        $configWithFlagOn = $configWithFlagOff;
        $configWithFlagOn['debug_header_enabled'] = true;

        $hashOff = $cfg->computeHash( $configWithFlagOff );
        $hashOn  = $cfg->computeHash( $configWithFlagOn );

        $this->assertNotSame(
            $hashOff,
            $hashOn,
            'debug_header_enabled participates in the hash: true vs false must produce different hashes'
        );
    }

    /**
     * password must always be excluded from the hash.
     */
    public function test_password_excluded_from_hash(): void
    {
        $cfg = new ObjectCacheConfig();

        $configA = [
            'host'                 => '127.0.0.1',
            'password'             => 'secret-one',
            'debug_header_enabled' => false,
        ];
        $configB = $configA;
        $configB['password'] = 'secret-two';

        $this->assertSame(
            $cfg->computeHash( $configA ),
            $cfg->computeHash( $configB ),
            'password key must be excluded from computeHash: same config different password must yield same hash'
        );
    }
}
