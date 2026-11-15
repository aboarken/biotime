<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Site\View\Calendar;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use BiotimePortal\Component\Biotime\Administrator\Attendance\Day;
use BiotimePortal\Component\Biotime\Administrator\Attendance\Period;
use BiotimePortal\Component\Biotime\Site\Model\CalendarModel;
use BiotimePortal\Component\Biotime\Site\View\PortalViewTrait;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;

/**
 * Attendance Calendar: the month as weeks (Monday first); each day links to its details page.
 */
final class HtmlView extends BaseHtmlView
{
    use PortalViewTrait;

    public Period $period;

    /** @var list<list<?Day>> weeks of 7 cells, null outside the month */
    public array $weeks = [];

    public string $today = '';

    public function display($tpl = null): void
    {
        /** @var CalendarModel $model */
        $model = $this->getModel();

        [$this->year, $this->month] = $model->selectedMonth();
        $this->period = $model->period();
        $this->today  = $model->attendance()->today();

        $cells = array_fill(0, $this->period->days[0]->weekday() - 1, null);

        foreach ($this->period->days as $day) {
            $cells[] = $day;
        }

        while (\count($cells) % 7 !== 0) {
            $cells[] = null;
        }

        $this->weeks = array_chunk($cells, 7);

        $this->preparePage($model, 'calendar', 'COM_BIOTIME_CALENDAR_TITLE');
        $model->logPage('calendar', \sprintf('%04d-%02d', $this->year, $this->month));

        parent::display($tpl);
    }
}
