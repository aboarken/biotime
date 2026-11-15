<?php

/**
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;

/** @var \BiotimePortal\Component\Biotime\Site\View\Calendar\HtmlView $this */

$f = $this->f;
$legend = ['present', 'late', 'early', 'absent', 'leave', 'holiday', 'rest'];
?>
<div class="com-biotime biotime-calendar">
    <?php if ($this->showPageHeading) : ?>
        <h1 class="h2 mb-3"><?php echo $this->escape($this->pageHeading); ?></h1>
    <?php endif; ?>

    <?php echo LayoutHelper::render('biotime.monthnav', ['view' => 'calendar', 'year' => $this->year, 'month' => $this->month, 'f' => $f]); ?>
    <h2 class="h4" id="biotime-cal-title"><?php echo $this->escape($this->monthTitle()); ?></h2>
    <?php echo LayoutHelper::render('biotime.notices', ['period' => $this->period, 'zone' => $this->zone, 'updated' => $this->updatedText($this->period->fetchedAt)]); ?>

    <table class="biotime-cal" aria-labelledby="biotime-cal-title">
        <thead>
            <tr>
                <?php for ($d = 1; $d <= 7; $d++) : ?>
                    <th scope="col"><abbr title="<?php echo $this->escape($f->weekday($d)); ?>"><?php echo $this->escape($f->weekday($d, true)); ?></abbr></th>
                <?php endfor; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($this->weeks as $week) : ?>
            <tr>
                <?php foreach ($week as $day) : ?>
                    <?php if ($day === null) : ?>
                        <td class="biotime-cal-empty"></td>
                    <?php else :
                        $status = $day->status ?? ($day->punches !== [] ? 'punched' : 'none');
                        $label  = $f->weekday($day->weekday()) . ' ' . $f->date($day->date);
                        ?>
                        <td class="biotime-cal-day biotime-cal-<?php echo $this->escape($status); ?><?php echo $day->date === $this->today ? ' biotime-cal-today' : ''; ?>">
                            <a href="<?php echo $this->url('view=day&date=' . $day->date); ?>" aria-label="<?php echo $this->escape($label); ?>"<?php echo $day->date === $this->today ? ' aria-current="date"' : ''; ?>>
                                <span class="biotime-cal-num"><?php echo (int) substr($day->date, 8, 2); ?></span>
                                <?php echo LayoutHelper::render('biotime.status', ['day' => $day, 'f' => $f]); ?>
                                <?php if ($day->exceptions !== []) : ?>
                                    <span class="biotime-cal-ex" title="<?php echo $this->escape(implode(', ', array_map([$f, 'exception'], $day->exceptions))); ?>">!<span class="visually-hidden"> <?php echo $this->escape(implode(', ', array_map([$f, 'exception'], $day->exceptions))); ?></span></span>
                                <?php endif; ?>
                            </a>
                        </td>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h3 class="h6 mt-3"><?php echo Text::_('COM_BIOTIME_LEGEND'); ?></h3>
    <ul class="list-inline biotime-legend">
        <?php foreach ($legend as $status) : ?>
            <li class="list-inline-item"><span class="biotime-status biotime-status-<?php echo $status; ?>"><?php echo Text::_('COM_BIOTIME_STATUS_' . strtoupper($status)); ?></span></li>
        <?php endforeach; ?>
        <li class="list-inline-item"><span class="biotime-status biotime-status-punched"><?php echo Text::_('COM_BIOTIME_STATUS_PUNCHED'); ?></span></li>
    </ul>
</div>
