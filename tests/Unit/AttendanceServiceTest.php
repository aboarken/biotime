<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Tests\Unit;

use BiotimePortal\Component\Biotime\Administrator\Attendance\AttendanceService;
use BiotimePortal\Component\Biotime\Administrator\Attendance\Day;
use BiotimePortal\Component\Biotime\Administrator\Attendance\Punch;
use BiotimePortal\Component\Biotime\Administrator\Attendance\SourceConfig;
use BiotimePortal\Component\Biotime\Administrator\Identity\Identity;
use BiotimePortal\Component\Biotime\Administrator\Support\Formatter;
use BiotimePortal\Component\Biotime\Tests\Support\ArrayLogger;
use BiotimePortal\Component\Biotime\Tests\Support\Fakes;
use BiotimePortal\Component\Biotime\Tests\Support\RoutingTransport;
use PHPUnit\Framework\TestCase;

/**
 * Month data: ownership checks (rows of other employees are dropped), per-day schedules, status
 * mapping, day statuses from punches (present with or without a missing punch, absent, weekend),
 * summaries, punch counts by verification, exceptions,
 * cache and independent failures.
 */
final class AttendanceServiceTest extends TestCase
{
    private RoutingTransport $zkb;

    private ArrayLogger $log;

    /** @var array<string, mixed> */
    private array $session = [];

    private Identity $alice;

    private const DAILY_PATH = '/att/api/fixture_daily/';

    protected function setUp(): void
    {
        $this->zkb   = new RoutingTransport();
        $this->log   = new ArrayLogger();
        $this->alice = Identity::matched(5, '11', 'E1');

        // Punches. ZKBioTime "ignores" the filter here and also returns Bob's punch: it must be hidden.
        $this->zkb->list('/iclock/api/transactions/', [
            ['emp_code' => 'E1', 'punch_time' => '2026-11-02 07:58:10', 'punch_state_display' => 'Check In', 'terminal_alias' => 'Gate A', 'area_alias' => 'HQ'],
            ['emp_code' => 'E1', 'punch_time' => '2026-11-02 17:01:00', 'punch_state_display' => 'Check Out', 'terminal_alias' => 'Gate A', 'area_alias' => 'HQ'],
            ['emp_code' => 'E1', 'punch_time' => '2026-11-03 09:15:00', 'punch_state_display' => 'Check In', 'terminal_alias' => 'Gate B'],
            ['emp_code' => 'E1', 'punch_time' => '2026-11-03 09:15:30', 'punch_state_display' => 'Check In', 'terminal_alias' => 'Gate B'],
            ['emp_code' => 'E1', 'punch_time' => '2026-11-03 15:00:00', 'punch_state_display' => 'Check Out'],
            ['emp_code' => 'E1', 'punch_time' => '2026-11-04 08:00:00', 'punch_state_display' => 'Check In'],
            ['emp_code' => 'E2', 'punch_time' => '2026-11-05 03:33:33', 'punch_state_display' => 'BOB-SECRET'],
            ['punch_time' => '2026-11-06 03:33:33'],
            ['emp_code' => 'E1', 'punch_time' => 'garbage'],
        ]);

        // Calculated daily attendance with a different shift on different days.
        $this->zkb->list(self::DAILY_PATH, [
            ['emp' => ['code' => 'E1'], 'att_date' => '2026-11-02', 'shift' => 'Morning', 'in_time' => '08:00', 'out_time' => '17:00', 'clock_in' => '07:58', 'clock_out' => '17:01', 'late_min' => 0, 'early_min' => 0, 'work_min' => 540, 'ot_min' => 1, 'state' => 'On Time'],
            ['emp' => ['code' => 'E1'], 'att_date' => '2026-11-03', 'shift' => 'Late Shift', 'in_time' => '09:00', 'out_time' => '18:00', 'clock_in' => '09:15', 'clock_out' => '15:00', 'late_min' => 15, 'early_min' => 180, 'work_min' => 345, 'ot_min' => 0, 'state' => 'Late'],
            ['emp' => ['code' => 'E1'], 'att_date' => '2026-11-05', 'shift' => 'Morning', 'in_time' => '08:00', 'out_time' => '17:00', 'state' => 'Absence'],
            ['emp' => ['code' => 'E1'], 'att_date' => '2026-11-07', 'state' => 'Weekend'],
            ['emp' => ['code' => 'E2'], 'att_date' => '2026-11-04', 'shift' => 'BOB-SHIFT', 'state' => 'Late'],
        ]);
    }

    private function daily(): SourceConfig
    {
        return SourceConfig::parse('daily', [
            'path'   => self::DAILY_PATH,
            'fields' => "emp_code = emp.code\ndate = att_date\nschedule = shift\nsched_start = in_time\nsched_end = out_time\ncheck_in = clock_in\ncheck_out = clock_out\nlate = late_min\nearly = early_min\nworked = work_min\novertime = ot_min\nstatus = state",
            'status_map' => "On Time = present\nLate = late\nAbsence = absent\nWeekend = rest",
        ]);
    }

    private function service(?SourceConfig $daily = null, int $ttl = 300): AttendanceService
    {
        $empty = SourceConfig::parse('daily', []);

        return new AttendanceService(
            fn () => Fakes::client($this->zkb),
            $daily ?? $empty,
            Fakes::cache($this->session, $ttl),
            $this->log,
            new \DateTimeZone('Asia/Riyadh'),
            static fn () => new \DateTimeImmutable('2026-11-20 12:00:00', new \DateTimeZone('UTC'))
        );
    }

    public function testOnlyTheIdentitysEmployeeCodeAndMonthAreRequested(): void
    {
        $this->service($this->daily())->month($this->alice, 2026, 11);

        $tx = $this->zkb->queries('/iclock/api/transactions/')[0];
        self::assertSame('E1', $tx['emp_code']);
        self::assertSame('2026-11-01 00:00:00', $tx['start_time']);
        self::assertSame('2026-11-30 23:59:59', $tx['end_time']);

        $daily = $this->zkb->queries(self::DAILY_PATH)[0];
        self::assertSame(['E1', '2026-11-01', '2026-11-30'], [$daily['emp_code'], $daily['start_date'], $daily['end_date']]);
    }

    public function testRowsOfOtherEmployeesAreHiddenAndLogged(): void
    {
        $period = $this->service($this->daily())->month($this->alice, 2026, 11);

        $all = serialize($period);
        self::assertStringNotContainsString('BOB-SECRET', $all);
        self::assertStringNotContainsString('BOB-SHIFT', $all);
        self::assertSame([], $period->day('2026-11-05')->punches);
        self::assertNull($period->day('2026-11-04')->schedule);
        self::assertTrue($this->log->has('warning', 'rows of other employees'));
        self::assertStringNotContainsString('E2', $this->log->text(), 'no employee codes in the log');
    }

    public function testEachDateKeepsItsOwnScheduleAndCalculatedValues(): void
    {
        $period = $this->service($this->daily())->month($this->alice, 2026, 11);

        $d2 = $period->day('2026-11-02');
        $d3 = $period->day('2026-11-03');
        self::assertSame(['Morning', '08:00:00', '17:00:00'], [$d2->schedule, $d2->schedStart, $d2->schedEnd]);
        self::assertSame(['Late Shift', '09:00:00', '18:00:00'], [$d3->schedule, $d3->schedStart, $d3->schedEnd]);
        self::assertSame(['present', 'late', 'absent', 'rest'], [$d2->status, $d3->status, $period->day('2026-11-05')->status, $period->day('2026-11-07')->status]);
        self::assertSame([15, 180, 345], [$d3->late, $d3->early, $d3->worked]);
        self::assertSame('09:15:00', $d3->checkIn, 'check-in from ZKBioTime, not recalculated');
        self::assertFalse($d3->checkFromPunches);
        self::assertCount(30, $period->days);
    }

    public function testWithoutACalculatedSourceStatusesComeFromThePunches(): void
    {
        // Today is Friday 2026-11-20 (Asia/Riyadh); Friday and Saturday are weekend days.
        $period = $this->service()->month($this->alice, 2026, 11);

        $d2 = $period->day('2026-11-02');
        self::assertSame(['07:58:10', '17:01:00'], [$d2->checkIn, $d2->checkOut], 'first check-in and last check-out as recorded');
        self::assertTrue($d2->checkFromPunches);
        self::assertTrue($d2->statusFromPunches);
        self::assertSame('present', $d2->status);
        self::assertSame(542, $d2->span());
        self::assertNull($d2->schedule);
        self::assertNull($d2->late);

        $d4 = $period->day('2026-11-04');
        self::assertSame('present', $d4->status, 'check-in only: present ...');
        self::assertTrue($d4->missingPunch(), '... with the Missing Punch tag');
        self::assertFalse($d2->missingPunch());
        self::assertSame('absent', $period->day('2026-11-05')->status, 'past working day without punches');
        self::assertSame('absent', $period->day('2026-11-01')->status, 'Sunday is a working day');
        self::assertSame(Day::STATUS_WEEKEND, $period->day('2026-11-06')->status, 'Friday');
        self::assertSame(Day::STATUS_WEEKEND, $period->day('2026-11-07')->status, 'Saturday');
        self::assertSame('absent', $period->day('2026-11-10')->status, 'a past working day without punches');
        self::assertSame(Day::STATUS_WEEKEND, $period->day('2026-11-20')->status, 'today, a Friday');
        self::assertNull($period->day('2026-11-23')->status, 'future working day: nothing to say yet');
        self::assertSame(Day::STATUS_WEEKEND, $period->day('2026-11-27')->status, 'future weekend');
        self::assertSame([], $this->zkb->queries(self::DAILY_PATH));
    }

    public function testAPunchOnAWeekendDayRemovesTheWeekendTag(): void
    {
        $this->zkb->list('/iclock/api/transactions/', [
            ['emp_code' => 'E1', 'punch_time' => '2026-11-07 08:00:00', 'punch_state' => '0', 'punch_state_display' => 'Check In'],
            ['emp_code' => 'E1', 'punch_time' => '2026-11-07 12:30:00', 'punch_state' => '255', 'punch_state_display' => 'Unknown'],
            ['emp_code' => 'E1', 'punch_time' => '2026-11-14 09:00:00', 'punch_state_display' => 'Check In'],
        ]);

        $period = $this->service()->month($this->alice, 2026, 11);
        $sat    = $period->day('2026-11-07');

        self::assertTrue($sat->weekend);
        self::assertTrue($sat->workedOnWeekend());
        self::assertSame('present', $sat->status);
        self::assertSame('present', $period->day('2026-11-14')->status, 'a weekend day with only a check-in');
        self::assertTrue($period->day('2026-11-14')->missingPunch());
        self::assertSame(2, $period->summary()->weekendWorked);
    }

    public function testTodayWithACheckInIsCheckedIn(): void
    {
        $this->zkb->list('/iclock/api/transactions/', [['emp_code' => 'E1', 'punch_time' => '2026-11-19 07:40:00', 'punch_state_display' => 'Check In']]);

        $service = new AttendanceService(
            fn () => Fakes::client($this->zkb),
            SourceConfig::parse('daily', []),
            Fakes::cache($this->session),
            $this->log,
            new \DateTimeZone('Asia/Riyadh'),
            static fn () => new \DateTimeImmutable('2026-11-19 09:00:00', new \DateTimeZone('Asia/Riyadh')),
            [5, 6]
        );
        $day = $service->month($this->alice, 2026, 11)->day('2026-11-19');

        self::assertSame(Day::STATUS_CHECKED_IN, $day->status);
        self::assertNotContains(Day::EX_MISSING_OUT, $day->exceptions, 'the day is not over');
        self::assertFalse($day->missingPunch(), 'the check-out may still come');
    }

    public function testTodayWithACheckOutIsCheckedOut(): void
    {
        $this->zkb->list('/iclock/api/transactions/', [
            ['emp_code' => 'E1', 'punch_time' => '2026-11-19 07:40:00', 'punch_state_display' => 'Check In'],
            ['emp_code' => 'E1', 'punch_time' => '2026-11-19 14:05:00', 'punch_state_display' => 'Check In'],
        ]);

        $service = new AttendanceService(
            fn () => Fakes::client($this->zkb),
            SourceConfig::parse('daily', []),
            Fakes::cache($this->session),
            $this->log,
            new \DateTimeZone('Asia/Riyadh'),
            static fn () => new \DateTimeImmutable('2026-11-19 15:00:00', new \DateTimeZone('Asia/Riyadh')),
            [5, 6]
        );
        $day = $service->month($this->alice, 2026, 11)->day('2026-11-19');

        self::assertSame(['07:40:00', '14:05:00'], [$day->checkIn, $day->checkOut], 'the 14:05 punch is the check-out');
        self::assertSame(Day::STATUS_CHECKED_OUT, $day->status);
        self::assertFalse($day->missingPunch());
        self::assertContains(Day::STATUS_CHECKED_OUT, Day::ATTENDED);
    }

    public function testWeekendDaysAreConfigurable(): void
    {
        $service = new AttendanceService(
            fn () => Fakes::client($this->zkb),
            SourceConfig::parse('daily', []),
            Fakes::cache($this->session),
            $this->log,
            new \DateTimeZone('Asia/Riyadh'),
            static fn () => new \DateTimeImmutable('2026-11-20 12:00:00', new \DateTimeZone('UTC')),
            [6, 7]
        );
        $period = $service->month($this->alice, 2026, 11);

        self::assertSame('absent', $period->day('2026-11-06')->status, 'Friday is a working day here');
        self::assertSame(Day::STATUS_WEEKEND, $period->day('2026-11-08')->status, 'Sunday');
    }

    public function testSummaryIsCountedFromThePunches(): void
    {
        $s = $this->service()->month($this->alice, 2026, 11)->summary();

        self::assertSame(15, $s->workingDays, '20 days so far minus 5 weekend days');
        self::assertSame(3, $s->presentDays, 'the missing-punch day counts as present ...');
        self::assertSame(1, $s->missingPunchDays, '... and as a missing punch');
        self::assertSame(12, $s->absentDays);
        self::assertSame(5, $s->weekendDays);
        self::assertSame(0, $s->weekendWorked);
        self::assertSame(20, $s->rate);
        self::assertSame(6, $s->punchCount);
        self::assertSame(3, $s->punchDays);
        self::assertSame(887, $s->spanMinutes);
        self::assertSame(444, $s->avgSpanMinutes);
        self::assertSame('08:24:23', $s->avgCheckIn);
        self::assertSame('16:00:30', $s->avgCheckOut);
        self::assertSame('07:58:10', $s->firstCheckIn);
        self::assertSame('17:01:00', $s->lastCheckOut);
    }

    public function testMyPunchesCountsByVerificationMonthAndDevice(): void
    {
        $this->zkb->list('/iclock/api/transactions/', [
            ['emp_code' => 'E1', 'punch_time' => '2026-10-05 07:50:00', 'punch_state_display' => 'Check In', 'verify_type_display' => 'Face', 'terminal_alias' => 'Gate A', 'area_alias' => 'HQ'],
            ['emp_code' => 'E1', 'punch_time' => '2026-10-05 15:10:00', 'punch_state_display' => 'Unknown', 'verify_type' => 1, 'terminal_alias' => 'Gate B'],
            ['emp_code' => 'E1', 'punch_time' => '2026-11-02 07:58:00', 'punch_state_display' => 'Check In', 'verify_type_display' => 'Fingerprint', 'terminal_alias' => 'Gate A'],
            ['emp_code' => 'E1', 'punch_time' => '2026-11-02 16:00:00', 'punch_state_display' => 'Check Out', 'verify_type' => 15, 'terminal_alias' => 'Gate A'],
            ['emp_code' => 'E1', 'punch_time' => '2026-11-03 08:00:00', 'punch_state_display' => 'Check In', 'verify_type_display' => 'Card'],
        ]);

        $period = $this->service()->range($this->alice, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-11-20'));
        $stats  = \BiotimePortal\Component\Biotime\Administrator\Attendance\PunchStats::of($period->days);

        self::assertSame(['fingerprint' => 2, 'face' => 2, 'other' => 1, 'total' => 5, 'days' => 3], $stats->total);
        self::assertSame(['fingerprint' => 1, 'face' => 1, 'other' => 0, 'total' => 2, 'days' => 1], $stats->months[10]);
        self::assertSame(3, $stats->months[11]['total']);
        self::assertSame(['Gate A', 'Gate B', '—'], array_column($stats->devices, 'device'), 'most used device first');
        self::assertSame(['fingerprint' => 1, 'face' => 2, 'total' => 3, 'last' => '2026-11-02 16:00:00'], array_intersect_key($stats->devices[0], ['fingerprint' => 0, 'face' => 0, 'total' => 0, 'last' => 0]));
    }

    public function testTheWordUnknownIsNotShownInPunchDetails(): void
    {
        $this->zkb->list('/iclock/api/transactions/', [
            ['emp_code' => 'E1', 'punch_time' => '2026-11-12 07:45:00', 'punch_state' => 0, 'punch_state_display' => 'Check In', 'terminal_alias' => 'Main Gate'],
            ['emp_code' => 'E1', 'punch_time' => '2026-11-12 15:20:00', 'punch_state' => 255, 'punch_state_display' => 'Unknown', 'verify_type_display' => 'unknown', 'terminal_alias' => 'Main Gate', 'area_alias' => ' Unknown '],
        ]);

        $day = $this->service()->month($this->alice, 2026, 11)->day('2026-11-12');

        self::assertSame('Check In', $day->punches[0]->state);
        self::assertSame([null, null, null, 'Main Gate'], [$day->punches[1]->state, $day->punches[1]->verify, $day->punches[1]->area, $day->punches[1]->device]);
        self::assertSame(['07:45:00', '15:20:00'], [$day->checkIn, $day->checkOut], 'the punch still counts');
    }

    public function testPunchDetailsIncludeHowAndWhere(): void
    {
        $this->zkb->list('/iclock/api/transactions/', [
            ['emp_code' => 'E1', 'punch_time' => '2026-11-02 07:58:10', 'punch_state_display' => 'Check In', 'verify_type_display' => 'Face', 'terminal_alias' => 'Gate A', 'terminal_sn' => 'CQZ7232', 'area_alias' => 'HQ'],
        ]);

        $p = $this->service()->month($this->alice, 2026, 11)->day('2026-11-02')->punches[0];

        self::assertSame(['Face', 'Gate A', 'HQ'], [$p->verify, $p->device, $p->area]);
    }

    public function testExceptionsAreDetectedButNothingIsCorrected(): void
    {
        $period = $this->service($this->daily())->month($this->alice, 2026, 11);

        self::assertContains(Day::EX_DUPLICATE, $period->day('2026-11-03')->exceptions);
        self::assertContains(Day::EX_LATE, $period->day('2026-11-03')->exceptions);
        self::assertContains(Day::EX_EARLY, $period->day('2026-11-03')->exceptions);
        self::assertContains(Day::EX_NO_SCHEDULE, $period->day('2026-11-04')->exceptions, 'punch but no schedule');

        $plain = $this->service()->month($this->alice, 2026, 11);
        self::assertContains(Day::EX_MISSING_OUT, $plain->day('2026-11-04')->exceptions, 'one check-in punch only');
        self::assertSame('08:00:00', $plain->day('2026-11-04')->checkIn);
        self::assertNull($plain->day('2026-11-04')->checkOut);
    }

    public function testMonthIsCachedPerUserAndEmployee(): void
    {
        $this->service($this->daily())->month($this->alice, 2026, 11);
        $calls = \count($this->zkb->calls);

        $again = $this->service($this->daily())->month($this->alice, 2026, 11);
        self::assertCount($calls, $this->zkb->calls, 'second read comes from the cache');
        self::assertNotNull($again->fetchedAt);

        // Another employee (or a re-mapped user) never sees the cached rows.
        $this->service($this->daily())->month(Identity::matched(5, '13', 'E2'), 2026, 11);
        self::assertGreaterThan($calls, \count($this->zkb->calls));

        $this->zkb->calls = [];
        $this->service($this->daily(), 0)->month($this->alice, 2026, 11);
        self::assertNotSame([], $this->zkb->calls, 'cache time 0 reads again');
    }

    public function testPastMonthsAreKeptForADayTheCurrentMonthForTheCacheTime(): void
    {
        $time    = strtotime('2026-11-20 12:00:00 UTC');
        $service = new AttendanceService(
            fn () => Fakes::client($this->zkb),
            SourceConfig::parse('daily', []),
            Fakes::cache($this->session, 300, static function () use (&$time): int {
                return $time;
            }),
            $this->log,
            new \DateTimeZone('Asia/Riyadh'),
            static fn () => new \DateTimeImmutable('2026-11-20 12:00:00', new \DateTimeZone('UTC'))
        );
        $reads = fn (): int => \count($this->zkb->queries('/iclock/api/transactions/'));

        $service->range($this->alice, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-11-20'));
        self::assertSame(2, $reads(), 'October and November read');

        $time += 600; // 10 minutes later: past the 5-minute cache time
        $service->range($this->alice, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-11-20'));
        self::assertSame(3, $reads(), 'only November (the current month) is read again');

        $time += 86400; // a day later: October is read again too
        $service->range($this->alice, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-11-20'));
        self::assertSame(5, $reads());
    }

    public function testOneFailingPartDoesNotHideTheOthers(): void
    {
        $this->zkb->on(self::DAILY_PATH, static fn () => [500, ['detail' => 'boom']]);

        $period = $this->service($this->daily())->month($this->alice, 2026, 11);

        self::assertSame('server', $period->error('daily'));
        self::assertNull($period->error('punches'));
        self::assertCount(2, $period->day('2026-11-02')->punches);
    }

    public function testRangeOverTwoMonthsReadsBoth(): void
    {
        $period = $this->service($this->daily())->range($this->alice, new \DateTimeImmutable('2026-10-25'), new \DateTimeImmutable('2026-11-03'));

        self::assertCount(10, $period->days);
        self::assertCount(2, $this->zkb->queries('/iclock/api/transactions/'));
        self::assertSame('2026-10-25', $period->from);
    }

    /** The time of day decides: before 12:00 check-in, from 12:00 check-out (whatever state the device recorded). */
    public function testCheckInBeforeNoonCheckOutFromNoon(): void
    {
        $p = static fn (string $t, ?string $state, ?string $code = null): Punch => new Punch('2026-11-02', $t, $state, null, null, $code);

        self::assertSame([null, '17:00:00'], AttendanceService::checkTimes([$p('17:00:00', 'Check Out')]));
        self::assertSame([null, '15:30:00'], AttendanceService::checkTimes([$p('15:30:00', 'Check In', '0')]), 'an afternoon "Check In" punch is the check-out');
        self::assertSame(['08:00:00', null], AttendanceService::checkTimes([$p('08:00:00', 'Check Out', '1')]), 'a morning "Check Out" punch is the check-in');
        self::assertSame(['11:59:59', '12:00:00'], AttendanceService::checkTimes([$p('11:59:59', null), $p('12:00:00', null)]), '11:59 is a check-in, 12:00 a check-out');
        self::assertSame(['07:50:00', '17:00:00'], AttendanceService::checkTimes([
            $p('07:50:00', 'Check In'), $p('07:51:00', 'Check In'), $p('12:30:00', 'Break Out'), $p('13:00:00', 'Break In'), $p('17:00:00', 'Check Out'),
        ]), 'first morning punch and last afternoon punch');
        self::assertSame(['07:50:00', '15:30:00'], AttendanceService::checkTimes([$p('07:50:00', 'Check In', '0'), $p('15:30:00', 'Unknown', '255')]));
        self::assertSame(['09:00:00', null], AttendanceService::checkTimes([$p('09:00:00', null)]));
        self::assertSame([null, null], AttendanceService::checkTimes([]));

        // Through the whole service: the day shows only a check-out and says check-in is missing.
        $this->zkb->list('/iclock/api/transactions/', [['emp_code' => 'E1', 'punch_time' => '2026-11-09 17:05:00', 'punch_state_display' => 'Check Out']]);
        $day = $this->service()->month($this->alice, 2026, 11)->day('2026-11-09');

        self::assertNull($day->checkIn);
        self::assertSame('17:05:00', $day->checkOut);
        self::assertContains(Day::EX_MISSING_IN, $day->exceptions);
        self::assertNotContains(Day::EX_MISSING_OUT, $day->exceptions);
    }

    public function testValueParsingHelpers(): void
    {
        self::assertSame(90, SourceConfig::toMinutes('1:30', 'minutes'));
        self::assertSame(90, SourceConfig::toMinutes('1.5', 'hours'));
        self::assertSame(2, SourceConfig::toMinutes('120', 'seconds'));
        self::assertNull(SourceConfig::toMinutes('abc', 'minutes'));
        self::assertNull(SourceConfig::toMinutes('-5', 'minutes'));
        self::assertSame('08:05:00', AttendanceService::time('2026-11-02T08:05:00+03:00'));
        self::assertSame('2026-11-02 08:05:00', AttendanceService::dateTime('2026-11-02T08:05'));
        self::assertNull(AttendanceService::dateTime('2026-02-30 08:00:00'));
        self::assertNull(AttendanceService::time('25:00'));

        $f = new Formatter('d M Y', 'h:i A', static fn (string $k): string => $k === 'NOVEMBER_SHORT' ? 'Nov' : $k);
        self::assertSame('05 Nov 2026', $f->date('2026-11-05'));
        self::assertSame('02:05 COM_BIOTIME_PM', $f->time('14:05:00'));
        self::assertSame('8:05', $f->duration(485));
        self::assertSame('—', $f->duration(null));
    }
}
