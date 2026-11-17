<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Tests\Unit;

use BiotimePortal\Component\Biotime\Administrator\Database\DbConfig;
use BiotimePortal\Component\Biotime\Administrator\Database\DbConnector;
use BiotimePortal\Component\Biotime\Administrator\Database\DbException;
use BiotimePortal\Component\Biotime\Administrator\Database\Query;
use BiotimePortal\Component\Biotime\Administrator\Database\SchemaInspector;
use PHPUnit\Framework\TestCase;

/** A catalogue answered from a list of [SQL fragment => rows]; anything else fails the test. */
final class CatalogueQuery extends Query
{
    /** @var list<string> */
    public array $sent = [];

    /** @param array<string, list<array<string, mixed>>|\Closure> $answers */
    public function __construct(string $engine, private readonly array $answers)
    {
        parent::__construct(null, $engine);
    }

    public function rows(string $sql, array $params = []): array
    {
        $this->sent[] = $sql;

        foreach ($this->answers as $fragment => $rows) {
            if (str_contains($sql, $fragment)) {
                return $rows instanceof \Closure ? $rows($params) : $rows;
            }
        }

        throw new \LogicException('Unexpected query: ' . $sql);
    }
}

final class DatabaseTest extends TestCase
{
    private function pgsql(array $override = []): DbConfig
    {
        $v = $override + ['host' => 'db.example.com', 'port' => 7496, 'name' => 'biotime', 'user' => 'joomla_portal', 'ssl' => 'verify', 'ca' => '/etc/ssl/zkb.pem'];

        return new DbConfig('pgsql', $v['host'], $v['port'], $v['name'], $v['user'], 'pw', $v['ssl'], $v['ca'], 5, 30);
    }

    public function testPostgresConnectionStringIsReadOnlyEncryptedAndQuoted(): void
    {
        $dsn = $this->pgsql()->dsn();

        self::assertStringStartsWith('pgsql:', $dsn);
        self::assertStringContainsString("host='db.example.com';port=7496;dbname='biotime'", $dsn);
        self::assertStringContainsString('sslmode=verify-full', $dsn);
        self::assertStringContainsString("sslrootcert='/etc/ssl/zkb.pem'", $dsn);
        self::assertStringContainsString('default_transaction_read_only=on', $dsn);
        self::assertStringContainsString('statement_timeout=30000', $dsn);
        self::assertStringNotContainsString('pw', str_replace('pgsql', '', $dsn), 'no password');
        self::assertStringContainsString('sslmode=require', $this->pgsql(['ssl' => 'require', 'ca' => ''])->dsn());
        self::assertStringContainsString('sslmode=disable', $this->pgsql(['ssl' => 'off', 'ca' => ''])->dsn());
        self::assertStringNotContainsString('sslrootcert', $this->pgsql(['ssl' => 'off'])->dsn());
        self::assertStringNotContainsString('pw', print_r($this->pgsql(), true), 'debug output hides the password');
    }

    public function testSqlServerConnectionString(): void
    {
        $dsn = (new DbConfig('sqlsrv', '10.0.0.5', 1433, 'biotime', 'portal', 'pw', 'verify'))->dsn();

        self::assertSame('sqlsrv:Server=10.0.0.5,1433;Database=biotime;LoginTimeout=5;Encrypt=yes;TrustServerCertificate=no;ApplicationIntent=ReadOnly;APP=Joomla ZKBioTime portal', $dsn);
        self::assertStringContainsString('TrustServerCertificate=yes', (new DbConfig('sqlsrv', 'h', 1433, 'b', 'u', 'pw', 'require'))->dsn());
        self::assertStringContainsString('Encrypt=no', (new DbConfig('sqlsrv', 'h', 1433, 'b', 'u', 'pw', 'off'))->dsn());
    }

    public function testValuesThatCouldAddConnectionOptionsAreRefused(): void
    {
        $bad = [
            ['host' => 'db sslmode=disable'], ['host' => 'db;sslmode=disable'], ['port' => 0], ['name' => "b' sslmode='disable"],
            ['user' => 'u;x'], ['ssl' => 'none'], ['ca' => "/a.pem' sslmode='disable"], ['ca' => '/a;b.pem'], ['ca' => ''],
        ];

        foreach ($bad as $override) {
            try {
                $this->pgsql($override);
                self::fail('accepted ' . json_encode($override));
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        self::assertTrue(DbConfig::validHost('2001:db8::1'));
        self::assertSame(['db.example.com', 7496], DbConfig::parseAddress('DB.example.com', 7496));
        self::assertSame(['10.0.0.5', 15432], DbConfig::parseAddress('10.0.0.5:15432', 7496));
        self::assertSame(['2001:db8::1', 5432], DbConfig::parseAddress('[2001:db8::1]:5432', 7496));
        self::assertSame(['2001:db8::1', 7496], DbConfig::parseAddress('2001:db8::1', 7496));

        foreach (['db.example.com:0', 'db:x', 'postgres://db', 'db.example.com:7496/biotime', ''] as $bad) {
            try {
                DbConfig::parseAddress($bad, 7496);
                self::fail('accepted ' . $bad);
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        foreach ([['fw.example.com', 0], ['', 7496]] as [$ip, $port]) {
            try {
                new DbConfig('pgsql', 'db', 7496, 'b', 'u', 'pw', 'require', '', 5, 30, $ip, $port);
                self::fail('connect via accepted ' . $ip);
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertStringContainsString('Server=[2001:db8::1],1433', (new DbConfig('sqlsrv', '2001:db8::1', 1433, 'b', 'u', 'pw'))->dsn());
    }

    public function testDriverErrorsAreSortedAndCleaned(): void
    {
        $error = static fn (string $message, string $state = 'HY000'): DbException => DbException::fromDriver(
            (static function () use ($message, $state): \PDOException {
                $e            = new \PDOException($message);
                $e->errorInfo = [$state];

                return $e;
            })(),
            'connect'
        );

        self::assertSame(DbException::AUTH, $error('FATAL: password authentication failed for user "joomla_portal"', '08006')->getKind());
        self::assertSame(DbException::AUTH, $error('FATAL: no pg_hba.conf entry for host "1.2.3.4"', '08006')->getKind());
        self::assertSame(DbException::SSL, $error('server certificate for "localhost" does not match host name "db"', '08006')->getKind());
        self::assertSame(DbException::DATABASE, $error('FATAL: database "biotim" does not exist', '08006')->getKind());
        self::assertSame(DbException::UNREACHABLE, $error('could not connect to server: Connection refused', '08006')->getKind());
        self::assertSame(DbException::UNREACHABLE, $error('timeout expired', '08006')->getKind());
        self::assertSame(DbException::AUTH, $error("[Microsoft][ODBC Driver 18 for SQL Server][SQL Server]Login failed for user 'portal'.", '28000')->getKind());
        self::assertSame('connect failed: host=db password=… user=x', DbException::clean("connect failed:\n host=db password='s3cret' user=x"));
    }

    public function testMissingPhpExtensionIsReportedBeforeConnecting(): void
    {
        $connector = new DbConnector(static fn () => self::fail('must not connect'), static fn (string $ext): bool => false);

        try {
            $connector->connect($this->pgsql());
            self::fail('no exception');
        } catch (DbException $e) {
            self::assertSame([DbException::DRIVER_MISSING, 'pdo_pgsql'], [$e->getKind(), $e->getDetail()]);
        }
    }

    public function testOnlySingleSelectStatementsAreSent(): void
    {
        $q = new Query(null, 'pgsql');

        foreach (['DELETE FROM iclock_transaction', 'SELECT 1; DROP TABLE x', 'update x set y = 1'] as $sql) {
            try {
                $q->rows($sql);
                self::fail('sent: ' . $sql);
            } catch (\LogicException $e) {
                self::assertStringContainsString('Only single SELECT', $e->getMessage());
            }
        }
    }

    public function testInspectorReportsNamesAndPermissionsOnly(): void
    {
        $q = new CatalogueQuery('pgsql', [
            'SHOW server_version' => [['server_version' => '10.23']],
            'pg_stat_ssl'         => [['ssl' => true, 'version' => 'TLSv1.3']],
            'FROM pg_roles'       => [['rolsuper' => false, 'rolcreaterole' => false, 'rolcreatedb' => false, 'config' => 'default_transaction_read_only=on']],
            'has_database_privilege' => [['?column?' => true]],
            'FROM pg_class'       => [
                ['id' => 1, 'name' => 'att_leave', 'readable' => false, 'writable' => false],
                ['id' => 2, 'name' => 'auth_user', 'readable' => false, 'writable' => false],
                ['id' => 3, 'name' => 'iclock_transaction', 'readable' => true, 'writable' => false],
                ['id' => 4, 'name' => 'personnel_employee', 'readable' => true, 'writable' => false],
            ],
            'FROM pg_attribute'   => static fn (array $p): array => [['name' => 'col' . $p[':id'], 'type' => 'integer']],
        ]);

        $r = (new SchemaInspector())->inspect($q);

        self::assertSame(['10.23', true, 'TLSv1.3', false, true, true], [$r['version'], $r['ssl'], $r['ssl_detail'], $r['superuser'], $r['role_read_only'], $r['can_create']]);
        self::assertTrue($r['safe']);
        self::assertSame(['att_leave', 'iclock_transaction', 'personnel_employee'], array_column($r['tables'], 'name'));
        self::assertSame([['col1', 'integer']], $r['tables'][0]['columns']);
        self::assertSame(['auth_user'], $r['others'], 'other tables by name only, no columns');
        self::assertSame(['iclock_transaction' => true, 'iclock_terminal' => false], array_intersect_key(array_column($r['expected'], 'readable', 'name'), ['iclock_transaction' => 1, 'iclock_terminal' => 1]));

        foreach ($q->sent as $sql) {
            self::assertDoesNotMatchRegularExpression('/\bFROM\s+(iclock_|personnel_|att_|auth_)/i', $sql, 'never reads a ZKBioTime table');
        }
    }

    public function testInspectorFlagsAUserThatMayChangeData(): void
    {
        $q = new CatalogueQuery('pgsql', [
            'SHOW server_version'    => [['server_version' => '16']],
            'pg_stat_ssl'            => [['ssl' => false, 'version' => null]],
            'FROM pg_roles'          => [['rolsuper' => true, 'rolcreaterole' => true, 'rolcreatedb' => true, 'config' => '']],
            'has_database_privilege' => [['?column?' => true]],
            'FROM pg_class'          => [['id' => 3, 'name' => 'iclock_transaction', 'readable' => true, 'writable' => true]],
            'FROM pg_attribute'      => [],
        ]);

        $r = (new SchemaInspector())->inspect($q);

        self::assertFalse($r['safe']);
        self::assertSame(['iclock_transaction'], $r['writable']);
        self::assertFalse($r['ssl']);
    }

    public function testSqlServerCatalogue(): void
    {
        $q = new CatalogueQuery('sqlsrv', [
            'SERVERPROPERTY'      => [['v' => '15.0.2000.5']],
            'dm_exec_connections' => static fn (): array => throw new DbException(DbException::QUERY, 'denied'),
            'IS_SRVROLEMEMBER'    => [['sysadmin' => 0, 'owner' => 0, 'writer' => 0, 'ddl' => 0, 'can_create' => 0]],
            'FROM sys.objects'    => [['id' => 7, 'name' => 'iclock_transaction', 'readable' => 1, 'writable' => 0]],
            'FROM sys.columns'    => [['name' => 'emp_code', 'type' => 'nvarchar']],
        ]);

        $r = (new SchemaInspector())->inspect($q);

        self::assertSame(['15.0.2000.5', null, false, true, true], [$r['version'], $r['ssl'], $r['superuser'], $r['safe'], $r['hidden_names']]);
        self::assertSame([['emp_code', 'nvarchar']], $r['tables'][0]['columns']);
    }

    /**
     * Against a real PostgreSQL, when BIOTIME_TEST_PG is set to "host:port:database:user:password"
     * of a read-only user (docs/TESTING.md). Skipped otherwise.
     */
    public function testRealPostgres(): void
    {
        $env = getenv('BIOTIME_TEST_PG');

        if (!\is_string($env) || $env === '' || !\extension_loaded('pdo_pgsql')) {
            self::markTestSkipped('BIOTIME_TEST_PG not set');
        }

        [$host, $port, $db, $user, $password] = explode(':', $env, 5);
        $config = new DbConfig('pgsql', $host, (int) $port, $db, $user, $password, DbConfig::SSL_REQUIRE);
        $r      = (new SchemaInspector())->inspect((new DbConnector())->connect($config));

        self::assertTrue($r['ssl']);
        self::assertTrue($r['safe'], 'the read-only user can change nothing');
        self::assertContains('iclock_transaction', array_column($r['tables'], 'name'));

        $pdo = new \PDO($config->dsn(), $user, $password, $config->pdoOptions());
        $this->expectExceptionMessage('read-only transaction');
        $pdo->exec('CREATE TABLE biotime_portal_write_test (i int)');
    }
}
