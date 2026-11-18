<?php

/**
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;

/** @var \BiotimePortal\Component\Biotime\Site\View\Day\HtmlView $this */

$f     = $this->f;
$d     = $this->day;
$e     = fn (string $text): string => $this->escape($text);
$check = fn (string $type): string => LayoutHelper::render('biotime.checktime', ['day' => $d, 'f' => $f, 'type' => $type, 'device' => true]);
$min   = fn (?int $minutes): string => LayoutHelper::render('biotime.minutes', ['minutes' => $minutes, 'f' => $f]);
$calc  = $this->period->calculated;
?>
<div class="com-biotime biotime-day" data-bt-root="day">
    <?php if ($this->canAttendance) : ?><a class="bt-back" href="<?php echo $this->monthUrl('attendance', $this->year, $this->month); ?>">&larr; <?php echo Text::_('COM_BIOTIME_BACK_ATTENDANCE'); ?></a><?php endif; ?>
    <?php if ($this->canCalendar) : ?><a class="bt-back" href="<?php echo $this->monthUrl('calendar', $this->year, $this->month); ?>">&larr; <?php echo Text::_('COM_BIOTIME_BACK_CALENDAR'); ?></a><?php endif; ?>

    <div class="bt-head">
        <div>
            <h1 class="h2"><?php echo $e($this->pageHeading); ?></h1>
            <div class="bt-head-meta">
                <?php echo LayoutHelper::render('biotime.status', ['day' => $d, 'f' => $f]); ?>
                <?php if ($d->workedOnWeekend()) : ?><span class="bt-badge bt-tone-sky"><?php echo Text::_('COM_BIOTIME_WORKED_ON_WEEKEND'); ?></span><?php endif; ?>
                <?php if ($calc && $d->status === 'holiday' && $d->statusRaw !== null) : ?><span><?php echo $e($d->statusRaw); ?></span><?php endif; ?>
                <span><?php echo $e(Text::plural('COM_BIOTIME_N_PUNCHES', count($d->punches))); ?></span>
            </div>
        </div>
    </div>

    <?php echo $this->notices($this->period); ?>

    <section class="bt-panel">
        <div class="bt-panel-head"><h2 class="bt-panel-title"><?php echo Text::_('COM_BIOTIME_CHECK_IN_OUT'); ?></h2></div>
        <dl class="bt-today">
            <?php if ($calc) : ?>
                <div><dt><?php echo Text::_('COM_BIOTIME_COL_SCHEDULE'); ?></dt><dd><?php echo LayoutHelper::render('biotime.schedule', ['day' => $d, 'f' => $f]); ?></dd></div>
            <?php endif; ?>
            <div><dt><?php echo Text::_('COM_BIOTIME_COL_CHECK_IN'); ?></dt><dd><?php echo $check('in'); ?></dd></div>
            <div><dt><?php echo Text::_('COM_BIOTIME_COL_CHECK_OUT'); ?></dt><dd><?php echo $check('out'); ?></dd></div>
            <?php if ($calc) : ?>
                <div><dt><?php echo Text::_('COM_BIOTIME_COL_LATE'); ?></dt><dd><?php echo $min($d->late); ?></dd></div>
                <div><dt><?php echo Text::_('COM_BIOTIME_COL_EARLY'); ?></dt><dd><?php echo $min($d->early); ?></dd></div>
                <div><dt><?php echo Text::_('COM_BIOTIME_COL_LATE_EARLY'); ?></dt><dd><?php echo $min(($d->late ?? 0) + ($d->early ?? 0)); ?></dd></div>
                <div><dt><?php echo Text::_('COM_BIOTIME_COL_WORKED'); ?></dt><dd><?php echo $e($f->duration($d->worked !== null && $d->worked > 0 ? $d->worked : null)); ?></dd></div>
                <?php if (($d->overtime ?? 0) > 0) : ?>
                    <div><dt><?php echo Text::_('COM_BIOTIME_COL_OVERTIME'); ?></dt><dd><?php echo $e($f->duration($d->overtime)); ?></dd></div>
                <?php endif; ?>
            <?php endif; ?>
            <div><dt><?php echo Text::_('COM_BIOTIME_COL_SPAN'); ?></dt><dd><?php echo $e($f->duration($d->span())); ?></dd></div>
            <div><dt><?php echo Text::_('COM_BIOTIME_COL_STATUS'); ?></dt><dd><?php echo LayoutHelper::render('biotime.status', ['day' => $d, 'f' => $f]); ?></dd></div>
        </dl>
    </section>

    <?php // Late and early have their own fields when read from the database. ?>
    <?php $exceptions = $calc ? array_values(array_diff($d->exceptions, ['late', 'early'])) : $d->exceptions; ?>
    <?php if ($exceptions !== []) : ?>
        <div class="bt-alert bt-tone-amber" role="note">
            <strong><?php echo Text::_('COM_BIOTIME_EXCEPTIONS'); ?>:</strong>
            <?php echo $e(implode(', ', array_map([$f, 'exception'], $exceptions))); ?>.
            <?php echo Text::_('COM_BIOTIME_EXCEPTIONS_READONLY'); ?>
        </div>
    <?php endif; ?>

    <section class="bt-panel">
        <div class="bt-panel-head">
            <h2 class="bt-panel-title"><?php echo Text::_('COM_BIOTIME_ALL_PUNCHES'); ?></h2>
            <span class="bt-panel-count"><?php echo count($d->punches); ?></span>
        </div>
        <?php if ($d->punches === []) : ?>
            <p class="bt-panel-body bt-empty"><?php echo Text::_('COM_BIOTIME_NO_PUNCHES'); ?></p>
        <?php else : ?>
            <div class="bt-table-wrap">
                <table class="bt-table">
                    <caption class="visually-hidden"><?php echo Text::_('COM_BIOTIME_ALL_PUNCHES'); ?></caption>
                    <thead><tr>
                        <th scope="col">#</th>
                        <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_TIME'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_PUNCH_STATE'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_DIRECTION'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_VERIFY'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_DEVICE'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_AREA'); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($d->punches as $i => $p) : ?>
                        <tr>
                            <td class="bt-num"><?php echo $i + 1; ?></td>
                            <td><strong><?php echo $e($f->time($p->time)); ?></strong></td>
                            <td><?php echo $e($f->text($p->state)); ?></td>
                            <td>
                                <?php if ($p->time === $d->checkIn) : ?>
                                    <span class="bt-badge bt-tone-green"><?php echo Text::_('COM_BIOTIME_COL_CHECK_IN'); ?></span>
                                <?php elseif ($p->time === $d->checkOut) : ?>
                                    <span class="bt-badge bt-tone-blue"><?php echo Text::_('COM_BIOTIME_COL_CHECK_OUT'); ?></span>
                                <?php else : ?>
                                    <span class="bt-check-none">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $e($f->text($p->verify)); ?></td>
                            <td><?php echo $e($f->text($p->device)); ?></td>
                            <td><?php echo $e($f->text($p->area)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

</div>
