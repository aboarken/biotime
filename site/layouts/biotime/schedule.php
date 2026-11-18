<?php

/**
 * The schedule of a day: its start and end time. The timetable's name (e.g. "IN07:30-OUT15:30")
 * only when there are no times, so the same information is not shown twice.
 *
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

/** @var array{day: \BiotimePortal\Component\Biotime\Administrator\Attendance\Day, f: \BiotimePortal\Component\Biotime\Administrator\Support\Formatter} $displayData */
$day = $displayData['day'];
$f   = $displayData['f'];
$e   = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
?>
<?php if ($day->schedule === null && $day->schedStart === null) : ?>
    <span class="bt-check-none">—</span>
<?php else : ?>
    <?php if ($day->schedStart !== null || $day->schedEnd !== null) : ?>
        <span class="bt-sched"><?php echo $e($f->time($day->schedStart) . '–' . $f->time($day->schedEnd)); ?></span>
    <?php else : ?>
        <span class="bt-sched"><?php echo $e((string) $day->schedule); ?></span>
    <?php endif; ?>
<?php endif; ?>
