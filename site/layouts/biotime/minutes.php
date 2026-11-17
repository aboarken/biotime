<?php

/**
 * Late-in or early-out of a day (ZKBioTime's calculation, from the database): the time in amber
 * when there is any, else a dash.
 *
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

/** @var array{minutes: ?int, f: \BiotimePortal\Component\Biotime\Administrator\Support\Formatter} $displayData */
$minutes = $displayData['minutes'];
?>
<?php if ($minutes !== null && $minutes > 0) : ?>
    <span class="bt-late"><?php echo htmlspecialchars($displayData['f']->duration($minutes), ENT_QUOTES, 'UTF-8'); ?></span>
<?php else : ?>
    <span class="bt-check-none">—</span>
<?php endif; ?>
