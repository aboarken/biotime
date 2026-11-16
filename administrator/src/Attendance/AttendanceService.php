<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Attendance;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use BiotimePortal\Component\Biotime\Administrator\Api\ZkbClient;
use BiotimePortal\Component\Biotime\Administrator\Api\ZkbException;
use BiotimePortal\Component\Biotime\Administrator\Cache\UserCacheInterface;
use BiotimePortal\Component\Biotime\Administrator\Identity\Identity;
use Psr\Log\LoggerInterface;

/**
 * Reads one employee's attendance. Every method takes the resolved Identity: the employee is never
 * chosen by request input. Each data type is fetched once per month and cached per user, so the
 * Dashboard, My Attendance, the Calendar, My Schedules and Reports share it.
 *
 * Ownership: rows whose employee code differs from the identity's (or is missing) are dropped and
 * a warning is logged, in case ZKBioTime ignored the employee filter.
 */
final class AttendanceService
{
    /**
     * Transaction fields read from the transaction list.
     * VERIFIED: BioTime 9.5 API User Manual (16 Aug 2024), 9.1 (emp_code, punch_time, punch_state, terminal_alias, area_alias) and the
     * real server (also punch_state_display); see docs/API-MAPPING.md.
     */
    public const TX_EMP_CODE = 'emp_code';
    public const TX_TIME     = 'punch_time';
    public const TX_STATE    = 'punch_state_display';
    public const TX_STATE_CODE = 'punch_state';
    public const TX_DEVICE   = 'terminal_alias';
    public const TX_AREA     = 'area_alias';

    public const TYPE_PUNCHES = 'punches';
    public const TYPE_DAILY   = 'daily';
    public const TYPE_LEAVE   = 'leave';

    /** @var \Closure(): \DateTimeImmutable */
    private \Closure $clock;

    /** @var array<string, array{read: int, hidden: int, skipped: int, cached: int, error: ?string, state: string}> */
    private array $stats = [];

    /**
     * @param \Closure(): ZkbClient                 $clientFactory
     * @param \DateTimeZone                         $serverZone  ZKBioTime server time zone; times are shown as recorded
     * @param (\Closure(): \DateTimeImmutable)|null $clock
     */
    public function __construct(
        private readonly \Closure $clientFactory,
        private readonly SourceConfig $daily,
        private readonly SourceConfig $leave,
        private readonly bool $leaveEnabled,
        private readonly UserCacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly \DateTimeZone $serverZone,
        ?\Closure $clock = null
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable('now');
    }

    /** Today's date (Y-m-d) in the ZKBioTime server's time zone. */
    public function today(): string
    {
        return ($this->clock)()->setTimezone($this->serverZone)->format('Y-m-d');
    }

    public function serverZone(): \DateTimeZone
    {
        return $this->serverZone;
    }

    public function dailySource(): SourceConfig
    {
        return $this->daily;
    }

    public function leaveEnabled(): bool
    {
        return $this->leaveEnabled && $this->leave->isConfigured();
    }

    public function month(Identity $identity, int $year, int $month): Period
    {
        $first = self::firstOfMonth($year, $month);

        return $this->range($identity, $first, $first->modify('last day of this month'));
    }

    /**
     * Every day from $from to $to (inclusive); callers limit the length (Reports: settings maximum).
     */
    public function range(Identity $identity, \DateTimeImmutable $from, \DateTimeImmutable $to): Period
    {
        $from = new \DateTimeImmutable($from->format('Y-m-d'));
        $to   = new \DateTimeImmutable($to->format('Y-m-d'));

        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }

        $data = [self::TYPE_PUNCHES => [], self::TYPE_DAILY => [], self::TYPE_LEAVE => []];
        $errors = $truncated = [];
        $fetched = null;

        for ($m = $from->modify('first day of this month'); $m <= $to; $m = $m->modify('first day of next month')) {
            foreach ([self::TYPE_PUNCHES, self::TYPE_DAILY, self::TYPE_LEAVE] as $type) {
                $part = $this->monthRows($identity, $type, (int) $m->format('Y'), (int) $m->format('n'));

                if ($part['error'] !== null) {
                    $errors[$type] = $part['error'];

                    continue;
                }

                $data[$type] = array_merge($data[$type], $part['rows']);
                $truncated[$type] = ($truncated[$type] ?? false) || $part['truncated'];

                if ($part['at'] !== null) {
                    $fetched = $fetched === null ? $part['at'] : min($fetched, $part['at']);
                }
            }
        }

        return $this->build($from, $to, $data, $errors, $truncated, $fetched);
    }

    /**
     * Counters for the one log line each page writes (no personal data).
     *
     * @return array<string, array{read: int, hidden: int, skipped: int, cached: int, error: ?string, state: string}>
     */
    public function stats(): array
    {
        return $this->stats;
    }

    public static function firstOfMonth(int $year, int $month): \DateTimeImmutable
    {
        $year  = max(2000, min(2100, $year));
        $month = max(1, min(12, $month));

        return new \DateTimeImmutable(\sprintf('%04d-%02d-01', $year, $month));
    }

    /**
     * @param array<string, list<array<string, mixed>>> $data
     * @param array<string, ?string>                    $errors
     * @param array<string, bool>                       $truncated
     */
    private function build(\DateTimeImmutable $from, \DateTimeImmutable $to, array $data, array $errors, array $truncated, ?int $fetched): Period
    {
        $punches = [];

        foreach ($data[self::TYPE_PUNCHES] as $row) {
            $punch = Punch::fromCache($row);
            $punches[$punch->date][] = $punch;
        }

        foreach ($punches as &$list) {
            usort($list, static fn (Punch $a, Punch $b): int => $a->time <=> $b->time);
        }
        unset($list);

        $rows = [];

        foreach ($data[self::TYPE_DAILY] as $row) {
            $rows[$row['date']] ??= $row;
        }

        $leave = array_map(fn (array $row): LeaveRecord => LeaveRecord::fromCache($row, $this->leave), $data[self::TYPE_LEAVE]);
        usort($leave, static fn (LeaveRecord $a, LeaveRecord $b): int => $a->start <=> $b->start);

        $today = $this->today();
        $days  = [];

        for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) {
            $date = $d->format('Y-m-d');
            $p    = $punches[$date] ?? [];
            $row  = $rows[$date] ?? null;

            $leaveText = $row['leave'] ?? null;

            if ($leaveText === null) {
                foreach ($leave as $record) {
                    if ($record->covers($date) && $record->status !== 'rejected') {
                        $leaveText = $record->type ?? '—';

                        break;
                    }
                }
            }

            $checkIn  = $row['check_in'] ?? null;
            $checkOut = $row['check_out'] ?? null;
            $fromPunches = false;

            if ($checkIn === null && $checkOut === null && $p !== []) {
                // ZKBioTime gave no calculated times: use the punches, by their state.
                [$checkIn, $checkOut] = self::checkTimes($p);
                $fromPunches          = true;
            }

            $day = new Day(
                $date,
                $p,
                $row !== null,
                $row['schedule'] ?? null,
                $row['sched_start'] ?? null,
                $row['sched_end'] ?? null,
                $row['break'] ?? null,
                $row['schedule_note'] ?? null,
                $checkIn,
                $checkOut,
                $fromPunches,
                isset($row['late']) ? (int) $row['late'] : null,
                isset($row['early']) ? (int) $row['early'] : null,
                isset($row['worked']) ? (int) $row['worked'] : null,
                isset($row['overtime']) ? (int) $row['overtime'] : null,
                isset($row['status']) ? $this->daily->status($row['status']) : null,
                $row['status'] ?? null,
                $leaveText
            );

            $day->detectExceptions($this->daily->isConfigured(), $date < $today);
            $days[] = $day;
        }

        return new Period($from->format('Y-m-d'), $to->format('Y-m-d'), $days, $leave, $this->daily, $this->leave, $errors, $truncated, $fetched);
    }

    /**
     * One month of one data type: from the cache, or read, normalised and ownership-checked.
     *
     * @return array{rows: list<array<string, mixed>>, truncated: bool, at: ?int, error: ?string}
     */
    private function monthRows(Identity $identity, string $type, int $year, int $month): array
    {
        $stat = &$this->stats[$type];
        $stat ??= ['read' => 0, 'hidden' => 0, 'skipped' => 0, 'cached' => 0, 'error' => null, 'state' => 'ok'];

        if ($type === self::TYPE_DAILY && !$this->daily->isConfigured()) {
            $stat['state'] = 'not configured';

            return ['rows' => [], 'truncated' => false, 'at' => null, 'error' => null];
        }

        if ($type === self::TYPE_LEAVE && !$this->leaveEnabled()) {
            $stat['state'] = $this->leaveEnabled ? 'not configured' : 'module off';

            return ['rows' => [], 'truncated' => false, 'at' => null, 'error' => null];
        }

        $key    = $type . ':' . \sprintf('%04d-%02d', $year, $month);
        $cached = $this->cache->get($identity, $key);

        if ($cached !== null && isset($cached['rows'], $cached['at'])) {
            $stat['cached']++;
            $stat['read'] += \count($cached['rows']);

            return ['rows' => $cached['rows'], 'truncated' => (bool) ($cached['truncated'] ?? false), 'at' => (int) $cached['at'], 'error' => null];
        }

        $first = self::firstOfMonth($year, $month);
        $last  = $first->modify('last day of this month');

        try {
            $client = ($this->clientFactory)();
            $result = match ($type) {
                self::TYPE_PUNCHES => $client->transactions($identity->empCode(), $first->setTime(0, 0, 0), $last->setTime(23, 59, 59)),
                self::TYPE_DAILY   => $client->sourceRows($this->daily, $identity->empCode(), $first, $last),
                default            => $client->sourceRows($this->leave, $identity->empCode(), $first, $last),
            };
        } catch (ZkbException $e) {
            $stat['error'] = $e->getKind();

            return ['rows' => [], 'truncated' => false, 'at' => null, 'error' => $e->getKind()];
        }

        $rows   = [];
        $hidden = 0;

        foreach ($result->items as $item) {
            $code = $type === self::TYPE_PUNCHES
                ? (isset($item[self::TX_EMP_CODE]) && is_scalar($item[self::TX_EMP_CODE]) ? (string) $item[self::TX_EMP_CODE] : null)
                : ($type === self::TYPE_DAILY ? $this->daily : $this->leave)->value($item, 'emp_code');

            if ($code !== $identity->empCode()) {
                $hidden++;

                continue;
            }

            $row = match ($type) {
                self::TYPE_PUNCHES => self::normalisePunch($item),
                self::TYPE_DAILY   => $this->normaliseDaily($item, $first, $last),
                default            => $this->normaliseLeave($item),
            };

            if ($row === null) {
                $stat['skipped']++;

                continue;
            }

            $rows[] = $row;
        }

        if ($hidden > 0) {
            $this->logger->warning(\sprintf('ZKBioTime returned %d %s rows of other employees (or without an employee code) for Joomla user %d; they were hidden', $hidden, $type, $identity->joomlaUserId));
        }

        $stat['read']   += \count($rows);
        $stat['hidden'] += $hidden;

        $at = ($this->clock)()->getTimestamp();
        $this->cache->set($identity, $key, ['rows' => $rows, 'truncated' => $result->truncated, 'at' => $at]);

        return ['rows' => $rows, 'truncated' => $result->truncated, 'at' => $at, 'error' => null];
    }

    /**
     * @param array<mixed> $item
     *
     * @return array{t: string, s: ?string, d: ?string, a: ?string}|null
     */
    private static function normalisePunch(array $item): ?array
    {
        $time = isset($item[self::TX_TIME]) && \is_string($item[self::TX_TIME]) ? self::dateTime($item[self::TX_TIME]) : null;

        if ($time === null) {
            return null;
        }

        $text = static fn (string $k): ?string => isset($item[$k]) && is_scalar($item[$k]) && trim((string) $item[$k]) !== ''
            ? mb_substr(trim(strip_tags((string) $item[$k])), 0, 100)
            : null;

        $code = isset($item[self::TX_STATE_CODE]) && is_scalar($item[self::TX_STATE_CODE]) && preg_match('/^\d{1,3}$/', (string) $item[self::TX_STATE_CODE]) ? (string) $item[self::TX_STATE_CODE] : null;

        return ['t' => $time, 's' => $text(self::TX_STATE), 'c' => $code, 'd' => $text(self::TX_DEVICE), 'a' => $text(self::TX_AREA)];
    }

    /**
     * @param array<mixed> $item
     *
     * @return array<string, mixed>|null
     */
    private function normaliseDaily(array $item, \DateTimeImmutable $first, \DateTimeImmutable $last): ?array
    {
        $date = self::date($this->daily->value($item, 'date'));

        if ($date === null || $date < $first->format('Y-m-d') || $date > $last->format('Y-m-d')) {
            return null;
        }

        $row = ['date' => $date];

        foreach (['schedule', 'break', 'schedule_note', 'status', 'leave'] as $field) {
            $row[$field] = $this->daily->value($item, $field);
        }

        foreach (['sched_start', 'sched_end', 'check_in', 'check_out'] as $field) {
            $row[$field] = self::time($this->daily->value($item, $field));
        }

        foreach (['late', 'early', 'worked', 'overtime'] as $field) {
            $row[$field] = $this->daily->minutes($item, $field);
        }

        return array_filter($row, static fn ($v) => $v !== null);
    }

    /**
     * @param array<mixed> $item
     *
     * @return array<string, ?string>|null
     */
    private function normaliseLeave(array $item): ?array
    {
        $start = self::date($this->leave->value($item, 'start'));

        if ($start === null) {
            return null;
        }

        $end = self::date($this->leave->value($item, 'end'));

        return [
            'start'    => $start,
            'end'      => $end !== null && $end >= $start ? $end : null,
            'type'     => $this->leave->value($item, 'type'),
            'duration' => $this->leave->value($item, 'duration'),
            'status'   => $this->leave->value($item, 'status'),
        ];
    }

    /**
     * Check-in and check-out from one day's punches (in time order), exactly as recorded, when
     * ZKBioTime's calculated times are not available. Punches whose state is unknown (e.g. state
     * 255 "Unknown", as many terminals record) are used only where no explicit punch exists:
     *  - check-in: the first check-in punch; else the first unknown punch that has a later punch;
     *  - check-out: the last check-out punch after the check-in; else the last unknown punch after
     *    the check-in;
     *  - break punches are ignored; a check-out is never shown as check-in; a single punch of
     *    unknown state is shown under neither (it stays in the punch list as incomplete).
     *
     * @param list<Punch> $punches
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function checkTimes(array $punches): array
    {
        $punches = array_values($punches);
        $last    = \count($punches) - 1;
        $inIdx   = null;

        foreach ($punches as $i => $punch) {
            if ($punch->direction() === Punch::IN) {
                $inIdx = $i;

                break;
            }
        }

        if ($inIdx === null) {
            foreach ($punches as $i => $punch) {
                if ($punch->direction() === null && $i < $last) {
                    $inIdx = $i;

                    break;
                }
            }
        }

        $outIdx = null;

        foreach ([Punch::OUT, null] as $wanted) {
            for ($i = $last; $i >= 0 && $outIdx === null; $i--) {
                if ($punches[$i]->direction() !== $wanted || ($inIdx !== null && $i <= $inIdx) || ($wanted === null && $inIdx === null)) {
                    continue;
                }

                $outIdx = $i;
            }

            if ($outIdx !== null) {
                break;
            }
        }

        return [$inIdx !== null ? $punches[$inIdx]->time : null, $outIdx !== null ? $punches[$outIdx]->time : null];
    }

    /** "YYYY-MM-DD HH:MM[:SS]" (or with T), kept as the server recorded it: never shifted. */
    public static function dateTime(string $value): ?string
    {
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?/', trim($value), $m) || !checkdate((int) substr($m[1], 5, 2), (int) substr($m[1], 8, 2), (int) substr($m[1], 0, 4))) {
            return null;
        }

        return $m[1] . ' ' . $m[2] . ':' . $m[3] . ':' . ($m[4] ?? '00');
    }

    public static function date(?string $value): ?string
    {
        if ($value === null || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', trim($value), $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }

    /** The time part of "HH:MM", "HH:MM:SS" or a date-time, as "HH:MM:SS". */
    public static function time(?string $value): ?string
    {
        if ($value === null || !preg_match('/(?:^|[ T])(\d{1,2}):([0-5]\d)(?::([0-5]\d))?(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?$/', trim($value), $m) || (int) $m[1] > 23) {
            return null;
        }

        return \sprintf('%02d:%s:%s', (int) $m[1], $m[2], $m[3] ?? '00');
    }
}
