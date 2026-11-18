<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Tests\Unit;

use BiotimePortal\Component\Biotime\Administrator\Database\DbException;
use BiotimePortal\Component\Biotime\Administrator\Database\TeamReader;
use BiotimePortal\Component\Biotime\Tests\Support\CatalogueQuery;
use PHPUnit\Framework\TestCase;

/**
 * Manager Dashboard (1.0.0): a manager's department, the departments below it and their employees.
 */
final class TeamReaderTest extends TestCase
{
    private ?CatalogueQuery $q = null;

    private function reader(array $answers): TeamReader
    {
        $q = $this->q = new CatalogueQuery('pgsql', $answers);

        return new TeamReader(static fn () => $q);
    }

    public function testTheDepartmentAndEverythingBelowItInTreeOrder(): void
    {
        $r = $this->reader(['WITH RECURSIVE tree' => [
            ['id' => 10, 'dept_name' => 'School', 'parent_dept_id' => 1, 'depth' => 0],
            ['id' => 12, 'dept_name' => 'Science', 'parent_dept_id' => 10, 'depth' => 1],
            ['id' => 11, 'dept_name' => 'Arts', 'parent_dept_id' => 10, 'depth' => 1],
            ['id' => 13, 'dept_name' => 'Physics', 'parent_dept_id' => 12, 'depth' => 2],
            ['id' => 13, 'dept_name' => 'Physics', 'parent_dept_id' => 12, 'depth' => 2],
        ]]);

        self::assertSame(
            [[10, 'School', null, 0], [11, 'Arts', 10, 1], [12, 'Science', 10, 1], [13, 'Physics', 12, 2]],
            array_map(static fn (array $d): array => [$d['id'], $d['name'], $d['parent'], $d['depth']], $r->departments(10)),
            'the root first (its own parent ignored), then each department followed by those below it, by name; no repeats'
        );
    }

    public function testWithoutDepartmentParentsOnlyTheOwnDepartment(): void
    {
        $r = $this->reader([
            'WITH RECURSIVE tree'    => static fn (): array => throw new DbException(DbException::QUERY, 'no column'),
            'FROM personnel_department WHERE id = :root' => [['id' => 10, 'dept_name' => 'School', 'parent_dept_id' => null, 'depth' => 0]],
        ]);

        self::assertSame([['id' => 10, 'name' => 'School', 'parent' => null, 'depth' => 0]], $r->departments(10));
    }

    public function testEmployeesOfTheDepartmentsWhoHaveNotResigned(): void
    {
        $params = null;
        $r      = $this->reader(['FROM personnel_employee e' => static function (array $p) use (&$params): array {
            $params = $p;

            return [
                ['id' => 1, 'emp_code' => 'E1', 'first_name' => 'Alice', 'last_name' => 'A', 'department_id' => 10, 'dept_name' => 'School', 'position_name' => 'Teacher'],
                ['id' => 2, 'emp_code' => '', 'first_name' => 'No', 'last_name' => 'Code', 'department_id' => 10, 'dept_name' => 'School', 'position_name' => null],
                ['id' => 3, 'emp_code' => 'E3', 'first_name' => '', 'last_name' => '', 'department_id' => 11, 'dept_name' => null, 'position_name' => null],
            ];
        }]);

        $list = $r->employees([10, 11, 10], '2026-11-18');

        self::assertSame([['E1', 'Alice A', 'School', 'Teacher'], ['E3', 'E3', null, null]], array_map(static fn (array $e): array => [$e['emp_code'], $e['name'], $e['department'], $e['position']], $list), 'no employee without a code; the code when there is no name');
        self::assertSame([':date' => '2026-11-18'], $params);
        self::assertStringContainsString('IN (10,11)', end($this->q->sent));
        self::assertStringContainsString('personnel_resign', end($this->q->sent));
        self::assertSame([], $r->employees([], '2026-11-18'));
        self::assertSame([], $r->employees([10], 'not a date'));
    }

    public function testDepartmentsAManagerManagesInZkbioTime(): void
    {
        $params = null;
        $r      = $this->reader(['WHERE dept_manager_id = :emp' => static function (array $p) use (&$params): array {
            $params = $p;

            return [['id' => 12], ['id' => 10], ['id' => 12]];
        }]);

        self::assertSame([12, 10], $r->managedDepartments(7));
        self::assertSame([':emp' => 7], $params);

        $old = $this->reader(['WHERE dept_manager_id = :emp' => static fn (): array => throw new DbException(DbException::QUERY, 'column does not exist')]);
        self::assertSame([], $old->managedDepartments(7), 'no department managers in this ZKBioTime: none');

        $down = $this->reader(['WHERE dept_manager_id = :emp' => static fn (): array => throw new DbException(DbException::UNREACHABLE, 'down')]);
        $this->expectException(DbException::class);
        $down->managedDepartments(7);
    }

    /** Against the PostgreSQL fixture (tests/Support/sql/zkbiotime-pgsql.sql), when BIOTIME_TEST_PG is set. */
    public function testRealPostgres(): void
    {
        $env = getenv('BIOTIME_TEST_PG');

        if (!\is_string($env) || $env === '' || !\extension_loaded('pdo_pgsql')) {
            self::markTestSkipped('BIOTIME_TEST_PG not set');
        }

        [$host, $port, $db, $user, $password] = explode(':', $env, 5);
        $config = new \BiotimePortal\Component\Biotime\Administrator\Database\DbConfig('pgsql', $host, (int) $port, $db, $user, $password, \BiotimePortal\Component\Biotime\Administrator\Database\DbConfig::SSL_REQUIRE);
        $team   = new TeamReader(static fn () => (new \BiotimePortal\Component\Biotime\Administrator\Database\DbConnector())->connect($config));

        self::assertSame([10], $team->managedDepartments(1), 'Alice is the Department Manager of IT');
        self::assertSame([], $team->managedDepartments(2), 'Bob manages nothing');
        self::assertSame([10], $team->topDepartments(), 'View all departments: IT is the only top department (HR is below it)');
        self::assertSame([[10, 0], [11, 1]], array_map(static fn (array $d): array => [$d['id'], $d['depth']], $team->departments(10)), 'IT and HR below it');
        self::assertSame([[11, 0]], array_map(static fn (array $d): array => [$d['id'], $d['depth']], $team->departments(11)), 'HR alone');
        self::assertSame(['E2', 'E1'], array_column($team->employees([10, 11], '2026-11-18'), 'emp_code'), 'by department name: Human Resources, then Information Technology');
        self::assertSame(['E1'], array_column($team->employees([10, 11], '2027-01-01'), 'emp_code'), 'Bob resigned on 1 January 2027');
    }
}
