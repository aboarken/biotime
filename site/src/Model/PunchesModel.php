<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Site\Model;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use BiotimePortal\Component\Biotime\Administrator\Attendance\AttendanceService;
use BiotimePortal\Component\Biotime\Administrator\Attendance\Period;
use Joomla\CMS\Factory;

/**
 * My Punches: the logged-in employee's own punches for one year (up to today), so they can be
 * counted per month, per device and by fingerprint or face. Months are read (and cached) one by
 * one, as on the other pages.
 */
final class PunchesModel extends PortalModel
{
    private ?Period $yearPeriod = null;

    public function selectedYear(): int
    {
        $current = (int) substr($this->attendance()->today(), 0, 4);
        $year    = Factory::getApplication()->getInput()->getInt('y', $current);

        return max(AttendanceService::FIRST_YEAR, min($current, $year));
    }

    /** 0 = the whole year, else the month 1–12 chosen in the filter bar. */
    public function selectedMonthOnly(): int
    {
        $month = Factory::getApplication()->getInput()->getInt('mo', 0);

        return $month >= 1 && $month <= 12 ? $month : 0;
    }

    public function yearPeriod(): Period
    {
        if ($this->yearPeriod !== null) {
            return $this->yearPeriod;
        }

        $year  = $this->selectedYear();
        $today = new \DateTimeImmutable($this->attendance()->today());
        $end   = new \DateTimeImmutable(\sprintf('%04d-12-31', $year));

        return $this->yearPeriod = $this->attendance()->range($this->identity(), new \DateTimeImmutable(\sprintf('%04d-01-01', $year)), min($end, $today));
    }
}
