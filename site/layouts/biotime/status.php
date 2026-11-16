<?php

/**
 * A day's status as a badge in the portal's design: a coloured pill with a dot and a text label,
 * so it never depends on colour alone. A present day whose check-in or check-out is missing also
 * gets the amber "Missing Punch" tag.
 *
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use BiotimePortal\Component\Biotime\Site\View\Ui;

/** @var array{day: \BiotimePortal\Component\Biotime\Administrator\Attendance\Day, f: \BiotimePortal\Component\Biotime\Administrator\Support\Formatter} $displayData */
$day    = $displayData['day'];
$f      = $displayData['f'];
$status = $day->status ?? ($day->punches !== [] ? 'punched' : 'none');

if ($status === 'none') {
    echo '<span class="bt-check-none">—</span>';

    return;
}

$label = $status === 'punched' ? \Joomla\CMS\Language\Text::_('COM_BIOTIME_STATUS_PUNCHED') : $f->status($day);
?>
<span class="bt-badges"><span class="bt-badge bt-tone-<?php echo Ui::tone($status); ?>"><span class="bt-dot" aria-hidden="true"></span><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></span><?php if ($day->missingPunch()) : ?><span class="bt-badge bt-tone-amber"><span aria-hidden="true">!</span><?php echo \Joomla\CMS\Language\Text::_('COM_BIOTIME_MISSING_PUNCH'); ?></span><?php endif; ?></span>
