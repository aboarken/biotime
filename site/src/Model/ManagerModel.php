<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Site\Model;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use BiotimePortal\Component\Biotime\Administrator\Attendance\DateRange;
use BiotimePortal\Component\Biotime\Administrator\Attendance\Day;
use BiotimePortal\Component\Biotime\Administrator\Database\DbException;
use BiotimePortal\Component\Biotime\Administrator\Identity\Identity;
use Joomla\CMS\Factory;

/**
 * Manager Dashboard (1.0.0). A manager (permission "View Manager Dashboard") sees the employees
 * of their own ZKBioTime department and of every department below it, nobody else. The department
 * and employee filters only narrow that list: a value outside it is ignored. The period is at most
 * MAX_DAYS days (today by default), because every employee in the list is read.
 */
final class ManagerModel extends PortalModel
{
    public const MAX_DAYS = 31;

    /** @var list<array{id: int, name: string, parent: ?int, depth: int}>|null */
    private ?array $departments = null;

    /** @var list<array{id: int, emp_code: string, name: string, department_id: ?int, department: ?string, position: ?string}>|null */
    private ?array $team = null;

    /**
     * The manager's department and the departments below it; [] when the manager has no
     * department in ZKBioTime.
     *
     * @return list<array{id: int, name: string, parent: ?int, depth: int}>
     *
     * @throws DbException
     */
    public function departments(): array
    {
        if ($this->departments !== null) {
            return $this->departments;
        }

        $own = $this->biotime()->getAttendanceReader()->employee($this->identity()->empCode());

        return $this->departments = $own === null || $own['department_id'] === null
            ? []
            : $this->biotime()->getTeamReader()->departments((int) $own['department_id']);
    }

    /**
     * Every employee the manager may see (who has not resigned by today).
     *
     * @return list<array{id: int, emp_code: string, name: string, department_id: ?int, department: ?string, position: ?string}>
     *
     * @throws DbException
     */
    public function team(): array
    {
        return $this->team ??= $this->biotime()->getTeamReader()->employees(array_column($this->departments(), 'id'), $this->attendance()->today());
    }

    /** The chosen department (?dept=), when it is one of the manager's; 0 for all. */
    public function selectedDepartment(): int
    {
        $id = Factory::getApplication()->getInput()->getInt('dept', 0);

        return \in_array($id, array_column($this->departments(), 'id'), true) ? $id : 0;
    }

    /** The chosen employee code (?emp=), when it is one of the manager's employees; '' for all. */
    public function selectedEmployee(): string
    {
        $code = trim(Factory::getApplication()->getInput()->getString('emp', ''));

        return \in_array($code, array_column($this->team(), 'emp_code'), true) ? $code : '';
    }

    /**
     * The department and those below it (for the department filter).
     *
     * @return list<int>
     */
    public function departmentAndBelow(int $id): array
    {
        $ids   = [$id];
        $added = true;

        while ($added) {
            $added = false;

            foreach ($this->departments() as $d) {
                if ($d['parent'] !== null && \in_array($d['parent'], $ids, true) && !\in_array($d['id'], $ids, true)) {
                    $ids[] = $d['id'];
                    $added = true;
                }
            }
        }

        return $ids;
    }

    /**
     * The employees shown: the manager's, narrowed by the department (and those below it) and the
     * employee filters.
     *
     * @return list<array{id: int, emp_code: string, name: string, department_id: ?int, department: ?string, position: ?string}>
     */
    public function shownEmployees(): array
    {
        $dept = $this->selectedDepartment();
        $emp  = $this->selectedEmployee();
        $ids  = $dept > 0 ? $this->departmentAndBelow($dept) : null;

        return array_values(array_filter($this->team(), static fn (array $e): bool => ($emp === '' || $e['emp_code'] === $emp)
            && ($ids === null || \in_array($e['department_id'], $ids, true))));
    }

    /**
     * From – To (?from=&to=), today when not given; at most MAX_DAYS days.
     *
     * @return array{0: string, 1: string}
     */
    public function managerRange(): array
    {
        $input = Factory::getApplication()->getInput();
        $today = $this->attendance()->today();
        $range = DateRange::of($input->getString('from', ''), $input->getString('to', ''), $today) ?? [$today, $today];
        $max   = (new \DateTimeImmutable($range[0]))->modify('+' . (self::MAX_DAYS - 1) . ' days')->format('Y-m-d');

        return [$range[0], min($range[1], $max)];
    }

    /**
     * Each shown employee's days in the range (up to today), read as that employee. The cache
     * entries belong to the manager's Joomla user and the employee's code.
     *
     * @param  list<array{id: int, emp_code: string, name: string}> $employees
     * @return array{days: array<string, list<Day>>, errors: array<string, string>}
     */
    public function teamDays(array $employees, string $from, string $to): array
    {
        $days   = [];
        $errors = [];
        $user   = $this->identity()->joomlaUserId;

        // Everyone's rows in a few queries instead of about fifteen per employee and month.
        try {
            $this->biotime()->getAttendanceReader()->preload(array_column($employees, 'emp_code'), new \DateTimeImmutable($from), new \DateTimeImmutable($to));
        } catch (DbException) {
            // Each employee is then read on its own, and its error shown as usual.
        }

        foreach ($employees as $e) {
            $period = $this->attendance()->range(Identity::matched($user, (string) $e['id'], $e['emp_code']), new \DateTimeImmutable($from), new \DateTimeImmutable($to));

            foreach ($period->errors as $type => $kind) {
                $errors[$type] = (string) $kind;
            }

            $days[$e['emp_code']] = array_values(array_filter($period->days, static fn (Day $d): bool => $d->date <= $period->today));
        }

        return ['days' => $days, 'errors' => $errors];
    }
}
