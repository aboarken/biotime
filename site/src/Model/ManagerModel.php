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
 * of the departments ZKBioTime lets them see (1.0.8): those of their own ZKBioTime user's data
 * permissions (every department for a ZKBioTime superuser), and those ZKBioTime names them
 * Department Manager of with the departments below them; nobody else. The department
 * and employee filters only narrow that list: a value outside it is ignored. The period is at most
 * MAX_DAYS days (today by default), because every employee in the list is read.
 */
final class ManagerModel extends PortalModel
{
    public const MAX_DAYS = 31;

    /** Cache key prefix of the manager's departments and team (1.0.10). */
    private const CACHE = 'mgr1:';

    /** @var list<array{id: int, name: string, parent: ?int, depth: int}>|null */
    private ?array $departments = null;

    /** @var list<array{id: int, emp_code: string, name: string, department_id: ?int, department: ?string, position: ?string}>|null */
    private ?array $team = null;

    /**
     * The departments the manager manages in ZKBioTime and those below them; [] when ZKBioTime
     * names them manager of none (they then see a message, not a team).
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

        // Kept for the cache time (1.0.10): the departments a manager may see change rarely, and
        // reading them takes several queries to the ZKBioTime server on every page.
        $cache  = $this->biotime()->getUserCache();
        $cached = $cache->get($this->identity(), self::CACHE . 'departments');

        if (isset($cached['d']) && \is_array($cached['d'])) {
            return $this->departments = $cached['d'];
        }

        $this->departments = $this->readDepartments();

        if ($this->departments !== []) {
            $cache->set($this->identity(), self::CACHE . 'departments', ['d' => $this->departments]);
        }

        return $this->departments;
    }

    /**
     * @return list<array{id: int, name: string, parent: ?int, depth: int}>
     *
     * @throws DbException
     */
    private function readDepartments(): array
    {
        $own = $this->biotime()->getAttendanceReader()->employee($this->identity()->empCode());

        if ($own === null) {
            $this->logger()->warning(\sprintf('Manager Dashboard, Joomla user %d: no department shown: the mapped employee code is not exactly one ZKBioTime employee', $this->identity()->joomlaUserId));

            return [];
        }

        // Only what ZKBioTime gives the manager (1.0.8):
        //  - the manager's own ZKBioTime user account (same e-mail or username = employee ID):
        //    a superuser sees every department, others the departments of their data
        //    permissions, exactly those;
        //  - departments ZKBioTime names them Department Manager of, with those below them.
        $team  = $this->biotime()->getTeamReader();
        $out   = [];
        $mail  = (string) (Factory::getApplication()->getIdentity()?->email ?? '');
        // Only ZKBioTime decides (1.0.8; the Joomla permission "View all departments" is gone).
        $scope = $team->userScope($this->identity()->empCode(), [$mail]);

        if (!$scope['readable']) {
            $this->logger()->warning('Manager Dashboard: ZKBioTime users (auth_user, auth_user_auth_dept) could not be read; only Department Managers are used. Grant SELECT (id, username, email, is_active, is_superuser) ON auth_user and SELECT ON auth_user_auth_dept to the portal\'s database user.');
        }

        $roots = $scope['superuser'] ? $team->topDepartments() : $team->managedDepartments((int) $own['id']);

        foreach ($roots as $root) {
            if (isset($out[$root])) {
                continue;
            }

            foreach ($team->departments($root) as $d) {
                $out[$d['id']] ??= $d;
            }
        }

        foreach ($team->departmentsByIds($scope['departments']) as $d) {
            $out[$d['id']] ??= $d;
        }

        // Why a manager sees nothing (1.0.9), in administrator/logs/com_biotime.php: no names.
        if ($out === []) {
            $this->logger()->warning(\sprintf(
                'Manager Dashboard, Joomla user %d: no department shown: %s',
                $this->identity()->joomlaUserId,
                match (true) {
                    !$scope['readable'] && $scope['users'] === 0 => 'ZKBioTime users could not be read (see the grant in docs/DATABASE.md), and the employee is no Department Manager',
                    !$scope['readable']                          => \sprintf('%d ZKBioTime user(s) matched, but their department permissions (auth_user_auth_dept) could not be read', $scope['users']),
                    $scope['users'] === 0                        => 'no active ZKBioTime user matched the employee ID (emp_pin or username) or the e-mail of the employee or the Joomla account, and the employee is no Department Manager',
                    default                                      => \sprintf('%d ZKBioTime user(s) matched, but none is a superuser or may manage all departments, and their data permissions list no department', $scope['users']),
                }
            ));
        }

        return array_values($out);
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
        if ($this->team !== null) {
            return $this->team;
        }

        // Kept for the cache time (1.0.10), for the same departments and day.
        $ids    = array_column($this->departments(), 'id');
        $key    = self::CACHE . 'team:' . $this->attendance()->today() . ':' . md5(implode(',', $ids));
        $cache  = $this->biotime()->getUserCache();
        $cached = $cache->get($this->identity(), $key);

        if (isset($cached['t']) && \is_array($cached['t'])) {
            return $this->team = $cached['t'];
        }

        $this->team = $this->biotime()->getTeamReader()->employees($ids, $this->attendance()->today());
        $cache->set($this->identity(), $key, ['t' => $this->team]);

        return $this->team;
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
     * Employee ID search (?code=, 1.0.4): the text typed, at most 20 characters; '' for none.
     */
    public function searchedCode(): string
    {
        return mb_substr(trim(Factory::getApplication()->getInput()->getString('code', '')), 0, 20);
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
        $code = $this->searchedCode();
        $ids  = $dept > 0 ? $this->departmentAndBelow($dept) : null;

        // The ID search only narrows the manager's own employees (case-insensitive, part of the ID).
        return array_values(array_filter($this->team(), static fn (array $e): bool => ($emp === '' || $e['emp_code'] === $emp)
            && ($code === '' || mb_stripos($e['emp_code'], $code) !== false)
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
            $period = $this->attendance()->range(Identity::matched($user, (string) $e['id'], $e['emp_code']), new \DateTimeImmutable($from), new \DateTimeImmutable($to), false);

            foreach ($period->errors as $type => $kind) {
                $errors[$type] = (string) $kind;
            }

            $days[$e['emp_code']] = array_values(array_filter($period->days, static fn (Day $d): bool => $d->date <= $period->today));
        }

        return ['days' => $days, 'errors' => $errors];
    }
}
