<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Devices;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects


/**
 * The devices read from ZKBioTime, or the error kind when they could not be read.
 */
final class DeviceList
{
    /**
     * @param list<Device> $devices
     */
    public function __construct(
        public readonly array $devices,
        public readonly ?string $error,
        public readonly bool $truncated,
        public readonly ?int $fetchedAt
    ) {
    }

    /** @return array<string, int> health => number of devices (every health, 0 included) */
    public function counts(): array
    {
        $counts = array_fill_keys(Device::HEALTHS, 0);

        foreach ($this->devices as $device) {
            $counts[$device->health]++;
        }

        return $counts;
    }

    /** @return list<string> distinct area names, sorted */
    public function areas(): array
    {
        $areas = array_values(array_unique(array_filter(array_map(static fn (Device $d): ?string => $d->area, $this->devices))));
        sort($areas, SORT_NATURAL | SORT_FLAG_CASE);

        return $areas;
    }
}
