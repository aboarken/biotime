<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Tests\Unit;

use BiotimePortal\Component\Biotime\Administrator\Attendance\Day;
use BiotimePortal\Component\Biotime\Administrator\Attendance\DayFilter;
use PHPUnit\Framework\TestCase;

/**
 * The status filters shared by My Attendance and the Manager Dashboard (1.0.0).
 */
final class DayFilterTest extends TestCase
{
    private static function day(?string $status, int $punches = 0, ?int $late = null, ?int $early = null): Day
    {
        return new Day('2026-11-02', array_fill(0, $punches, null), true, null, null, null, null, null, null, null, true, $late, $early, null, null, $status, null);
    }

    public function testOnlyKnownFiltersAreKept(): void
    {
        self::assertSame('late', DayFilter::valid('late'));
        self::assertSame('', DayFilter::valid('anything'));
        self::assertSame('', DayFilter::valid(''));
    }

    public function testWhichDaysAFilterKeeps(): void
    {
        $present = self::day('present', 2, 12, null);
        $absent  = self::day('absent');
        $early   = self::day('present', 2, null, 25);

        self::assertTrue(DayFilter::matches($absent, ''), 'no filter: every day');
        self::assertTrue(DayFilter::matches($present, 'attended'));
        self::assertFalse(DayFilter::matches($absent, 'attended'));
        self::assertTrue(DayFilter::matches($absent, 'absent'));
        self::assertTrue(DayFilter::matches($present, 'late'));
        self::assertFalse(DayFilter::matches($early, 'late'));
        self::assertTrue(DayFilter::matches($early, 'early'));
        self::assertTrue(DayFilter::matches($absent, 'nopunches'));
        self::assertTrue(DayFilter::matches($present, 'punches'));
    }
}
