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
     * @param int     $workingDays    days so far that are not weekend, holiday or rest days (plus weekend days worked)
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
        public readonly ?string $lastCheckOut
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
        $off      = $count(static fn (Day $d): bool => \in_array($d->status, [Day::STATUS_WEEKEND, 'rest', 'holiday'], true) && $d->punches === []);
        $working  = \count($days) - $off;
        $spans    = array_values(array_filter(array_map(static fn (Day $d): ?int => $d->span(), $days), static fn (?int $m): bool => $m !== null));
        $ins      = array_values(array_filter(array_map(static fn (Day $d): ?string => $d->checkIn, $days)));
        $outs     = array_values(array_filter(array_map(static fn (Day $d): ?string => $d->checkOut, $days)));

        return new self(
            $working,
            $present,
            $count(static fn (Day $d): bool => $d->missingPunch()),
            $count(static fn (Day $d): bool => $d->status === 'absent'),
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
            $outs === [] ? null : max($outs)
        );
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
