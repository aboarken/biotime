<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Tests\Unit;

use BiotimePortal\Component\Biotime\Administrator\Attendance\AttendanceService;
use BiotimePortal\Component\Biotime\Administrator\Attendance\SourceConfig;
use BiotimePortal\Component\Biotime\Administrator\Database\AttendanceReader;
use BiotimePortal\Component\Biotime\Administrator\Database\DbConfig;
use BiotimePortal\Component\Biotime\Administrator\Database\DbConnector;
use BiotimePortal\Component\Biotime\Administrator\Database\DbException;
use BiotimePortal\Component\Biotime\Administrator\Identity\Identity;
use BiotimePortal\Component\Biotime\Tests\Support\ArrayLogger;
use BiotimePortal\Component\Biotime\Tests\Support\CatalogueQuery;
use BiotimePortal\Component\Biotime\Tests\Support\Fakes;
use BiotimePortal\Component\Biotime\Tests\Support\RoutingTransport;
use PHPUnit\Framework\TestCase;

/**
 * Attendance read from ZKBioTime's database (0.6.0): the reader's queries and their results, and
 * the month pages built from them (schedule, late-in, early-out, worked time, holidays, totals).
 */
final class AttendanceReaderTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $cache = [];

    /** @return array<string, list<array<string, mixed>>|\Closure> */
    private function answers(int $durationFactor = 60): array
    {
        $base = static fn (string $date, ?string $in, ?string $out, ?string $cin, ?string $cout, int $late, int $early, int $worked, array $more = []): array => $more + [
            'att_date' => $date, 'sched_in' => $in, 'sched_out' => $out, 'sched_seconds' => $in !== null ? 28800 : 0,
            'clock_in' => $cin, 'clock_out' => $cout, 'duration' => $in !== null ? 480 * $durationFactor : 0,
            'late' => $late * $durationFactor, 'early_leave' => $early * $durationFactor, 'total_worked' => $worked * $durationFactor,
            'absent' => 0, 'leave' => 0, 'day_off' => 0, 'timetable' => $in !== null ? 'Day 07:30-15:30' : null, 'total_ot' => null,
        ];

        return [
            'FROM personnel_employee' => [['id' => 1, 'department_id' => 10, 'dept_name' => 'Information Technology', 'position_name' => 'Developer', 'hire_date' => '2024-09-01']],
            'FROM att_attemployee'    => [['group_id' => 5]],
            'FROM iclock_transaction' => [
                ['punch_time' => '2026-11-02 07:42:10', 'emp_code' => 'E1', 'punch_state' => '0', 'verify_type' => 15, 'terminal_alias' => 'Main Gate', 'area_alias' => 'HQ'],
                ['punch_time' => '2026-11-02 15:05:00', 'emp_code' => 'E1', 'punch_state' => '1', 'verify_type' => 1, 'terminal_alias' => 'Main Gate', 'area_alias' => 'HQ'],
                ['punch_time' => '2026-11-09 08:00:00', 'emp_code' => 'E1', 'punch_state' => '0', 'verify_type' => 1, 'terminal_alias' => 'Main Gate', 'area_alias' => 'HQ'],
                ['punch_time' => '2026-11-09 16:00:00', 'emp_code' => 'E1', 'punch_state' => '1', 'verify_type' => 1, 'terminal_alias' => 'Main Gate', 'area_alias' => 'HQ'],
                ['punch_time' => '2026-11-03 07:00:00', 'emp_code' => 'E2', 'punch_state' => '0', 'verify_type' => 1, 'terminal_alias' => 'X', 'area_alias' => 'X'],
            ],
            'FROM att_payloadbase'    => [
                $base('2026-11-02', '07:30:00', '15:30:00', '07:42:10', '15:05:00', 12, 25, 443, ['total_ot' => 30 * $durationFactor]),
                $base('2026-11-04', '07:30:00', '15:30:00', null, null, 0, 0, 0, ['absent' => 480]),
                $base('2026-11-10', null, null, null, null, 0, 0, 0, ['day_off' => 1]),
                // A split shift: two timetables on one date add up.
                $base('2026-11-11', '07:30:00', '11:30:00', '07:35:00', '11:30:00', 5, 0, 235, ['timetable' => 'Morning']),
                $base('2026-11-11', '13:00:00', '17:00:00', '13:10:00', '16:50:00', 10, 10, 220, ['timetable' => 'Afternoon']),
            ],
            'FROM att_holiday'        => [
                ['alias' => 'National Day', 'first_day' => '2026-11-15', 'last_day' => '2026-11-16', 'department_id' => null, 'att_group_id' => null],
                ['alias' => 'HR Day', 'first_day' => '2026-11-17', 'last_day' => '2026-11-17', 'department_id' => 11, 'att_group_id' => null],
                ['alias' => 'Group Day', 'first_day' => '2026-11-18', 'last_day' => '2026-11-18', 'department_id' => null, 'att_group_id' => 5],
            ],
        ];
    }

    private function reader(CatalogueQuery $q, string $unit = 'auto'): AttendanceReader
    {
        return new AttendanceReader(static fn () => $q, 'Asia/Riyadh', $unit);
    }

    public function testDaysAreZkbioTimesCalculationInMinutesWhateverTheStoredUnit(): void
    {
        foreach ([60 => 'seconds', 1 => 'minutes'] as $factor => $unit) {
            $r    = $this->reader(new CatalogueQuery('pgsql', $this->answers($factor)));
            $emp  = $r->employee('E1');
            $days = array_column($r->days($emp, new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-30')), null, 'date');

            self::assertSame([12, 25, 443, 30], [$days['2026-11-02']['late'], $days['2026-11-02']['early'], $days['2026-11-02']['worked'], $days['2026-11-02']['overtime']], 'stored in ' . $unit);
            self::assertSame(['Day 07:30-15:30', '07:30:00', '15:30:00', '07:42:10', '15:05:00'], [$days['2026-11-02']['schedule'], $days['2026-11-02']['sched_start'], $days['2026-11-02']['sched_end'], $days['2026-11-02']['check_in'], $days['2026-11-02']['check_out']]);
            self::assertTrue($days['2026-11-04']['absent']);
            self::assertTrue($days['2026-11-10']['off']);
            self::assertSame(['Morning + Afternoon', '07:30:00', '17:00:00', '07:35:00', '16:50:00', 15, 10], [$days['2026-11-11']['schedule'], $days['2026-11-11']['sched_start'], $days['2026-11-11']['sched_end'], $days['2026-11-11']['check_in'], $days['2026-11-11']['check_out'], $days['2026-11-11']['late'], $days['2026-11-11']['early']], 'split shift');
            self::assertSame(['National Day', 'National Day', 'Group Day'], [$days['2026-11-15']['holiday'], $days['2026-11-16']['holiday'], $days['2026-11-18']['holiday']]);
            self::assertArrayNotHasKey('2026-11-17', $days, 'another department\'s holiday');
        }

        // A fixed unit overrides the check.
        $r    = $this->reader(new CatalogueQuery('pgsql', $this->answers(60)), 'minutes');
        $days = array_column($r->days($r->employee('E1'), new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-30')), null, 'date');
        self::assertSame(720, $days['2026-11-02']['late']);
    }

    public function testEveryQueryIsForTheOneEmployeeAndOnlyReads(): void
    {
        $q = new CatalogueQuery('pgsql', $this->answers());
        $r = $this->reader($q);
        $r->days($r->employee('E1'), new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-30'));
        $r->punches('E1', new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-30'));

        foreach ($q->sent as $sql) {
            self::assertMatchesRegularExpression('/^\s*SELECT\b/', $sql);

            if (str_contains($sql, 'FROM iclock_transaction')) {
                self::assertStringContainsString('t.emp_code = :code', $sql);
                self::assertStringContainsString('AT TIME ZONE', $sql, 'times in the server\'s zone');
            }

            if (str_contains($sql, 'FROM att_payloadbase')) {
                self::assertStringContainsString('b.emp_id = :emp', $sql);
            }
        }

        // Punches in the API's field names, with the state and verification as ZKBioTime shows them.
        $p = $this->reader(new CatalogueQuery('pgsql', $this->answers()))->punches('E1', new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-30'))[0];
        self::assertSame(['2026-11-02 07:42:10', 'Check In', 'Face', '15'], [$p['punch_time'], $p['punch_state_display'], $p['verify_type_display'], $p['verify_type']]);
    }

    public function testNoOrTwoEmployeesWithTheCodeShowNothing(): void
    {
        self::assertNull($this->reader(new CatalogueQuery('pgsql', ['FROM personnel_employee' => []]))->employee('E1'));
        self::assertNull($this->reader(new CatalogueQuery('pgsql', ['FROM personnel_employee' => [['id' => 1, 'department_id' => null, 'dept_name' => null, 'position_name' => null, 'hire_date' => null], ['id' => 2, 'department_id' => null, 'dept_name' => null, 'position_name' => null, 'hire_date' => null]]]))->employee('E1'));
    }

    private function service(CatalogueQuery $q): AttendanceService
    {
        $zkb    = new RoutingTransport();
        $reader = $this->reader($q);

        return new AttendanceService(
            static fn () => Fakes::client($zkb),
            SourceConfig::parse('daily', []),
            Fakes::cache($this->cache),
            new ArrayLogger(),
            new \DateTimeZone('Asia/Riyadh'),
            static fn () => new \DateTimeImmutable('2026-11-20 12:00:00', new \DateTimeZone('UTC')),
            [5, 6],
            static fn () => $reader
        );
    }

    public function testMonthPagesUseZkbioTimesCalculation(): void
    {
        $alice  = Identity::matched(5, '11', 'E1');
        $period = $this->service(new CatalogueQuery('pgsql', $this->answers()))->month($alice, 2026, 11);

        self::assertTrue($period->calculated);

        $d = $period->day('2026-11-02');
        self::assertSame(['present', 'Day 07:30-15:30', '07:30:00', '07:42:10', '15:05:00', 12, 25], [$d->status, $d->schedule, $d->schedStart, $d->checkIn, $d->checkOut, $d->late, $d->early]);
        self::assertCount(2, $d->punches);
        self::assertSame('absent', $period->day('2026-11-04')->status);
        self::assertSame('rest', $period->day('2026-11-10')->status, 'a day off in the schedule is not absent');
        self::assertSame(['holiday', 'National Day'], [$period->day('2026-11-15')->status, $period->day('2026-11-15')->statusRaw]);
        self::assertSame('absent', $period->day('2026-11-17')->status, 'another department\'s holiday');
        self::assertSame('present', $period->day('2026-11-09')->status, 'not calculated yet: from the punches');
        self::assertNull($period->day('2026-11-09')->late);
        self::assertSame([], $period->day('2026-11-03')->punches, 'Bob\'s punch is never shown');

        $s = $period->summary();
        self::assertSame([27, 35, 62, 2, 2, 3], [$s->lateMinutes, $s->earlyMinutes, $s->lateEarlyMinutes(), $s->lateDays, $s->earlyDays, $s->holidayDays]);
        self::assertSame(443 + 235 + 220, $s->workedMinutes);
        self::assertSame(30, $s->overtimeMinutes);
        self::assertSame(['department' => 'Information Technology', 'position' => 'Developer', 'hire_date' => '2024-09-01'], $this->service(new CatalogueQuery('pgsql', $this->answers()))->profile($alice));
    }

    public function testADatabaseErrorShowsAMessageAndNoData(): void
    {
        $q      = new CatalogueQuery('pgsql', ['FROM personnel_employee' => static fn (): array => throw new DbException(DbException::UNREACHABLE, 'down')]);
        $period = $this->service($q)->month(Identity::matched(5, '11', 'E1'), 2026, 11);

        self::assertSame(['punches' => 'network', 'daily' => 'network'], $period->errors);
        self::assertSame([], $period->day('2026-11-02')->punches);
    }

    /**
     * Against a real PostgreSQL loaded with tests/Support/sql/zkbiotime-pgsql.sql, when
     * BIOTIME_TEST_PG = "host:port:database:user:password" (a read-only user). Skipped otherwise.
     */
    public function testRealPostgres(): void
    {
        $env = getenv('BIOTIME_TEST_PG');

        if (!\is_string($env) || $env === '' || !\extension_loaded('pdo_pgsql')) {
            self::markTestSkipped('BIOTIME_TEST_PG not set');
        }

        [$host, $port, $db, $user, $password] = explode(':', $env, 5);
        $config = new DbConfig('pgsql', $host, (int) $port, $db, $user, $password, DbConfig::SSL_REQUIRE);
        $r      = new AttendanceReader(static fn () => (new DbConnector())->connect($config), 'Asia/Riyadh');
        $emp    = $r->employee('E1');
        $from   = new \DateTimeImmutable('2026-11-01');
        $to     = new \DateTimeImmutable('2026-11-30');

        self::assertSame(['Information Technology', 'Developer', 5], [$emp['department'], $emp['position'], $emp['group_id']]);
        self::assertSame(['2026-11-02 07:42:10', '2026-11-02 15:05:00', '2026-11-03 07:31:00'], array_column($r->punches('E1', $from, $to), 'punch_time'), 'local time, this month, Alice only');

        $days = array_column($r->days($emp, $from, $to), null, 'date');
        self::assertSame([12, 25, 443, 30, '07:42:10'], [$days['2026-11-02']['late'], $days['2026-11-02']['early'], $days['2026-11-02']['worked'], $days['2026-11-02']['overtime'], $days['2026-11-02']['check_in']]);
        self::assertSame(['National Day', 'National Day', 'Group Day'], [$days['2026-11-08']['holiday'], $days['2026-11-09']['holiday'], $days['2026-11-15']['holiday']]);
        self::assertArrayNotHasKey('2026-11-12', $days, 'HR Day is for another department');
    }
}
