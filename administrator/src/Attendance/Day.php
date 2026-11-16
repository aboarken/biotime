<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Attendance;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects


/**
 * One calendar day of one employee: the punches, check-in and check-out, the day's status and
 * read-only exceptions. Values ZKBioTime did not provide are null and shown as "—".
 *
 * Status: ZKBioTime's calculated status when a daily source is configured; otherwise the portal
 * tells it from the punches (see AttendanceService::dayStatus()): present (any punch; with the
 * "Missing Punch" tag when the check-in or check-out is missing, see missingPunch()), checked in
 * (today), absent (a past working day without any punch) or weekend (a weekend day without
 * punches).
 */
final class Day
{
    /** Statuses only the portal sets (when ZKBioTime gives no calculated status). */
    public const STATUS_WEEKEND    = 'weekend';
    public const STATUS_CHECKED_IN = 'checked_in';
    public const STATUS_CHECKED_OUT = 'checked_out';

    /** Every status a day can have (ZKBioTime's mapped statuses plus the portal's own). */
    public const STATUSES = [...SourceConfig::DAILY_STATUSES, self::STATUS_WEEKEND, self::STATUS_CHECKED_IN, self::STATUS_CHECKED_OUT];

    /** Statuses that count as attended. */
    public const ATTENDED = ['present', 'late', 'early', 'incomplete', self::STATUS_CHECKED_IN, self::STATUS_CHECKED_OUT];

    public const EX_MISSING_IN    = 'missing_check_in';
    public const EX_MISSING_OUT   = 'missing_check_out';
    public const EX_INCOMPLETE    = 'incomplete';
    public const EX_DUPLICATE     = 'duplicate';
    public const EX_LATE          = 'late';
    public const EX_EARLY         = 'early';
    public const EX_NO_SCHEDULE   = 'missing_schedule';

    public const EXCEPTIONS = [self::EX_MISSING_IN, self::EX_MISSING_OUT, self::EX_INCOMPLETE, self::EX_DUPLICATE, self::EX_LATE, self::EX_EARLY, self::EX_NO_SCHEDULE];

    /** Seconds within which two punches count as a duplicate. */
    public const DUPLICATE_SECONDS = 60;

    /** @var list<string> */
    public array $exceptions = [];

    /**
     * @param list<Punch> $punches  in time order
     * @param bool        $hasRow   the daily source returned a row for this date
     */
    public function __construct(
        public readonly string $date,
        public readonly array $punches,
        public readonly bool $hasRow,
        public readonly ?string $schedule,
        public readonly ?string $schedStart,
        public readonly ?string $schedEnd,
        public readonly ?string $break,
        public readonly ?string $scheduleNote,
        public readonly ?string $checkIn,
        public readonly ?string $checkOut,
        public readonly bool $checkFromPunches,
        public readonly ?int $late,
        public readonly ?int $early,
        public readonly ?int $worked,
        public readonly ?int $overtime,
        public readonly ?string $status,
        public readonly ?string $statusRaw,
        public readonly bool $weekend = false,
        public readonly bool $statusFromPunches = false
    ) {
    }

    /**
     * The employee punched but the check-in or the check-out is missing ("Missing Punch"). Not
     * today while checked in: the check-out may still come.
     */
    public function missingPunch(): bool
    {
        if ($this->punches === [] && $this->checkIn === null && $this->checkOut === null) {
            return false;
        }

        if ($this->status === self::STATUS_CHECKED_IN) {
            return false;
        }

        return $this->checkIn === null || $this->checkOut === null;
    }

    /** A weekend day on which the employee punched (the weekend tag is then not shown). */
    public function workedOnWeekend(): bool
    {
        return $this->weekend && $this->punches !== [];
    }

    /**
     * Minutes from check-in to check-out, both as recorded; null unless both exist and the
     * check-out is later. This is the time between the two punches, not ZKBioTime's worked hours.
     */
    public function span(): ?int
    {
        if ($this->checkIn === null || $this->checkOut === null) {
            return null;
        }

        $seconds = self::seconds($this->checkOut) - self::seconds($this->checkIn);

        return $seconds > 0 ? intdiv($seconds, 60) : null;
    }

    /** The punch recorded at this time ("HH:MM:SS"), e.g. the one behind the check-in. */
    public function punchAt(?string $time): ?Punch
    {
        foreach ($time === null ? [] : $this->punches as $punch) {
            if ($punch->time === $time) {
                return $punch;
            }
        }

        return null;
    }

    public static function seconds(string $his): int
    {
        [$h, $m, $s] = array_map('intval', explode(':', $his . ':00:00'));

        return $h * 3600 + $m * 60 + $s;
    }

    public function weekday(): int
    {
        return (int) (new \DateTimeImmutable($this->date))->format('N');
    }

    public function hasData(): bool
    {
        return $this->hasRow || $this->punches !== [];
    }

    /**
     * Read-only exceptions from the values above. Nothing is corrected.
     *
     * @param bool $dailyConfigured the administrator configured a daily source
     * @param bool $isPast          the day is over (no "missing check-out" or "incomplete" before that)
     */
    public function detectExceptions(bool $dailyConfigured, bool $isPast): void
    {
        $ex = [];

        if ($this->checkIn === null && $this->checkOut !== null) {
            $ex[] = self::EX_MISSING_IN;
        }

        if ($isPast && $this->checkIn !== null && $this->checkOut === null) {
            $ex[] = self::EX_MISSING_OUT;
        }

        // A single punch whose state is unknown: neither check-in nor check-out can be told.
        if ($isPast && \count($this->punches) === 1 && !$this->hasRow && $this->checkIn === null && $this->checkOut === null) {
            $ex[] = self::EX_INCOMPLETE;
        }

        for ($i = 1; $i < \count($this->punches); $i++) {
            if ($this->punches[$i]->seconds() - $this->punches[$i - 1]->seconds() < self::DUPLICATE_SECONDS) {
                $ex[] = self::EX_DUPLICATE;

                break;
            }
        }

        if (($this->late ?? 0) > 0 || $this->status === 'late') {
            $ex[] = self::EX_LATE;
        }

        if (($this->early ?? 0) > 0 || $this->status === 'early') {
            $ex[] = self::EX_EARLY;
        }

        if ($dailyConfigured && $this->schedule === null && ($this->punches !== [] || $this->checkIn !== null)) {
            $ex[] = self::EX_NO_SCHEDULE;
        }

        $this->exceptions = $ex;
    }
}
