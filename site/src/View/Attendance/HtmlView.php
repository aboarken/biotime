<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Site\View\Attendance;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use BiotimePortal\Component\Biotime\Administrator\Attendance\Day;
use BiotimePortal\Component\Biotime\Administrator\Attendance\Period;
use BiotimePortal\Component\Biotime\Administrator\Attendance\Summary;
use BiotimePortal\Component\Biotime\Site\Model\AttendanceModel;
use BiotimePortal\Component\Biotime\Site\View\PortalViewTrait;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;

/**
 * My Attendance: one month, one row per day: check-in, check-out (with the device), the time
 * between them, the punches and the day's status.
 */
final class HtmlView extends BaseHtmlView
{
    use PortalViewTrait;

    public const PAGE_SIZE = 31;

    /** Status filter values. */
    public const FILTERS = ['attended', 'present', 'missingpunch', 'missingmorning', 'missingafternoon', 'absent', 'weekend', 'weekendworked', 'late', 'early', 'holiday', 'exceptions', 'punches', 'nopunches'];

    public Period $period;

    public Summary $summary;

    /** @var list<Day> */
    public array $rows = [];

    public string $status = '';

    public string $date = '';

    public int $page = 1;

    public int $pages = 1;

    public int $total = 0;

    public function display($tpl = null): void
    {
        /** @var AttendanceModel $model */
        $model = $this->getModel();
        $input = Factory::getApplication()->getInput();

        // Search by date: the date's month is shown, filtered to that day.
        // Typed as DD/MM/YYYY in the filter bar; links use YYYY-MM-DD.
        $date = trim($input->getString('date', ''));

        if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$#', $date, $m)) {
            $date = \sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }

        if (preg_match('/^(20\d{2})-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/', $date) && checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
            $this->date = $date;
            $input->set('month', substr($date, 0, 7));
            // The filter bar also sends its month and year lists: the chosen date wins.
            $input->set('y', (int) substr($date, 0, 4));
            $input->set('mo', (int) substr($date, 5, 2));
        }

        $status       = $input->getCmd('status', '');
        $this->status = \in_array($status, self::FILTERS, true) ? $status : '';

        [$this->year, $this->month] = $model->selectedMonth();
        $this->period  = $model->period();
        $this->summary = $this->period->summary();

        $rows = array_values(array_filter($this->period->days, fn (Day $d): bool => $this->matches($d)));

        $this->total = \count($rows);
        $this->pages = max(1, (int) ceil($this->total / self::PAGE_SIZE));
        $this->page  = min(max(1, $input->getInt('page', 1)), $this->pages);
        $this->rows  = \array_slice($rows, ($this->page - 1) * self::PAGE_SIZE, self::PAGE_SIZE);

        $this->preparePage($model, 'attendance', 'COM_BIOTIME_ATTENDANCE_TITLE');
        $model->logPage('attendance', \sprintf('%04d-%02d', $this->year, $this->month) . ($this->status !== '' ? ' status=' . $this->status : ''));

        parent::display($tpl);
    }

    public function pageUrl(int $page): string
    {
        return $this->monthUrl('attendance', $this->year, $this->month, array_filter([
            'status' => $this->status,
            'date'   => $this->date,
            'page'   => $page > 1 ? (string) $page : '',
        ]));
    }

    private function matches(Day $d): bool
    {
        if ($this->date !== '' && $d->date !== $this->date) {
            return false;
        }

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
