<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Tests\Unit;

use BiotimePortal\Component\Biotime\Administrator\Attendance\DateRange;
use PHPUnit\Framework\TestCase;

/**
 * My Dashboard's school year (1 August to today) and My Attendance's From – To dates (0.9.0).
 */
final class DateRangeTest extends TestCase
{
    public function testTheSchoolYearStartsOnTheFirstOfAugust(): void
    {
        self::assertSame('2026-08-01', DateRange::schoolYearStart('2026-11-18'));
        self::assertSame('2026-08-01', DateRange::schoolYearStart('2026-08-01'));
        self::assertSame('2026-08-01', DateRange::schoolYearStart('2027-07-31'), 'before August: last year\'s');
        self::assertSame('2025-01-01', DateRange::schoolYearStart('2025-03-10'), 'not before the first year');
        self::assertSame('2027-07-31', DateRange::schoolYearEnd('2026-11-18'));
        self::assertSame('2027-07-31', DateRange::schoolYearEnd('2027-07-31'));
        self::assertSame('2028-07-31', DateRange::schoolYearEnd('2027-08-01'), 'from 1 August the next school year');
    }

    public function testDatesAreTypedAsDayMonthYear(): void
    {
        self::assertSame('2026-09-03', DateRange::parse('03/09/2026'));
        self::assertSame('2026-09-03', DateRange::parse('3.9.2026'));
        self::assertSame('2026-09-03', DateRange::parse('2026-09-03'));
        self::assertNull(DateRange::parse('31/02/2026'));
        self::assertNull(DateRange::parse('tomorrow'));
        self::assertNull(DateRange::parse(''));
    }

    public function testFromAndToAreKeptBetweenTheFirstYearAndToday(): void
    {
        $today = '2026-11-18';

        self::assertNull(DateRange::of('', '', $today));
        self::assertSame(['2026-09-01', '2026-09-30'], DateRange::of('01/09/2026', '30/09/2026', $today));
        self::assertSame(['2026-09-01', '2026-09-30'], DateRange::of('30/09/2026', '01/09/2026', $today), 'swapped');
        self::assertSame(['2026-10-05', $today], DateRange::of('05/10/2026', '', $today), 'from alone: to today');
        self::assertSame(['2026-10-05', '2026-10-05'], DateRange::of('', '05/10/2026', $today), 'to alone: that day');
        self::assertSame(['2026-11-01', $today], DateRange::of('01/11/2026', '31/12/2026', $today), 'never after today');
        self::assertSame(['2025-01-01', '2025-01-31'], DateRange::of('01/01/2020', '31/01/2025', $today), 'not before the first year');
        self::assertSame(['2025-01-01', '2026-01-01'], DateRange::of('01/01/2025', '18/11/2026', $today), 'at most 366 days');
    }
}
