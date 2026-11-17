<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Database;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Reads one employee's attendance from ZKBioTime's own PostgreSQL database (0.6.0), read only:
 *  - punches:  iclock_transaction;
 *  - each day: ZKBioTime's calculated attendance, att_payloadbase (scheduled and actual times,
 *              late, early leave, worked time) with the timetable's name (att_timeinterval) and
 *              overtime (att_payloadovertime);
 *  - holidays: att_holiday (for everyone, the employee's department or attendance group);
 *  - the employee: personnel_employee with department and position.
 *
 * Table and column names: from the server's Test Database report (ZKBioTime 9.5, PostgreSQL 15).
 * Times are stored with a time zone; they are returned in the ZKBioTime server's time zone, as
 * the devices and ZKBioTime show them. Every query filters by this one employee.
 *
 * Duration columns (late, early_leave, worked, overtime) are whole numbers whose unit ZKBioTime
 * does not document: "auto" compares each day's scheduled duration with its scheduled start and
 * end to tell seconds from minutes; the administrator can set the unit instead.
 */
final class AttendanceReader
{
    public const UNITS = ['auto', 'minutes', 'seconds'];

    /** ZKTeco punch states and verify types, as ZKBioTime shows them. */
    private const STATES  = ['0' => 'Check In', '1' => 'Check Out', '2' => 'Break Out', '3' => 'Break In', '4' => 'Overtime In', '5' => 'Overtime Out'];
    private const VERIFY  = ['0' => 'Password', '1' => 'Fingerprint', '4' => 'Card', '15' => 'Face'];

    /** @var array<string, ?array<string, mixed>> */
    private array $employees = [];

    private ?Query $query = null;

    /**
     * @param \Closure(): Query $connect opens the connection when first needed
     */
    public function __construct(
        private readonly \Closure $connect,
        private readonly string $timezone,
        private readonly string $unit = 'auto'
    ) {
        if (!\in_array($timezone, \DateTimeZone::listIdentifiers(), true) && $timezone !== 'UTC') {
            throw new \InvalidArgumentException('timezone');
        }
    }

    /**
     * The employee with this code, or null.
     *
     * @return array{id: int, department_id: ?int, group_id: ?int, department: ?string, position: ?string, hire_date: ?string}|null
     *
     * @throws DbException
     */
    public function employee(string $empCode): ?array
    {
        if (\array_key_exists($empCode, $this->employees)) {
            return $this->employees[$empCode];
        }

        $row = $this->q()->rows(
            "SELECT e.id, e.department_id, d.dept_name, p.position_name, to_char(e.hire_date, 'YYYY-MM-DD') AS hire_date
               FROM personnel_employee e
               LEFT JOIN personnel_department d ON d.id = e.department_id
               LEFT JOIN personnel_position p ON p.id = e.position_id
              WHERE e.emp_code = :code
              ORDER BY e.id
              LIMIT 2",
            [':code' => $empCode]
        );

        // Not exactly one employee with this code: nothing is shown (never another's data).
        if (\count($row) !== 1) {
            return $this->employees[$empCode] = null;
        }

        $group = null;

        try {
            $group = $this->q()->value('SELECT group_id FROM att_attemployee WHERE emp_id = :id LIMIT 1', [':id' => (int) $row[0]['id']]);
        } catch (DbException) {
            // Attendance groups are optional (holidays per group only).
        }

        return $this->employees[$empCode] = [
            'id'            => (int) $row[0]['id'],
            'department_id' => $row[0]['department_id'] !== null ? (int) $row[0]['department_id'] : null,
            'group_id'      => $group !== null ? (int) $group : null,
            'department'    => self::text($row[0]['dept_name']),
            'position'      => self::text($row[0]['position_name']),
            'hire_date'     => self::text($row[0]['hire_date']),
        ];
    }

    /**
     * Punches from $from 00:00 to $to 23:59:59 (server time), in the API's field names, so they
     * are checked and stored exactly as punches read from the API.
     *
     * @return list<array<string, mixed>>
     *
     * @throws DbException
     */
    public function punches(string $empCode, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->q()->rows(
            "SELECT to_char(t.punch_time AT TIME ZONE :tz1, 'YYYY-MM-DD HH24:MI:SS') AS punch_time,
                    t.emp_code, t.punch_state, t.verify_type, t.terminal_alias, t.area_alias
               FROM iclock_transaction t
              WHERE t.emp_code = :code
                AND t.punch_time >= (CAST(:from AS timestamp) AT TIME ZONE :tz2)
                AND t.punch_time < (CAST(:to AS timestamp) AT TIME ZONE :tz3)
              ORDER BY t.punch_time",
            [
                ':tz1'  => $this->timezone,
                ':code' => $empCode,
                ':from' => $from->format('Y-m-d') . ' 00:00:00',
                ':tz2'  => $this->timezone,
                ':to'   => $to->modify('+1 day')->format('Y-m-d') . ' 00:00:00',
                ':tz3'  => $this->timezone,
            ]
        );

        return array_map(static function (array $r): array {
            $state  = trim((string) $r['punch_state']);
            $verify = $r['verify_type'] !== null ? (string) $r['verify_type'] : null;

            return [
                'emp_code'            => (string) $r['emp_code'],
                'punch_time'          => (string) $r['punch_time'],
                'punch_state'         => $state,
                'punch_state_display' => self::STATES[$state] ?? null,
                'verify_type'         => $verify,
                'verify_type_display' => $verify !== null ? (self::VERIFY[$verify] ?? null) : null,
                'terminal_alias'      => $r['terminal_alias'],
                'area_alias'          => $r['area_alias'],
            ];
        }, $rows);
    }

    /**
     * One row per date that ZKBioTime calculated, or that is a holiday for this employee:
     * date, schedule (timetable names), sched_start, sched_end, check_in, check_out (ZKBioTime's
     * paired clock-in and clock-out), late, early, worked, overtime (minutes), absent, day_off,
     * leave (bool), holiday (its name).
     *
     * @param array{id: int, department_id: ?int, group_id: ?int} $employee from employee()
     *
     * @return list<array<string, mixed>>
     *
     * @throws DbException
     */
    public function days(array $employee, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->q()->rows(
            "SELECT to_char(b.att_date, 'YYYY-MM-DD') AS att_date,
                    to_char(b.check_in AT TIME ZONE :tz1, 'HH24:MI:SS') AS sched_in,
                    to_char(b.check_out AT TIME ZONE :tz2, 'HH24:MI:SS') AS sched_out,
                    CAST(EXTRACT(EPOCH FROM (b.check_out - b.check_in)) AS integer) AS sched_seconds,
                    to_char(b.clock_in AT TIME ZONE :tz3, 'HH24:MI:SS') AS clock_in,
                    to_char(b.clock_out AT TIME ZONE :tz4, 'HH24:MI:SS') AS clock_out,
                    b.duration, b.late, b.early_leave, b.total_worked, b.absent, b.leave, b.day_off,
                    t.alias AS timetable, o.total_ot
               FROM att_payloadbase b
               LEFT JOIN att_timeinterval t ON t.id = b.timetable_id
               LEFT JOIN att_payloadovertime o ON o.uuid = b.overtime_id
              WHERE b.emp_id = :emp
                AND b.att_date >= CAST(:from AS date) AND b.att_date <= CAST(:to AS date)
              ORDER BY b.att_date, b.check_in",
            [
                ':tz1'  => $this->timezone,
                ':tz2'  => $this->timezone,
                ':tz3'  => $this->timezone,
                ':tz4'  => $this->timezone,
                ':emp'  => $employee['id'],
                ':from' => $from->format('Y-m-d'),
                ':to'   => $to->format('Y-m-d'),
            ]
        );

        $divisor = $this->divisor($rows);
        $minutes = static fn (mixed $v): ?int => $v === null ? null : max(0, (int) round(((int) $v) / $divisor));
        $days    = [];

        foreach ($rows as $r) {
            $date = (string) $r['att_date'];
            $d    = &$days[$date];
            $d  ??= ['date' => $date, 'schedules' => [], 'late' => 0, 'early' => 0, 'worked' => 0, 'overtime' => 0, 'absent' => false, 'leave' => false, 'off' => true];

            if (($name = self::text($r['timetable'])) !== null && !\in_array($name, $d['schedules'], true)) {
                $d['schedules'][] = $name;
            }

            foreach (['sched_start' => 'sched_in', 'check_in' => 'clock_in'] as $key => $col) {
                if ($r[$col] !== null && (!isset($d[$key]) || $r[$col] < $d[$key])) {
                    $d[$key] = (string) $r[$col];
                }
            }

            foreach (['sched_end' => 'sched_out', 'check_out' => 'clock_out'] as $key => $col) {
                if ($r[$col] !== null && (!isset($d[$key]) || $r[$col] > $d[$key])) {
                    $d[$key] = (string) $r[$col];
                }
            }

            $d['late']     += $minutes($r['late']) ?? 0;
            $d['early']    += $minutes($r['early_leave']) ?? 0;
            $d['worked']   += $minutes($r['total_worked']) ?? 0;
            $d['overtime'] += $minutes($r['total_ot']) ?? 0;
            $d['absent']    = $d['absent'] || (int) $r['absent'] > 0;
            $d['leave']     = $d['leave'] || (int) $r['leave'] > 0;
            // A day off only when every timetable row of the date is one.
            $d['off']       = $d['off'] && (int) $r['day_off'] > 0;
            unset($d);
        }

        foreach ($this->holidays($employee, $from, $to) as $date => $name) {
            $days[$date] ??= ['date' => $date, 'schedules' => [], 'off' => false];
            $days[$date]['holiday'] = $name;
        }

        ksort($days);

        return array_values(array_map(static function (array $d): array {
            $d['schedule'] = $d['schedules'] !== [] ? implode(' + ', $d['schedules']) : null;
            unset($d['schedules']);

            return $d;
        }, $days));
    }

    /**
     * Holidays of this employee in the range, date => name: those for everyone (no department
     * and no group), for the employee's department or for the employee's attendance group.
     *
     * @return array<string, string>
     */
    private function holidays(array $employee, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        try {
            $rows = $this->q()->rows(
                "SELECT alias, to_char(start_date, 'YYYY-MM-DD') AS first_day,
                        to_char(COALESCE(end_date, start_date + (GREATEST(duration_day, 1) - 1)), 'YYYY-MM-DD') AS last_day,
                        department_id, att_group_id
                   FROM att_holiday
                  WHERE start_date <= CAST(:to AS date)
                    AND COALESCE(end_date, start_date + (GREATEST(duration_day, 1) - 1)) >= CAST(:from AS date)",
                [':to' => $to->format('Y-m-d'), ':from' => $from->format('Y-m-d')]
            );
        } catch (DbException) {
            return [];
        }

        $out = [];

        foreach ($rows as $r) {
            $dept  = $r['department_id'] !== null ? (int) $r['department_id'] : null;
            $group = $r['att_group_id'] !== null ? (int) $r['att_group_id'] : null;

            $applies = ($dept === null && $group === null)
                || ($dept !== null && $dept === $employee['department_id'])
                || ($group !== null && $group === $employee['group_id']);

            if (!$applies) {
                continue;
            }

            $day  = new \DateTimeImmutable(max((string) $r['first_day'], $from->format('Y-m-d')));
            $last = min((string) $r['last_day'], $to->format('Y-m-d'));

            for (; $day->format('Y-m-d') <= $last; $day = $day->modify('+1 day')) {
                $out[$day->format('Y-m-d')] ??= self::text($r['alias']) ?? 'Holiday';
            }
        }

        return $out;
    }

    /**
     * 60 when duration columns are in seconds, 1 when in minutes. "auto": a day's scheduled
     * duration is close to its scheduled start-to-end span in seconds when the unit is seconds,
     * and about 60 times smaller when it is minutes; most days decide.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function divisor(array $rows): int
    {
        if ($this->unit !== 'auto') {
            return $this->unit === 'seconds' ? 60 : 1;
        }

        $votes = ['seconds' => 0, 'minutes' => 0];

        foreach ($rows as $r) {
            $span = (int) ($r['sched_seconds'] ?? 0);
            $dur  = (int) ($r['duration'] ?? 0);

            if ($span <= 0 || $dur <= 0) {
                continue;
            }

            $votes[$dur / $span > 0.2 ? 'seconds' : 'minutes']++;
        }

        // Without a scheduled day to compare: minutes, the unit ZKBioTime's timetables use.
        return $votes['seconds'] > $votes['minutes'] ? 60 : 1;
    }

    private function q(): Query
    {
        return $this->query ??= ($this->connect)();
    }

    private static function text(mixed $value): ?string
    {
        if (!\is_scalar($value)) {
            return null;
        }

        $value = trim(strip_tags((string) $value));

        return $value === '' ? null : mb_substr($value, 0, 100);
    }
}
