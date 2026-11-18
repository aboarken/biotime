<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Database;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use BiotimePortal\Component\Biotime\Administrator\Identity\EmployeeDirectoryInterface;
use BiotimePortal\Component\Biotime\Administrator\Identity\EmployeeKeys;
use BiotimePortal\Component\Biotime\Administrator\Support\SourceException;

/**
 * Employees from ZKBioTime's database (personnel_employee), for matching Joomla users (0.6.1).
 * Read only; database errors are reported as SourceException kinds, so matching can tell them
 * apart (e.g. an unreachable database keeps a verified mapping and tries again later).
 */
final class DbEmployeeDirectory implements EmployeeDirectoryInterface
{
    private const COLUMNS = 'id, emp_code, first_name, last_name, email';

    private ?Query $query = null;

    /**
     * @param \Closure(): Query $connect opens the connection when first needed
     */
    public function __construct(private readonly \Closure $connect)
    {
    }

    public function allEmployees(): array
    {
        // Only employees with an e-mail address can be matched by it.
        return $this->rows('SELECT ' . self::COLUMNS . " FROM personnel_employee WHERE COALESCE(email, '') <> '' ORDER BY id");
    }

    public function employeesByCode(string $code): array
    {
        return $this->rows('SELECT ' . self::COLUMNS . ' FROM personnel_employee WHERE emp_code = :code ORDER BY id LIMIT 50', [':code' => EmployeeKeys::code($code)]);
    }

    public function employee(string|int $id): array
    {
        $rows = $this->rows('SELECT ' . self::COLUMNS . ' FROM personnel_employee WHERE id = :id', [':id' => (int) EmployeeKeys::id($id)]);

        if ($rows === []) {
            throw new SourceException(SourceException::NOT_FOUND, 'Employee not found in the database');
        }

        return $rows[0];
    }

    /**
     * @param array<string, scalar> $params
     *
     * @return list<array<string, mixed>>
     *
     * @throws SourceException
     */
    private function rows(string $sql, array $params = []): array
    {
        try {
            $this->query ??= ($this->connect)();

            return array_map(static fn (array $r): array => [
                'id'         => (string) $r['id'],
                'emp_code'   => (string) $r['emp_code'],
                'first_name' => $r['first_name'],
                'last_name'  => $r['last_name'],
                'email'      => $r['email'],
            ], $this->query->rows($sql, $params));
        } catch (DbException $e) {
            throw SourceException::fromDatabase($e, 'ZKBioTime database: employees could not be read');
        }
    }
}
