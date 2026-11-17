<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Attendance;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects


/**
 * Attendance of one employee for a date range (a month or a year). Each data type
 * (punches, daily calculated attendance) is read on its own: when one fails, its error
 * kind is set and the others are still shown.
 */
final class Period
{
    /**
     * @param list<Day>         $days      every date of the range, oldest first
     * @param array<string, ?string> $errors    data type => ZkbException kind (or null)
     * @param array<string, bool>    $truncated data type => cut off at the safety maximum
     * @param bool                   $calculated read from ZKBioTime's database: days carry ZKBioTime's
     *                                           schedule, late, early leave and worked time
     */
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly array $days,
        public readonly SourceConfig $daily,
        public readonly array $errors,
        public readonly array $truncated,
        public readonly ?int $fetchedAt,
        public readonly string $today = '9999-12-31',
        public readonly bool $calculated = false
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

    public function isTruncated(): bool
    {
        return \in_array(true, $this->truncated, true);
    }
}
