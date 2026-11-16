<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Identity;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects


use BiotimePortal\Component\Biotime\Administrator\Api\ZkbClient;
use BiotimePortal\Component\Biotime\Administrator\Api\ZkbException;
use BiotimePortal\Component\Biotime\Administrator\Audit\AuditLogInterface;
use Psr\Log\LoggerInterface;

/**
 * Maps the logged-in Joomla user to exactly one ZKBioTime employee.
 *
 * Rules:
 *  - First choice: the employee code in the user's Joomla custom field (e.g. "employeeid"). It must
 *    equal exactly one employee's emp_code. When the field is set but no employee has that code,
 *    the user is "not found": e-mail is NOT tried, so a wrong code never maps someone else.
 *  - When the field is empty and e-mail matching is enabled, e-mail addresses are compared exactly
 *    after trim + lowercase over every employee the API user may read; list filters are not
 *    trusted, since they may return partial matches.
 *  - Exactly one match maps the user. None is "not found", several are "ambiguous"; both are
 *    retried at most every RECHECK_SECONDS, and an administrator can map by employee code.
 *  - One employee can only be mapped to one Joomla user; a second user becomes "ambiguous".
 *  - An automatic mapping stays valid while the user's field still holds its code, or (for an
 *    e-mail match, with the field empty) while the Joomla e-mail is unchanged; otherwise it is
 *    suspended until an administrator confirms it. Automatic mappings are re-verified against
 *    ZKBioTime once a day (suspended when the employee is gone or its code or e-mail changed).
 *  - Administrator mappings do not depend on e-mail; they are re-verified daily for existence.
 */
final class IdentityResolver
{
    public const RECHECK_SECONDS  = 900;
    public const REVERIFY_SECONDS = 86400;

    /** After a failed daily re-verification (ZKBioTime slow or unreachable), wait this long before trying again. */
    public const REVERIFY_RETRY_SECONDS = 3600;

    /** @var array<int, Identity> */
    private array $memo = [];

    /** @var \Closure(): \DateTimeImmutable */
    private \Closure $clock;

    /**
     * @param \Closure(): ZkbClient                 $clientFactory  Lazy: active mappings need no API call
     * @param (\Closure(): \DateTimeImmutable)|null $clock
     */
    public function __construct(
        private readonly UserMapRepositoryInterface $repository,
        private readonly \Closure $clientFactory,
        private readonly AuditLogInterface $audit,
        private readonly LoggerInterface $logger,
        private readonly bool $autoMatch = true,
        ?\Closure $clock = null,
        private readonly ?UserFieldReaderInterface $fieldReader = null
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * @throws ZkbException when ZKBioTime must be asked and cannot answer (nothing is stored then)
     */
    public function resolve(int $joomlaUserId, string $joomlaEmail): Identity
    {
        if ($joomlaUserId <= 0) {
            return Identity::notMatched(0, UserMapping::STATUS_NOT_FOUND);
        }

        $code = $this->fieldReader?->value($joomlaUserId);

        return $this->memo[$joomlaUserId] ??= $this->doResolve($joomlaUserId, EmailNormalizer::normalize($joomlaEmail), $code);
    }

    private function doResolve(int $userId, string $email, ?string $fieldCode): Identity
    {
        $now     = ($this->clock)();
        $mapping = $this->repository->find($userId);

        if ($mapping !== null) {
            if ($mapping->status === UserMapping::STATUS_SUSPENDED) {
                return Identity::notMatched($userId, UserMapping::STATUS_SUSPENDED);
            }

            if ($mapping->isActive()) {
                if ($mapping->source === UserMapping::SOURCE_AUTO) {
                    $stillValid = $fieldCode !== null ? $fieldCode === $mapping->empCode : $mapping->email === $email;

                    if (!$stillValid) {
                        $this->suspend($mapping, $now, $fieldCode !== null ? 'employee_field_changed' : 'joomla_email_changed');

                        return Identity::notMatched($userId, UserMapping::STATUS_SUSPENDED);
                    }
                }

                if ($this->age($mapping, $now) >= self::REVERIFY_SECONDS) {
                    return $this->reverify($mapping, $email, $fieldCode !== null, $now);
                }

                return Identity::matched($userId, (string) $mapping->employeeId, (string) $mapping->empCode);
            }

            // Not found or ambiguous: reuse the recent result unless the e-mail or the code changed.
            if ($mapping->email === $email && $mapping->empCode === $fieldCode && $this->age($mapping, $now) < self::RECHECK_SECONDS) {
                return Identity::notMatched($userId, $mapping->status);
            }
        }

        if ($fieldCode !== null) {
            // The field is only trusted when the user cannot change it themselves.
            if ($this->fieldReader?->editableBy($userId) === true) {
                $this->logger->warning(\sprintf('Joomla user %d can edit the employee code field themselves, so it is not used for matching. Allow editing it only for administrators (Users → Fields → the field → Permissions: Edit Custom Field Value), or map the user in User Mappings.', $userId));

                return $this->storeNotMatched($userId, $email, UserMapping::STATUS_NOT_FOUND, $now, 'employee_field_editable_by_user', $fieldCode);
            }

            return $this->lookupCode($userId, $email, $fieldCode, $now);
        }

        if (!$this->autoMatch) {
            return Identity::notMatched($userId, $mapping->status ?? UserMapping::STATUS_NOT_FOUND);
        }

        // A user who was not matched and then changed their Joomla e-mail (which Joomla does not
        // verify) could choose a colleague's address: such a match waits for an administrator.
        $hold = $mapping !== null && !$mapping->isActive() && $mapping->email !== '' && $mapping->email !== $email;

        return $this->lookup($userId, $email, $now, $hold);
    }

    /**
     * Match by the employee code in the user's custom field: exactly one employee must have it.
     */
    private function lookupCode(int $userId, string $email, string $code, \DateTimeImmutable $now): Identity
    {
        try {
            ZkbClient::empCode($code);
        } catch (\InvalidArgumentException) {
            return $this->storeNotMatched($userId, $email, UserMapping::STATUS_NOT_FOUND, $now, 'invalid_employee_field');
        }

        $matches = [];

        foreach ($this->client()->employeesByCode($code) as $employee) {
            $id = (string) ($employee['id'] ?? '');

            if ((string) ($employee['emp_code'] ?? '') === $code && preg_match('/^[1-9][0-9]{0,19}$/', $id)) {
                $matches[$id] = $code;
            }
        }

        if ($matches === []) {
            return $this->storeNotMatched($userId, $email, UserMapping::STATUS_NOT_FOUND, $now, 'no_employee_with_code', $code);
        }

        if (\count($matches) > 1) {
            return $this->storeNotMatched($userId, $email, UserMapping::STATUS_AMBIGUOUS, $now, 'several_employees_with_code:' . \count($matches), $code);
        }

        return $this->map($userId, $email, (string) array_key_first($matches), $code, $now, 'field');
    }

    private function lookup(int $userId, string $email, \DateTimeImmutable $now, bool $hold = false): Identity
    {
        if (!EmailNormalizer::isUsable($email)) {
            return $this->storeNotMatched($userId, $email, UserMapping::STATUS_NOT_FOUND, $now, 'invalid_email');
        }

        $result  = $this->client()->allEmployees();
        $matches = [];

        foreach ($result->items as $employee) {
            if (EmailNormalizer::normalize(\is_string($employee['email'] ?? null) ? $employee['email'] : '') !== $email) {
                continue;
            }

            $id   = (string) ($employee['id'] ?? '');
            $code = (string) ($employee['emp_code'] ?? '');

            if (preg_match('/^[1-9][0-9]{0,19}$/', $id) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,29}$/', $code)) {
                $matches[$id] = $code;
            }
        }

        if ($matches === []) {
            if ($result->truncated) {
                $this->logger->warning(\sprintf('Employee matching for Joomla user %d read only part of the employee list (safety maximum); raise it in Integration Settings', $userId));
            }

            return $this->storeNotMatched($userId, $email, UserMapping::STATUS_NOT_FOUND, $now, 'no_employee_with_email');
        }

        if (\count($matches) > 1) {
            $this->logger->warning(\sprintf('Employee matching for Joomla user %d: %d employees share the e-mail address; an administrator must map by employee code', $userId, \count($matches)));

            return $this->storeNotMatched($userId, $email, UserMapping::STATUS_AMBIGUOUS, $now, 'several_employees:' . \count($matches));
        }

        $employeeId = (string) array_key_first($matches);

        return $this->map($userId, $email, $employeeId, $matches[$employeeId], $now, 'email', $hold);
    }

    /** Store an automatic mapping unless the employee already belongs to another Joomla user. */
    private function map(int $userId, string $email, string $employeeId, string $empCode, \DateTimeImmutable $now, string $method, bool $hold = false): Identity
    {
        $owner = $this->repository->findByEmployeeId($employeeId);

        if ($owner !== null && $owner->joomlaUserId !== $userId) {
            $this->logger->warning(\sprintf('Employee %s is already mapped to Joomla user %d; not mapping user %d', $employeeId, $owner->joomlaUserId, $userId));

            return $this->storeNotMatched($userId, $email, UserMapping::STATUS_AMBIGUOUS, $now, 'employee_already_mapped:' . $owner->joomlaUserId, $method === 'field' ? $empCode : null);
        }

        if ($hold) {
            $this->repository->save(new UserMapping($userId, $employeeId, $empCode, $email, UserMapping::STATUS_SUSPENDED, UserMapping::SOURCE_AUTO, $now, $now));
            $this->audit->record($userId, 'identity.suspended', AuditLogInterface::RESULT_DENIED, 'email_changed_while_unmatched employee:' . $employeeId);
            $this->logger->warning(\sprintf('Joomla user %d matched an employee only after changing their e-mail; the mapping waits for an administrator to confirm it in User Mappings', $userId));

            return Identity::notMatched($userId, UserMapping::STATUS_SUSPENDED);
        }

        $this->repository->save(new UserMapping($userId, $employeeId, $empCode, $email, UserMapping::STATUS_ACTIVE, UserMapping::SOURCE_AUTO, $now, $now));
        $this->audit->record($userId, 'identity.matched', AuditLogInterface::RESULT_OK, 'by_' . $method . ' employee:' . $employeeId);

        return Identity::matched($userId, $employeeId, $empCode);
    }

    private function reverify(UserMapping $mapping, string $email, bool $byField, \DateTimeImmutable $now): Identity
    {
        $userId = $mapping->joomlaUserId;

        try {
            $employee = $this->client()->employee((string) $mapping->employeeId);
        } catch (ZkbException $e) {
            if ($e->getKind() === ZkbException::NOT_FOUND) {
                $this->suspend($mapping, $now, 'employee_not_found');

                return Identity::notMatched($userId, UserMapping::STATUS_SUSPENDED);
            }

            // ZKBioTime unavailable: keep the verified mapping and try again in an hour, not on every
            // page load (each attempt waits for ZKBioTime, which made every page slow). The check
            // time is set so that the mapping is due again after REVERIFY_RETRY_SECONDS.
            $this->logger->warning(\sprintf('Could not re-verify the mapping of Joomla user %d (%s); trying again in %d minutes', $userId, $e->getKind(), intdiv(self::REVERIFY_RETRY_SECONDS, 60)));
            $this->repository->save($mapping->with(['checkedAt' => $now->modify('-' . (self::REVERIFY_SECONDS - self::REVERIFY_RETRY_SECONDS) . ' seconds')]));

            return Identity::matched($userId, (string) $mapping->employeeId, (string) $mapping->empCode);
        }

        if ((string) ($employee['emp_code'] ?? '') !== $mapping->empCode) {
            $this->suspend($mapping, $now, 'employee_code_changed');

            return Identity::notMatched($userId, UserMapping::STATUS_SUSPENDED);
        }

        if ($mapping->source === UserMapping::SOURCE_AUTO && !$byField && EmailNormalizer::normalize(\is_string($employee['email'] ?? null) ? $employee['email'] : '') !== $email) {
            $this->suspend($mapping, $now, 'employee_email_changed');

            return Identity::notMatched($userId, UserMapping::STATUS_SUSPENDED);
        }

        $this->repository->save($mapping->with(['checkedAt' => $now]));

        return Identity::matched($userId, (string) $mapping->employeeId, (string) $mapping->empCode);
    }

    private function suspend(UserMapping $mapping, \DateTimeImmutable $now, string $reason): void
    {
        $this->repository->save($mapping->with(['status' => UserMapping::STATUS_SUSPENDED, 'checkedAt' => $now]));
        $this->audit->record($mapping->joomlaUserId, 'identity.suspended', AuditLogInterface::RESULT_DENIED, $reason);
    }

    /**
     * @param ?string $triedCode the code from the user's field, kept so a changed field is retried at once
     */
    private function storeNotMatched(int $userId, string $email, string $status, \DateTimeImmutable $now, string $reason, ?string $triedCode = null): Identity
    {
        $this->repository->save(new UserMapping($userId, null, $triedCode, $email, $status, UserMapping::SOURCE_AUTO, null, $now));
        $this->audit->record($userId, 'identity.' . $status, AuditLogInterface::RESULT_DENIED, $reason);

        return Identity::notMatched($userId, $status);
    }

    private function age(UserMapping $mapping, \DateTimeImmutable $now): int
    {
        return $now->getTimestamp() - $mapping->checkedAt->getTimestamp();
    }

    private function client(): ZkbClient
    {
        return ($this->clientFactory)();
    }
}
