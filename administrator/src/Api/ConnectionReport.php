<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZkbPortal\Component\Zkbiotime\Administrator\Api;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Result of "Test Connection". Contains counts only, never personal data or secrets.
 */
final class ConnectionReport
{
    public function __construct(
        public readonly string $method,
        public readonly bool $staticToken,
        public readonly ?int $tokenRenewAt,
        public readonly ?int $employeeCount,
        public readonly int $elapsedMs
    ) {
    }
}
