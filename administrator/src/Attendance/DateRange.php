<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Attendance;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects


/**
 * Date ranges of the portal pages (0.9.0): the school year My Dashboard shows (1 August to today)
 * and the From – To dates of My Attendance. Dates are YYYY-MM-DD in the ZKBioTime server's time zone.
 */
final class DateRange
{
    /** At most this many days after the From date. */
    public const MAX_DAYS = 365;

    /** 1 August of the school year $today is in (last year's before August), not before the first year. */
    public static function schoolYearStart(string $today): string
    {
        $year = (int) substr($today, 0, 4) - ((int) substr($today, 5, 2) < 8 ? 1 : 0);

        return max(\sprintf('%04d-08-01', $year), AttendanceService::FIRST_YEAR . '-01-01');
    }

    /** A date typed as DD/MM/YYYY (or D/M/YYYY, with / . or -) or YYYY-MM-DD; null when not a date. */
    public static function parse(string $value): ?string
    {
        $value = trim($value);

        if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$#', $value, $m)) {
            $value = \sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }

        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return $value;
    }

    /**
     * From – To as typed; null when neither is a date. From alone: from that date to today; To
     * alone: that date only. Swapped when reversed, kept between 1 January of the first year and
     * today, and cut to MAX_DAYS after From.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function of(string $from, string $to, string $today): ?array
    {
        $from = self::parse($from);
        $to   = self::parse($to);

        if ($from === null && $to === null) {
            return null;
        }

        $first = AttendanceService::FIRST_YEAR . '-01-01';
        $from ??= $to;
        $to   ??= $today;

        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }

        $from = min(max($from, $first), $today);
        $to   = min(max($to, $first), $today);
        $max  = (new \DateTimeImmutable($from))->modify('+' . self::MAX_DAYS . ' days')->format('Y-m-d');

        return [$from, min($to, $max)];
    }
}
