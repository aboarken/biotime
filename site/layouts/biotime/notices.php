<?php

/**
 * Notices above portal data: unavailable parts, cut-off results, how check-in, check-out and the
 * day statuses are read, the ZKBioTime time zone, and when the data was read.
 *
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/** @var array{period: \BiotimePortal\Component\Biotime\Administrator\Attendance\Period, zone: ?string, updated: string, weekend?: string} $displayData */
$period  = $displayData['period'];
$e       = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
$weekend = (string) ($displayData['weekend'] ?? '');
?>
<?php foreach ($period->errors as $type => $kind) : ?>
    <div class="alert alert-danger" role="alert">
        <?php echo $e(Text::_('COM_BIOTIME_PART_' . strtoupper($type))); ?>:
        <?php echo $e(Text::_('COM_BIOTIME_ERROR_' . strtoupper((string) $kind))); ?>
    </div>
<?php endforeach; ?>
<?php if ($period->isTruncated()) : ?>
    <div class="alert alert-warning" role="alert"><?php echo Text::_('COM_BIOTIME_TRUNCATED'); ?></div>
<?php endif; ?>
<p class="small text-muted mb-2">
    <?php echo $e(Text::_('COM_BIOTIME_NOTE_CHECK_RULE')); ?>
    <?php echo $e($weekend !== '' ? Text::sprintf('COM_BIOTIME_NOTE_WEEKEND_RULE', $weekend) : Text::_('COM_BIOTIME_NOTE_NO_WEEKEND')); ?>
    <?php if (!empty($displayData['zone'])) : ?>
        <?php echo $e(Text::sprintf('COM_BIOTIME_TIMES_IN_ZONE', $displayData['zone'])); ?>
    <?php endif; ?>
    <?php echo $e($displayData['updated']); ?>
</p>
