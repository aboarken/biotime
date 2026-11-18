<?php

/**
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;

/**
 * One day of one employee in the Manager Dashboard table and its PDF report (1.0.11): check-in
 * and check-out, or the day's status when there are no punches, and the day's late-in plus
 * early-out. The cell is amber when there are any, and its tooltip lists them.
 *
 * @var array{day: ?\BiotimePortal\Component\Biotime\Administrator\Attendance\Day, f: \BiotimePortal\Component\Biotime\Administrator\Support\Formatter} $displayData
 */

$day   = $displayData['day'];
$f     = $displayData['f'];
$e     = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
$check = static fn ($day, string $type): string => LayoutHelper::render('biotime.checktime', ['day' => $day, 'f' => $f, 'type' => $type, 'compact' => true]);
$late  = $day?->late ?? 0;
$early = $day?->early ?? 0;
$tip   = $day === null ? '' : implode(' · ', array_filter([
    $day->checkIn !== null ? Text::_('COM_BIOTIME_COL_CHECK_IN') . ' ' . $f->time($day->checkIn) : '',
    $day->checkOut !== null ? Text::_('COM_BIOTIME_COL_CHECK_OUT') . ' ' . $f->time($day->checkOut) : '',
    $late > 0 ? Text::_('COM_BIOTIME_COL_LATE') . ' ' . $f->duration($late) : '',
    $early > 0 ? Text::_('COM_BIOTIME_COL_EARLY') . ' ' . $f->duration($early) : '',
]));
$class = $day === null ? '' : ($day->status === 'holiday' ? 'bt-row-holiday' : ($day->weekend ? 'bt-row-weekend' : ($late + $early > 0 ? 'bt-mgr-late' : '')));
?>
<td class="bt-inout <?php echo $class; ?>"<?php echo $tip !== '' ? ' title="' . $e($tip) . '"' : ''; ?>>
    <?php if ($day === null) : ?>
        <span class="bt-check-none">—</span>
    <?php elseif ($day->punches === [] && $day->status !== null) : ?>
        <?php echo LayoutHelper::render('biotime.status', ['day' => $day, 'f' => $f]); ?>
    <?php else : ?>
        <span class="bt-inout-line"><?php echo $check($day, 'in'); ?></span>
        <span class="bt-inout-line"><?php echo $check($day, 'out'); ?></span>
        <?php if ($late + $early > 0) : ?>
            <span class="bt-mgr-mins"><?php echo $e($f->duration($late + $early)); ?></span>
        <?php endif; ?>
    <?php endif; ?>
</td>
