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
use BiotimePortal\Component\Biotime\Administrator\Identity\Identity;
use Joomla\CMS\Factory;
use Joomla\Input\Input;

/**
 * Attendance Reports: a month, or a custom range of at most the configured number of days.
 */
final class ReportsModel extends PortalModel
{
    /** @var array{from: \DateTimeImmutable, to: \DateTimeImmutable, error: ?string, query: array<string, string>, mode: string}|null */
    private ?array $range = null;

    private ?Period $reportPeriod = null;

    /**
     * Read and check the range from GET (page) or POST (export).
     *
     * @return array{from: \DateTimeImmutable, to: \DateTimeImmutable, error: ?string, query: array<string, string>, mode: string}
     */
    public function readRange(?Input $input = null): array
    {
        $input ??= Factory::getApplication()->getInput();
        $mode    = $input->getCmd('mode', 'month') === 'range' ? 'range' : 'month';

        if ($mode === 'month') {
            $value = $input->getString('month', '');

            if (preg_match('/^(20\d{2}|2100)-(0[1-9]|1[0-2])$/', $value, $m)) {
                $first = AttendanceService::firstOfMonth((int) $m[1], (int) $m[2]);
            } else {
                $today = $this->attendance()->today();
                $first = AttendanceService::firstOfMonth((int) substr($today, 0, 4), (int) substr($today, 5, 2));
            }

            return ['from' => $first, 'to' => $first->modify('last day of this month'), 'error' => null, 'mode' => 'month', 'query' => ['mode' => 'month', 'month' => $first->format('Y-m')]];
        }

        $from = self::date($input->getString('from', ''));
        $to   = self::date($input->getString('to', ''));
        $max  = $this->settings()->reportMaxDays();

        if ($from === null || $to === null) {
            $today = new \DateTimeImmutable($this->attendance()->today());

            return ['from' => $from ?? $today->modify('first day of this month'), 'to' => $to ?? $today, 'error' => 'COM_BIOTIME_REPORT_ERR_DATES', 'mode' => 'range', 'query' => ['mode' => 'range']];
        }

        $error = null;

        if ($to < $from) {
            $error = 'COM_BIOTIME_REPORT_ERR_ORDER';
        } elseif ((int) $from->diff($to)->days + 1 > $max) {
            $error = 'COM_BIOTIME_REPORT_ERR_TOO_LONG';
        }

        return ['from' => $from, 'to' => $to, 'error' => $error, 'mode' => 'range', 'query' => ['mode' => 'range', 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')]];
    }

    public function range(): array
    {
        return $this->range ??= $this->readRange();
    }

    public function period(): Period
    {
        $range = $this->range();

        return $this->reportPeriod ??= $this->attendance()->range($this->identity(), $range['from'], $range['to']);
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    public function export(Identity $identity, \DateTimeImmutable $from, \DateTimeImmutable $to, string $format): array
    {
        $name = '';

        // The employee's own name on the report (the same allow-listed record as My Profile).
        try {
            $profile = $this->biotime()->getProfileService()->profile($identity);
            $name    = trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? ''));
        } catch (\Throwable) {
            // The report still names the employee code.
        }

        return $this->biotime()->getExportService()->export($identity, $from, $to, $format, $this->formatter(), $name);
    }

    private static function date(string $value): ?\DateTimeImmutable
    {
        if (!preg_match('/^(20\d{2})-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/', $value, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return new \DateTimeImmutable($value);
    }
}
