<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Identity;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects


use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

final class DbUserMapRepository implements UserMapRepositoryInterface
{
    private const TABLE  = '#__biotime_usermap';
    private const FORMAT = 'Y-m-d H:i:s';

    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    public function find(int $joomlaUserId): ?UserMapping
    {
        $query = $this->db->createQuery()
            ->select('*')
            ->from($this->db->quoteName(self::TABLE))
            ->where($this->db->quoteName('joomla_user_id') . ' = :id')
            ->bind(':id', $joomlaUserId, ParameterType::INTEGER);

        $row = $this->db->setQuery($query)->loadObject();

        return $row ? $this->hydrate($row) : null;
    }

    public function findByEmployeeId(string $employeeId): ?UserMapping
    {
        $query = $this->db->createQuery()
            ->select('*')
            ->from($this->db->quoteName(self::TABLE))
            ->where($this->db->quoteName('employee_id') . ' = :eid')
            ->bind(':eid', $employeeId);

        $row = $this->db->setQuery($query, 0, 1)->loadObject();

        return $row ? $this->hydrate($row) : null;
    }

    public function save(UserMapping $mapping): void
    {
        $utc = new \DateTimeZone('UTC');
        $row = (object) [
            'joomla_user_id' => $mapping->joomlaUserId,
            'employee_id'    => $mapping->employeeId,
            'emp_code'       => $mapping->empCode,
            'email'          => $mapping->email,
            'status'         => $mapping->status,
            'source'         => $mapping->source,
            'matched_at'     => $mapping->matchedAt?->setTimezone($utc)->format(self::FORMAT),
            'checked_at'     => $mapping->checkedAt->setTimezone($utc)->format(self::FORMAT),
            'modified_by'    => $mapping->modifiedBy,
        ];

        if ($this->find($mapping->joomlaUserId) !== null) {
            $this->db->updateObject(self::TABLE, $row, 'joomla_user_id', true);

            return;
        }

        try {
            $this->db->insertObject(self::TABLE, $row);
        } catch (\RuntimeException $e) {
            // Another request inserted the row first (or the employee is already mapped).
            if ($this->find($mapping->joomlaUserId) === null) {
                throw $e;
            }

            $this->db->updateObject(self::TABLE, $row, 'joomla_user_id', true);
        }
    }

    public function delete(int $joomlaUserId): void
    {
        $query = $this->db->createQuery()
            ->delete($this->db->quoteName(self::TABLE))
            ->where($this->db->quoteName('joomla_user_id') . ' = :id')
            ->bind(':id', $joomlaUserId, ParameterType::INTEGER);

        $this->db->setQuery($query)->execute();
    }

    private function hydrate(object $row): UserMapping
    {
        $utc = new \DateTimeZone('UTC');

        return new UserMapping(
            (int) $row->joomla_user_id,
            $row->employee_id !== null && $row->employee_id !== '' ? (string) $row->employee_id : null,
            $row->emp_code !== null && $row->emp_code !== '' ? (string) $row->emp_code : null,
            (string) $row->email,
            (string) $row->status,
            (string) $row->source,
            $row->matched_at ? new \DateTimeImmutable((string) $row->matched_at, $utc) : null,
            new \DateTimeImmutable((string) $row->checked_at, $utc),
            (int) $row->modified_by
        );
    }
}
