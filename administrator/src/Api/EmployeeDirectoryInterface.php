<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Api;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Where employees are looked up to match Joomla users: the API (ZkbClient) or, since 0.6.1,
 * ZKBioTime's database (Database\DbEmployeeDirectory). Records carry at least id, emp_code,
 * first_name, last_name and email; ids are the same in both (personnel_employee.id).
 */
interface EmployeeDirectoryInterface
{
    /**
     * Every employee (id, emp_code, email), for matching by e-mail.
     *
     * @throws ZkbException
     */
    public function allEmployees(): ListResult;

    /**
     * Employees with this code (callers still compare emp_code exactly).
     *
     * @return list<array<string, mixed>>
     *
     * @throws ZkbException
     */
    public function employeesByCode(string $code): array;

    /**
     * One employee by id.
     *
     * @return array<string, mixed>
     *
     * @throws ZkbException NOT_FOUND when there is none
     */
    public function employee(string|int $id): array;
}
