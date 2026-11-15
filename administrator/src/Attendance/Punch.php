<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Attendance;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects


/**
 * One punch (transaction) as ZKBioTime recorded it, in the ZKBioTime server's local time.
 */
final class Punch
{
    public function __construct(
        public readonly string $date,
        public readonly string $time,
        public readonly ?string $state,
        public readonly ?string $device,
        public readonly ?string $area
    ) {
    }

    /** @param array{t: string, s?: ?string, d?: ?string, a?: ?string} $row */
    public static function fromCache(array $row): self
    {
        return new self(substr($row['t'], 0, 10), substr($row['t'], 11), $row['s'] ?? null, $row['d'] ?? null, $row['a'] ?? null);
    }

    public function seconds(): int
    {
        [$h, $m, $s] = array_map('intval', explode(':', $this->time . ':00:00'));

        return $h * 3600 + $m * 60 + $s;
    }
}
