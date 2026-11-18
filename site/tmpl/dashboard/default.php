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
 * Layout (same design as the ServiceDesk Plus portal): heading with the range shown (the school
 * year so far: 1 August to today, 0.9.0), Today panel, summary cards (5 per row), then Recent
 * Activity. Styles are in media/com_biotime/css/portal.css (.bt-*). Tones: green present, amber
 * missing punch, red absent, blue weekend, purple holiday, teal checked in, sky weekend worked, muted for zero.
 */

$s     = $this->summary;
$f     = $this->f;
$e     = fn (string $text): string => $this->escape($text);
$check = fn ($day, string $type, bool $device = false): string => LayoutHelper::render('biotime.checktime', ['day' => $day, 'f' => $f, 'type' => $type, 'device' => $device]);
$t     = $this->todayDay;
$min   = fn (?int $minutes): string => LayoutHelper::render('biotime.minutes', ['minutes' => $minutes, 'f' => $f]);
// Read from ZKBioTime's database: schedule, late-in, early-out, worked time and holidays (0.6.0).
$calc  = $this->period->calculated;

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

$panelHead = static fn (string $title, string $url = '', string $linkText = ''): string => '<div class="bt-panel-head">'
    . '<h2 class="bt-panel-title">' . $title . '</h2>'
    . ($url !== '' ? '<a class="bt-panel-link" href="' . $url . '">' . $linkText . ' &rarr;</a>' : '')
    . '</div>';

?>
<div class="com-biotime biotime-dashboard" data-bt-root="dashboard">
    <div class="bt-head">
        <?php if ($this->showPageHeading) : ?>
            <h1 class="h2"><?php echo $e($this->pageHeading); ?></h1>
        <?php else : ?>
            <span></span>
        <?php endif; ?>
    </div>

    <?php if ($this->profileLine !== []) : ?>
        <p class="bt-profile"><?php echo $e(implode(' · ', $this->profileLine)); ?></p>
    <?php endif; ?>

    <p class="bt-range"><?php echo $e(Text::sprintf('COM_BIOTIME_DASHBOARD_RANGE', $f->date($this->from), $f->date($this->to))); ?></p>

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
                <?php if ($calc) : ?>
                    <div><dt><?php echo Text::_('COM_BIOTIME_COL_LATE'); ?></dt><dd><?php echo $min($t->late); ?></dd></div>
                    <div><dt><?php echo Text::_('COM_BIOTIME_COL_EARLY'); ?></dt><dd><?php echo $min($t->early); ?></dd></div>
                <?php else : ?>
                    <div><dt><?php echo Text::_('COM_BIOTIME_COL_SPAN'); ?></dt><dd><?php echo $e($f->duration($t->span())); ?></dd></div>
                <?php endif; ?>
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
        <?php if ($calc) :
            // From the database: 3 rows of 5, the last with ZKBioTime's calculation.
            echo $stat(Text::_('COM_BIOTIME_CARD_PRESENT_DAYS'), $s->presentDays . ' <small>/ ' . $s->workingDays . '</small>', 'green', 'attended', Text::_('COM_BIOTIME_CARD_PRESENT_DAYS_HINT'));
            echo $stat(Text::_('COM_BIOTIME_CARD_ABSENT_DAYS'), (string) $s->absentDays, Ui::countTone($s->absentDays, 'red'), 'absent');
            echo $stat(Text::_('COM_BIOTIME_CARD_MISSING_MORNING'), (string) $s->missingMorningDays, Ui::countTone($s->missingMorningDays, 'amber'), 'missingmorning', Text::_('COM_BIOTIME_CARD_MISSING_MORNING_HINT'));
            echo $stat(Text::_('COM_BIOTIME_CARD_MISSING_AFTERNOON'), (string) $s->missingAfternoonDays, Ui::countTone($s->missingAfternoonDays, 'amber'), 'missingafternoon', Text::_('COM_BIOTIME_CARD_MISSING_AFTERNOON_HINT'));
            echo $stat(Text::_('COM_BIOTIME_CARD_HOLIDAYS'), (string) $s->holidayDays, Ui::countTone($s->holidayDays, 'purple'), 'holiday');
            echo $stat(Text::_('COM_BIOTIME_CARD_WEEKEND_WORKED'), (string) $s->weekendWorked, Ui::countTone($s->weekendWorked, 'sky'), 'weekendworked');
            echo $stat(Text::_('COM_BIOTIME_CARD_RATE'), $s->rate === null ? '—' : $s->rate . ' <small>%</small>', $s->rate === null ? 'muted' : ($s->rate >= 90 ? 'green' : ($s->rate >= 75 ? 'amber' : 'red')));
            echo $stat(Text::_('COM_BIOTIME_CARD_AVG_CHECK_IN'), $e($f->time($s->avgCheckIn)), 'teal', '', $s->firstCheckIn !== null ? Text::sprintf('COM_BIOTIME_CARD_EARLIEST', $f->time($s->firstCheckIn)) : '');
            echo $stat(Text::_('COM_BIOTIME_CARD_AVG_CHECK_OUT'), $e($f->time($s->avgCheckOut)), 'blue', '', $s->lastCheckOut !== null ? Text::sprintf('COM_BIOTIME_CARD_LATEST', $f->time($s->lastCheckOut)) : '');
            echo $stat(Text::_('COM_BIOTIME_CARD_PUNCHES'), (string) $s->punchCount, Ui::countTone($s->punchCount, 'blue'), 'punches', Text::plural('COM_BIOTIME_CARD_PUNCH_DAYS_HINT', $s->punchDays));
            echo $stat(Text::_('COM_BIOTIME_CARD_LATE_TOTAL'), $e($f->duration($s->lateMinutes)), Ui::countTone($s->lateMinutes, 'amber'), 'late', Text::plural('COM_BIOTIME_N_DAYS', $s->lateDays));
            echo $stat(Text::_('COM_BIOTIME_CARD_EARLY_TOTAL'), $e($f->duration($s->earlyMinutes)), Ui::countTone($s->earlyMinutes, 'amber'), 'early', Text::plural('COM_BIOTIME_N_DAYS', $s->earlyDays));
            echo $stat(Text::_('COM_BIOTIME_CARD_LATE_EARLY'), $e($f->duration($s->lateEarlyMinutes())), Ui::countTone($s->lateEarlyMinutes(), 'red'), '', Text::_('COM_BIOTIME_CARD_LATE_EARLY_HINT'));
            echo $stat(Text::_('COM_BIOTIME_CARD_WORKED'), $e($f->duration($s->workedMinutes)), 'blue');
            echo $stat(Text::_('COM_BIOTIME_CARD_OVERTIME'), $e($f->duration($s->overtimeMinutes)), Ui::countTone($s->overtimeMinutes, 'teal'));
        else :
            // From the API (no holidays): 5 + 5.
            echo $stat(Text::_('COM_BIOTIME_CARD_PRESENT_DAYS'), $s->presentDays . ' <small>/ ' . $s->workingDays . '</small>', 'green', 'attended', Text::_('COM_BIOTIME_CARD_PRESENT_DAYS_HINT'));
            echo $stat(Text::_('COM_BIOTIME_CARD_ABSENT_DAYS'), (string) $s->absentDays, Ui::countTone($s->absentDays, 'red'), 'absent');
            echo $stat(Text::_('COM_BIOTIME_CARD_MISSING_MORNING'), (string) $s->missingMorningDays, Ui::countTone($s->missingMorningDays, 'amber'), 'missingmorning', Text::_('COM_BIOTIME_CARD_MISSING_MORNING_HINT'));
            echo $stat(Text::_('COM_BIOTIME_CARD_MISSING_AFTERNOON'), (string) $s->missingAfternoonDays, Ui::countTone($s->missingAfternoonDays, 'amber'), 'missingafternoon', Text::_('COM_BIOTIME_CARD_MISSING_AFTERNOON_HINT'));
            echo $stat(Text::_('COM_BIOTIME_CARD_WEEKEND_WORKED'), (string) $s->weekendWorked, Ui::countTone($s->weekendWorked, 'sky'), 'weekendworked');
            echo $stat(Text::_('COM_BIOTIME_CARD_RATE'), $s->rate === null ? '—' : $s->rate . ' <small>%</small>', $s->rate === null ? 'muted' : ($s->rate >= 90 ? 'green' : ($s->rate >= 75 ? 'amber' : 'red')));
            echo $stat(Text::_('COM_BIOTIME_CARD_AVG_CHECK_IN'), $e($f->time($s->avgCheckIn)), 'teal', '', $s->firstCheckIn !== null ? Text::sprintf('COM_BIOTIME_CARD_EARLIEST', $f->time($s->firstCheckIn)) : '');
            echo $stat(Text::_('COM_BIOTIME_CARD_AVG_CHECK_OUT'), $e($f->time($s->avgCheckOut)), 'blue', '', $s->lastCheckOut !== null ? Text::sprintf('COM_BIOTIME_CARD_LATEST', $f->time($s->lastCheckOut)) : '');
            echo $stat(Text::_('COM_BIOTIME_CARD_AVG_SPAN'), $e($f->duration($s->avgSpanMinutes)), 'slate', '', Text::_('COM_BIOTIME_CARD_AVG_SPAN_HINT'));
            echo $stat(Text::_('COM_BIOTIME_CARD_PUNCHES'), (string) $s->punchCount, Ui::countTone($s->punchCount, 'blue'), 'punches', Text::plural('COM_BIOTIME_CARD_PUNCH_DAYS_HINT', $s->punchDays));
        endif;
        ?>
    </div>

    <section class="bt-panel">
        <?php echo $panelHead(Text::_('COM_BIOTIME_RECENT_ACTIVITY'), $this->canAttendance ? $this->attendanceUrl() : '', Text::_('COM_BIOTIME_VIEW_ALL')); ?>
        <?php if ($this->period->error('punches') !== null) : ?>
            <p class="bt-panel-body bt-empty"><?php echo Text::_('COM_BIOTIME_NOTHING_TO_SHOW'); ?></p>
        <?php elseif ($this->recent === []) : ?>
            <p class="bt-panel-body bt-empty"><?php echo Text::_('COM_BIOTIME_NO_RECENT'); ?></p>
        <?php else : ?>
            <div class="bt-table-wrap">
                <table class="bt-table">
                    <caption class="visually-hidden"><?php echo Text::_('COM_BIOTIME_RECENT_ACTIVITY'); ?></caption>
                    <thead>
                        <tr>
                            <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_DATE'); ?></th>
                            <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_DAY'); ?></th>
                            <?php if ($calc) : ?><th scope="col"><?php echo Text::_('COM_BIOTIME_COL_SCHEDULE'); ?></th><?php endif; ?>
                            <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_CHECK_IN'); ?></th>
                            <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_CHECK_OUT'); ?></th>
                            <?php if ($calc) : ?>
                                <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_LATE'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_EARLY'); ?></th>
                            <?php else : ?>
                                <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_SPAN'); ?></th>
                            <?php endif; ?>
                            <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_STATUS'); ?></th>
                            <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_NOTE'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($this->recent as $day) : ?>
                        <tr class="<?php echo $day->status === 'holiday' ? 'bt-row-holiday' : ($day->weekend ? 'bt-row-weekend' : ''); ?><?php echo $day->date === $this->today ? ' bt-row-today' : ''; ?>">
                            <th scope="row"><a href="<?php echo $this->url('view=day&date=' . $day->date); ?>"><?php echo $e($f->date($day->date)); ?></a></th>
                            <td><?php echo $e($f->weekday($day->weekday())); ?></td>
                            <?php if ($calc) : ?><td><?php echo LayoutHelper::render('biotime.schedule', ['day' => $day, 'f' => $f]); ?></td><?php endif; ?>
                            <td><?php echo $check($day, 'in'); ?></td>
                            <td><?php echo $check($day, 'out'); ?></td>
                            <?php if ($calc) : ?>
                                <td><?php echo $min($day->late); ?></td>
                                <td><?php echo $min($day->early); ?></td>
                            <?php else : ?>
                                <td><?php echo $e($f->duration($day->span())); ?></td>
                            <?php endif; ?>
                            <td><?php echo LayoutHelper::render('biotime.status', ['day' => $day, 'f' => $f, 'tag' => false]); ?></td>
                            <td><?php if ($day->missingPunch()) : ?><span class="bt-badge bt-tone-amber"><span aria-hidden="true">!</span><?php echo Text::_('COM_BIOTIME_MISSING_PUNCH'); ?></span><?php else : ?><span class="bt-check-none">—</span><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
