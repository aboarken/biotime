<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Site\Model;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use BiotimePortal\Component\Biotime\Administrator\Devices\DeviceList;

/**
 * Biometric Devices: every ZKBioTime device the API user may read. Shows no employee data, so it
 * does not need a matched employee (only the zkb.devices.view permission).
 */
final class DevicesModel extends PortalModel
{
    private ?DeviceList $list = null;

    public function devices(): DeviceList
    {
        return $this->list ??= $this->biotime()->getDeviceService()->devices();
    }
}
