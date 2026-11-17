<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Tests\Unit;

use BiotimePortal\Component\Biotime\Administrator\Api\ZkbException;
use BiotimePortal\Component\Biotime\Administrator\Database\DbConfig;
use BiotimePortal\Component\Biotime\Administrator\Database\DbConnector;
use BiotimePortal\Component\Biotime\Administrator\Database\DbEmployeeDirectory;
use BiotimePortal\Component\Biotime\Administrator\Database\DbException;
use BiotimePortal\Component\Biotime\Administrator\Identity\IdentityResolver;
use BiotimePortal\Component\Biotime\Administrator\Identity\MappingManager;
use BiotimePortal\Component\Biotime\Administrator\Identity\UserMapping;
use BiotimePortal\Component\Biotime\Administrator\Security\KeyVault;
use BiotimePortal\Component\Biotime\Administrator\Settings\Settings;
use BiotimePortal\Component\Biotime\Administrator\Settings\SettingsValidator;
use BiotimePortal\Component\Biotime\Administrator\Settings\ValidationFailed;
use BiotimePortal\Component\Biotime\Tests\Support\ArrayLogger;
use BiotimePortal\Component\Biotime\Tests\Support\CatalogueQuery;
use BiotimePortal\Component\Biotime\Tests\Support\FixedFieldReader;
use BiotimePortal\Component\Biotime\Tests\Support\MemoryAudit;
use BiotimePortal\Component\Biotime\Tests\Support\MemoryUserMapRepository;
use PHPUnit\Framework\TestCase;

/**
 * Employees from ZKBioTime's database (0.6.1): users are matched, re-checked and mapped without
 * the API, and the API settings may be left empty.
 */
final class DbEmployeeDirectoryTest extends TestCase
{
    private const ALICE = ['id' => 11, 'emp_code' => 'E1', 'first_name' => 'Alice', 'last_name' => 'A', 'email' => 'Alice@Example.com'];
    private const BOB   = ['id' => 12, 'emp_code' => 'E2', 'first_name' => 'Bob', 'last_name' => 'B', 'email' => 'bob@example.com'];

    private function directory(array $employees): DbEmployeeDirectory
    {
        $q = new CatalogueQuery('pgsql', [
            'WHERE emp_code = :code' => static fn (array $p): array => array_values(array_filter($employees, static fn (array $e): bool => $e['emp_code'] === $p[':code'])),
            'WHERE id = :id'         => static fn (array $p): array => array_values(array_filter($employees, static fn (array $e): bool => $e['id'] === $p[':id'])),
            'FROM personnel_employee' => $employees,
        ]);

        return new DbEmployeeDirectory(static fn () => $q);
    }

    private function resolver(MemoryUserMapRepository $repo, DbEmployeeDirectory $dir, ?FixedFieldReader $fields = null): IdentityResolver
    {
        return new IdentityResolver($repo, static fn () => $dir, new MemoryAudit(), new ArrayLogger(), true, static fn () => new \DateTimeImmutable('2026-11-15 10:00:00', new \DateTimeZone('UTC')), $fields ?? new FixedFieldReader());
    }

    public function testUsersAreMatchedByEmailOrCodeFromTheDatabase(): void
    {
        $repo = new MemoryUserMapRepository();
        $dir  = $this->directory([self::ALICE, self::BOB]);

        $byEmail = $this->resolver($repo, $dir)->resolve(5, 'alice@example.COM');
        self::assertSame(['11', 'E1'], [$byEmail->employeeId(), $byEmail->empCode()], 'same ids as the API');

        $byCode = $this->resolver($repo, $dir, new FixedFieldReader([6 => 'E2']))->resolve(6, 'other@example.com');
        self::assertSame('12', $byCode->employeeId());

        self::assertFalse($this->resolver(new MemoryUserMapRepository(), $dir)->resolve(7, 'nobody@example.com')->isMatched());
    }

    public function testDailyCheckSuspendsAnEmployeeGoneFromTheDatabase(): void
    {
        $repo = new MemoryUserMapRepository();
        $repo->save(new UserMapping(5, '11', 'E1', 'alice@example.com', UserMapping::STATUS_ACTIVE, UserMapping::SOURCE_AUTO, new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-01')));

        self::assertFalse($this->resolver($repo, $this->directory([self::BOB]))->resolve(5, 'alice@example.com')->isMatched());
        self::assertSame(UserMapping::STATUS_SUSPENDED, $repo->find(5)->status);
    }

    public function testAnUnreachableDatabaseKeepsAVerifiedMapping(): void
    {
        $repo = new MemoryUserMapRepository();
        $repo->save(new UserMapping(5, '11', 'E1', 'alice@example.com', UserMapping::STATUS_ACTIVE, UserMapping::SOURCE_AUTO, new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-01')));
        $down = new DbEmployeeDirectory(static fn () => throw new DbException(DbException::UNREACHABLE, 'down'));

        self::assertTrue($this->resolver($repo, $down)->resolve(5, 'alice@example.com')->isMatched());

        try {
            $down->employeesByCode('E1');
            self::fail('no exception');
        } catch (ZkbException $e) {
            self::assertSame(ZkbException::NETWORK, $e->getKind(), 'reported as the API\'s kinds');
        }
    }

    public function testAdministratorsMapByCodeFromTheDatabase(): void
    {
        $repo     = new MemoryUserMapRepository();
        $employee = (new MappingManager($repo, fn () => $this->directory([self::ALICE]), new MemoryAudit()))->assign(5, 'alice@example.com', 'E1', 1);

        self::assertSame('Alice', $employee['first_name']);
        self::assertSame('11', $repo->find(5)->employeeId);
    }

    public function testTheApiMayBeLeftEmptyOnlyWhenEverythingIsReadFromTheDatabase(): void
    {
        $vault     = new KeyVault('a-joomla-configuration-secret-value');
        $validator = new SettingsValidator(static fn (string $p, string $c): string => $vault->encrypt($p, $c), static fn (): bool => true);
        $input     = [
            'server_url' => '', 'api_username' => '', 'api_password' => '', 'auth_method' => 'jwt',
            'connect_timeout' => '5', 'timeout' => '30', 'page_size' => '100', 'max_pages' => '50', 'cache_minutes' => '5',
            'db_engine' => 'pgsql', 'db_server' => 'zkb.example.com:7496', 'db_name' => 'biotime', 'db_user' => 'joomla_portal',
            'db_password' => 'pw', 'db_ssl' => 'require', 'data_source' => 'database',
        ];

        $values = $validator->validate($input, new Settings());
        self::assertSame(['', 'database'], [$values['server_url'], $values['data_source']]);
        self::assertTrue((new Settings($values))->readsDatabase());

        try {
            $validator->validate(['data_source' => 'api'] + $input, new Settings());
            self::fail('API without a server accepted');
        } catch (ValidationFailed $e) {
            self::assertContains('COM_BIOTIME_SETTINGS_ERR_URL_REQUIRED', $e->getKeys());
        }
    }

    /** Against the PostgreSQL fixture (tests/Support/sql/zkbiotime-pgsql.sql), when BIOTIME_TEST_PG is set. */
    public function testRealPostgres(): void
    {
        $env = getenv('BIOTIME_TEST_PG');

        if (!\is_string($env) || $env === '' || !\extension_loaded('pdo_pgsql')) {
            self::markTestSkipped('BIOTIME_TEST_PG not set');
        }

        [$host, $port, $db, $user, $password] = explode(':', $env, 5);
        $config = new DbConfig('pgsql', $host, (int) $port, $db, $user, $password, DbConfig::SSL_REQUIRE);
        $dir    = new DbEmployeeDirectory(static fn () => (new DbConnector())->connect($config));

        self::assertSame(['1', 'E1', 'a@example.com'], [$dir->employeesByCode('E1')[0]['id'], $dir->employee(1)['emp_code'], $dir->employee('1')['email']]);
        self::assertCount(2, $dir->allEmployees()->items);
        $this->expectException(ZkbException::class);
        $dir->employee(999);
    }
}
