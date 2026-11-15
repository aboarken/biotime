<?php

/**
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use BiotimePortal\Component\Biotime\Administrator\Identity\EmailNormalizer;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;

/** @var \BiotimePortal\Component\Biotime\Administrator\View\Usermappings\HtmlView $this */

$this->getDocument()->getWebAssetManager()->useScript('multiselect');

$badge = [
    'active'    => 'bg-success',
    'suspended' => 'bg-danger',
    'not_found' => 'bg-secondary',
    'ambiguous' => 'bg-warning text-dark',
];
$date = static fn (?string $v): string => $v ? HTMLHelper::_('date', $v, Text::_('DATE_FORMAT_LC5')) : '—';
?>
<form action="<?php echo Route::_('index.php?option=com_biotime&view=usermappings'); ?>" method="post" name="adminForm" id="adminForm">
    <?php echo LayoutHelper::render('joomla.searchtools.default', ['view' => $this]); ?>

    <?php if ($this->items === []) : ?>
        <div class="alert alert-info"><?php echo Text::_('JGLOBAL_NO_MATCHING_RESULTS'); ?></div>
    <?php else : ?>
        <div class="table-responsive">
        <table class="table table-striped" id="usermapList">
            <caption class="visually-hidden"><?php echo Text::_('COM_BIOTIME_MAPPINGS_TITLE'); ?></caption>
            <thead>
                <tr>
                    <td class="w-1 text-center"><?php echo HTMLHelper::_('grid.checkall'); ?></td>
                    <th scope="col"><?php echo Text::_('COM_BIOTIME_MAPPING_JOOMLA_USER'); ?></th>
                    <th scope="col"><?php echo Text::_('COM_BIOTIME_MAPPING_JOOMLA_EMAIL'); ?></th>
                    <th scope="col"><?php echo Text::_('COM_BIOTIME_MAPPING_EMP_CODE'); ?></th>
                    <th scope="col"><?php echo Text::_('COM_BIOTIME_MAPPING_MAPPED_EMAIL'); ?></th>
                    <th scope="col"><?php echo Text::_('COM_BIOTIME_MAPPING_STATUS'); ?></th>
                    <th scope="col"><?php echo Text::_('COM_BIOTIME_MAPPING_SOURCE'); ?></th>
                    <th scope="col"><?php echo Text::_('COM_BIOTIME_MAPPING_MATCHED_AT'); ?></th>
                    <th scope="col"><?php echo Text::_('COM_BIOTIME_MAPPING_CHECKED_AT'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($this->items as $i => $item) :
                $editUrl      = Route::_('index.php?option=com_biotime&view=usermap&layout=edit&joomla_user_id=' . (int) $item->joomla_user_id);
                $hasMapping   = (string) $item->status !== '';
                $emailChanged = $hasMapping && $item->joomla_email !== null && EmailNormalizer::normalize($item->joomla_email) !== $item->email;
                ?>
                <tr>
                    <td class="text-center">
                        <?php if ($hasMapping) : ?>
                            <?php echo HTMLHelper::_('grid.id', $i, (int) $item->joomla_user_id, false, 'cid', 'cb', $this->escape((string) $item->name)); ?>
                        <?php endif; ?>
                    </td>
                    <th scope="row">
                        <?php if ($item->name === null) : ?>
                            <em><?php echo Text::sprintf('COM_BIOTIME_MAPPING_DELETED_USER', (int) $item->joomla_user_id); ?></em>
                        <?php else : ?>
                            <a href="<?php echo $editUrl; ?>"><?php echo $this->escape($item->name); ?></a>
                            <div class="small text-muted"><?php echo $this->escape((string) $item->username); ?></div>
                        <?php endif; ?>
                    </th>
                    <td>
                        <?php echo $this->escape((string) $item->joomla_email); ?>
                        <?php if ($emailChanged) : ?>
                            <span class="badge bg-warning text-dark"><?php echo Text::_('COM_BIOTIME_MAPPING_EMAIL_CHANGED'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $this->escape((string) ($item->emp_code ?? '')) ?: '—'; ?></td>
                    <td><?php echo $hasMapping ? $this->escape((string) $item->email) : '—'; ?></td>
                    <td>
                        <?php if ($hasMapping) : ?>
                            <span class="badge <?php echo $badge[$item->status] ?? 'bg-secondary'; ?>"><?php echo Text::_('COM_BIOTIME_MAPSTATUS_' . strtoupper($item->status)); ?></span>
                        <?php else : ?>
                            <span class="badge bg-light text-dark border"><?php echo Text::_('COM_BIOTIME_MAPSTATUS_NONE'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $hasMapping ? Text::_('COM_BIOTIME_SOURCE_' . strtoupper($item->source)) : '—'; ?></td>
                    <td><?php echo $date($item->matched_at); ?></td>
                    <td><?php echo $date($item->checked_at); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <?php echo $this->pagination->getListFooter(); ?>
    <?php endif; ?>

    <input type="hidden" name="task" value="">
    <input type="hidden" name="boxchecked" value="0">
    <?php echo HTMLHelper::_('form.token'); ?>
</form>
