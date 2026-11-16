<?php

/**
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use BiotimePortal\Component\Biotime\Site\View\Ui;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;

/** @var \BiotimePortal\Component\Biotime\Site\View\Calendar\HtmlView $this */

$f      = $this->f;
$e      = fn (string $text): string => $this->escape($text);
// [status, label]: Missing Punch is a tag on a present day, not a status of its own.
$legend = [
    ['present', Text::_('COM_BIOTIME_STATUS_PRESENT')],
    ['missing_punch', Text::_('COM_BIOTIME_MISSING_PUNCH')],
    ['checked_in', Text::_('COM_BIOTIME_STATUS_CHECKED_IN')],
    ['absent', Text::_('COM_BIOTIME_STATUS_ABSENT')],
    ['weekend', Text::_('COM_BIOTIME_STATUS_WEEKEND')],
];
?>
<div class="com-biotime biotime-calendar">
    <div class="bt-head">
        <?php if ($this->showPageHeading) : ?>
            <h1 class="h2"><?php echo $e($this->pageHeading); ?></h1>
        <?php else : ?>
            <span></span>
        <?php endif; ?>
        <?php echo LayoutHelper::render('biotime.monthnav', ['view' => 'calendar', 'year' => $this->year, 'month' => $this->month, 'f' => $f]); ?>
    </div>

    <?php echo LayoutHelper::render('biotime.toolbar', ['view' => 'calendar', 'year' => $this->year, 'month' => $this->month, 'f' => $f]); ?>
    <?php echo $this->notices($this->period); ?>

    <section class="bt-panel">
        <div class="bt-panel-head">
            <h2 class="bt-panel-title" id="bt-cal-title"><?php echo $e($this->monthTitle()); ?></h2>
        </div>
        <div class="bt-panel-body">
            <table class="bt-cal" aria-labelledby="bt-cal-title">
                <thead>
                    <tr>
                        <?php for ($d = 1; $d <= 7; $d++) : ?>
                            <th scope="col"><abbr title="<?php echo $e($f->weekday($d)); ?>"><?php echo $e($f->weekday($d, true)); ?></abbr></th>
                        <?php endfor; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($this->weeks as $week) : ?>
                    <tr>
                        <?php foreach ($week as $day) : ?>
                            <?php if ($day === null) : ?>
                                <td class="bt-cal-empty"></td>
                            <?php else :
                                $status = $day->status ?? ($day->punches !== [] ? 'punched' : null);
                                $label  = $f->weekday($day->weekday()) . ' ' . $f->date($day->date) . ': ' . ($day->status !== null ? $f->status($day) : '—')
                                    . ($day->missingPunch() ? ', ' . Text::_('COM_BIOTIME_MISSING_PUNCH') : '')
                                    . ($day->checkIn !== null ? ', ' . Text::_('COM_BIOTIME_COL_CHECK_IN') . ' ' . $f->time($day->checkIn) : '')
                                    . ($day->checkOut !== null ? ', ' . Text::_('COM_BIOTIME_COL_CHECK_OUT') . ' ' . $f->time($day->checkOut) : '');
                                $today  = $day->date === $this->today;
                                ?>
                                <td class="bt-cal-day bt-tone-<?php echo Ui::tone($status); ?><?php echo $today ? ' bt-cal-today' : ''; ?>">
                                    <a href="<?php echo $this->url('view=day&date=' . $day->date); ?>" aria-label="<?php echo $e($label); ?>"<?php echo $today ? ' aria-current="date"' : ''; ?>>
                                        <span class="bt-cal-num"><?php echo (int) substr($day->date, 8, 2); ?></span>
                                        <?php if ($status !== null) : ?>
                                            <span class="bt-cal-status"><span class="bt-cal-sym" aria-hidden="true"><?php echo Ui::symbol($status); ?></span><span class="bt-cal-label"><?php echo $e($status === 'punched' ? Text::_('COM_BIOTIME_STATUS_PUNCHED') : $f->status($day)); ?></span></span>
                                        <?php endif; ?>
                                        <?php if ($day->checkIn !== null || $day->checkOut !== null || $day->punches !== []) : ?>
                                            <span class="bt-cal-times">
                                                <?php echo LayoutHelper::render('biotime.checktime', ['day' => $day, 'f' => $f, 'type' => 'in', 'compact' => true]); ?>
                                                <?php echo LayoutHelper::render('biotime.checktime', ['day' => $day, 'f' => $f, 'type' => 'out', 'compact' => true]); ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($day->missingPunch()) : ?>
                                            <span class="bt-cal-tag"><?php echo Text::_('COM_BIOTIME_MISSING_PUNCH'); ?></span>
                                            <span class="bt-cal-ex" title="<?php echo $e(Text::_('COM_BIOTIME_MISSING_PUNCH')); ?>">!</span>
                                        <?php endif; ?>
                                    </a>
                                </td>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <ul class="bt-legend" aria-label="<?php echo $e(Text::_('COM_BIOTIME_LEGEND')); ?>">
            <?php foreach ($legend as [$status, $text]) : ?>
                <li><span class="bt-badge bt-tone-<?php echo Ui::tone($status); ?>"><span aria-hidden="true"><?php echo Ui::symbol($status); ?></span><?php echo $e($text); ?></span></li>
            <?php endforeach; ?>
        </ul>
    </section>
</div>
