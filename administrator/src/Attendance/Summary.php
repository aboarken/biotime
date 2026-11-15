<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Attendance;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects


/**
 * Figures for a set of days, counted only from values ZKBioTime returned. A figure is null when
 * ZKBioTime does not provide what it needs; pages then leave it out or show "—".
 */
final class Summary
{
    public function __construct(
        public readonly ?int $workingDays,
        public readonly ?int $presentDays,
        public readonly ?int $lateCount,
        public readonly ?int $lateMinutes,
        public readonly ?int $earlyCount,
        public readonly ?int $earlyMinutes,
        public readonly ?int $absentDays,
        public readonly ?int $leaveDays,
        public readonly ?int $holidays,
        public readonly ?int $restDays,
        public readonly ?int $workedMinutes,
        public readonly ?int $overtimeMinutes,
        public readonly int $punchDays,
        public readonly int $exceptionCount
    ) {
    }

    /**
     * @param list<Day> $days
     */
    public static function of(array $days, SourceConfig $daily): self
    {
        $statusKnown = $daily->isConfigured() && $daily->has('status');
        $count       = static fn (array $statuses): ?int => $statusKnown
            ? \count(array_filter($days, static fn (Day $d): bool => \in_array($d->status, $statuses, true)))
            : null;

        $sum = static function (string $field) use ($days, $daily): ?int {
            if (!$daily->isConfigured() || !$daily->has($field)) {
                return null;
            }

            return array_sum(array_map(static fn (Day $d): int => (int) $d->{$field}, $days));
        };

        $occurrences = static function (string $field, string $status) use ($days, $daily, $statusKnown): ?int {
            if (!$daily->isConfigured() || (!$daily->has($field) && !$statusKnown)) {
                return null;
            }

            return \count(array_filter($days, static fn (Day $d): bool => ($d->{$field} ?? 0) > 0 || $d->status === $status));
        };

        return new self(
            $statusKnown ? \count(array_filter($days, static fn (Day $d): bool => $d->status !== null && !\in_array($d->status, ['rest', 'holiday'], true))) : null,
            $count(['present', 'late', 'early']),
            $occurrences('late', 'late'),
            $sum('late'),
            $occurrences('early', 'early'),
            $sum('early'),
            $count(['absent']),
            $count(['leave']),
            $count(['holiday']),
            $count(['rest']),
            $sum('worked'),
            $sum('overtime'),
            \count(array_filter($days, static fn (Day $d): bool => $d->punches !== [])),
            array_sum(array_map(static fn (Day $d): int => \count($d->exceptions), $days))
        );
    }
}
