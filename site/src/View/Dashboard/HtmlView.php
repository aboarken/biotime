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
use BiotimePortal\Component\Biotime\Administrator\Attendance\PunchStats;
use BiotimePortal\Component\Biotime\Administrator\Attendance\Summary;
use BiotimePortal\Component\Biotime\Site\Model\DashboardModel;
use BiotimePortal\Component\Biotime\Site\View\PortalViewTrait;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;

/**
 * My Dashboard: today's check-in and check-out, summary cards counted from the punches, the
 * monthly overview (chart + the same figures as text) and recent activity.
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

    /** Today, when the month shown is the current month. */
    public ?Day $todayDay = null;

    /** The month's punches per device, by fingerprint and face. */
    public PunchStats $punchStats;

    public function display($tpl = null): void
    {
        /** @var DashboardModel $model */
        $model = $this->getModel();

        [$this->year, $this->month] = $model->selectedMonth();
        $this->period        = $model->period();
        $this->summary       = $this->period->summary();
        $this->showChart     = $model->settings()->moduleEnabled('charts');
        $this->canAttendance = $model->can('zkb.attendance.view');
        $this->canCalendar   = $model->can('zkb.calendar.view');
        $this->today         = $model->attendance()->today();
        $this->todayDay      = $this->period->day($this->today);

        // The last 7 days up to today that have something to say (punches or absent).
        $days = array_filter($this->period->days, fn (Day $d): bool => $d->date <= $this->today && ($d->hasData() || $d->status !== null));
        $this->recent = \array_slice(array_reverse(array_values($days)), 0, 7);

        // Every device used in the selected month (most used first), by fingerprint and face.
        $this->punchStats = PunchStats::of($this->period->days);

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
