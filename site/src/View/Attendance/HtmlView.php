<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Site\View\Attendance;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use BiotimePortal\Component\Biotime\Administrator\Attendance\DateRange;
use BiotimePortal\Component\Biotime\Administrator\Attendance\Day;
use BiotimePortal\Component\Biotime\Administrator\Attendance\Period;
use BiotimePortal\Component\Biotime\Administrator\Attendance\Summary;
use BiotimePortal\Component\Biotime\Site\Model\AttendanceModel;
use BiotimePortal\Component\Biotime\Site\View\PortalViewTrait;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;

/**
 * My Attendance: one month, or from one date to another (0.9.0), one row per day: check-in,
 * check-out (with the device), the time between them and the day's status.
 */
final class HtmlView extends BaseHtmlView
{
    use PortalViewTrait;

    /** Status filter values. */
    public const FILTERS = ['attended', 'present', 'missingpunch', 'missingmorning', 'missingafternoon', 'absent', 'weekend', 'weekendworked', 'late', 'early', 'holiday', 'exceptions', 'punches', 'nopunches'];

    public Period $period;

    public Summary $summary;

    /** @var list<Day> */
    public array $rows = [];

    public string $status = '';

    /** From date – to date (YYYY-MM-DD) when chosen; empty for the selected month. */
    public string $from = '';

    public string $to = '';

    public int $total = 0;

    public function display($tpl = null): void
    {
        /** @var AttendanceModel $model */
        $model = $this->getModel();
        $input = Factory::getApplication()->getInput();

        // Links from before 0.9.0 search one date (?date=): the same as from = to = that date.
        if ($input->getString('from', '') === '' && $input->getString('to', '') === '' && ($date = DateRange::parse($input->getString('date', ''))) !== null) {
            $input->set('from', $date);
            $input->set('to', $date);
        }

        $status       = $input->getCmd('status', '');
        $this->status = \in_array($status, self::FILTERS, true) ? $status : '';

        // From date – to date (below the month): wins over the month and year lists.
        $range = $model->selectedRange();

        if ($range !== null) {
            [$this->from, $this->to] = $range;
            $this->year   = (int) substr($this->to, 0, 4);
            $this->month  = (int) substr($this->to, 5, 2);
            $this->period = $model->rangePeriod($this->from, $this->to);
        } else {
            [$this->year, $this->month] = $model->selectedMonth();
            $this->period = $model->period();
        }

        $this->summary = $this->period->summary();

        $rows = array_values(array_filter($this->period->days, fn (Day $d): bool => $this->matches($d)));

        $this->total = \count($rows);
        // Every day of the month or of From – To on one page (0.9.1): no second page.
        $this->rows  = $rows;

        $this->preparePage($model, 'attendance', 'COM_BIOTIME_ATTENDANCE_TITLE');
        $model->logPage('attendance', ($range !== null ? $this->from . '..' . $this->to : \sprintf('%04d-%02d', $this->year, $this->month)) . ($this->status !== '' ? ' status=' . $this->status : ''));

        parent::display($tpl);
    }

    /** This page with the same month or range, filtered by $status. */
    public function filterUrl(string $status): string
    {
        $extra = array_filter(['status' => $status]);

        return $this->from !== ''
            ? $this->url('view=attendance&' . http_build_query(['from' => $this->from, 'to' => $this->to] + $extra))
            : $this->monthUrl('attendance', $this->year, $this->month, $extra);
    }

    /** The table's title: the month, or "01/08/2026 – 18/11/2026". */
    public function rangeTitle(): string
    {
        return $this->from === '' ? $this->monthTitle() : $this->f->date($this->from) . ($this->to !== $this->from ? ' – ' . $this->f->date($this->to) : '');
    }

    private function matches(Day $d): bool
    {
        return match ($this->status) {
            ''              => true,
            'attended'      => \in_array($d->status, Day::ATTENDED, true),
            'present'       => \in_array($d->status, ['present', 'late', 'early'], true),
            'exceptions'    => $d->exceptions !== [],
            'punches'       => $d->punches !== [],
            'nopunches'     => $d->punches === [],
            'weekendworked' => $d->workedOnWeekend(),
            'missingpunch'  => $d->missingPunch(),
            'missingmorning'   => $d->missingMorning(),
            'missingafternoon' => $d->missingAfternoon(),
            'late'          => ($d->late ?? 0) > 0,
            'early'         => ($d->early ?? 0) > 0,
            default         => $d->status === $this->status,
        };
    }
}
