<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Site\View\Dashboard;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use BiotimePortal\Component\Biotime\Administrator\Attendance\Day;
use BiotimePortal\Component\Biotime\Administrator\Attendance\Period;
use BiotimePortal\Component\Biotime\Administrator\Attendance\Summary;
use BiotimePortal\Component\Biotime\Site\Model\DashboardModel;
use BiotimePortal\Component\Biotime\Site\View\PortalViewTrait;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;

/**
 * My Dashboard: summary cards, the monthly overview (chart + the same figures as text) and recent
 * activity. Punches, calculated attendance and leave each load on their own.
 */
final class HtmlView extends BaseHtmlView
{
    use PortalViewTrait;

    public Period $period;

    public Summary $summary;

    /** @var list<Day> newest first */
    public array $recent = [];

    public bool $showChart = false;

    public bool $canAttendance = false;

    public bool $canCalendar = false;

    public string $today = '';

    public function display($tpl = null): void
    {
        /** @var DashboardModel $model */
        $model = $this->getModel();

        [$this->year, $this->month] = $model->selectedMonth();
        $this->period        = $model->period();
        $this->summary       = $this->period->summary();
        $this->showChart     = $model->settings()->moduleEnabled('charts') && $this->summary->presentDays !== null;
        $this->canAttendance = $model->can('zkb.attendance.view');
        $this->canCalendar   = $model->can('zkb.calendar.view');
        $this->today         = $model->attendance()->today();

        $days = array_filter($this->period->days, fn (Day $d): bool => $d->date <= $this->today && $d->hasData());
        $this->recent = \array_slice(array_reverse(array_values($days)), 0, 7);

        $this->preparePage($model, 'dashboard', 'COM_BIOTIME_DASHBOARD_TITLE');
        $model->logPage('dashboard', \sprintf('%04d-%02d', $this->year, $this->month));

        parent::display($tpl);
    }

    /** Link to My Attendance for this month, optionally filtered by status. */
    public function attendanceUrl(string $status = ''): string
    {
        return $this->monthUrl('attendance', $this->year, $this->month, $status !== '' ? ['status' => $status] : []);
    }
}
