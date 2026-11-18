<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Identity;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Checks of ZKBioTime employee ids (personnel_employee.id) and codes (emp_code) before they are
 * stored or go into a query.
 */
final class EmployeeKeys
{
    /**
     * @throws \InvalidArgumentException
     */
    public static function id(string|int $id): string
    {
        $id = (string) $id;

        if (!preg_match('/^[1-9][0-9]{0,19}$/', $id)) {
            throw new \InvalidArgumentException('Invalid ZKBioTime id');
        }

        return $id;
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function code(string $code): string
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,29}$/', $code)) {
            throw new \InvalidArgumentException('Invalid employee code');
        }

        return $code;
    }
}
