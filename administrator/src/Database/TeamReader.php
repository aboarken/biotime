<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Database;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects


/**
 * Manager Dashboard (0.11.0): the departments and employees a manager may see, read from
 * ZKBioTime's database (read-only). A manager sees their own department and every department
 * below it (personnel_department.parent_dept_id), and the employees in them who have not resigned
 * (personnel_resign) by the given date. Names only: no other personal data is read.
 */
final class TeamReader
{
    /** Safety maxima: departments below one department, employees shown. */
    public const MAX_DEPTH     = 20;
    public const MAX_EMPLOYEES = 2000;

    private ?Query $query = null;

    /**
     * @param \Closure(): Query $connect opens the connection when first needed
     */
    public function __construct(private readonly \Closure $connect)
    {
    }

    /**
     * The department $rootId and every department below it, as a tree in display order (each
     * department followed by its sub-departments, by name).
     *
     * @return list<array{id: int, name: string, parent: ?int, depth: int}>
     *
     * @throws DbException
     */
    public function departments(int $rootId): array
    {
        try {
            $rows = $this->q()->rows(
                'WITH RECURSIVE tree AS (
                    SELECT id, dept_name, parent_dept_id, 0 AS depth FROM personnel_department WHERE id = :root
                    UNION ALL
                    SELECT d.id, d.dept_name, d.parent_dept_id, t.depth + 1
                      FROM personnel_department d JOIN tree t ON d.parent_dept_id = t.id
                     WHERE t.depth < ' . self::MAX_DEPTH . ' AND d.id <> d.parent_dept_id
                )
                SELECT id, dept_name, parent_dept_id, depth FROM tree',
                [':root' => $rootId]
            );
        } catch (DbException $e) {
            // Without parent_dept_id (another ZKBioTime version): the department alone.
            if ($e->getKind() === DbException::UNREACHABLE) {
                throw $e;
            }

            $rows = $this->q()->rows('SELECT id, dept_name, NULL AS parent_dept_id, 0 AS depth FROM personnel_department WHERE id = :root', [':root' => $rootId]);
        }

        $byParent = [];
        $seen     = [];

        foreach ($rows as $r) {
            $id = (int) $r['id'];

            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $parent    = $id === $rootId || $r['parent_dept_id'] === null ? null : (int) $r['parent_dept_id'];
            $byParent[$parent ?? 0][] = ['id' => $id, 'name' => self::text($r['dept_name']) ?? ('#' . $id), 'parent' => $parent];
        }

        $out  = [];
        $walk = static function (int $parent, int $depth) use (&$walk, &$out, $byParent): void {
            $children = $byParent[$parent] ?? [];
            usort($children, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

            foreach ($children as $c) {
                if ($depth > self::MAX_DEPTH) {
                    return;
                }

                $out[] = $c + ['depth' => $depth];

                if ($c['id'] !== $parent) {
                    $walk($c['id'], $depth + 1);
                }
            }
        };
        $walk(0, 0);

        return $out;
    }

    /**
     * The employees of these departments who have not resigned by $date, by department and name.
     *
     * @param  list<int> $departmentIds
     * @return list<array{id: int, emp_code: string, name: string, department_id: ?int, department: ?string, position: ?string}>
     *
     * @throws DbException
     */
    public function employees(array $departmentIds, string $date): array
    {
        $ids = array_values(array_unique(array_map('intval', $departmentIds)));

        if ($ids === [] || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return [];
        }

        // Integers only, so they are written into the statement; everything else is bound.
        $rows = $this->q()->rows(
            'SELECT e.id, e.emp_code, e.first_name, e.last_name, e.department_id, d.dept_name, p.position_name
               FROM personnel_employee e
               LEFT JOIN personnel_department d ON d.id = e.department_id
               LEFT JOIN personnel_position p ON p.id = e.position_id
              WHERE e.department_id IN (' . implode(',', $ids) . ')
                AND NOT EXISTS (SELECT 1 FROM personnel_resign r WHERE r.employee_id = e.id AND r.resign_date <= :date)
              ORDER BY d.dept_name, e.first_name, e.last_name, e.emp_code
              LIMIT ' . self::MAX_EMPLOYEES,
            [':date' => $date]
        );

        $out = [];

        foreach ($rows as $r) {
            $code = self::text($r['emp_code']);

            if ($code === null) {
                continue;
            }

            $out[] = [
                'id'            => (int) $r['id'],
                'emp_code'      => $code,
                'name'          => self::text(trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''))) ?? $code,
                'department_id' => $r['department_id'] !== null ? (int) $r['department_id'] : null,
                'department'    => self::text($r['dept_name'] ?? null),
                'position'      => self::text($r['position_name'] ?? null),
            ];
        }

        return $out;
    }

    private function q(): Query
    {
        return $this->query ??= ($this->connect)();
    }

    private static function text(mixed $value): ?string
    {
        if (!\is_scalar($value)) {
            return null;
        }

        $value = trim(strip_tags((string) $value));

        return $value === '' ? null : mb_substr($value, 0, 100);
    }
}
