<?php

/**
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;

/** @var \BiotimePortal\Component\Biotime\Site\View\Dashboard\HtmlView $this */

$s     = $this->summary;
$f     = $this->f;
$link  = fn (string $status): string => $this->canAttendance ? $this->attendanceUrl($status) : '';
$check = fn ($day, string $type, bool $device = false): string => LayoutHelper::render('biotime.checktime', ['day' => $day, 'f' => $f, 'type' => $type, 'device' => $device]);

// Summary cards, all counted from the punches: [label, value, status filter, hint]
$cards = [
    [Text::_('COM_BIOTIME_CARD_PRESENT_DAYS'), $s->presentDays . ' / ' . $s->workingDays, 'attended', Text::_('COM_BIOTIME_CARD_PRESENT_DAYS_HINT')],
    [Text::_('COM_BIOTIME_CARD_ABSENT_DAYS'), $s->absentDays, 'absent', ''],
    [Text::_('COM_BIOTIME_CARD_INCOMPLETE_DAYS'), $s->incompleteDays, 'incomplete', Text::_('COM_BIOTIME_CARD_INCOMPLETE_DAYS_HINT')],
    [Text::_('COM_BIOTIME_CARD_RATE'), $s->rate === null ? '—' : $s->rate . ' %', '', ''],
    [Text::_('COM_BIOTIME_CARD_AVG_CHECK_IN'), $f->time($s->avgCheckIn), '', $s->firstCheckIn !== null ? Text::sprintf('COM_BIOTIME_CARD_EARLIEST', $f->time($s->firstCheckIn)) : ''],
    [Text::_('COM_BIOTIME_CARD_AVG_CHECK_OUT'), $f->time($s->avgCheckOut), '', $s->lastCheckOut !== null ? Text::sprintf('COM_BIOTIME_CARD_LATEST', $f->time($s->lastCheckOut)) : ''],
    [Text::_('COM_BIOTIME_CARD_AVG_SPAN'), $f->duration($s->avgSpanMinutes), '', $s->spanMinutes !== null ? Text::sprintf('COM_BIOTIME_CARD_TOTAL_SPAN', $f->duration($s->spanMinutes)) : ''],
    [Text::_('COM_BIOTIME_CARD_WEEKEND_WORKED'), $s->weekendWorked, 'weekendworked', ''],
    [Text::_('COM_BIOTIME_CARD_PUNCHES'), $s->punchCount, 'punches', Text::plural('COM_BIOTIME_CARD_PUNCH_DAYS_HINT', $s->punchDays)],
];

if ($s->leaveDays > 0) {
    $cards[] = [Text::_('COM_BIOTIME_CARD_LEAVE_DAYS'), $s->leaveDays, 'leave', ''];
}

// Monthly overview: days per status (up to today).
$overview = array_values(array_filter([
    ['present', Text::_('COM_BIOTIME_OVERVIEW_COMPLETE'), $s->completeDays],
    ['incomplete', Text::_('COM_BIOTIME_STATUS_INCOMPLETE'), $s->incompleteDays],
    ['absent', Text::_('COM_BIOTIME_STATUS_ABSENT'), $s->absentDays],
    ['leave', Text::_('COM_BIOTIME_STATUS_LEAVE'), $s->leaveDays],
    ['weekend', Text::_('COM_BIOTIME_STATUS_WEEKEND'), $s->weekendDays],
    ['weekendworked', Text::_('COM_BIOTIME_CARD_WEEKEND_WORKED'), $s->weekendWorked],
], static fn (array $o): bool => $o[0] !== 'leave' || $o[2] > 0));
$max = max(1, ...array_map(static fn (array $o): int => $o[2], $overview));
$t   = $this->todayDay;
?>
<div class="com-biotime biotime-dashboard">
    <?php if ($this->showPageHeading) : ?>
        <h1 class="h2 mb-3"><?php echo $this->escape($this->pageHeading); ?></h1>
    <?php endif; ?>

    <?php echo LayoutHelper::render('biotime.monthnav', ['view' => 'dashboard', 'year' => $this->year, 'month' => $this->month, 'f' => $f]); ?>
    <h2 class="h4"><?php echo $this->escape($this->monthTitle()); ?></h2>
    <?php echo $this->notices($this->period); ?>

    <?php if ($t !== null) : ?>
        <section class="card mb-4 biotime-today" aria-labelledby="biotime-today-title">
            <div class="card-body">
                <h2 class="h5 card-title" id="biotime-today-title"><?php echo $this->escape(Text::sprintf('COM_BIOTIME_TODAY', $f->weekday($t->weekday()) . ', ' . $f->date($t->date))); ?></h2>
                <div class="row g-3 align-items-center">
                    <div class="col-6 col-md-3">
                        <div class="small text-muted"><?php echo Text::_('COM_BIOTIME_COL_CHECK_IN'); ?></div>
                        <div class="fs-4 fw-semibold"><?php echo $check($t, 'in', true); ?></div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="small text-muted"><?php echo Text::_('COM_BIOTIME_COL_CHECK_OUT'); ?></div>
                        <div class="fs-4 fw-semibold"><?php echo $check($t, 'out', true); ?></div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="small text-muted"><?php echo Text::_('COM_BIOTIME_COL_SPAN'); ?></div>
                        <div class="fs-4 fw-semibold"><?php echo $this->escape($f->duration($t->span())); ?></div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="small text-muted"><?php echo Text::_('COM_BIOTIME_COL_STATUS'); ?></div>
                        <div><?php echo $t->status === null && $t->punches === [] ? '<span class="text-muted">' . Text::_('COM_BIOTIME_TODAY_NO_PUNCH') . '</span>' : LayoutHelper::render('biotime.status', ['day' => $t, 'f' => $f]); ?></div>
                    </div>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <?php foreach ($cards as [$label, $value, $status, $hint]) :
            $url = $status !== '' ? $link($status) : '';
            ?>
            <div class="col-6 col-md-4 col-xl-3">
                <div class="card h-100 biotime-stat">
                    <div class="card-body">
                        <div class="small text-muted"><?php echo $this->escape($label); ?></div>
                        <div class="fs-3 fw-semibold">
                            <?php if ($url !== '') : ?>
                                <a class="stretched-link text-reset text-decoration-none" href="<?php echo $url; ?>"><?php echo $this->escape((string) $value); ?></a>
                            <?php else : ?>
                                <?php echo $this->escape((string) $value); ?>
                            <?php endif; ?>
                        </div>
                        <?php if ($hint !== '') : ?><div class="small text-muted"><?php echo $this->escape($hint); ?></div><?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <h2 class="h4"><?php echo Text::_('COM_BIOTIME_MONTHLY_OVERVIEW'); ?></h2>
    <div class="row g-3 mb-4">
        <?php if ($this->showChart) : ?>
            <div class="col-12 col-lg-7">
                <svg class="biotime-chart w-100" viewBox="0 0 400 <?php echo 20 + 28 * count($overview); ?>" role="img" aria-labelledby="biotime-chart-title biotime-chart-desc">
                    <title id="biotime-chart-title"><?php echo Text::_('COM_BIOTIME_MONTHLY_OVERVIEW'); ?></title>
                    <desc id="biotime-chart-desc"><?php echo Text::_('COM_BIOTIME_CHART_DESC'); ?></desc>
                    <?php foreach ($overview as $i => [$key, $label, $count]) :
                        $y = 10 + 28 * $i;
                        $w = (int) round(220 * $count / $max);
                        ?>
                        <text x="0" y="<?php echo $y + 15; ?>" class="biotime-chart-label"><?php echo $this->escape($label); ?></text>
                        <rect x="150" y="<?php echo $y; ?>" width="<?php echo max(2, $w); ?>" height="20" rx="3" class="biotime-bar biotime-bar-<?php echo $key; ?>"></rect>
                        <text x="<?php echo 156 + max(2, $w); ?>" y="<?php echo $y + 15; ?>" class="biotime-chart-label"><?php echo $count; ?></text>
                    <?php endforeach; ?>
                </svg>
            </div>
        <?php endif; ?>
        <div class="col-12 <?php echo $this->showChart ? 'col-lg-5' : ''; ?>">
            <div class="table-responsive">
                <table class="table table-striped table-sm">
                    <caption class="visually-hidden"><?php echo Text::_('COM_BIOTIME_MONTHLY_OVERVIEW'); ?></caption>
                    <thead><tr><th scope="col"><?php echo Text::_('COM_BIOTIME_COL_STATUS'); ?></th><th scope="col" class="text-end"><?php echo Text::_('COM_BIOTIME_COL_DAYS'); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($overview as [$key, $label, $count]) : ?>
                        <tr>
                            <th scope="row"><?php echo $this->escape($label); ?></th>
                            <td class="text-end">
                                <?php if ($this->canAttendance) : ?><a href="<?php echo $this->attendanceUrl($key === 'present' ? 'present' : $key); ?>"><?php echo $count; ?></a><?php else : ?><?php echo $count; ?><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($this->devices !== []) : ?>
                <h3 class="h6 mt-3"><?php echo Text::_('COM_BIOTIME_DEVICES_USED'); ?></h3>
                <ul class="list-unstyled small mb-0">
                    <?php foreach ($this->devices as $name => $n) : ?>
                        <li><?php echo $this->escape((string) $name); ?> <span class="text-muted">(<?php echo $this->escape(Text::plural('COM_BIOTIME_N_PUNCHES', $n)); ?>)</span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <h2 class="h4"><?php echo Text::_('COM_BIOTIME_RECENT_ACTIVITY'); ?></h2>
    <?php if ($this->period->error('punches') !== null) : ?>
        <p class="text-muted"><?php echo Text::_('COM_BIOTIME_NOTHING_TO_SHOW'); ?></p>
    <?php elseif ($this->recent === []) : ?>
        <p class="text-muted"><?php echo Text::_('COM_BIOTIME_NO_RECENT'); ?></p>
    <?php else : ?>
        <div class="table-responsive mb-2">
            <table class="table table-striped align-middle biotime-table">
                <caption class="visually-hidden"><?php echo Text::_('COM_BIOTIME_RECENT_ACTIVITY'); ?></caption>
                <thead>
                    <tr>
                        <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_DATE'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_CHECK_IN'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_CHECK_OUT'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_SPAN'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_STATUS'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($this->recent as $day) : ?>
                    <tr>
                        <th scope="row"><a href="<?php echo $this->url('view=day&date=' . $day->date); ?>"><?php echo $this->escape($f->date($day->date)); ?></a>
                            <span class="small text-muted"><?php echo $this->escape($f->weekday($day->weekday(), true)); ?></span></th>
                        <td><?php echo $check($day, 'in'); ?></td>
                        <td><?php echo $check($day, 'out'); ?></td>
                        <td><?php echo $this->escape($f->duration($day->span())); ?></td>
                        <td><?php echo LayoutHelper::render('biotime.status', ['day' => $day, 'f' => $f]); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($this->canAttendance) : ?>
            <p><a href="<?php echo $this->attendanceUrl(); ?>"><?php echo Text::_('COM_BIOTIME_VIEW_FULL_MONTH'); ?></a></p>
        <?php endif; ?>
    <?php endif; ?>
</div>
