<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Tests\Unit;

use BiotimePortal\Component\Biotime\Administrator\Attendance\AttendanceService;
use BiotimePortal\Component\Biotime\Administrator\Attendance\Day;
use BiotimePortal\Component\Biotime\Administrator\Attendance\Punch;
use BiotimePortal\Component\Biotime\Administrator\Attendance\SourceConfig;
use BiotimePortal\Component\Biotime\Administrator\Export\ExportService;
use BiotimePortal\Component\Biotime\Administrator\Export\PdfWriter;
use BiotimePortal\Component\Biotime\Administrator\Export\XlsxWriter;
use BiotimePortal\Component\Biotime\Administrator\Identity\Identity;
use BiotimePortal\Component\Biotime\Administrator\Profile\ProfileService;
use BiotimePortal\Component\Biotime\Administrator\Support\Formatter;
use BiotimePortal\Component\Biotime\Tests\Support\ArrayLogger;
use BiotimePortal\Component\Biotime\Tests\Support\Fakes;
use BiotimePortal\Component\Biotime\Tests\Support\RoutingTransport;
use PHPUnit\Framework\TestCase;

/**
 * Month data: ownership checks (rows of other employees are dropped), per-day schedules, status
 * mapping, summaries, exceptions, cache, independent failures, exports and profile.
 */
final class AttendanceServiceTest extends TestCase
{
    private RoutingTransport $zkb;

    private ArrayLogger $log;

    /** @var array<string, mixed> */
    private array $session = [];

    private Identity $alice;

    private const DAILY_PATH = '/att/api/fixture_daily/';
    private const LEAVE_PATH = '/att/api/fixture_leave/';

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

        $this->zkb->list(self::LEAVE_PATH, [
            ['emp_code' => 'E1', 'start' => '2026-11-10', 'end' => '2026-11-11', 'leave_type' => 'Annual', 'hours' => '16', 'approval' => 'Approved'],
            ['emp_code' => 'E2', 'start' => '2026-11-12', 'leave_type' => 'BOB-LEAVE', 'approval' => 'Approved'],
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

    private function leave(): SourceConfig
    {
        return SourceConfig::parse('leave', [
            'path' => self::LEAVE_PATH, 'fields' => "emp_code = emp_code\nstart = start\nend = end\ntype = leave_type\nduration = hours\nstatus = approval",
            'status_map' => 'Approved = approved', 'duration_unit' => 'hours',
        ]);
    }

    private function service(?SourceConfig $daily = null, bool $leave = true, int $ttl = 300): AttendanceService
    {
        $empty = SourceConfig::parse('daily', []);

        return new AttendanceService(
            fn () => Fakes::client($this->zkb),
            $daily ?? $empty,
            $leave ? $this->leave() : SourceConfig::parse('leave', []),
            $leave,
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
        self::assertStringNotContainsString('BOB-LEAVE', $all);
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

    public function testSummaryCountsOnlyWhatZkbioTimeReturned(): void
    {
        $s = $this->service($this->daily())->month($this->alice, 2026, 11)->summary();

        self::assertSame(2, $s->presentDays);
        self::assertSame(1, $s->lateCount);
        self::assertSame(15, $s->lateMinutes);
        self::assertSame(1, $s->earlyCount);
        self::assertSame(180, $s->earlyMinutes);
        self::assertSame(1, $s->absentDays);
        self::assertSame(1, $s->restDays);
        self::assertSame(885, $s->workedMinutes);
        self::assertSame(1, $s->overtimeMinutes);
        self::assertSame(3, $s->workingDays);
        self::assertSame(3, $s->punchDays);
    }

    public function testWithoutACalculatedSourceNothingIsCalculated(): void
    {
        $period = $this->service()->month($this->alice, 2026, 11);
        $s      = $period->summary();

        self::assertNull($s->workingDays);
        self::assertNull($s->presentDays);
        self::assertNull($s->lateMinutes);
        self::assertNull($s->workedMinutes);
        self::assertNull($s->overtimeMinutes);
        self::assertSame(3, $s->punchDays);

        $d2 = $period->day('2026-11-02');
        self::assertSame(['07:58:10', '17:01:00'], [$d2->checkIn, $d2->checkOut], 'first and last punch as recorded');
        self::assertTrue($d2->checkFromPunches);
        self::assertNull($d2->late);
        self::assertNull($d2->status);
        self::assertNull($d2->schedule);
        self::assertSame([], $this->zkb->queries(self::DAILY_PATH));
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

    public function testLeaveIsReadWhenEnabledAndShownOnItsDays(): void
    {
        $period = $this->service($this->daily())->month($this->alice, 2026, 11);

        self::assertCount(1, $period->leave);
        self::assertSame(['Annual', 960, 'approved'], [$period->leave[0]->type, $period->leave[0]->durationMinutes, $period->leave[0]->status]);
        self::assertSame('Annual', $period->day('2026-11-11')->leave);

        $this->zkb->calls = [];
        $this->session    = [];
        $this->service($this->daily(), false)->month($this->alice, 2026, 11);
        self::assertSame([], $this->zkb->queries(self::LEAVE_PATH), 'leave module off: no call');
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
        $this->service($this->daily(), true, 0)->month($this->alice, 2026, 11);
        self::assertNotSame([], $this->zkb->calls, 'cache time 0 reads again');
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

    public function testExportContainsOnlyTheUsersOwnRecords(): void
    {
        $service = $this->service($this->daily());
        $f       = new Formatter('Y-m-d', 'H:i', static fn (string $k): string => $k);
        $export  = new ExportService($service, new XlsxWriter(), new PdfWriter(sys_get_temp_dir()));

        [$xlsx, $mime, $name] = $export->export($this->alice, new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-30'), 'xlsx', $f, 'Alice A');
        self::assertSame('attendance-E1-20261101-20261130.xlsx', $name);
        self::assertStringContainsString('spreadsheetml', $mime);

        $file = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($file, $xlsx);
        $zip = new \ZipArchive();
        $zip->open($file);
        $days = (string) $zip->getFromName('xl/worksheets/sheet2.xml');
        $zip->close();
        unlink($file);

        self::assertStringContainsString('Late Shift', $days);
        self::assertStringContainsString('07:58', $days);
        self::assertStringNotContainsString('BOB', $days);
        self::assertStringNotContainsString('03:33', $days);

        [$pdf] = $export->export($this->alice, new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-30'), 'pdf', $f, 'محمد');
        self::assertStringStartsWith('%PDF-', $pdf);

        $this->expectException(\InvalidArgumentException::class);
        $export->export($this->alice, new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-30'), 'csv', $f, '');
    }

    public function testProfileShowsOnlyAllowListedFields(): void
    {
        $this->zkb->on('/personnel/api/employees/11/', static fn () => [200, [
            'id' => 11, 'emp_code' => 'E1', 'first_name' => 'Alice', 'last_name' => 'A', 'email' => 'a@x.com', 'hire_date' => '2020-01-05',
            'department' => ['dept_name' => 'Finance'], 'position' => ['position_name' => 'Clerk'], 'area' => [['area_name' => 'HQ'], ['area_name' => 'Jeddah']],
            'card_no' => '99887766', 'ssn' => '1234567890', 'device_password' => 'pw-123', 'fingerprint' => 'TEMPLATE', 'national' => 'ID-777',
        ]]);

        $profile = (new ProfileService(fn () => Fakes::client($this->zkb), Fakes::cache($this->session), $this->log))->profile($this->alice);

        self::assertSame(['Finance', 'Clerk', 'HQ, Jeddah', '2020-01-05'], [$profile['department'], $profile['position'], $profile['area'], $profile['hire_date']]);
        $text = serialize($profile) . serialize($this->session);

        foreach (['99887766', '1234567890', 'pw-123', 'TEMPLATE', 'ID-777'] as $secret) {
            self::assertStringNotContainsString($secret, $text);
        }
    }

    public function testProfileOfAnotherEmployeeIsNotFound(): void
    {
        $this->zkb->on('/personnel/api/employees/11/', static fn () => [200, ['id' => 11, 'emp_code' => 'E2', 'first_name' => 'Bob']]);

        $this->expectExceptionObject(new \BiotimePortal\Component\Biotime\Administrator\Api\ZkbException('not_found', 'Employee record does not match the mapping'));
        (new ProfileService(fn () => Fakes::client($this->zkb), Fakes::cache($this->session), $this->log))->profile($this->alice);
    }

    /** A check-out punch is never shown as check-in (reported on a real site). */
    public function testCheckOutAloneIsNotShownAsCheckIn(): void
    {
        $p = static fn (string $t, ?string $state, ?string $code = null): Punch => new Punch('2026-11-02', $t, $state, null, null, $code);

        self::assertSame([null, '17:00:00'], AttendanceService::checkTimes([$p('17:00:00', 'Check Out')]));
        self::assertSame(['08:00:00', null], AttendanceService::checkTimes([$p('08:00:00', 'Check In')]));
        self::assertSame(['08:00:00', '17:00:00'], AttendanceService::checkTimes([
            $p('08:00:00', 'Check In'), $p('12:00:00', 'Break Out'), $p('13:00:00', 'Break In'), $p('17:00:00', 'Check Out'),
        ]), 'break punches are ignored');
        self::assertSame(['08:00:00', '19:30:00'], AttendanceService::checkTimes([
            $p('08:00:00', 'Check In'), $p('17:00:00', 'Check Out'), $p('18:00:00', 'Overtime In'), $p('19:30:00', 'Overtime Out'),
        ]));
        self::assertSame([null, '17:00:00'], AttendanceService::checkTimes([$p('17:00:00', null, '1')]), 'code 1 = check-out when there is no text');
        self::assertSame(['08:00:00', null], AttendanceService::checkTimes([$p('08:00:00', 'تسجيل دخول', '0')]), 'unknown text falls back to the code');
        self::assertSame([null, null], AttendanceService::checkTimes([$p('09:00:00', null)]), 'single punch of unknown state: neither');

        // As recorded on the real server: "Check In" (state 0) and "Unknown" (state 255).
        self::assertSame(['07:50:00', '15:30:00'], AttendanceService::checkTimes([$p('07:50:00', 'Check In', '0'), $p('15:30:00', 'Unknown', '255')]), 'unknown after check-in is the check-out');
        self::assertSame(['07:50:00', '15:30:00'], AttendanceService::checkTimes([$p('07:50:00', 'Unknown', '255'), $p('15:30:00', 'Unknown', '255')]));
        self::assertSame(['07:50:00', '17:00:00'], AttendanceService::checkTimes([$p('07:50:00', 'Unknown', '255'), $p('12:00:00', 'Unknown', '255'), $p('17:00:00', 'Check Out', '1')]), 'an explicit check-out wins');
        self::assertSame([null, null], AttendanceService::checkTimes([$p('15:30:00', 'Unknown', '255')]), 'a lone unknown punch: neither');
        self::assertSame(['07:50:00', null], AttendanceService::checkTimes([$p('07:50:00', 'Check In', '0'), $p('12:00:00', 'Break Out', '2')]), 'a break is not a check-out');
        self::assertSame(['08:00:00', '17:00:00'], AttendanceService::checkTimes([$p('08:00:00', null), $p('17:00:00', null)]), 'unknown states: first and last');

        // Through the whole service: the day shows only a check-out and says check-in is missing.
        $this->zkb->list('/iclock/api/transactions/', [['emp_code' => 'E1', 'punch_time' => '2026-11-09 17:05:00', 'punch_state_display' => 'Check Out']]);
        $day = $this->service()->month($this->alice, 2026, 11)->day('2026-11-09');

        self::assertNull($day->checkIn);
        self::assertSame('17:05:00', $day->checkOut);
        self::assertContains(Day::EX_MISSING_IN, $day->exceptions);
        self::assertNotContains(Day::EX_INCOMPLETE, $day->exceptions);
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
