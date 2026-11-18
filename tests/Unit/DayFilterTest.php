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

    /** 1.0.3: Leaves card and filter; Days Present is present / (present + absent); All Days is the sum. */
    public function testLeavesAndDaysPresent(): void
    {
        $days = [
            new Day('2026-11-01', [null], true, null, null, null, null, null, '07:30:00', '15:00:00', true, null, null, null, null, 'present', null),
            new Day('2026-11-02', [], true, null, null, null, null, null, null, null, true, null, null, null, null, 'absent', null),
            new Day('2026-11-03', [], true, null, null, null, null, null, null, null, true, null, null, null, null, 'other', 'Annual Leave'),
            new Day('2026-11-04', [], true, null, null, null, null, null, null, null, true, null, null, null, null, 'holiday', null),
            new Day('2026-11-06', [], true, null, null, null, null, null, null, null, true, null, null, null, null, 'weekend', null, true),
        ];

        self::assertTrue(DayFilter::matches($days[2], 'leave'));
        self::assertFalse(DayFilter::matches($days[1], 'leave'));

        $s = \BiotimePortal\Component\Biotime\Administrator\Attendance\Summary::of($days, '2026-11-18');
        self::assertSame([1, 1, 2, 50, 1, 1, 5], [$s->presentDays, $s->absentDays, $s->workingDays, $s->rate, $s->leaveDays, $s->offDays, $s->allDays], 'present 1 of 2 (leave not counted); All Days = 5');
        self::assertTrue(DayFilter::matches($days[4], 'weekend'));
        self::assertFalse(DayFilter::matches($days[0], 'weekend'));
    }
}
