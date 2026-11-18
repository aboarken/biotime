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
 * My Dashboard: the school year so far (from 1 August to today, 0.9.0): today's check-in and
 * check-out, summary cards and recent activity.
 */
final class HtmlView extends BaseHtmlView
{
    use PortalViewTrait;

    public Period $period;

    public Summary $summary;

    /** @var list<Day> newest first */
    public array $recent = [];

    public bool $canAttendance = false;

    public bool $canCalendar = false;

    public string $today = '';

    /** Today (always in the range shown). */
    public ?Day $todayDay = null;

    /** The range shown: 1 August to today (YYYY-MM-DD). */
    public string $from = '';

    public string $to = '';

    /** @var array{department: ?string, position: ?string, hire_date: ?string, name: ?string}|null from the database */
    public ?array $profile = null;

    /** Under the heading: department · position · employee ID · name (0.9.0). @var list<string> */
    public array $profileLine = [];

    public function display($tpl = null): void
    {
        /** @var DashboardModel $model */
        $model = $this->getModel();

        [$this->from, $this->to] = $model->schoolYear();
        $this->year          = (int) substr($this->to, 0, 4);
        $this->month         = (int) substr($this->to, 5, 2);
        $this->period        = $model->rangePeriod($this->from, $this->to);
        $this->summary       = $this->period->summary();
        $this->canAttendance = $model->can('zkb.attendance.view');
        $this->canCalendar   = $model->can('zkb.calendar.view');
        $this->today         = $model->attendance()->today();
        $this->todayDay      = $this->period->day($this->today);

        // The last 7 days up to today that have something to say (punches or absent).
        $days = array_filter($this->period->days, fn (Day $d): bool => $d->date <= $this->today && ($d->hasData() || $d->status !== null));
        $this->recent = \array_slice(array_reverse(array_values($days)), 0, 7);

        $this->profile = $model->attendance()->profile($model->identity());
        // The name from ZKBioTime; with the API, the Joomla user's name.
        $this->profileLine = array_values(array_filter([
            $this->profile['department'] ?? null,
            $this->profile['position'] ?? null,
            $model->identity()->empCode(),
            $this->profile['name'] ?? (string) \Joomla\CMS\Factory::getApplication()->getIdentity()?->name,
        ], static fn (?string $v): bool => $v !== null && trim($v) !== ''));

        $this->preparePage($model, 'dashboard', 'COM_BIOTIME_DASHBOARD_TITLE');
        $model->logPage('dashboard', $this->from . '..' . $this->to);

        parent::display($tpl);
    }

    /** Link to My Attendance for the same range, optionally filtered by status. */
    public function attendanceUrl(string $status = ''): string
    {
        return $this->url('view=attendance&' . http_build_query(array_filter(['from' => $this->from, 'to' => $this->to, 'status' => $status])));
    }
}
