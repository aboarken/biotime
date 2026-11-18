<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Database;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects


/**
 * Manager Dashboard (1.0.0): the departments and employees a manager may see, read from
 * ZKBioTime's database (read-only). A manager sees the departments ZKBioTime names them Department
 * Manager of (personnel_department.dept_manager_id, 1.0.2) and every department below those
 * (parent_dept_id), and the employees in them who have not resigned (personnel_resign) by the
 * given date. Names only: no other personal data is read.
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
     * The departments this employee manages in ZKBioTime (personnel_department.dept_manager_id,
     * the department's "Department Manager"), by id; [] when none, or when this ZKBioTime version
     * has no department managers.
     *
     * @return list<int>
     *
     * @throws DbException when the database cannot be reached
     */
    public function managedDepartments(int $employeeId): array
    {
        try {
            $rows = $this->q()->rows('SELECT id FROM personnel_department WHERE dept_manager_id = :emp ORDER BY id', [':emp' => $employeeId]);
        } catch (DbException $e) {
            if ($e->getKind() !== DbException::QUERY) {
                throw $e;
            }

            return [];
        }

        return array_values(array_unique(array_map(static fn (array $r): int => (int) $r['id'], $rows)));
    }

    /**
     * What the employee's own ZKBioTime user account may see (1.0.8): ZKBioTime has employees and,
     * separately, users (auth_user) whose data permissions list departments (auth_user_auth_dept).
     * The employee's users are those linked to the employee code (emp_pin), whose username is the
     * employee code, or with the employee's e-mail address (or one of $emails); active and not
     * deleted. A superuser or a user who may manage all departments (can_manage_all_dept) sees
     * every department. Only these columns of auth_user are read: id, username, email, emp_pin,
     * is_active, is_superuser, can_manage_all_dept, del_flag (never the password).
     *
     * @param  list<string> $emails further e-mail addresses of the same person (e.g. Joomla's)
     * @return array{readable: bool, superuser: bool, departments: list<int>, users: int} readable
     *         false when ZKBioTime's users cannot be read (not granted, or another ZKBioTime
     *         version); users: how many active ZKBioTime users matched (1.0.9, for the log)
     *
     * @throws DbException when the database cannot be reached
     */
    public function userScope(string $empCode, array $emails = []): array
    {
        $none = ['readable' => false, 'superuser' => false, 'departments' => [], 'users' => 0];

        try {
            foreach ($this->q()->rows('SELECT email FROM personnel_employee WHERE emp_code = :code', [':code' => $empCode]) as $r) {
                $emails[] = (string) $r['email'];
            }

            $emails = array_values(array_unique(array_filter(array_map(static fn (string $m): string => strtolower(trim($m)), $emails), static fn (string $m): bool => str_contains($m, '@'))));
            $bind   = [':code' => strtolower($empCode)];

            foreach ($emails as $n => $mail) {
                $bind[':m' . $n] = $mail;
            }

            // Optional columns of this ZKBioTime version (9.5 has them all): emp_pin, the user's
            // employee ID; can_manage_all_dept, "manage all departments"; del_flag, deleted users.
            $has = array_flip(array_map('strval', array_column($this->q()->rows(
                "SELECT column_name FROM information_schema.columns WHERE table_name = 'auth_user'"
            ), 'column_name')));
            $all = isset($has['can_manage_all_dept']) ? 'is_superuser OR COALESCE(can_manage_all_dept, false)' : 'is_superuser';
            $who = ['LOWER(username) = :code'];

            if (isset($has['emp_pin'])) {
                $who[] = 'LOWER(emp_pin) = :code';
            }

            if ($emails !== []) {
                $who[] = 'LOWER(email) IN (' . implode(', ', array_keys(\array_slice($bind, 1, null, true))) . ')';
            }

            $users = $this->q()->rows(
                "SELECT id, ({$all}) AS is_superuser FROM auth_user
                  WHERE is_active" . (isset($has['del_flag']) ? ' AND COALESCE(del_flag, 0) = 0' : '') . '
                    AND (' . implode(' OR ', $who) . ')',
                $bind
            );

            // The link table's columns as Django named them (9.5: myuser_id, department_id).
            $columns = array_column($this->q()->rows(
                "SELECT column_name FROM information_schema.columns WHERE table_name = 'auth_user_auth_dept'"
            ), 'column_name');
        } catch (DbException $e) {
            if ($e->getKind() !== DbException::QUERY) {
                throw $e;
            }

            return $none;
        }

        $userCol = null;
        $deptCol = null;

        foreach ($columns as $c) {
            $c = (string) $c;

            if (preg_match('/^[a-z_]*user_id$/', $c)) {
                $userCol ??= $c;
            } elseif (preg_match('/^[a-z_]*(department|dept)_id$/', $c)) {
                $deptCol ??= $c;
            }
        }

        if ($users === []) {
            return ['readable' => true, 'superuser' => false, 'departments' => [], 'users' => 0];
        }

        if (array_filter($users, static fn (array $u): bool => \in_array($u['is_superuser'], [true, 't', 1, '1'], true)) !== []) {
            return ['readable' => true, 'superuser' => true, 'departments' => [], 'users' => \count($users)];
        }

        if ($userCol === null || $deptCol === null) {
            return ['users' => \count($users)] + $none;
        }

        // Column names come from the database catalogue and are checked above; ids are integers.
        $ids = implode(',', array_map(static fn (array $u): int => (int) $u['id'], $users));

        try {
            $rows = $this->q()->rows("SELECT {$deptCol} AS d FROM auth_user_auth_dept WHERE {$userCol} IN ({$ids})");
        } catch (DbException $e) {
            if ($e->getKind() !== DbException::QUERY) {
                throw $e;
            }

            return ['users' => \count($users)] + $none;
        }

        return ['readable' => true, 'superuser' => false, 'departments' => array_values(array_unique(array_map(static fn (array $r): int => (int) $r['d'], $rows))), 'users' => \count($users)];
    }

    /**
     * These departments only (no others below them), in tree order where they are below one
     * another: what a ZKBioTime user's data permissions list (1.0.8).
     *
     * @param  list<int> $ids
     * @return list<array{id: int, name: string, parent: ?int, depth: int}>
     *
     * @throws DbException
     */
    public function departmentsByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            return [];
        }

        try {
            $rows = $this->q()->rows('SELECT id, dept_name, parent_dept_id FROM personnel_department WHERE id IN (' . implode(',', $ids) . ')');
        } catch (DbException $e) {
            if ($e->getKind() !== DbException::QUERY) {
                throw $e;
            }

            $rows = array_map(static fn (array $r): array => $r + ['parent_dept_id' => null], $this->q()->rows('SELECT id, dept_name FROM personnel_department WHERE id IN (' . implode(',', $ids) . ')'));
        }

        $in       = array_flip(array_map(static fn (array $r): int => (int) $r['id'], $rows));
        $byParent = [];

        foreach ($rows as $r) {
            $id     = (int) $r['id'];
            $parent = $r['parent_dept_id'] !== null && (int) $r['parent_dept_id'] !== $id && isset($in[(int) $r['parent_dept_id']]) ? (int) $r['parent_dept_id'] : 0;
            $byParent[$parent][] = ['id' => $id, 'name' => self::text($r['dept_name']) ?? ('#' . $id), 'parent' => $parent === 0 ? null : $parent];
        }

        $out  = [];
        $seen = [];
        $walk = static function (int $parent, int $depth) use (&$walk, &$out, &$seen, $byParent): void {
            $children = $byParent[$parent] ?? [];
            usort($children, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

            foreach ($children as $c) {
                if (isset($seen[$c['id']]) || $depth > self::MAX_DEPTH) {
                    continue;
                }

                $seen[$c['id']] = true;
                $out[]          = $c + ['depth' => $depth];
                $walk($c['id'], $depth + 1);
            }
        };
        $walk(0, 0);

        return $out;
    }

    /**
     * The top departments (no parent, or a parent that does not exist), by id: with the
     * departments below them, every department (a ZKBioTime superuser's, or a user's who may manage
     * all departments).
     *
     * @return list<int>
     *
     * @throws DbException
     */
    public function topDepartments(): array
    {
        try {
            $rows = $this->q()->rows(
                'SELECT d.id FROM personnel_department d
                  WHERE d.parent_dept_id IS NULL OR d.parent_dept_id = d.id
                     OR NOT EXISTS (SELECT 1 FROM personnel_department p WHERE p.id = d.parent_dept_id)
                  ORDER BY d.id'
            );
        } catch (DbException $e) {
            if ($e->getKind() !== DbException::QUERY) {
                throw $e;
            }

            // Without parent_dept_id: every department is a top department.
            $rows = $this->q()->rows('SELECT id FROM personnel_department ORDER BY id');
        }

        return array_values(array_unique(array_map(static fn (array $r): int => (int) $r['id'], $rows)));
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
