<?php

/**
 * The schedule ZKBioTime applied to a day: the timetable's name and its start and end time.
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
    <?php endif; ?>
    <?php if ($day->schedule !== null) : ?>
        <span class="bt-sub"><?php echo $e($day->schedule); ?></span>
    <?php endif; ?>
<?php endif; ?>
