<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Site\View\Manager;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use BiotimePortal\Component\Biotime\Administrator\Attendance\Day;
use BiotimePortal\Component\Biotime\Administrator\Attendance\DayFilter;
use BiotimePortal\Component\Biotime\Administrator\Attendance\Summary;
use BiotimePortal\Component\Biotime\Administrator\Database\DbException;
use BiotimePortal\Component\Biotime\Site\Model\ManagerModel;
use BiotimePortal\Component\Biotime\Site\View\PortalViewTrait;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;

/**
 * Manager Dashboard (1.0.0): the workforce of the manager's department and the departments below
 * it. Filters: department, employee, From – To (today by default). Cards: workforce and the same
 * figures as My Attendance, for everyone shown. Below them one row per employee: a column per
 * date with that day's check-in and check-out, then late in, early out and both together over the
 * period.
 */
final class HtmlView extends BaseHtmlView
{
    use PortalViewTrait;

    /** @var list<array{id: int, name: string, parent: ?int, depth: int}> */
    public array $departments = [];

    /** @var list<array{id: int, emp_code: string, name: string, department_id: ?int, department: ?string, position: ?string}> */
    public array $team = [];

    /** @var list<array{id: int, emp_code: string, name: string, department_id: ?int, department: ?string, position: ?string}> */
    public array $employees = [];

    public int $dept = 0;

    public string $emp = '';

    /** Employee ID search (1.0.4). */
    public string $code = '';

    public string $from = '';

    public string $to = '';

    public string $status = '';

    public string $today = '';

    public ?Summary $summary = null;

    /**
     * One row per employee shown (with the status filter: those with at least one matching day):
     * their days by date, and their Late In and Early Out over the period (minutes).
     *
     * @var list<array{employee: array{emp_code: string, name: string, department: ?string}, days: array<string, Day>, late: int, early: int}>
     */
    public array $rows = [];

    /** Days with a check-in (morning punches) and with a check-out (afternoon punches), 1.0.2. */
    public int $morningPunches = 0;

    public int $afternoonPunches = 0;

    /** @var list<string> the period's dates up to today, the table's columns */
    public array $dates = [];

    /** @var array<string, string> part => error kind */
    public array $errors = [];

    /** Why nothing can be shown ('' when it can): 'nodepartment' or a database error kind. */
    public string $problem = '';

    /**
     * More employees match than are read at once (1.0.4): the manager narrows the list with a
     * department, an employee or an Employee ID first. Keeps the page small enough for Joomla
     * (whose plugins scan the whole page) and quick to read.
     */
    public const MAX_EMPLOYEES = 300;

    /** Employees per page of the table (1.0.4). */
    public const PAGE_SIZE = 50;

    /** How many employees match when there are too many to read (MAX_EMPLOYEES); 0 otherwise. */
    public int $tooMany = 0;

    public int $page = 1;

    /** Employees in the table on every page together. */
    public int $rowCount = 0;

    public int $pages = 1;

    public function display($tpl = null): void
    {
        /** @var ManagerModel $model */
        $model = $this->getModel();
        $input = Factory::getApplication()->getInput();

        $this->status = DayFilter::valid($input->getCmd('status', ''));
        $this->today  = $model->attendance()->today();
        [$this->from, $this->to] = $model->managerRange();
        $this->year  = (int) substr($this->to, 0, 4);
        $this->month = (int) substr($this->to, 5, 2);

        try {
            $this->departments = $model->departments();
            $this->problem     = $this->departments === [] ? 'nodepartment' : '';

            if ($this->problem === '') {
                $this->team      = $model->team();
                $this->dept      = $model->selectedDepartment();
                $this->emp       = $model->selectedEmployee();
                $this->code      = $model->searchedCode();
                $this->employees = $model->shownEmployees();

                if (\count($this->employees) > self::MAX_EMPLOYEES) {
                    $this->tooMany   = \count($this->employees);
                    $this->employees = [];
                }

                $read         = $model->teamDays($this->employees, $this->from, $this->to);
                $this->errors = $read['errors'];
                $all          = [];

                for ($d = new \DateTimeImmutable($this->from); $d->format('Y-m-d') <= min($this->to, $this->today); $d = $d->modify('+1 day')) {
                    $this->dates[] = $d->format('Y-m-d');
                }

                foreach ($this->employees as $e) {
                    $row     = ['employee' => $e, 'days' => [], 'late' => 0, 'early' => 0];
                    $matches = $this->status === '';

                    foreach ($read['days'][$e['emp_code']] ?? [] as $day) {
                        $all[]                   = $day;
                        $this->morningPunches   += $day->checkIn !== null ? 1 : 0;
                        $this->afternoonPunches += $day->checkOut !== null ? 1 : 0;
                        $row['days'][$day->date] = $day;
                        $row['late']            += $day->late ?? 0;
                        $row['early']           += $day->early ?? 0;
                        $matches                 = $matches || DayFilter::matches($day, $this->status);
                    }

                    if ($matches) {
                        $this->rows[] = $row;
                    }
                }

                $this->summary = Summary::of($all, $this->today);

                // The cards count every employee shown; the table shows PAGE_SIZE of them at a time.
                $this->rowCount = \count($this->rows);
                $this->pages = max(1, (int) ceil($this->rowCount / self::PAGE_SIZE));
                $this->page  = min(max(1, $input->getInt('page', 1)), $this->pages);
                $this->rows  = \array_slice($this->rows, ($this->page - 1) * self::PAGE_SIZE, self::PAGE_SIZE);
            }
        } catch (DbException $e) {
            $this->problem = $e->getKind();
            $model->logger()->warning('Manager Dashboard: the team could not be read (' . $e->getKind() . ')');
        }

        $this->preparePage($model, 'manager', 'COM_BIOTIME_MANAGER_TITLE');
        $model->logPage('manager', $this->from . '..' . $this->to . ' employees=' . \count($this->employees) . ($this->status !== '' ? ' status=' . $this->status : ''));

        parent::display($tpl);
    }

    /** This page with the same filters, changed by $changes ('' removes one). */
    public function filterUrl(array $changes = []): string
    {
        $query = array_filter(array_merge([
            'dept'   => $this->dept > 0 ? (string) $this->dept : '',
            'emp'    => $this->emp,
            'code'   => $this->code,
            'from'   => $this->from,
            'to'     => $this->to,
            'status' => $this->status,
        ], $changes), static fn ($v): bool => $v !== '' && $v !== null);

        return $this->url('view=manager' . ($query !== [] ? '&' . http_build_query($query) : ''));
    }
}
