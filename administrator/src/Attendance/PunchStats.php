<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Attendance;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects


/**
 * The logged-in employee's own punches counted by how they were verified (fingerprint, face,
 * other), per month and per device, for My Punches. Only counting: nothing is calculated.
 */
final class PunchStats
{
    public const KINDS = [Punch::VERIFY_FINGERPRINT, Punch::VERIFY_FACE, Punch::VERIFY_OTHER];

    /**
     * @param array<int, array{fingerprint: int, face: int, other: int, total: int, days: int}> $months month 1–12 => counts
     * @param list<array{device: string, area: ?string, fingerprint: int, face: int, other: int, total: int, last: string}> $devices most used first
     * @param array{fingerprint: int, face: int, other: int, total: int, days: int} $total
     */
    public function __construct(
        public readonly array $months,
        public readonly array $devices,
        public readonly array $total
    ) {
    }

    /**
     * @param list<Day> $days
     */
    public static function of(array $days): self
    {
        $empty  = ['fingerprint' => 0, 'face' => 0, 'other' => 0, 'total' => 0, 'days' => 0];
        $months = [];
        $devs   = [];
        $total  = $empty;

        foreach ($days as $day) {
            if ($day->punches === []) {
                continue;
            }

            $m = (int) substr($day->date, 5, 2);
            $months[$m] ??= $empty;
            $months[$m]['days']++;
            $total['days']++;

            foreach ($day->punches as $punch) {
                $kind = $punch->verifyKind();
                $months[$m][$kind]++;
                $months[$m]['total']++;
                $total[$kind]++;
                $total['total']++;

                $name = $punch->device ?? '—';
                $devs[$name] ??= ['device' => $name, 'area' => $punch->area, 'fingerprint' => 0, 'face' => 0, 'other' => 0, 'total' => 0, 'last' => ''];
                $devs[$name][$kind]++;
                $devs[$name]['total']++;
                $devs[$name]['area'] ??= $punch->area;
                $devs[$name]['last'] = max($devs[$name]['last'], $day->date . ' ' . $punch->time);
            }
        }

        ksort($months);
        $devices = array_values($devs);
        usort($devices, static fn (array $a, array $b): int => [$b['total'], $a['device']] <=> [$a['total'], $b['device']]);

        return new self($months, $devices, $total);
    }
}
