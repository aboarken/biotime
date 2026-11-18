<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Tests\Unit;

use BiotimePortal\Component\Biotime\Administrator\Attendance\ChartData;
use BiotimePortal\Component\Biotime\Administrator\Attendance\Day;
use PHPUnit\Framework\TestCase;

/**
 * My Dashboard's Statistics (0.10.0): every day up to today in exactly one kind, per month.
 */
final class ChartDataTest extends TestCase
{
    private static function day(string $date, ?string $status, int $punches = 0, ?string $in = null): Day
    {
        return new Day($date, array_fill(0, $punches, null), true, null, null, null, null, null, $in, null, true, null, null, null, null, $status, null);
    }

    public function testEveryDaySoFarIsCountedOnce(): void
    {
        $days = [
            self::day('2026-08-02', 'present', 2, '07:40:00'),
            self::day('2026-08-03', 'absent'),
            self::day('2026-08-07', Day::STATUS_WEEKEND),
            self::day('2026-08-08', Day::STATUS_WEEKEND, 1, '09:00:00'),
            self::day('2026-09-01', 'holiday'),
            self::day('2026-09-02', 'other'),
            self::day('2026-09-03', 'rest'),
            self::day('2026-09-04', null),
            self::day('2026-12-01', 'absent'),
        ];

        self::assertSame(['present' => 2, 'absent' => 1, 'holiday' => 1, 'weekend' => 2, 'leave' => 1], ChartData::breakdown($days, '2026-11-18'));

        $months = ChartData::months($days, '2026-11-18');
        self::assertSame([[2026, 8], [2026, 9]], array_map(static fn (array $m): array => [$m['year'], $m['month']], $months), 'no month after today');
        self::assertSame(['present' => 2, 'absent' => 1, 'holiday' => 0, 'weekend' => 1, 'leave' => 0], $months[0]['kinds']);
        self::assertSame(['present' => 0, 'absent' => 0, 'holiday' => 1, 'weekend' => 1, 'leave' => 1], $months[1]['kinds']);
    }

    public function testTimesAsMinutes(): void
    {
        self::assertSame(456, ChartData::minutes('07:36'));
        self::assertSame(932, ChartData::minutes('15:32:10'));
        self::assertNull(ChartData::minutes(null));
        self::assertNull(ChartData::minutes('—'));
    }
}
