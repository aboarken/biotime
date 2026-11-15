<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Site\Model;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects


/**
 * My Profile: the employee's own record, through an allow-list of fields.
 */
final class ProfileModel extends PortalModel
{
    /**
     * @return array<string, ?string>
     *
     * @throws \BiotimePortal\Component\Biotime\Administrator\Api\ZkbException
     */
    public function profile(): array
    {
        return $this->biotime()->getProfileService()->profile($this->identity());
    }
}
