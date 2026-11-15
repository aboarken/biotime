<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Cache;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use BiotimePortal\Component\Biotime\Administrator\Identity\Identity;

/**
 * Short-lived cache whose keys always include the Joomla user id and the employee code.
 * Only data already checked for ownership may be stored.
 */
interface UserCacheInterface
{
    /** @return array<mixed>|null */
    public function get(Identity $identity, string $key): ?array;

    /** @param array<mixed> $value */
    public function set(Identity $identity, string $key, array $value): void;

    public function clear(Identity $identity, string $prefix = ''): void;
}
