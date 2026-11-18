<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Identity;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use BiotimePortal\Component\Biotime\Administrator\Support\SourceException;

/**
 * Where employees are looked up to match Joomla users: ZKBioTime's database
 * (Database\DbEmployeeDirectory). Records carry id, emp_code, first_name, last_name and email;
 * the id is personnel_employee.id.
 */
interface EmployeeDirectoryInterface
{
    /**
     * Every employee with an e-mail address (id, emp_code, email), for matching by e-mail.
     *
     * @return list<array<string, mixed>>
     *
     * @throws SourceException
     */
    public function allEmployees(): array;

    /**
     * Employees with this code (callers still compare emp_code exactly).
     *
     * @return list<array<string, mixed>>
     *
     * @throws SourceException
     */
    public function employeesByCode(string $code): array;

    /**
     * One employee by id.
     *
     * @return array<string, mixed>
     *
     * @throws SourceException NOT_FOUND when there is none
     */
    public function employee(string|int $id): array;
}
