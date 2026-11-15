<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZkbPortal\Component\Zkbiotime\Administrator\Security;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use ZkbPortal\Component\Zkbiotime\Administrator\Audit\AuditCounterInterface;
use ZkbPortal\Component\Zkbiotime\Administrator\Audit\AuditLogInterface;

/**
 * Sliding one-hour limit per user and action, counted from the audit table.
 * Attempts that reached ZKBioTime (ok or error) count; requests refused locally do not.
 */
final class RateLimiter
{
    /** @var \Closure(): \DateTimeImmutable */
    private \Closure $clock;

    public function __construct(private readonly AuditCounterInterface $counter, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function allow(int $userId, string $action, int $perHour): bool
    {
        return $this->allowAny($userId, [$action], $perHour);
    }

    /**
     * One shared budget for several actions (e.g. PDF and Excel exports).
     *
     * @param  list<string>  $actions
     */
    public function allowAny(int $userId, array $actions, int $perHour): bool
    {
        $since = ($this->clock)()->modify('-1 hour');
        $used  = 0;

        foreach ($actions as $action) {
            $used += $this->counter->countSince($userId, $action, $since, [AuditLogInterface::RESULT_OK, AuditLogInterface::RESULT_ERROR]);
        }

        return $used < max(1, $perHour);
    }
}
