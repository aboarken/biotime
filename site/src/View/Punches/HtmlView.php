<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Site\View\Punches;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use BiotimePortal\Component\Biotime\Administrator\Attendance\Day;
use BiotimePortal\Component\Biotime\Administrator\Attendance\Period;
use BiotimePortal\Component\Biotime\Administrator\Attendance\PunchStats;
use BiotimePortal\Component\Biotime\Site\Model\PunchesModel;
use BiotimePortal\Component\Biotime\Site\View\PortalViewTrait;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;

/**
 * My Punches: the logged-in employee's own punches for a year: totals by fingerprint and face,
 * per month (with a total row) and per device (for the year or one month).
 */
final class HtmlView extends BaseHtmlView
{
    use PortalViewTrait;

    public Period $period;

    /** The whole year, per month. */
    public PunchStats $yearStats;

    /** The year, or the month chosen in the filter bar: totals and devices. */
    public PunchStats $selected;

    public int $monthOnly = 0;

    public int $yearNumber = 0;

    public function display($tpl = null): void
    {
        /** @var PunchesModel $model */
        $model = $this->getModel();

        $this->monthOnly  = $model->selectedMonthOnly();
        $this->yearNumber = $model->selectedYear();
        $this->period    = $model->yearPeriod();
        $this->yearStats = PunchStats::of($this->period->days);
        $this->selected  = $this->monthOnly === 0
            ? $this->yearStats
            : PunchStats::of(array_values(array_filter($this->period->days, fn (Day $d): bool => (int) substr($d->date, 5, 2) === $this->monthOnly)));

        $this->preparePage($model, 'punches', 'COM_BIOTIME_PUNCHES_TITLE');
        $model->logPage('punches', (string) $this->yearNumber . ($this->monthOnly > 0 ? \sprintf('-%02d', $this->monthOnly) : ''));

        parent::display($tpl);
    }

    /** My Attendance for one month of the selected year. */
    public function monthLink(int $month): string
    {
        return $this->monthUrl('attendance', $this->yearNumber, $month);
    }
}
