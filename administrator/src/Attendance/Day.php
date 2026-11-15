<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Attendance;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects


/**
 * One calendar day of one employee: the schedule applied that day and the calculated values from
 * the daily source (when configured), the punches, and read-only exceptions. Values ZKBioTime did
 * not provide are null and shown as "—".
 */
final class Day
{
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
        public readonly ?string $leave
    ) {
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
