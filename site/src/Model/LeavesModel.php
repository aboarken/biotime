<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Site\Model;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use BiotimePortal\Component\Biotime\Administrator\Database\DbException;

/**
 * My Leaves (1.1.0): the logged-in employee's own leave requests in ZKBioTime, read only. The
 * employee is the server-side mapping; nothing in the request chooses whose requests are read.
 */
final class LeavesModel extends PortalModel
{
    /**
     * @return list<array<string, mixed>>
     *
     * @throws DbException
     */
    public function requests(): array
    {
        return $this->biotime()->getLeaveReader()->requestsOf((int) $this->identity()->employeeId());
    }
}
