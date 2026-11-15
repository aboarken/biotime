<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Identity;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects


interface UserMapRepositoryInterface
{
    public function find(int $joomlaUserId): ?UserMapping;

    public function findByEmployeeId(string $employeeId): ?UserMapping;

    /** Insert or update by joomla_user_id. */
    public function save(UserMapping $mapping): void;

    public function delete(int $joomlaUserId): void;
}
