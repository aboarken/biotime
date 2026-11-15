<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Tests\Support;

use BiotimePortal\Component\Biotime\Administrator\Api\ClientConfig;
use BiotimePortal\Component\Biotime\Administrator\Api\Credentials;
use BiotimePortal\Component\Biotime\Administrator\Api\MemoryTokenStore;
use BiotimePortal\Component\Biotime\Administrator\Api\ZkbClient;
use BiotimePortal\Component\Biotime\Administrator\Audit\AuditLogInterface;
use BiotimePortal\Component\Biotime\Administrator\Cache\StoredUserCache;
use BiotimePortal\Component\Biotime\Administrator\Identity\UserFieldReaderInterface;
use BiotimePortal\Component\Biotime\Administrator\Identity\UserMapping;
use BiotimePortal\Component\Biotime\Administrator\Identity\UserMapRepositoryInterface;

final class MemoryUserMapRepository implements UserMapRepositoryInterface
{
    /** @var array<int, UserMapping> */
    public array $rows = [];

    public function find(int $joomlaUserId): ?UserMapping
    {
        return $this->rows[$joomlaUserId] ?? null;
    }

    public function findByEmployeeId(string $employeeId): ?UserMapping
    {
        foreach ($this->rows as $row) {
            if ($row->employeeId === $employeeId) {
                return $row;
            }
        }

        return null;
    }

    public function save(UserMapping $mapping): void
    {
        // Like the unique index on employee_id.
        $owner = $mapping->employeeId !== null ? $this->findByEmployeeId($mapping->employeeId) : null;

        if ($owner !== null && $owner->joomlaUserId !== $mapping->joomlaUserId) {
            throw new \RuntimeException('Duplicate employee_id');
        }

        $this->rows[$mapping->joomlaUserId] = $mapping;
    }

    public function delete(int $joomlaUserId): void
    {
        unset($this->rows[$joomlaUserId]);
    }
}

final class MemoryAudit implements AuditLogInterface
{
    /** @var list<array{user: int, action: string, result: string, detail: string, actor: int}> */
    public array $records = [];

    public function record(int $userId, string $action, string $result, string $detail = '', int $actorId = 0): void
    {
        $this->records[] = ['user' => $userId, 'action' => $action, 'result' => $result, 'detail' => $detail, 'actor' => $actorId];
    }

    public function actions(): array
    {
        return array_column($this->records, 'action');
    }
}

final class FixedFieldReader implements UserFieldReaderInterface
{
    /** @param array<int, ?string> $values */
    public function __construct(public array $values = [])
    {
    }

    public function value(int $joomlaUserId): ?string
    {
        return $this->values[$joomlaUserId] ?? null;
    }
}

final class Fakes
{
    public static function client(RoutingTransport $transport, int $pageSize = 100, int $maxPages = 50): ZkbClient
    {
        return new ZkbClient(
            $transport,
            new ClientConfig('https://zkb.example.com:5555'),
            new Credentials(Credentials::METHOD_JWT, 'api', 'secret-pass'),
            new MemoryTokenStore(),
            new ArrayLogger(),
            $pageSize,
            $maxPages,
            null,
            0
        );
    }

    /** A session-like cache over a plain array, with a controllable clock. */
    public static function cache(array &$store, int $ttl = 300, ?\Closure $clock = null): StoredUserCache
    {
        return new StoredUserCache(
            static function () use (&$store): array {
                return $store;
            },
            static function (array $data) use (&$store): void {
                $store = $data;
            },
            $ttl,
            $clock
        );
    }
}
