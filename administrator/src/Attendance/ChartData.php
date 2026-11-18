<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Attendance;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects


/**
 * The figures behind My Dashboard's Statistics charts (0.10.0), from the same days as the cards.
 * Every day up to today falls in exactly one kind, so the kinds add up to the days so far.
 */
final class ChartData
{
    /** Day kinds, in the charts' order. */
    public const KINDS = ['present', 'absent', 'holiday', 'weekend', 'leave'];

    /** The kind of a day: present (any punch), absent, holiday, weekend, leave; null after today or unknown. */
    public static function kind(Day $d): ?string
    {
        return match (true) {
            $d->punches !== [] || \in_array($d->status, Day::ATTENDED, true) => 'present',
            $d->status === 'absent'                                          => 'absent',
            $d->status === 'holiday'                                         => 'holiday',
            \in_array($d->status, [Day::STATUS_WEEKEND, 'rest'], true)       => 'weekend',
            $d->status === 'other'                                           => 'leave',
            default                                                          => null,
        };
    }

    /**
     * Days up to today per kind.
     *
     * @param  list<Day> $days
     * @return array<string, int> kind => days (every kind, 0 when none)
     */
    public static function breakdown(array $days, string $today): array
    {
        $counts = array_fill_keys(self::KINDS, 0);

        foreach ($days as $d) {
            if ($d->date <= $today && ($kind = self::kind($d)) !== null) {
                $counts[$kind]++;
            }
        }

        return $counts;
    }

    /**
     * One entry per month that has days up to today, oldest first.
     *
     * @param  list<Day> $days
     * @return list<array{year: int, month: int, kinds: array<string, int>, summary: Summary}>
     */
    public static function months(array $days, string $today): array
    {
        $byMonth = [];

        foreach ($days as $d) {
            if ($d->date <= $today) {
                $byMonth[substr($d->date, 0, 7)][] = $d;
            }
        }

        ksort($byMonth);
        $out = [];

        foreach ($byMonth as $ym => $list) {
            $out[] = [
                'year'    => (int) substr($ym, 0, 4),
                'month'   => (int) substr($ym, 5, 2),
                'kinds'   => self::breakdown($list, $today),
                'summary' => Summary::of($list, $today),
            ];
        }

        return $out;
    }

    /** "HH:MM[:SS]" as minutes after midnight; null when not a time. */
    public static function minutes(?string $time): ?int
    {
        return $time !== null && preg_match('/^(\d{1,2}):(\d{2})/', $time, $m) ? (int) $m[1] * 60 + (int) $m[2] : null;
    }
}
