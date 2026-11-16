<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Devices;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use BiotimePortal\Component\Biotime\Administrator\Api\ZkbClient;
use BiotimePortal\Component\Biotime\Administrator\Api\ZkbException;
use BiotimePortal\Component\Biotime\Administrator\Attendance\AttendanceService;
use Psr\Log\LoggerInterface;

/**
 * Reads the biometric devices (terminals) from ZKBioTime and tells their health from the last
 * time each device contacted ZKBioTime:
 *  - online:  within the last ONLINE_MINUTES minutes;
 *  - delayed: within the last DELAYED_MINUTES minutes (the device may be busy or briefly offline);
 *  - offline: longer ago, or ZKBioTime's own state text says so;
 *  - unknown: ZKBioTime sent no last activity.
 *
 * The list is kept in the user's session for the cache time (Integration Settings). Only the
 * allow-listed fields below are kept; nothing about employees is read.
 */
final class DeviceService
{
    public const ONLINE_MINUTES  = 10;
    public const DELAYED_MINUTES = 1440;

    private const CACHE_KEY = 'devices';

    /** @var \Closure(): \DateTimeImmutable */
    private \Closure $clock;

    /**
     * @param \Closure(): ZkbClient                    $clientFactory
     * @param \Closure(string): ?array<mixed>          $load   read a cache entry
     * @param \Closure(string, array<mixed>): void     $save   write a cache entry
     * @param int                                      $ttl    seconds; 0 = always read ZKBioTime
     * @param (\Closure(): \DateTimeImmutable)|null    $clock
     */
    public function __construct(
        private readonly \Closure $clientFactory,
        private readonly \Closure $load,
        private readonly \Closure $save,
        private readonly int $ttl,
        private readonly LoggerInterface $logger,
        private readonly \DateTimeZone $serverZone,
        ?\Closure $clock = null
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable('now');
    }

    /**
     * Every device, sorted by area and then name.
     */
    public function devices(): DeviceList
    {
        $now    = ($this->clock)();
        $cached = $this->ttl > 0 ? ($this->load)(self::CACHE_KEY) : null;

        if (\is_array($cached) && isset($cached['rows'], $cached['at']) && \is_array($cached['rows']) && (int) $cached['at'] + $this->ttl > $now->getTimestamp()) {
            return $this->build($cached['rows'], (bool) ($cached['truncated'] ?? false), (int) $cached['at'], null);
        }

        try {
            $result = ($this->clientFactory)()->terminals();
        } catch (ZkbException $e) {
            $this->logger->warning(\sprintf('ZKBioTime devices could not be read: %s', $e->getKind()));

            return new DeviceList([], $e->getKind(), false, null);
        }

        $rows    = [];
        $skipped = 0;

        foreach ($result->items as $item) {
            $row = self::normalise($item);

            if ($row === null) {
                $skipped++;

                continue;
            }

            $rows[] = $row;
        }

        $this->logger->info(\sprintf('ZKBioTime devices: %d read, %d skipped (unreadable)', \count($rows), $skipped));

        $at = $now->getTimestamp();

        if ($this->ttl > 0) {
            ($this->save)(self::CACHE_KEY, ['rows' => $rows, 'truncated' => $result->truncated, 'at' => $at]);
        }

        return $this->build($rows, $result->truncated, $at, null);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function build(array $rows, bool $truncated, ?int $at, ?string $error): DeviceList
    {
        $now     = ($this->clock)()->setTimezone($this->serverZone);
        $devices = [];

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $last    = isset($row['last']) && \is_string($row['last']) ? $row['last'] : null;
            $minutes = null;

            if ($last !== null) {
                try {
                    $then    = new \DateTimeImmutable($last, $this->serverZone);
                    $minutes = max(0, intdiv($now->getTimestamp() - $then->getTimestamp(), 60));
                } catch (\Exception) {
                    $minutes = null;
                }
            }

            $state = isset($row['state']) && \is_string($row['state']) ? $row['state'] : null;

            $devices[] = new Device(
                (string) ($row['alias'] ?? $row['model'] ?? $row['sn'] ?? '—'),
                self::str($row, 'sn'),
                self::str($row, 'alias'),
                self::str($row, 'model'),
                self::str($row, 'ip'),
                self::str($row, 'area'),
                self::str($row, 'fw'),
                $last,
                $minutes,
                self::health($minutes, $state),
                $state,
                self::int($row, 'users'),
                self::int($row, 'fp'),
                self::int($row, 'face'),
                self::int($row, 'palm'),
                self::int($row, 'tx'),
                isset($row['att']) && \is_bool($row['att']) ? $row['att'] : null
            );
        }

        usort($devices, static fn (Device $a, Device $b): int => [mb_strtolower((string) $a->area), mb_strtolower($a->name)] <=> [mb_strtolower((string) $b->area), mb_strtolower($b->name)]);

        return new DeviceList($devices, $error, $truncated, $at);
    }

    /**
     * Health from the minutes since the last activity; ZKBioTime's own state text decides only
     * when it clearly says offline or disabled, or when no last activity was sent.
     */
    public static function health(?int $minutesSince, ?string $stateText): string
    {
        $state = $stateText !== null ? mb_strtolower($stateText) : '';

        if (preg_match('/offline|disable|unauthori[sz]ed|abnormal/', $state)) {
            return Device::OFFLINE;
        }

        if ($minutesSince === null) {
            return str_contains($state, 'online') ? Device::ONLINE : Device::UNKNOWN;
        }

        return match (true) {
            $minutesSince <= self::ONLINE_MINUTES  => Device::ONLINE,
            $minutesSince <= self::DELAYED_MINUTES => Device::DELAYED,
            default                                => Device::OFFLINE,
        };
    }

    /**
     * Allow-listed fields of one /iclock/api/terminals/ row. Names as in the BioTime 9.5 API manual;
     * the area may be an object ({area_name}) or a plain area_name field.
     *
     * @param array<mixed> $item
     *
     * @return array<string, mixed>|null
     */
    private static function normalise(array $item): ?array
    {
        $text = static function (mixed $v, int $max = 100): ?string {
            if (!is_scalar($v) || \is_bool($v)) {
                return null;
            }

            $v = trim(strip_tags((string) $v));

            return $v === '' ? null : mb_substr($v, 0, $max);
        };

        $count = static fn (mixed $v): ?int => (\is_int($v) || (\is_string($v) && ctype_digit($v))) ? (int) $v : null;

        $sn = $text($item['sn'] ?? null, 50);

        if ($sn === null && $text($item['alias'] ?? null) === null) {
            return null;
        }

        $area = $item['area'] ?? null;
        $area = \is_array($area) ? ($text($area['area_name'] ?? null) ?? $text($area['area_code'] ?? null)) : $text($area);
        $area ??= $text($item['area_name'] ?? null);

        $ip = $text($item['ip_address'] ?? null, 45);

        if ($ip !== null && filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $ip = null;
        }

        $last = \is_string($item['last_activity'] ?? null) ? AttendanceService::dateTime($item['last_activity']) : null;

        $stateText = null;

        foreach (['state_display', 'status_display', 'state'] as $key) {
            $value = $text($item[$key] ?? null, 40);

            // A bare state code is not documented with its meaning, so it is not shown.
            if ($value !== null && !ctype_digit($value)) {
                $stateText = $value;

                break;
            }
        }

        $att = $item['is_attendance'] ?? null;

        return array_filter([
            'sn'    => $sn,
            'alias' => $text($item['alias'] ?? null),
            'model' => $text($item['terminal_name'] ?? null),
            'ip'    => $ip,
            'area'  => $area,
            'fw'    => $text($item['fw_ver'] ?? null, 60),
            'last'  => $last,
            'state' => $stateText,
            'users' => $count($item['user_count'] ?? null),
            'fp'    => $count($item['fp_count'] ?? null),
            'face'  => $count($item['face_count'] ?? null),
            'palm'  => $count($item['palm_count'] ?? null),
            'tx'    => $count($item['transaction_count'] ?? null),
            'att'   => \is_bool($att) ? $att : (\in_array($att, [0, 1, '0', '1'], true) ? (bool) (int) $att : null),
        ], static fn ($v) => $v !== null);
    }

    /** @param array<string, mixed> $row */
    private static function str(array $row, string $key): ?string
    {
        return isset($row[$key]) && \is_string($row[$key]) ? $row[$key] : null;
    }

    /** @param array<string, mixed> $row */
    private static function int(array $row, string $key): ?int
    {
        return isset($row[$key]) && \is_int($row[$key]) ? $row[$key] : null;
    }
}
