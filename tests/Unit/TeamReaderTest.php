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
 * Manager Dashboard (0.11.0): a manager's department, the departments below it and their employees.
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
}
