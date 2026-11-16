<?php

/**
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;

/** @var \BiotimePortal\Component\Biotime\Site\View\Day\HtmlView $this */

$f   = $this->f;
$d   = $this->day;
$check = fn (string $type): string => LayoutHelper::render('biotime.checktime', ['day' => $d, 'f' => $f, 'type' => $type, 'device' => true]);
$dir   = static fn ($p): string => match ($p->direction()) {
    'in'    => Text::_('COM_BIOTIME_DIRECTION_IN'),
    'out'   => Text::_('COM_BIOTIME_DIRECTION_OUT'),
    'break' => Text::_('COM_BIOTIME_DIRECTION_BREAK'),
    default => '—',
};
?>
<div class="com-biotime biotime-day">
    <h1 class="h2 mb-3"><?php echo $this->escape($this->pageHeading); ?></h1>

    <p class="d-flex flex-wrap gap-2">
        <?php if ($this->canAttendance) : ?><a class="btn btn-sm btn-outline-secondary" href="<?php echo $this->monthUrl('attendance', $this->year, $this->month); ?>">‹ <?php echo Text::_('COM_BIOTIME_BACK_ATTENDANCE'); ?></a><?php endif; ?>
        <?php if ($this->canCalendar) : ?><a class="btn btn-sm btn-outline-secondary" href="<?php echo $this->monthUrl('calendar', $this->year, $this->month); ?>">‹ <?php echo Text::_('COM_BIOTIME_BACK_CALENDAR'); ?></a><?php endif; ?>
    </p>

    <?php echo $this->notices($this->period); ?>

    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3"><div class="card h-100"><div class="card-body">
            <div class="small text-muted"><?php echo Text::_('COM_BIOTIME_COL_CHECK_IN'); ?></div>
            <div class="fs-4 fw-semibold"><?php echo $check('in'); ?></div>
        </div></div></div>
        <div class="col-6 col-md-3"><div class="card h-100"><div class="card-body">
            <div class="small text-muted"><?php echo Text::_('COM_BIOTIME_COL_CHECK_OUT'); ?></div>
            <div class="fs-4 fw-semibold"><?php echo $check('out'); ?></div>
        </div></div></div>
        <div class="col-6 col-md-3"><div class="card h-100"><div class="card-body">
            <div class="small text-muted"><?php echo Text::_('COM_BIOTIME_COL_SPAN'); ?></div>
            <div class="fs-4 fw-semibold"><?php echo $this->escape($f->duration($d->span())); ?></div>
        </div></div></div>
        <div class="col-6 col-md-3"><div class="card h-100"><div class="card-body">
            <div class="small text-muted"><?php echo Text::_('COM_BIOTIME_COL_STATUS'); ?></div>
            <div><?php echo LayoutHelper::render('biotime.status', ['day' => $d, 'f' => $f]); ?></div>
            <?php if ($d->workedOnWeekend()) : ?><div class="small text-muted mt-1"><?php echo Text::_('COM_BIOTIME_WORKED_ON_WEEKEND'); ?></div><?php endif; ?>
        </div></div></div>
    </div>
    <?php if ($d->leave !== null) : ?>
        <p><?php echo Text::_('COM_BIOTIME_COL_LEAVE'); ?>: <?php echo $this->escape($d->leave); ?></p>
    <?php endif; ?>

    <?php if ($d->exceptions !== []) : ?>
        <h2 class="h5"><?php echo Text::_('COM_BIOTIME_EXCEPTIONS'); ?></h2>
        <ul>
            <?php foreach ($d->exceptions as $ex) : ?><li><?php echo $this->escape($f->exception($ex)); ?></li><?php endforeach; ?>
        </ul>
        <p class="small text-muted"><?php echo Text::_('COM_BIOTIME_EXCEPTIONS_READONLY'); ?></p>
    <?php endif; ?>

    <h2 class="h5"><?php echo Text::_('COM_BIOTIME_ALL_PUNCHES'); ?></h2>
    <?php if ($d->punches === []) : ?>
        <p class="text-muted"><?php echo Text::_('COM_BIOTIME_NO_PUNCHES'); ?></p>
    <?php else : ?>
        <div class="table-responsive">
            <table class="table table-striped table-sm">
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
                        <th scope="row"><?php echo $i + 1; ?></th>
                        <td><?php echo $this->escape($f->time($p->time)); ?></td>
                        <td><?php echo $this->escape($f->text($p->state)); ?></td>
                        <td><?php echo $this->escape($dir($p)); ?><?php if ($p->time === $d->checkIn) : ?> <span class="badge text-bg-success"><?php echo Text::_('COM_BIOTIME_COL_CHECK_IN'); ?></span><?php elseif ($p->time === $d->checkOut) : ?> <span class="badge text-bg-primary"><?php echo Text::_('COM_BIOTIME_COL_CHECK_OUT'); ?></span><?php endif; ?></td>
                        <td><?php echo $this->escape($f->text($p->verify)); ?></td>
                        <td><?php echo $this->escape($f->text($p->device)); ?></td>
                        <td><?php echo $this->escape($f->text($p->area)); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($this->leave !== []) : ?>
        <h2 class="h5"><?php echo Text::_('COM_BIOTIME_LEAVE'); ?></h2>
        <ul>
            <?php foreach ($this->leave as $l) : ?>
                <li><?php echo $this->escape($f->text($l->type) . ' · ' . $f->date($l->start) . ($l->end !== null && $l->end !== $l->start ? ' – ' . $f->date($l->end) : '') . ' · ' . ($l->status !== null ? Text::_('COM_BIOTIME_LEAVE_' . strtoupper($l->status)) : $f->text($l->statusRaw))); ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
