<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Export;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use BiotimePortal\Component\Biotime\Administrator\Attendance\AttendanceService;
use BiotimePortal\Component\Biotime\Administrator\Attendance\Day;
use BiotimePortal\Component\Biotime\Administrator\Attendance\Period;
use BiotimePortal\Component\Biotime\Administrator\Identity\Identity;
use BiotimePortal\Component\Biotime\Administrator\Support\Formatter;

/**
 * PDF and Excel exports of the logged-in employee's own attendance. The data comes from
 * AttendanceService with the resolved Identity, i.e. through the same ownership checks as the pages.
 */
final class ExportService
{
    public const FORMATS = ['pdf', 'xlsx'];

    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly XlsxWriter $xlsx,
        private readonly PdfWriter $pdf
    ) {
    }

    /**
     * @return array{0: string, 1: string, 2: string} [file content, MIME type, file name]
     */
    public function export(Identity $identity, \DateTimeImmutable $from, \DateTimeImmutable $to, string $format, Formatter $f, string $employeeName): array
    {
        if (!\in_array($format, self::FORMATS, true)) {
            throw new \InvalidArgumentException('Unknown export format');
        }

        $table = self::table($this->attendance->range($identity, $from, $to), $f, $employeeName, $identity->empCode());
        $name  = 'attendance-' . $identity->empCode() . '-' . $from->format('Ymd') . '-' . $to->format('Ymd') . '.' . $format;

        return $format === 'pdf'
            ? [$this->pdf->write($table), 'application/pdf', $name]
            : [$this->xlsx->write($table), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $name];
    }

    public static function table(Period $period, Formatter $f, string $employeeName, string $empCode): ReportTable
    {
        $s       = $period->summary();
        $summary = [];
        $add     = static function (string $key, ?string $value) use (&$summary, $f): void {
            if ($value !== null) {
                $summary[] = [$f->t($key), $value];
            }
        };
        $n = static fn (?int $v): ?string => $v === null ? null : (string) $v;

        $add('COM_BIOTIME_CARD_WORKING_DAYS', $n($s->workingDays));
        $add('COM_BIOTIME_CARD_PRESENT_DAYS', $n($s->presentDays));
        $add('COM_BIOTIME_CARD_LATE_COUNT', $n($s->lateCount));
        $add('COM_BIOTIME_CARD_LATE_MINUTES', $s->lateMinutes === null ? null : $f->duration($s->lateMinutes));
        $add('COM_BIOTIME_CARD_EARLY_COUNT', $n($s->earlyCount));
        $add('COM_BIOTIME_CARD_EARLY_MINUTES', $s->earlyMinutes === null ? null : $f->duration($s->earlyMinutes));
        $add('COM_BIOTIME_CARD_ABSENT_DAYS', $n($s->absentDays));
        $add('COM_BIOTIME_CARD_LEAVE_DAYS', $n($s->leaveDays));
        $add('COM_BIOTIME_CARD_WORKED_HOURS', $s->workedMinutes === null ? null : $f->duration($s->workedMinutes));
        $add('COM_BIOTIME_CARD_OVERTIME_HOURS', $s->overtimeMinutes === null ? null : $f->duration($s->overtimeMinutes));
        $add('COM_BIOTIME_CARD_PUNCH_DAYS', (string) $s->punchDays);
        $add('COM_BIOTIME_CARD_EXCEPTIONS', (string) $s->exceptionCount);

        $rows = array_map(static fn (Day $d): array => [
            $f->date($d->date),
            $f->weekday($d->weekday(), true),
            $f->text($d->schedule),
            $f->time($d->schedStart),
            $f->time($d->schedEnd),
            $f->time($d->checkIn),
            $f->time($d->checkOut),
            $f->duration($d->late),
            $f->duration($d->early),
            $f->duration($d->worked),
            $f->duration($d->overtime),
            $f->status($d),
            implode(', ', array_map(static fn (string $e): string => $f->exception($e), $d->exceptions)),
        ], $period->days);

        $notes = [];

        if (!$period->daily->isConfigured()) {
            $notes[] = $f->t('COM_BIOTIME_NOTE_NO_DAILY');
        }

        foreach ($period->errors as $type => $kind) {
            $notes[] = $f->t('COM_BIOTIME_NOTE_PART_MISSING_' . strtoupper($type));
        }

        if ($period->isTruncated()) {
            $notes[] = $f->t('COM_BIOTIME_TRUNCATED');
        }

        return new ReportTable(
            $f->t('COM_BIOTIME_REPORT_TITLE'),
            trim($employeeName . ' (' . $empCode . ') · ' . $f->date($period->from) . ' – ' . $f->date($period->to)),
            $summary,
            array_map(static fn (string $k): string => $f->t($k), [
                'COM_BIOTIME_COL_DATE', 'COM_BIOTIME_COL_DAY', 'COM_BIOTIME_COL_SCHEDULE', 'COM_BIOTIME_COL_SCHED_START',
                'COM_BIOTIME_COL_SCHED_END', 'COM_BIOTIME_COL_CHECK_IN', 'COM_BIOTIME_COL_CHECK_OUT', 'COM_BIOTIME_COL_LATE',
                'COM_BIOTIME_COL_EARLY', 'COM_BIOTIME_COL_WORKED', 'COM_BIOTIME_COL_OVERTIME', 'COM_BIOTIME_COL_STATUS',
                'COM_BIOTIME_COL_EXCEPTIONS',
            ]),
            $rows,
            $notes
        );
    }
}
