<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Attendance;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects


/**
 * Figures for a set of days, counted from the punches ZKBioTime recorded and the day statuses
 * (see Day). Only days up to today count: future days have no attendance yet.
 */
final class Summary
{
    /**
     * @param int     $workingDays    present + absent days (1.0.3): leave, weekend, holiday and rest days are not working days
     * @param int     $presentDays    days with attendance (check-in and/or check-out)
     * @param int     $missingPunchDays present days with a check-in or a check-out missing
     * @param int     $absentDays     past working days without any punch
     * @param int     $weekendDays    weekend days without punches
     * @param int     $weekendWorked  weekend days with punches
     * @param ?int    $rate           present days as a percentage of working days; null without working days
     * @param ?int    $spanMinutes    total time between check-in and check-out
     * @param ?int    $avgSpanMinutes average time between check-in and check-out per complete day
     * @param ?string $avgCheckIn     average check-in time ("HH:MM:SS")
     * @param ?string $avgCheckOut    average check-out time
     * @param ?string $firstCheckIn   earliest check-in
     * @param ?string $lastCheckOut   latest check-out
     * @param int     $lateMinutes    total late-in (ZKBioTime's calculation, from the database)
     * @param int     $earlyMinutes   total early-out
     * @param int     $lateDays       days with late-in
     * @param int     $earlyDays      days with early-out
     * @param ?int    $workedMinutes  total worked time as ZKBioTime calculated it; null without it
     * @param int     $overtimeMinutes total overtime
     * @param int     $holidayDays    holidays without punches
     * @param int     $missingMorningDays   missing-punch days without a check-in (morning)
     * @param int     $missingAfternoonDays missing-punch days without a check-out (afternoon)
     * @param int     $leaveDays      leave days without punches (1.0.3)
     * @param int     $offDays        weekend and rest days without punches (1.0.3, Weekends card)
     * @param int     $allDays        every day so far with a status: present + absent + leave + holiday + weekend (1.0.3)
     */
    public function __construct(
        public readonly int $workingDays,
        public readonly int $presentDays,
        public readonly int $missingPunchDays,
        public readonly int $absentDays,
        public readonly int $weekendDays,
        public readonly int $weekendWorked,
        public readonly ?int $rate,
        public readonly int $punchDays,
        public readonly int $punchCount,
        public readonly ?int $spanMinutes,
        public readonly ?int $avgSpanMinutes,
        public readonly ?string $avgCheckIn,
        public readonly ?string $avgCheckOut,
        public readonly ?string $firstCheckIn,
        public readonly ?string $lastCheckOut,
        public readonly int $lateMinutes = 0,
        public readonly int $earlyMinutes = 0,
        public readonly int $lateDays = 0,
        public readonly int $earlyDays = 0,
        public readonly ?int $workedMinutes = null,
        public readonly int $overtimeMinutes = 0,
        public readonly int $holidayDays = 0,
        public readonly int $missingMorningDays = 0,
        public readonly int $missingAfternoonDays = 0,
        public readonly int $leaveDays = 0,
        public readonly int $allDays = 0,
        public readonly int $offDays = 0
    ) {
    }

    /**
     * @param list<Day> $days
     * @param string    $today Y-m-d; later days are not counted
     */
    public static function of(array $days, string $today): self
    {
        $days  = array_values(array_filter($days, static fn (Day $d): bool => $d->date <= $today));
        $count = static fn (\Closure $test): int => \count(array_filter($days, $test));

        $present  = $count(static fn (Day $d): bool => \in_array($d->status, Day::ATTENDED, true) || ($d->status === null && $d->punches !== []));
        $absent   = $count(static fn (Day $d): bool => $d->status === 'absent');
        // Days Present is "present / (present + absent)" (1.0.3): leave days are not counted
        // against the employee, nor are weekends, holidays and rest days.
        $working  = $present + $absent;
        $kinds    = ChartData::breakdown($days, $today);
        $spans    = array_values(array_filter(array_map(static fn (Day $d): ?int => $d->span(), $days), static fn (?int $m): bool => $m !== null));
        $ins      = array_values(array_filter(array_map(static fn (Day $d): ?string => $d->checkIn, $days)));
        $outs     = array_values(array_filter(array_map(static fn (Day $d): ?string => $d->checkOut, $days)));

        return new self(
            $working,
            $present,
            $count(static fn (Day $d): bool => $d->missingPunch()),
            $absent,
            $count(static fn (Day $d): bool => $d->status === Day::STATUS_WEEKEND),
            $count(static fn (Day $d): bool => $d->workedOnWeekend()),
            $working > 0 ? (int) round(100 * min($present, $working) / $working) : null,
            $count(static fn (Day $d): bool => $d->punches !== []),
            array_sum(array_map(static fn (Day $d): int => \count($d->punches), $days)),
            $spans === [] ? null : array_sum($spans),
            $spans === [] ? null : (int) round(array_sum($spans) / \count($spans)),
            self::averageTime($ins),
            self::averageTime($outs),
            $ins === [] ? null : min($ins),
            $outs === [] ? null : max($outs),
            array_sum(array_map(static fn (Day $d): int => $d->late ?? 0, $days)),
            array_sum(array_map(static fn (Day $d): int => $d->early ?? 0, $days)),
            $count(static fn (Day $d): bool => ($d->late ?? 0) > 0),
            $count(static fn (Day $d): bool => ($d->early ?? 0) > 0),
            array_filter($days, static fn (Day $d): bool => $d->worked !== null) === [] ? null : array_sum(array_map(static fn (Day $d): int => $d->worked ?? 0, $days)),
            array_sum(array_map(static fn (Day $d): int => $d->overtime ?? 0, $days)),
            $count(static fn (Day $d): bool => $d->status === 'holiday' && $d->punches === []),
            $count(static fn (Day $d): bool => $d->missingMorning()),
            $count(static fn (Day $d): bool => $d->missingAfternoon()),
            $kinds['leave'],
            array_sum($kinds),
            $kinds['weekend']
        );
    }

    /** Late-in and early-out together. */
    public function lateEarlyMinutes(): int
    {
        return $this->lateMinutes + $this->earlyMinutes;
    }

    /** @param list<string> $times "HH:MM:SS" */
    private static function averageTime(array $times): ?string
    {
        if ($times === []) {
            return null;
        }

        $avg = (int) round(array_sum(array_map([Day::class, 'seconds'], $times)) / \count($times));

        return \sprintf('%02d:%02d:%02d', intdiv($avg, 3600), intdiv($avg % 3600, 60), $avg % 60);
    }
}
