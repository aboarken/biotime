<?php

/**
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use BiotimePortal\Component\Biotime\Site\View\Ui;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;

/** @var \BiotimePortal\Component\Biotime\Site\View\Dashboard\HtmlView $this */

/*
 * Layout (same design as the ServiceDesk Plus portal): heading, the employee and the range shown
 * (the school year so far: 1 August to today, 0.9.0) as one table row, Today panel, summary cards
 * (5 per row), then Statistics charts (0.10.0, layouts/biotime/charts.php). Styles are in media/com_biotime/css/portal.css (.bt-*). Tones: green present, amber
 * missing punch, red absent, blue weekend, purple holiday, teal checked in, sky weekend worked, muted for zero.
 */

$s     = $this->summary;
$f     = $this->f;
$e     = fn (string $text): string => $this->escape($text);
$check = fn ($day, string $type, bool $device = false): string => LayoutHelper::render('biotime.checktime', ['day' => $day, 'f' => $f, 'type' => $type, 'device' => $device]);
$t     = $this->todayDay;
$min   = fn (?int $minutes): string => LayoutHelper::render('biotime.minutes', ['minutes' => $minutes, 'f' => $f]);

// Summary card: the whole card opens My Attendance with the matching filter (when allowed).
$stat = function (string $label, string $value, string $tone, string $filter = '', string $meta = ''): string {
    $url   = $filter !== '' && $this->canAttendance ? $this->attendanceUrl($filter) : '';
    $inner = '<span class="bt-stat-label">' . $this->escape($label) . '</span>'
        . '<span class="bt-stat-value">' . $value . '</span>'
        . ($meta !== '' ? '<span class="bt-stat-meta">' . $this->escape($meta) . '</span>' : '');

    return $url !== ''
        ? '<a class="bt-stat bt-tone-' . $tone . '" href="' . $url . '">' . $inner . '</a>'
        : '<div class="bt-stat bt-tone-' . $tone . '">' . $inner . '</div>';
};

?>
<div class="com-biotime biotime-dashboard" data-bt-root="dashboard">
    <div class="bt-head">
        <?php if ($this->showPageHeading) : ?>
            <h1 class="h2"><?php echo $e($this->pageHeading); ?></h1>
        <?php else : ?>
            <span></span>
        <?php endif; ?>
    </div>

    <?php // The employee and the range shown, as one table row (0.10.0). ?>
    <div class="bt-panel bt-profile-panel">
        <div class="bt-table-wrap">
            <table class="bt-table bt-profile-table">
                <caption class="visually-hidden"><?php echo Text::_('COM_BIOTIME_PROFILE'); ?></caption>
                <thead>
                    <tr>
                        <?php foreach ($this->profileRow as [$key]) : ?>
                            <th scope="col"><?php echo Text::_($key); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <?php foreach ($this->profileRow as [$key, $value]) : ?>
                            <td data-label="<?php echo $e(Text::_($key)); ?>"><?php echo $e($value); ?></td>
                        <?php endforeach; ?>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <?php echo $this->notices($this->period); ?>

    <?php if ($t !== null) : ?>
        <section class="bt-panel" aria-labelledby="bt-today-title">
            <div class="bt-panel-head">
                <h2 class="bt-panel-title" id="bt-today-title"><?php echo $e(Text::sprintf('COM_BIOTIME_TODAY', $f->weekday($t->weekday()) . ', ' . $f->date($t->date))); ?></h2>
                <a class="bt-panel-link" href="<?php echo $this->url('view=day&date=' . $t->date); ?>"><?php echo Text::_('COM_BIOTIME_DAY_DETAILS'); ?> &rarr;</a>
            </div>
            <dl class="bt-today">
                <div><dt><?php echo Text::_('COM_BIOTIME_COL_CHECK_IN'); ?></dt><dd><?php echo $check($t, 'in', true); ?></dd></div>
                <div><dt><?php echo Text::_('COM_BIOTIME_COL_CHECK_OUT'); ?></dt><dd><?php echo $check($t, 'out', true); ?></dd></div>
                <div><dt><?php echo Text::_('COM_BIOTIME_COL_LATE'); ?></dt><dd><?php echo $min($t->late); ?></dd></div>
                <div><dt><?php echo Text::_('COM_BIOTIME_COL_EARLY'); ?></dt><dd><?php echo $min($t->early); ?></dd></div>
                <div><dt><?php echo Text::_('COM_BIOTIME_COL_STATUS'); ?></dt><dd>
                    <?php echo $t->status === null && $t->punches === []
                        ? '<span class="bt-badge bt-tone-muted">' . Text::_('COM_BIOTIME_TODAY_NO_PUNCH') . '</span>'
                        : LayoutHelper::render('biotime.status', ['day' => $t, 'f' => $f]); ?>
                </dd></div>
            </dl>
        </section>
    <?php endif; ?>

    <?php // Summary cards, 5 per row ?>
    <div class="bt-stats bt-stats--5">
        <?php
        // 3 rows of 5, the last with ZKBioTime's calculation.
        echo $stat(Text::_('COM_BIOTIME_CARD_PRESENT_DAYS'), $s->presentDays . ' <small>/ ' . $s->workingDays . '</small>', Ui::cardTone('COM_BIOTIME_CARD_PRESENT_DAYS'), 'attended', Text::_('COM_BIOTIME_CARD_PRESENT_DAYS_HINT'));
        echo $stat(Text::_('COM_BIOTIME_CARD_ABSENT_DAYS'), (string) $s->absentDays, Ui::cardTone('COM_BIOTIME_CARD_ABSENT_DAYS'), 'absent');
        echo $stat(Text::_('COM_BIOTIME_CARD_LEAVES'), (string) $s->leaveDays, Ui::cardTone('COM_BIOTIME_CARD_LEAVES'), 'leave');
        echo $stat(Text::_('COM_BIOTIME_CARD_HOLIDAYS'), (string) $s->holidayDays, Ui::cardTone('COM_BIOTIME_CARD_HOLIDAYS'), 'holiday');
        echo $stat(Text::_('COM_BIOTIME_CARD_WEEKENDS'), (string) $s->offDays, Ui::cardTone('COM_BIOTIME_CARD_WEEKENDS'), 'weekend');
        echo $stat(Text::_('COM_BIOTIME_CARD_WEEKEND_WORKED'), (string) $s->weekendWorked, Ui::cardTone('COM_BIOTIME_CARD_WEEKEND_WORKED'), 'weekendworked');
        echo $stat(Text::_('COM_BIOTIME_CARD_RATE'), $s->rate === null ? '—' : $s->rate . ' <small>%</small>', Ui::cardTone('COM_BIOTIME_CARD_RATE'));
        echo $stat(Text::_('COM_BIOTIME_CARD_MISSING_MORNING'), (string) $s->missingMorningDays, Ui::cardTone('COM_BIOTIME_CARD_MISSING_MORNING'), 'missingmorning', Text::_('COM_BIOTIME_CARD_MISSING_MORNING_HINT'));
        echo $stat(Text::_('COM_BIOTIME_CARD_MISSING_AFTERNOON'), (string) $s->missingAfternoonDays, Ui::cardTone('COM_BIOTIME_CARD_MISSING_AFTERNOON'), 'missingafternoon', Text::_('COM_BIOTIME_CARD_MISSING_AFTERNOON_HINT'));
        echo $stat(Text::_('COM_BIOTIME_CARD_PUNCHES'), (string) $s->punchCount, Ui::cardTone('COM_BIOTIME_CARD_PUNCHES'), 'punches', Text::plural('COM_BIOTIME_CARD_PUNCH_DAYS_HINT', $s->punchDays));
        echo $stat(Text::_('COM_BIOTIME_CARD_AVG_CHECK_IN'), $e($f->time($s->avgCheckIn)), Ui::cardTone('COM_BIOTIME_CARD_AVG_CHECK_IN'), '', $s->firstCheckIn !== null ? Text::sprintf('COM_BIOTIME_CARD_EARLIEST', $f->time($s->firstCheckIn)) : '');
        echo $stat(Text::_('COM_BIOTIME_CARD_AVG_CHECK_OUT'), $e($f->time($s->avgCheckOut)), Ui::cardTone('COM_BIOTIME_CARD_AVG_CHECK_OUT'), '', $s->lastCheckOut !== null ? Text::sprintf('COM_BIOTIME_CARD_LATEST', $f->time($s->lastCheckOut)) : '');
        echo $stat(Text::_('COM_BIOTIME_CARD_LATE_TOTAL'), $e($f->duration($s->lateMinutes)), Ui::cardTone('COM_BIOTIME_CARD_LATE_TOTAL'), 'late', Text::plural('COM_BIOTIME_N_DAYS', $s->lateDays));
        echo $stat(Text::_('COM_BIOTIME_CARD_EARLY_TOTAL'), $e($f->duration($s->earlyMinutes)), Ui::cardTone('COM_BIOTIME_CARD_EARLY_TOTAL'), 'early', Text::plural('COM_BIOTIME_N_DAYS', $s->earlyDays));
        echo $stat(Text::_('COM_BIOTIME_CARD_LATE_EARLY'), $e($f->duration($s->lateEarlyMinutes())), Ui::cardTone('COM_BIOTIME_CARD_LATE_EARLY'), '', Text::_('COM_BIOTIME_CARD_LATE_EARLY_HINT'));
        echo $stat(Text::_('COM_BIOTIME_CARD_WORKED'), $e($f->duration($s->workedMinutes)), Ui::cardTone('COM_BIOTIME_CARD_WORKED'));
        echo $stat(Text::_('COM_BIOTIME_CARD_OVERTIME'), $e($f->duration($s->overtimeMinutes)), Ui::cardTone('COM_BIOTIME_CARD_OVERTIME'));
        ?>
    </div>

    <?php echo LayoutHelper::render('biotime.charts', ['days' => $this->period->days, 'today' => $this->today, 'summary' => $s, 'f' => $f, 'devices' => $this->devices]); ?>
</div>
