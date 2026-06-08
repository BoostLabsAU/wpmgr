<?php
/**
 * Smoke tests for the DbDumper wrapper. These exercise the contract surface
 * only — class loads, constructor accepts the documented credential shape,
 * and an already-completed cursor short-circuits without touching the DB.
 *
 * The real "actually dump a database" tests are tagged @group integration so
 * they skip in CI environments without a live MySQL.
 *
 * @package WPMgr\Agent\Tests\Backup
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests\Backup;

use WPMgr\Agent\Backup\DbDumper;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Backup\DbDumper
 */
final class DbDumperTest extends TestCase
{
    /**
     * The class must be loadable via the classmap autoloader and live in the
     * documented namespace. Catches namespace typos, missing `final`, or a
     * stray `class-` prefix in the include path.
     */
    public function test_class_exists_in_expected_namespace(): void
    {
        $this->assertTrue(class_exists(DbDumper::class));
    }

    /**
     * Constructor accepts the documented credential shape without error.
     * No DB connection is opened here — the dumper is lazy: PDO only
     * connects inside dump() (via ifsnop's Mysqldump::start).
     */
    public function test_constructor_accepts_documented_db_shape(): void
    {
        $dumper = new DbDumper([
            'host'     => 'localhost:3306',
            'user'     => 'wp',
            'password' => 'wp',
            'name'     => 'wp_db',
            'prefix'   => 'wp_',
        ]);

        $this->assertInstanceOf(DbDumper::class, $dumper);
    }

    /**
     * Constructor accepts an optional opts array (future-proofing knob).
     */
    public function test_constructor_accepts_opts_array(): void
    {
        $dumper = new DbDumper(
            [
                'host'     => 'db.internal',
                'user'     => 'wp',
                'password' => 'wp',
                'name'     => 'wp_db',
                'prefix'   => 'wp_',
            ],
            ['row_batch' => 5000]
        );

        $this->assertInstanceOf(DbDumper::class, $dumper);
    }

    /**
     * An already-completed cursor (`done => true`) must short-circuit and
     * return the cursor unchanged without touching the DB. This is the
     * idempotent-replay contract the watchdog/TaskRunner relies on.
     */
    public function test_dump_with_done_cursor_is_idempotent_noop(): void
    {
        $dumper = new DbDumper([
            'host'     => 'unreachable.invalid',
            'user'     => 'nobody',
            'password' => 'nothing',
            'name'     => 'no_db',
            'prefix'   => 'wp_',
        ]);

        $cursor = [
            'done'        => true,
            'output_path' => '/tmp/already-done.sql.gz',
            'bytes'       => 12345,
        ];

        $progressCalls = 0;
        $result        = $dumper->dump(
            '/tmp/should-not-be-touched.sql.gz',
            $cursor,
            function () use (&$progressCalls): void {
                $progressCalls++;
            }
        );

        $this->assertSame($cursor, $result);
        $this->assertSame(0, $progressCalls, 'no-op replay must not emit progress');
    }

    // -----------------------------------------------------------------------
    // splitHostPort parser — unit coverage via reflection.
    //
    // The method is private (implementation detail), but the parsing contract
    // is load-bearing (wrong parse → silent connection failure on every backup
    // for affected hosts). Reflection is the right seam here: we test the
    // behaviour, not the internal mechanism, and a rename would break these
    // tests intentionally.
    // -----------------------------------------------------------------------

    /**
     * Build a DbDumper and call its private splitHostPort() via reflection.
     *
     * @return array{0:string,1:int|null,2:string|null}
     */
    private function parseHostPort(string $input): array
    {
        $dumper = new DbDumper([
            'host'     => $input,
            'user'     => 'u',
            'password' => 'p',
            'name'     => 'db',
            'prefix'   => 'wp_',
        ]);

        $ref = new \ReflectionMethod(DbDumper::class, 'splitHostPort');
        $ref->setAccessible(true);
        /** @var array{0:string,1:int|null,2:string|null} */
        return $ref->invoke($dumper, $input);
    }

    /**
     * Plain IPv4 address — no port, no socket.
     */
    public function test_split_plain_ipv4(): void
    {
        [$host, $port, $socket] = $this->parseHostPort('127.0.0.1');
        $this->assertSame('127.0.0.1', $host);
        $this->assertNull($port);
        $this->assertNull($socket);
    }

    /**
     * IPv4 with explicit port.
     */
    public function test_split_ipv4_with_port(): void
    {
        [$host, $port, $socket] = $this->parseHostPort('127.0.0.1:3307');
        $this->assertSame('127.0.0.1', $host);
        $this->assertSame(3307, $port);
        $this->assertNull($socket);
    }

    /**
     * Hostname with Unix socket path (no port).
     * This is the form used by managed hosts that run MySQL on a Unix socket
     * (e.g. GridPane sets DB_HOST = 'localhost:/var/run/mysqld/mysqld.sock').
     * The OLD code left host as the full string and socket as null, causing
     * every backup to fail with a connect error.
     *
     * THIS IS THE REGRESSION TEST — it MUST fail on the old implementation
     * and pass on the new one.
     */
    public function test_split_localhost_with_socket_gridpane_form(): void
    {
        [$host, $port, $socket] = $this->parseHostPort('localhost:/var/run/mysqld/mysqld.sock');
        $this->assertSame('localhost', $host, 'host must be extracted before the colon');
        $this->assertNull($port, 'no port in this form');
        $this->assertSame('/var/run/mysqld/mysqld.sock', $socket, 'socket path must be captured');
    }

    /**
     * Host + port + socket — all three components present.
     */
    public function test_split_host_port_and_socket(): void
    {
        [$host, $port, $socket] = $this->parseHostPort('db.example.com:3306:/tmp/mysql.sock');
        $this->assertSame('db.example.com', $host);
        $this->assertSame(3306, $port);
        $this->assertSame('/tmp/mysql.sock', $socket);
    }

    /**
     * Socket-only form (empty host segment before the colon).
     * DB_HOST = ':/tmp/mysql.sock' means "use the socket, no TCP host".
     */
    public function test_split_socket_only_empty_host(): void
    {
        [$host, $port, $socket] = $this->parseHostPort(':/tmp/mysql.sock');
        // rawHost is '' — callers may treat '' as 'localhost'; we just ensure
        // the socket is captured and port is null.
        $this->assertSame('', $host);
        $this->assertNull($port);
        $this->assertSame('/tmp/mysql.sock', $socket);
    }

    /**
     * Bracketed IPv6 address with port.
     * Defensive: the parser must not mangle the address.
     */
    public function test_split_bracketed_ipv6_with_port(): void
    {
        [$host, $port, $socket] = $this->parseHostPort('[::1]:3306');
        $this->assertSame('[::1]', $host);
        $this->assertSame(3306, $port);
        $this->assertNull($socket);
    }

    /**
     * Bracketed IPv6 with no port — address only.
     */
    public function test_split_bracketed_ipv6_no_port(): void
    {
        [$host, $port, $socket] = $this->parseHostPort('[::1]');
        $this->assertSame('[::1]', $host);
        $this->assertNull($port);
        $this->assertNull($socket);
    }

    /**
     * Plain hostname with no colon — no port, no socket.
     */
    public function test_split_plain_hostname(): void
    {
        [$host, $port, $socket] = $this->parseHostPort('db.internal');
        $this->assertSame('db.internal', $host);
        $this->assertNull($port);
        $this->assertNull($socket);
    }

    /**
     * Live MySQL integration smoke. Skipped unless WPMGR_TEST_MYSQL_DSN-style
     * env vars are present — keeps CI green on hosts without a DB.
     *
     * @group integration
     */
    public function test_dump_against_live_mysql_produces_sql_gz(): void
    {
        $host = getenv('WPMGR_TEST_MYSQL_HOST');
        $user = getenv('WPMGR_TEST_MYSQL_USER');
        $pass = getenv('WPMGR_TEST_MYSQL_PASSWORD');
        $name = getenv('WPMGR_TEST_MYSQL_DATABASE');

        if ($host === false || $user === false || $name === false) {
            $this->markTestSkipped('WPMGR_TEST_MYSQL_* env vars not set.');
        }

        $outPath = sys_get_temp_dir() . '/wpmgr-dbdumper-test-' . bin2hex(random_bytes(6)) . '.sql.gz';

        $dumper = new DbDumper([
            'host'     => (string) $host,
            'user'     => (string) $user,
            'password' => $pass === false ? '' : (string) $pass,
            'name'     => (string) $name,
            'prefix'   => 'wp_',
        ]);

        $progress = [];
        $result   = $dumper->dump(
            $outPath,
            [],
            function (string $phase, array $detail) use (&$progress): void {
                $progress[] = [$phase, $detail];
            }
        );

        $this->assertTrue($result['done'] ?? false);
        $this->assertSame($outPath, $result['output_path'] ?? null);
        $this->assertGreaterThan(0, $result['bytes'] ?? 0);
        $this->assertFileExists($outPath);
        $this->assertNotSame([], $progress, 'progress hook should have fired');

        @unlink($outPath);
    }
}
