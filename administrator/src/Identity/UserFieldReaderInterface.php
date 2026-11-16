<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Identity;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Reads the employee code a Joomla user has in a custom user field (e.g. "employeeid").
 */
interface UserFieldReaderInterface
{
    /** The trimmed value, or null when the field does not exist or is empty for this user. */
    public function value(int $joomlaUserId): ?string;

    /**
     * Whether the user may change the field's value themselves (on their own profile). Then it
     * cannot be trusted for an automatic mapping: a user could enter a colleague's code.
     */
    public function editableBy(int $joomlaUserId): bool;
}
