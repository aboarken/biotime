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
$row = static fn (string $key, string $value): array => [Text::_($key), $value];
$facts = [
    $row('COM_BIOTIME_COL_SCHEDULE', $f->text($d->schedule)),
    $row('COM_BIOTIME_COL_SCHED_START', $f->time($d->schedStart)),
    $row('COM_BIOTIME_COL_SCHED_END', $f->time($d->schedEnd)),
    $row('COM_BIOTIME_COL_BREAK', $f->text($d->break)),
    $row('COM_BIOTIME_COL_CHECK_IN', $f->time($d->checkIn)),
    $row('COM_BIOTIME_COL_CHECK_OUT', $f->time($d->checkOut)),
    $row('COM_BIOTIME_COL_LATE', $f->duration($d->late)),
    $row('COM_BIOTIME_COL_EARLY', $f->duration($d->early)),
    $row('COM_BIOTIME_COL_WORKED', $f->duration($d->worked)),
    $row('COM_BIOTIME_COL_OVERTIME', $f->duration($d->overtime)),
    $row('COM_BIOTIME_COL_LEAVE', $f->text($d->leave)),
];
?>
<div class="com-biotime biotime-day">
    <h1 class="h2 mb-3"><?php echo $this->escape($this->pageHeading); ?></h1>

    <p class="d-flex flex-wrap gap-2">
        <?php if ($this->canAttendance) : ?><a class="btn btn-sm btn-outline-secondary" href="<?php echo $this->monthUrl('attendance', $this->year, $this->month); ?>">‹ <?php echo Text::_('COM_BIOTIME_BACK_ATTENDANCE'); ?></a><?php endif; ?>
        <?php if ($this->canCalendar) : ?><a class="btn btn-sm btn-outline-secondary" href="<?php echo $this->monthUrl('calendar', $this->year, $this->month); ?>">‹ <?php echo Text::_('COM_BIOTIME_BACK_CALENDAR'); ?></a><?php endif; ?>
    </p>

    <?php echo LayoutHelper::render('biotime.notices', ['period' => $this->period, 'zone' => $this->zone, 'updated' => $this->updatedText($this->period->fetchedAt)]); ?>

    <p><?php echo Text::_('COM_BIOTIME_COL_STATUS'); ?>: <?php echo LayoutHelper::render('biotime.status', ['day' => $d, 'f' => $f]); ?></p>

    <dl class="row biotime-facts">
        <?php foreach ($facts as [$label, $value]) : ?>
            <dt class="col-6 col-sm-4 col-lg-3"><?php echo $this->escape($label); ?></dt>
            <dd class="col-6 col-sm-8 col-lg-9"><?php echo $this->escape($value); ?></dd>
        <?php endforeach; ?>
    </dl>
    <?php if ($d->checkFromPunches) : ?>
        <p class="small text-muted"><?php echo Text::_('COM_BIOTIME_CHECK_FROM_PUNCHES'); ?></p>
    <?php endif; ?>
    <?php if ($d->scheduleNote !== null) : ?>
        <p><span class="badge text-bg-warning"><?php echo $this->escape($d->scheduleNote); ?></span></p>
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
                    <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_DEVICE'); ?></th>
                    <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_AREA'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($d->punches as $i => $p) : ?>
                    <tr>
                        <th scope="row"><?php echo $i + 1; ?></th>
                        <td><?php echo $this->escape($f->time($p->time)); ?></td>
                        <td><?php echo $this->escape($f->text($p->state)); ?></td>
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
