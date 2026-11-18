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
 * Manager Dashboard (0.11.0): the workforce of the manager's department and the departments below
 * it. Filters: department, employee, From – To (today by default). Cards: workforce and the same
 * figures as My Attendance, for everyone shown. Below them one row per employee per day: check-in
 * and check-out in one column, then late in, early out and both together.
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

    public string $from = '';

    public string $to = '';

    public string $status = '';

    public string $today = '';

    public ?Summary $summary = null;

    /** @var list<array{employee: array{emp_code: string, name: string, department: ?string}, day: Day}> rows shown (after the status filter), by employee then date */
    public array $rows = [];

    /** @var array<string, string> part => error kind */
    public array $errors = [];

    /** Why nothing can be shown ('' when it can): 'nodepartment' or a database error kind. */
    public string $problem = '';

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
                $this->employees = $model->shownEmployees();

                $read         = $model->teamDays($this->employees, $this->from, $this->to);
                $this->errors = $read['errors'];
                $all          = [];

                foreach ($this->employees as $e) {
                    foreach ($read['days'][$e['emp_code']] ?? [] as $day) {
                        $all[] = $day;

                        if (DayFilter::matches($day, $this->status)) {
                            $this->rows[] = ['employee' => $e, 'day' => $day];
                        }
                    }
                }

                $this->summary = Summary::of($all, $this->today);
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
            'from'   => $this->from,
            'to'     => $this->to,
            'status' => $this->status,
        ], $changes), static fn ($v): bool => $v !== '' && $v !== null);

        return $this->url('view=manager' . ($query !== [] ? '&' . http_build_query($query) : ''));
    }
}
