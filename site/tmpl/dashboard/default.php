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
 * Layout (same design as the ServiceDesk Plus portal): heading with month navigation, filter bar,
 * Today panel, summary cards, then Monthly Overview and Devices You Used side by side, then Recent
 * Activity. Styles are in media/com_biotime/css/portal.css (.bt-*). Tones: green present, amber
 * missing punch, red absent, slate weekend, teal checked in, purple weekend worked, muted for zero.
 */

$s     = $this->summary;
$f     = $this->f;
$e     = fn (string $text): string => $this->escape($text);
$check = fn ($day, string $type, bool $device = false): string => LayoutHelper::render('biotime.checktime', ['day' => $day, 'f' => $f, 'type' => $type, 'device' => $device]);
$t     = $this->todayDay;

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

// Monthly overview: days per status up to today. [filter, label, count, tone]. A present day with
// a missing check-in or check-out counts as Present and as Missing Punch.
$overview = [
    ['attended', Text::_('COM_BIOTIME_STATUS_PRESENT'), $s->presentDays, 'green'],
    ['missingpunch', Text::_('COM_BIOTIME_MISSING_PUNCH'), $s->missingPunchDays, 'amber'],
    ['absent', Text::_('COM_BIOTIME_STATUS_ABSENT'), $s->absentDays, 'red'],
    ['weekend', Text::_('COM_BIOTIME_STATUS_WEEKEND'), $s->weekendDays, 'slate'],
    ['weekendworked', Text::_('COM_BIOTIME_CARD_WEEKEND_WORKED'), $s->weekendWorked, 'purple'],
];
// The bar splits the days so far: present (complete), present with a missing punch, absent, weekend.
$bar  = [
    ['green', max(0, $s->presentDays - $s->missingPunchDays)],
    ['amber', $s->missingPunchDays],
    ['red', $s->absentDays],
    ['slate', $s->weekendDays],
];
$days = array_sum(array_column($bar, 1));
?>
<div class="com-biotime biotime-dashboard">
    <div class="bt-head">
        <?php if ($this->showPageHeading) : ?>
            <h1 class="h2"><?php echo $e($this->pageHeading); ?></h1>
        <?php else : ?>
            <span></span>
        <?php endif; ?>
        <?php echo LayoutHelper::render('biotime.monthnav', ['view' => 'dashboard', 'year' => $this->year, 'month' => $this->month, 'f' => $f]); ?>
    </div>

    <?php echo LayoutHelper::render('biotime.toolbar', ['view' => 'dashboard', 'year' => $this->year, 'month' => $this->month, 'f' => $f]); ?>
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
                <div><dt><?php echo Text::_('COM_BIOTIME_COL_SPAN'); ?></dt><dd><?php echo $e($f->duration($t->span())); ?></dd></div>
                <div><dt><?php echo Text::_('COM_BIOTIME_COL_STATUS'); ?></dt><dd>
                    <?php echo $t->status === null && $t->punches === []
                        ? '<span class="bt-badge bt-tone-muted">' . Text::_('COM_BIOTIME_TODAY_NO_PUNCH') . '</span>'
                        : LayoutHelper::render('biotime.status', ['day' => $t, 'f' => $f]); ?>
                </dd></div>
            </dl>
        </section>
    <?php endif; ?>

    <?php // Summary cards ?>
    <div class="bt-stats bt-stats--6">
        <?php
        echo $stat(Text::_('COM_BIOTIME_CARD_PRESENT_DAYS'), $s->presentDays . ' <small>/ ' . $s->workingDays . '</small>', 'green', 'attended', Text::_('COM_BIOTIME_CARD_PRESENT_DAYS_HINT'));
        echo $stat(Text::_('COM_BIOTIME_CARD_ABSENT_DAYS'), (string) $s->absentDays, Ui::countTone($s->absentDays, 'red'), 'absent');
        echo $stat(Text::_('COM_BIOTIME_CARD_MISSING_PUNCH_DAYS'), (string) $s->missingPunchDays, Ui::countTone($s->missingPunchDays, 'amber'), 'missingpunch', Text::_('COM_BIOTIME_CARD_MISSING_PUNCH_HINT'));
        echo $stat(Text::_('COM_BIOTIME_CARD_RATE'), $s->rate === null ? '—' : $s->rate . ' <small>%</small>', $s->rate === null ? 'muted' : ($s->rate >= 90 ? 'green' : ($s->rate >= 75 ? 'amber' : 'red')));
        echo $stat(Text::_('COM_BIOTIME_CARD_AVG_CHECK_IN'), $e($f->time($s->avgCheckIn)), 'teal', '', $s->firstCheckIn !== null ? Text::sprintf('COM_BIOTIME_CARD_EARLIEST', $f->time($s->firstCheckIn)) : '');
        echo $stat(Text::_('COM_BIOTIME_CARD_AVG_CHECK_OUT'), $e($f->time($s->avgCheckOut)), 'blue', '', $s->lastCheckOut !== null ? Text::sprintf('COM_BIOTIME_CARD_LATEST', $f->time($s->lastCheckOut)) : '');
        echo $stat(Text::_('COM_BIOTIME_CARD_AVG_SPAN'), $e($f->duration($s->avgSpanMinutes)), 'slate', '', Text::_('COM_BIOTIME_CARD_AVG_SPAN_HINT'));
        echo $stat(Text::_('COM_BIOTIME_CARD_TOTAL_SPAN_SHORT'), $e($f->duration($s->spanMinutes)), 'slate', '', Text::_('COM_BIOTIME_CARD_TOTAL_SPAN_HINT'));
        echo $stat(Text::_('COM_BIOTIME_CARD_WEEKEND_WORKED'), (string) $s->weekendWorked, Ui::countTone($s->weekendWorked, 'purple'), 'weekendworked');
        echo $stat(Text::_('COM_BIOTIME_CARD_PUNCHES'), (string) $s->punchCount, Ui::countTone($s->punchCount, 'blue'), 'punches', Text::plural('COM_BIOTIME_CARD_PUNCH_DAYS_HINT', $s->punchDays));
        ?>
    </div>

    <div class="bt-panels">
        <section class="bt-panel">
            <?php echo $panelHead(Text::_('COM_BIOTIME_MONTHLY_OVERVIEW'), $this->canAttendance ? $this->attendanceUrl() : '', Text::_('COM_BIOTIME_VIEW_ALL')); ?>
            <div class="bt-panel-body">
                <?php if ($this->showChart && $days > 0) : ?>
                    <div class="bt-bar" aria-hidden="true">
                        <?php foreach ($bar as [$tone, $count]) : ?>
                            <?php if ($count > 0) : ?>
                                <span class="bt-tone-<?php echo $tone; ?>" style="width: <?php echo round(100 * $count / $days, 2); ?>%"></span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <div class="bt-tiles">
                    <?php foreach ($overview as [$key, $label, $count, $tone]) :
                        $inner = '<span class="bt-tile-value">' . $count . '</span><span class="bt-tile-label">' . $e($label) . '</span>';
                        $cls   = 'bt-tile bt-tone-' . Ui::countTone($count, $tone);
                        ?>
                        <?php if ($this->canAttendance) : ?>
                            <a class="<?php echo $cls; ?>" href="<?php echo $this->attendanceUrl($key); ?>"><?php echo $inner; ?></a>
                        <?php else : ?>
                            <div class="<?php echo $cls; ?>"><?php echo $inner; ?></div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="bt-panel">
            <?php echo $panelHead(Text::_('COM_BIOTIME_DEVICES_USED')); ?>
            <?php $ps = $this->punchStats; $other = $ps->total['other'] > 0; ?>
            <?php if ($ps->devices === []) : ?>
                <p class="bt-panel-body bt-empty"><?php echo Text::_('COM_BIOTIME_DEVICES_USED_NONE'); ?></p>
            <?php else : ?>
                <div class="bt-table-wrap">
                    <table class="bt-table">
                        <caption class="visually-hidden"><?php echo Text::_('COM_BIOTIME_DEVICES_USED'); ?></caption>
                        <thead>
                            <tr>
                                <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_DEVICE'); ?></th>
                                <th scope="col" class="bt-num"><?php echo Text::_('COM_BIOTIME_VERIFY_FINGERPRINT'); ?></th>
                                <th scope="col" class="bt-num"><?php echo Text::_('COM_BIOTIME_VERIFY_FACE'); ?></th>
                                <?php if ($other) : ?><th scope="col" class="bt-num"><?php echo Text::_('COM_BIOTIME_VERIFY_OTHER'); ?></th><?php endif; ?>
                                <th scope="col" class="bt-num"><?php echo Text::_('COM_BIOTIME_COL_TOTAL'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($ps->devices as $d) : ?>
                            <tr>
                                <th scope="row"><?php echo $e($d['device']); ?></th>
                                <td class="bt-num"><?php echo (int) $d['fingerprint']; ?></td>
                                <td class="bt-num"><?php echo (int) $d['face']; ?></td>
                                <?php if ($other) : ?><td class="bt-num"><?php echo (int) $d['other']; ?></td><?php endif; ?>
                                <td class="bt-num"><strong><?php echo (int) $d['total']; ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="bt-row-total">
                                <th scope="row"><?php echo Text::_('COM_BIOTIME_COL_TOTAL'); ?></th>
                                <td class="bt-num"><?php echo (int) $ps->total['fingerprint']; ?></td>
                                <td class="bt-num"><?php echo (int) $ps->total['face']; ?></td>
                                <?php if ($other) : ?><td class="bt-num"><?php echo (int) $ps->total['other']; ?></td><?php endif; ?>
                                <td class="bt-num"><?php echo (int) $ps->total['total']; ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endif; ?>
        </section>
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
                            <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_CHECK_IN'); ?></th>
                            <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_CHECK_OUT'); ?></th>
                            <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_SPAN'); ?></th>
                            <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_STATUS'); ?></th>
                            <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_NOTE'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($this->recent as $day) : ?>
                        <tr class="<?php echo $day->weekend ? 'bt-row-weekend' : ''; ?><?php echo $day->date === $this->today ? ' bt-row-today' : ''; ?>">
                            <th scope="row"><a href="<?php echo $this->url('view=day&date=' . $day->date); ?>"><?php echo $e($f->date($day->date)); ?></a></th>
                            <td><?php echo $e($f->weekday($day->weekday())); ?></td>
                            <td><?php echo $check($day, 'in'); ?></td>
                            <td><?php echo $check($day, 'out'); ?></td>
                            <td><?php echo $e($f->duration($day->span())); ?></td>
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
