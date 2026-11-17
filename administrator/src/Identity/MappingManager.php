<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Identity;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects


use BiotimePortal\Component\Biotime\Administrator\Api\EmployeeDirectoryInterface;
use BiotimePortal\Component\Biotime\Administrator\Api\ZkbClient;
use BiotimePortal\Component\Biotime\Administrator\Api\ZkbException;
use BiotimePortal\Component\Biotime\Administrator\Audit\AuditLogInterface;

/**
 * Administrator operations on #__biotime_usermap. Every change is audited with the administrator
 * as actor. One employee can never be mapped to two Joomla users.
 */
final class MappingManager
{
    /** @var \Closure(): \DateTimeImmutable */
    private \Closure $clock;

    /**
     * @param \Closure(): EmployeeDirectoryInterface $clientFactory
     * @param (\Closure(): \DateTimeImmutable)|null $clock
     */
    public function __construct(
        private readonly UserMapRepositoryInterface $repository,
        private readonly \Closure $clientFactory,
        private readonly AuditLogInterface $audit,
        ?\Closure $clock = null
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * Map a Joomla user to the employee with this exact employee code.
     *
     * @return array<string, mixed> The employee record (to confirm the name to the administrator)
     *
     * @throws MappingException|ZkbException
     */
    public function assign(int $joomlaUserId, string $joomlaEmail, string $empCode, int $actorId): array
    {
        if ($joomlaUserId <= 0) {
            throw new MappingException('COM_BIOTIME_MAPPING_ERR_USER');
        }

        $empCode = trim($empCode);

        try {
            ZkbClient::empCode($empCode);
        } catch (\InvalidArgumentException) {
            throw new MappingException('COM_BIOTIME_MAPPING_ERR_CODE');
        }

        $exact = array_values(array_filter(
            ($this->clientFactory)()->employeesByCode($empCode),
            static fn (array $e): bool => (string) ($e['emp_code'] ?? '') === $empCode
        ));

        if ($exact === []) {
            throw new MappingException('COM_BIOTIME_MAPPING_ERR_NOT_FOUND');
        }

        if (\count($exact) > 1) {
            throw new MappingException('COM_BIOTIME_MAPPING_ERR_CODE_AMBIGUOUS');
        }

        $employee   = $exact[0];
        $employeeId = (string) ($employee['id'] ?? '');

        try {
            ZkbClient::id($employeeId);
        } catch (\InvalidArgumentException) {
            throw new ZkbException(ZkbException::BAD_RESPONSE, 'Employee without a valid id');
        }

        $owner = $this->repository->findByEmployeeId($employeeId);

        if ($owner !== null && $owner->joomlaUserId !== $joomlaUserId) {
            $this->audit->record($joomlaUserId, 'mapping.assign', AuditLogInterface::RESULT_DENIED, 'employee_already_mapped:' . $owner->joomlaUserId, $actorId);

            throw new MappingException('COM_BIOTIME_MAPPING_ERR_TAKEN');
        }

        $now = ($this->clock)();

        $this->repository->save(new UserMapping(
            $joomlaUserId,
            $employeeId,
            $empCode,
            EmailNormalizer::normalize($joomlaEmail),
            UserMapping::STATUS_ACTIVE,
            UserMapping::SOURCE_ADMIN,
            $now,
            $now,
            $actorId
        ));

        $this->audit->record($joomlaUserId, 'mapping.assign', AuditLogInterface::RESULT_OK, 'employee:' . $employeeId, $actorId);

        return $employee;
    }

    /**
     * Re-activate a mapping (e.g. after a suspension) for the same employee code.
     *
     * @return array<string, mixed>
     *
     * @throws MappingException|ZkbException
     */
    public function confirm(int $joomlaUserId, string $joomlaEmail, int $actorId): array
    {
        $mapping = $this->repository->find($joomlaUserId);

        if ($mapping === null || $mapping->empCode === null) {
            throw new MappingException('COM_BIOTIME_MAPPING_ERR_NOTHING_TO_CONFIRM');
        }

        return $this->assign($joomlaUserId, $joomlaEmail, $mapping->empCode, $actorId);
    }

    /**
     * @throws MappingException
     */
    public function suspend(int $joomlaUserId, int $actorId): void
    {
        $mapping = $this->repository->find($joomlaUserId) ?? throw new MappingException('COM_BIOTIME_MAPPING_ERR_NO_MAPPING');

        $this->repository->save($mapping->with(['status' => UserMapping::STATUS_SUSPENDED, 'checkedAt' => ($this->clock)(), 'modifiedBy' => $actorId]));
        $this->audit->record($joomlaUserId, 'mapping.suspend', AuditLogInterface::RESULT_OK, '', $actorId);
    }

    /** Delete the mapping, so the next visit runs automatic matching again. */
    public function rematch(int $joomlaUserId, int $actorId): void
    {
        $this->repository->delete($joomlaUserId);
        $this->audit->record($joomlaUserId, 'mapping.rematch', AuditLogInterface::RESULT_OK, '', $actorId);
    }
}
