<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Site\View\Schedules;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use BiotimePortal\Component\Biotime\Administrator\Attendance\Day;
use BiotimePortal\Component\Biotime\Administrator\Attendance\Period;
use BiotimePortal\Component\Biotime\Site\Model\SchedulesModel;
use BiotimePortal\Component\Biotime\Site\View\PortalViewTrait;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;

/**
 * My Schedules: the schedule ZKBioTime applied on each date (month list and one week), never one
 * assumed schedule for the whole month.
 */
final class HtmlView extends BaseHtmlView
{
    use PortalViewTrait;

    public Period $period;

    /** @var list<Day> Monday to Sunday of the selected week (days of this month only) */
    public array $week = [];

    public string $weekStart = '';

    public bool $configured = false;

    public string $today = '';

    public function display($tpl = null): void
    {
        /** @var SchedulesModel $model */
        $model = $this->getModel();

        [$this->year, $this->month] = $model->selectedMonth();
        $this->period     = $model->period();
        $this->configured = $this->period->daily->isConfigured() && $this->period->daily->has('schedule');
        $this->today      = $model->attendance()->today();

        // Week: ?week=YYYY-MM-DD inside this month, else the week of today, else the first week.
        $week = Factory::getApplication()->getInput()->getString('week', '');
        $ref  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $week) && $this->period->day($week) !== null
            ? $week
            : ($this->period->day($this->today) !== null ? $this->today : $this->period->from);

        $monday          = (new \DateTimeImmutable($ref))->modify('monday this week');
        $this->weekStart = $monday->format('Y-m-d');

        for ($i = 0; $i < 7; $i++) {
            $day = $this->period->day($monday->modify('+' . $i . ' day')->format('Y-m-d'));

            if ($day !== null) {
                $this->week[] = $day;
            }
        }

        $this->preparePage($model, 'schedules', 'COM_BIOTIME_SCHEDULES_TITLE');
        $model->logPage('schedules', \sprintf('%04d-%02d', $this->year, $this->month));

        parent::display($tpl);
    }

    public function weekUrl(int $byWeeks): ?string
    {
        $date = (new \DateTimeImmutable($this->weekStart))->modify(($byWeeks >= 0 ? '+' : '') . (7 * $byWeeks) . ' day');
        $end  = $date->modify('+6 day');

        // Stay inside this month; the month buttons go further.
        if ($end->format('Y-m-d') < $this->period->from || $date->format('Y-m-d') > $this->period->to) {
            return null;
        }

        $target = max($date->format('Y-m-d'), $this->period->from);

        return $this->monthUrl('schedules', $this->year, $this->month, ['week' => $target]);
    }
}
