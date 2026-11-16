<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Identity;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Factory;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * Reads a Joomla custom user field (context com_users.user) straight from #__fields_values, so
 * the value is the stored one and not a rendered display value. Only published fields count.
 */
final class DbUserFieldReader implements UserFieldReaderInterface
{
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly string $fieldName
    ) {
    }

    public function value(int $joomlaUserId): ?string
    {
        if ($this->fieldName === '' || $joomlaUserId <= 0) {
            return null;
        }

        // bind() takes its values by reference: use local copies.
        $itemId = (string) $joomlaUserId;
        $name   = $this->fieldName;
        $query  = $this->db->createQuery()
            ->select($this->db->quoteName('v.value'))
            ->from($this->db->quoteName('#__fields_values', 'v'))
            ->join('INNER', $this->db->quoteName('#__fields', 'f'), $this->db->quoteName('f.id') . ' = ' . $this->db->quoteName('v.field_id'))
            ->where($this->db->quoteName('f.name') . ' = :name')
            ->where($this->db->quoteName('f.context') . ' = ' . $this->db->quote('com_users.user'))
            ->where($this->db->quoteName('f.state') . ' = 1')
            ->where($this->db->quoteName('v.item_id') . ' = :item')
            ->bind(':name', $name)
            ->bind(':item', $itemId, ParameterType::STRING);

        $value = $this->db->setQuery($query, 0, 1)->loadResult();
        $value = \is_string($value) ? trim($value) : '';

        return $value === '' ? null : mb_substr($value, 0, 100);
    }

    /**
     * Whether the user may change the field's value on their own profile, by the same rules Joomla
     * uses for the profile form: the field is shown on the site (not "Administrator" only) and the
     * user has "Edit Custom Field Value" (core.edit.value) on the field. When unsure (the field or
     * the user cannot be read), the field is treated as editable, so it is not trusted.
     */
    public function editableBy(int $joomlaUserId): bool
    {
        if ($this->fieldName === '' || $joomlaUserId <= 0) {
            return false;
        }

        try {
            $name  = $this->fieldName;
            $query = $this->db->createQuery()
                ->select($this->db->quoteName(['id', 'params']))
                ->from($this->db->quoteName('#__fields'))
                ->where($this->db->quoteName('name') . ' = :name')
                ->where($this->db->quoteName('context') . ' = ' . $this->db->quote('com_users.user'))
                ->where($this->db->quoteName('state') . ' = 1')
                ->bind(':name', $name);

            $field = $this->db->setQuery($query, 0, 1)->loadObject();

            if ($field === null) {
                return false;
            }

            $params = json_decode((string) $field->params, true);

            // "Show On": Administrator (2) only: the user never sees the field on the site.
            if (\is_array($params) && (string) ($params['show_on'] ?? '') === '2') {
                return false;
            }

            $user = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($joomlaUserId);

            // Super Users may edit every field and are trusted anyway.
            if ($user->authorise('core.admin')) {
                return false;
            }

            return $user->authorise('core.edit.value', 'com_users.field.' . (int) $field->id);
        } catch (\Throwable) {
            return true;
        }
    }
}
