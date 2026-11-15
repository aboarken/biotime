<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Identity;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects


/**
 * One row of #__biotime_usermap: one Joomla user mapped to one ZKBioTime employee.
 */
final class UserMapping
{
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_AMBIGUOUS = 'ambiguous';
    public const STATUS_NOT_FOUND = 'not_found';
    public const STATUS_SUSPENDED = 'suspended';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_AMBIGUOUS, self::STATUS_NOT_FOUND, self::STATUS_SUSPENDED];

    public const SOURCE_AUTO  = 'auto';
    public const SOURCE_ADMIN = 'admin';

    public const SOURCES = [self::SOURCE_AUTO, self::SOURCE_ADMIN];

    public function __construct(
        public readonly int $joomlaUserId,
        public readonly ?string $employeeId,
        public readonly ?string $empCode,
        public readonly string $email,
        public readonly string $status,
        public readonly string $source,
        public readonly ?\DateTimeImmutable $matchedAt,
        public readonly \DateTimeImmutable $checkedAt,
        public readonly int $modifiedBy = 0
    ) {
        if ($joomlaUserId <= 0) {
            throw new \InvalidArgumentException('Invalid Joomla user id');
        }

        if (!\in_array($status, self::STATUSES, true) || !\in_array($source, self::SOURCES, true)) {
            throw new \InvalidArgumentException('Invalid mapping status or source');
        }

        if ($employeeId !== null && !preg_match('/^[1-9][0-9]{0,19}$/', $employeeId)) {
            throw new \InvalidArgumentException('Invalid employee id');
        }

        if ($empCode !== null && !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,29}$/', $empCode)) {
            throw new \InvalidArgumentException('Invalid employee code');
        }

        if ($status === self::STATUS_ACTIVE && ($employeeId === null || $empCode === null)) {
            throw new \InvalidArgumentException('An active mapping needs an employee');
        }
    }

    /**
     * Copy with changes. Keys: employeeId, empCode, email, status, source, matchedAt, checkedAt, modifiedBy.
     *
     * @param array<string, mixed> $changes
     */
    public function with(array $changes): self
    {
        $get = fn (string $k, mixed $current): mixed => \array_key_exists($k, $changes) ? $changes[$k] : $current;

        return new self(
            $this->joomlaUserId,
            $get('employeeId', $this->employeeId),
            $get('empCode', $this->empCode),
            $get('email', $this->email),
            $get('status', $this->status),
            $get('source', $this->source),
            $get('matchedAt', $this->matchedAt),
            $get('checkedAt', $this->checkedAt),
            $get('modifiedBy', $this->modifiedBy)
        );
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
