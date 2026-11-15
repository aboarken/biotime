<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Identity;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects


/**
 * The logged-in user's ZKBioTime employee, resolved on the server from #__biotime_usermap.
 *
 * This is the only source of the employee whose data a page may show. Ids, codes or dates in a
 * URL never choose the employee.
 */
final class Identity
{
    private function __construct(
        public readonly int $joomlaUserId,
        private readonly ?string $employeeId,
        private readonly ?string $empCode,
        public readonly string $status
    ) {
    }

    public static function matched(int $joomlaUserId, string $employeeId, string $empCode): self
    {
        return new self($joomlaUserId, $employeeId, $empCode, UserMapping::STATUS_ACTIVE);
    }

    public static function notMatched(int $joomlaUserId, string $status): self
    {
        return new self($joomlaUserId, null, null, $status);
    }

    public function isMatched(): bool
    {
        return $this->employeeId !== null;
    }

    public function employeeId(): string
    {
        return $this->employeeId ?? throw new \LogicException('Identity is not matched');
    }

    public function empCode(): string
    {
        return $this->empCode ?? throw new \LogicException('Identity is not matched');
    }
}
