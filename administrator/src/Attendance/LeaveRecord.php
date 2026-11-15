<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Attendance;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects


/**
 * One leave record from the configured leave source, as ZKBioTime returned it.
 */
final class LeaveRecord
{
    public function __construct(
        public readonly string $start,
        public readonly ?string $end,
        public readonly ?string $type,
        public readonly ?int $durationMinutes,
        public readonly ?string $durationText,
        public readonly ?string $status,
        public readonly ?string $statusRaw
    ) {
    }

    /** @param array<string, ?string> $row normalised row (see AttendanceService) */
    public static function fromCache(array $row, SourceConfig $source): self
    {
        $raw = $row['status'] ?? null;

        return new self(
            (string) $row['start'],
            $row['end'] ?? null,
            $row['type'] ?? null,
            SourceConfig::toMinutes($row['duration'] ?? null, $source->durationUnit),
            $row['duration'] ?? null,
            $raw !== null ? ($source->statusMap[mb_strtolower($raw)] ?? null) : null,
            $raw
        );
    }

    public function covers(string $date): bool
    {
        return $date >= $this->start && $date <= ($this->end ?? $this->start);
    }
}
