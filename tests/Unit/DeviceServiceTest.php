<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Tests\Unit;

use BiotimePortal\Component\Biotime\Administrator\Devices\Device;
use BiotimePortal\Component\Biotime\Administrator\Devices\DeviceService;
use BiotimePortal\Component\Biotime\Tests\Support\ArrayLogger;
use BiotimePortal\Component\Biotime\Tests\Support\Fakes;
use BiotimePortal\Component\Biotime\Tests\Support\RoutingTransport;
use PHPUnit\Framework\TestCase;

/**
 * Biometric Devices: the terminal list, allow-listed fields, health from the last activity,
 * cache and errors.
 */
final class DeviceServiceTest extends TestCase
{
    private RoutingTransport $zkb;

    /** @var array<string, array<mixed>> */
    private array $store = [];

    protected function setUp(): void
    {
        $this->zkb = new RoutingTransport();
        // As in the BioTime 9.5 API manual's example answer of /iclock/api/terminals/.
        $this->zkb->list('/iclock/api/terminals/', [
            ['id' => 1, 'sn' => 'CQZ7232460001', 'ip_address' => '10.0.0.21', 'alias' => 'Main Gate', 'terminal_name' => 'SpeedFace-V5L', 'fw_ver' => 'ZAM180-NF-Ver1.0.9', 'state' => 1,
                'area' => ['id' => 2, 'area_code' => 'HQ', 'area_name' => 'Head Office'], 'last_activity' => '2026-11-20 14:58:00',
                'user_count' => 412, 'fp_count' => 380, 'face_count' => 401, 'palm_count' => 0, 'transaction_count' => 15234, 'is_attendance' => 1, 'comm_key' => 'SECRET-KEY'],
            ['id' => 2, 'sn' => 'CQZ7232460002', 'alias' => 'Library', 'area_name' => 'Boys Campus', 'last_activity' => '2026-11-20 09:00:00', 'ip_address' => 'not-an-ip'],
            ['id' => 3, 'sn' => 'CQZ7232460003', 'alias' => 'Old Gym', 'area' => ['area_name' => 'Boys Campus'], 'last_activity' => '2026-10-01 07:00:00'],
            ['id' => 4, 'sn' => 'CQZ7232460004', 'terminal_name' => 'MB20'],
            ['id' => 5],
        ]);
    }

    private function service(int $ttl = 300): DeviceService
    {
        return new DeviceService(
            fn () => Fakes::client($this->zkb),
            fn (string $k): ?array => $this->store[$k] ?? null,
            function (string $k, array $v): void {
                $this->store[$k] = $v;
            },
            $ttl,
            new ArrayLogger(),
            new \DateTimeZone('Asia/Riyadh'),
            static fn () => new \DateTimeImmutable('2026-11-20 15:00:00', new \DateTimeZone('Asia/Riyadh'))
        );
    }

    public function testDevicesWithNameLocationAndHealth(): void
    {
        $list = $this->service()->devices();

        self::assertNull($list->error);
        self::assertCount(4, $list->devices, 'a row without serial number or name is skipped');

        $byName = [];

        foreach ($list->devices as $d) {
            $byName[$d->name] = $d;
        }

        $gate = $byName['Main Gate'];
        self::assertSame(['Head Office', '10.0.0.21', 'CQZ7232460001', 'SpeedFace-V5L', 'ZAM180-NF-Ver1.0.9'], [$gate->area, $gate->ip, $gate->sn, $gate->model, $gate->firmware]);
        self::assertSame([412, 380, 401, 0, 15234, true], [$gate->users, $gate->fingerprints, $gate->faces, $gate->palms, $gate->transactions, $gate->attendance]);
        self::assertSame([Device::ONLINE, 2], [$gate->health, $gate->minutesSince]);
        self::assertNull($gate->stateText, 'a bare state code is not shown');

        self::assertSame(['Boys Campus', null, Device::DELAYED], [$byName['Library']->area, $byName['Library']->ip, $byName['Library']->health]);
        self::assertSame(Device::OFFLINE, $byName['Old Gym']->health);
        self::assertSame(Device::UNKNOWN, $byName['MB20']->health, 'no last activity');

        self::assertSame([Device::ONLINE => 1, Device::DELAYED => 1, Device::OFFLINE => 1, Device::UNKNOWN => 1], $list->counts());
        self::assertSame(['Boys Campus', 'Head Office'], $list->areas());
        self::assertStringNotContainsString('SECRET-KEY', serialize($list) . serialize($this->store), 'only allow-listed fields are kept');
    }

    public function testHealthRules(): void
    {
        self::assertSame(Device::ONLINE, DeviceService::health(10, null));
        self::assertSame(Device::DELAYED, DeviceService::health(11, null));
        self::assertSame(Device::OFFLINE, DeviceService::health(1441, null));
        self::assertSame(Device::OFFLINE, DeviceService::health(1, 'Offline'), 'ZKBioTime says offline');
        self::assertSame(Device::ONLINE, DeviceService::health(null, 'Online'));
        self::assertSame(Device::UNKNOWN, DeviceService::health(null, null));
    }

    public function testTheListIsCachedAndErrorsAreReported(): void
    {
        $this->service()->devices();
        $calls = \count($this->zkb->calls);
        $this->service()->devices();
        self::assertCount($calls, $this->zkb->calls, 'second read from the cache');

        $this->store = [];
        $this->zkb->on('/iclock/api/terminals/', static fn () => [403, ['detail' => 'no']]);
        $list = $this->service()->devices();
        self::assertSame('permission', $list->error);
        self::assertSame([], $list->devices);
    }
}
