<?php

/**
 * The schedule of a day: its start and end time. The timetable's name (e.g. "IN07:30-OUT15:30")
 * only when there are no times, so the same information is not shown twice; "—" on weekends and
 * days off without times, and never a time without the other (1.0.4).
 *
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

/** @var array{day: \BiotimePortal\Component\Biotime\Administrator\Attendance\Day, f: \BiotimePortal\Component\Biotime\Administrator\Support\Formatter} $displayData */
$day = $displayData['day'];
$f   = $displayData['f'];
$e   = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
?>
<?php if ($day->schedStart !== null && $day->schedEnd !== null) : ?>
    <span class="bt-sched"><?php echo $e($f->time($day->schedStart) . '–' . $f->time($day->schedEnd)); ?></span>
<?php elseif ($day->schedule !== null && !$day->weekend && !\in_array($day->status, ['weekend', 'rest'], true)) : ?>
    <span class="bt-sched"><?php echo $e($day->schedule); ?></span>
<?php else : ?>
    <?php // No start and end (e.g. a weekend's "00:00" time card, 1.0.4): nothing to show. ?>
    <span class="bt-check-none">—</span>
<?php endif; ?>
