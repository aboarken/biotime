<?php

/**
 * Notices above portal data: unavailable parts and, when it differs from the
 * user's, the server's time zone. The explanatory note and "Updated" time were removed in 1.0.0.
 *
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/** @var array{period: \BiotimePortal\Component\Biotime\Administrator\Attendance\Period, zone: ?string} $displayData */
$period = $displayData['period'];
$e      = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
?>
<?php foreach ($period->errors as $type => $kind) : ?>
    <div class="bt-alert bt-tone-red" role="alert">
        <strong><?php echo $e(Text::_('COM_BIOTIME_PART_' . strtoupper($type))); ?>:</strong>
        <?php echo $e(Text::_('COM_BIOTIME_ERROR_' . strtoupper((string) $kind))); ?>
    </div>
<?php endforeach; ?>
<?php if (!empty($displayData['zone'])) : ?>
    <p class="bt-note"><?php echo $e(Text::sprintf('COM_BIOTIME_TIMES_IN_ZONE', $displayData['zone'])); ?></p>
<?php endif; ?>
