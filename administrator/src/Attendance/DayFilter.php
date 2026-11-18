<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Attendance;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects


/**
 * The status filters of My Attendance and the Manager Dashboard (cards and the Status list): which
 * days a filter keeps.
 */
final class DayFilter
{
    /** Filter values, in the Status list's order. */
    public const FILTERS = ['attended', 'present', 'missingpunch', 'missingmorning', 'missingafternoon', 'absent', 'leave', 'weekend', 'weekendworked', 'late', 'early', 'holiday', 'exceptions', 'punches', 'nopunches'];

    /** $status when it is a filter; '' (every day) otherwise. */
    public static function valid(string $status): string
    {
        return \in_array($status, self::FILTERS, true) ? $status : '';
    }

    public static function matches(Day $d, string $status): bool
    {
        return match ($status) {
            ''                 => true,
            'attended'         => \in_array($d->status, Day::ATTENDED, true),
            'present'          => \in_array($d->status, ['present', 'late', 'early'], true),
            'exceptions'       => $d->exceptions !== [],
            'punches'          => $d->punches !== [],
            'nopunches'        => $d->punches === [],
            'weekendworked'    => $d->workedOnWeekend(),
            'missingpunch'     => $d->missingPunch(),
            'missingmorning'   => $d->missingMorning(),
            'missingafternoon' => $d->missingAfternoon(),
            'late'             => ($d->late ?? 0) > 0,
            'early'            => ($d->early ?? 0) > 0,
            'leave'            => ChartData::kind($d) === 'leave',
            // The Weekends card's days: weekend and rest days without punches (1.0.3).
            'weekend'          => ChartData::kind($d) === 'weekend',
            default            => $d->status === $status,
        };
    }
}
