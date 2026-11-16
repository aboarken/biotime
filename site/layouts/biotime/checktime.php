<?php

/**
 * A check-in or check-out time, shown the same way on every page: an arrow and a fixed colour
 * (green in, blue out), the device and verification below, or "Missing" when the day has punches
 * but not this one.
 *
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use BiotimePortal\Component\Biotime\Administrator\Attendance\Day;
use Joomla\CMS\Language\Text;

/**
 * @var array{day: Day, f: \BiotimePortal\Component\Biotime\Administrator\Support\Formatter, type: 'in'|'out', device?: bool, compact?: bool} $displayData
 */
$day     = $displayData['day'];
$f       = $displayData['f'];
$in      = $displayData['type'] === 'in';
$time    = $in ? $day->checkIn : $day->checkOut;
$compact = !empty($displayData['compact']);
$e       = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
$label   = Text::_($in ? 'COM_BIOTIME_COL_CHECK_IN' : 'COM_BIOTIME_COL_CHECK_OUT');
$punch   = $day->punchAt($time);
$missing = $time === null && ($day->checkIn !== null || $day->checkOut !== null || $day->punches !== [])
    && !($day->status === Day::STATUS_CHECKED_IN && !$in);
?>
<?php if ($time !== null) : ?>
    <span class="bt-check bt-check-<?php echo $in ? 'in' : 'out'; ?>"<?php echo $compact ? ' title="' . $e($label) . '"' : ''; ?>><span aria-hidden="true"><?php echo $in ? '↘' : '↗'; ?></span><?php if ($compact) : ?><span class="visually-hidden"><?php echo $e($label); ?>:</span><?php endif; ?><?php echo $e($f->time($time)); ?></span>
    <?php if (!empty($displayData['device']) && $punch !== null && ($punch->device !== null || $punch->verify !== null)) : ?>
        <span class="bt-check-device"><?php echo $e(implode(' · ', array_filter([$punch->device, $punch->verify]))); ?></span>
    <?php endif; ?>
<?php elseif ($missing) : ?>
    <span class="bt-check bt-check-missing"><?php if ($compact) : ?><span class="visually-hidden"><?php echo $e($label); ?>:</span><?php endif; ?><?php echo Text::_('COM_BIOTIME_CHECK_MISSING'); ?></span>
<?php else : ?>
    <span class="bt-check-none">—</span>
<?php endif; ?>
