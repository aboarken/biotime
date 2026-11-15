<?php

/**
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;

/** @var \BiotimePortal\Component\Biotime\Site\View\Schedules\HtmlView $this */

$f    = $this->f;
$prev = $this->weekUrl(-1);
$next = $this->weekUrl(1);
?>
<div class="com-biotime biotime-schedules">
    <?php if ($this->showPageHeading) : ?>
        <h1 class="h2 mb-3"><?php echo $this->escape($this->pageHeading); ?></h1>
    <?php endif; ?>

    <?php echo LayoutHelper::render('biotime.monthnav', ['view' => 'schedules', 'year' => $this->year, 'month' => $this->month, 'f' => $f]); ?>
    <h2 class="h4"><?php echo $this->escape($this->monthTitle()); ?></h2>

    <?php if (!$this->configured) : ?>
        <div class="alert alert-info"><?php echo Text::_('COM_BIOTIME_SCHEDULES_NOT_AVAILABLE'); ?></div>
    <?php else : ?>
        <?php echo LayoutHelper::render('biotime.notices', ['period' => $this->period, 'zone' => $this->zone, 'updated' => $this->updatedText($this->period->fetchedAt), 'needDaily' => false]); ?>

        <h3 class="h5"><?php echo Text::sprintf('COM_BIOTIME_WEEK_OF', $this->escape($f->date($this->weekStart))); ?></h3>
        <div class="d-flex gap-2 mb-2">
            <?php if ($prev !== null) : ?><a class="btn btn-sm btn-outline-secondary" href="<?php echo $prev; ?>">‹ <?php echo Text::_('COM_BIOTIME_PREV_WEEK'); ?></a><?php endif; ?>
            <?php if ($next !== null) : ?><a class="btn btn-sm btn-outline-secondary" href="<?php echo $next; ?>"><?php echo Text::_('COM_BIOTIME_NEXT_WEEK'); ?> ›</a><?php endif; ?>
        </div>
        <ul class="row row-cols-1 row-cols-sm-2 row-cols-lg-4 row-cols-xl-7 g-2 list-unstyled biotime-week mb-4">
            <?php foreach ($this->week as $day) : ?>
                <li class="col">
                    <div class="card h-100<?php echo $day->date === $this->today ? ' border-primary' : ''; ?>">
                        <div class="card-body p-2">
                            <div class="fw-semibold"><a href="<?php echo $this->url('view=day&date=' . $day->date); ?>"><?php echo $this->escape($f->weekday($day->weekday(), true) . ' ' . $f->date($day->date)); ?></a></div>
                            <div><?php echo $this->escape($f->text($day->schedule)); ?></div>
                            <div class="small"><?php echo $this->escape($f->time($day->schedStart) . ' – ' . $f->time($day->schedEnd)); ?></div>
                            <?php if ($day->break !== null) : ?><div class="small text-muted"><?php echo Text::_('COM_BIOTIME_COL_BREAK'); ?>: <?php echo $this->escape($day->break); ?></div><?php endif; ?>
                            <?php if ($day->scheduleNote !== null) : ?><div class="small"><span class="badge text-bg-warning"><?php echo $this->escape($day->scheduleNote); ?></span></div><?php endif; ?>
                            <?php if (in_array($day->status, ['rest', 'holiday', 'leave'], true)) : ?><div><?php echo LayoutHelper::render('biotime.status', ['day' => $day, 'f' => $f]); ?></div><?php endif; ?>
                        </div>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>

        <h3 class="h5"><?php echo Text::_('COM_BIOTIME_SCHEDULE_MONTH'); ?></h3>
        <div class="table-responsive">
            <table class="table table-striped align-middle">
                <caption class="visually-hidden"><?php echo $this->escape($this->pageHeading . ' – ' . $this->monthTitle()); ?></caption>
                <thead>
                    <tr>
                        <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_DATE'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_DAY'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_SCHEDULE'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_SCHED_START'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_SCHED_END'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_BREAK'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_NOTE'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_DAY_TYPE'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($this->period->days as $day) : ?>
                    <tr>
                        <th scope="row" class="text-nowrap"><a href="<?php echo $this->url('view=day&date=' . $day->date); ?>"><?php echo $this->escape($f->date($day->date)); ?></a></th>
                        <td><?php echo $this->escape($f->weekday($day->weekday(), true)); ?></td>
                        <td><?php echo $this->escape($f->text($day->schedule)); ?></td>
                        <td><?php echo $this->escape($f->time($day->schedStart)); ?></td>
                        <td><?php echo $this->escape($f->time($day->schedEnd)); ?></td>
                        <td><?php echo $this->escape($f->text($day->break)); ?></td>
                        <td><?php echo $this->escape($f->text($day->scheduleNote)); ?></td>
                        <td><?php echo in_array($day->status, ['rest', 'holiday', 'leave'], true) ? LayoutHelper::render('biotime.status', ['day' => $day, 'f' => $f]) : '—'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
