<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Attendance;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects


/**
 * Attendance of one employee for a date range (a month or a year), read from ZKBioTime's
 * database: each day carries ZKBioTime's schedule, late-in, early-out and worked time. Each data
 * type (punches, daily calculated attendance) is read on its own: when one fails, its error kind
 * is set and the others are still shown.
 */
final class Period
{
    /**
     * @param list<Day>              $days   every date of the range, oldest first
     * @param array<string, ?string> $errors data type => SourceException kind (or null)
     */
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly array $days,
        public readonly array $errors,
        public readonly ?int $fetchedAt,
        public readonly string $today = '9999-12-31'
    ) {
    }

    public function day(string $date): ?Day
    {
        foreach ($this->days as $day) {
            if ($day->date === $date) {
                return $day;
            }
        }

        return null;
    }

    public function summary(): Summary
    {
        return Summary::of($this->days, $this->today);
    }

    public function error(string $type): ?string
    {
        return $this->errors[$type] ?? null;
    }
}
