<?php

/**
 * A day's status as a badge: colour plus a text label (and a decorative symbol), so it never
 * depends on colour alone.
 *
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

/** @var array{day: \BiotimePortal\Component\Biotime\Administrator\Attendance\Day, f: \BiotimePortal\Component\Biotime\Administrator\Support\Formatter} $displayData */
$day    = $displayData['day'];
$f      = $displayData['f'];
$status = $day->status ?? ($day->punches !== [] ? 'punched' : 'none');
$symbol = [
    'present' => '✓', 'late' => '⏱', 'early' => '↧', 'absent' => '✕', 'leave' => '✈',
    'holiday' => '★', 'rest' => '◦', 'incomplete' => '!', 'other' => '•', 'punched' => '•', 'none' => '',
][$status] ?? '•';
$label = match ($status) {
    'punched' => \Joomla\CMS\Language\Text::_('COM_BIOTIME_STATUS_PUNCHED'),
    'none'    => '—',
    default   => $f->status($day),
};
?>
<span class="biotime-status biotime-status-<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>"><?php if ($symbol !== '') : ?><span aria-hidden="true"><?php echo $symbol; ?></span> <?php endif; ?><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></span>
