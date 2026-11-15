<?php

/**
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;

/** @var \BiotimePortal\Component\Biotime\Site\View\Dashboard\HtmlView $this */

$s    = $this->summary;
$f    = $this->f;
$link = fn (string $status): string => $this->canAttendance ? $this->attendanceUrl($status) : '';

// Summary cards: only those ZKBioTime can fill. [label key, value text, status filter]
$cards = array_filter([
    [Text::_('COM_BIOTIME_CARD_WORKING_DAYS'), $s->workingDays, ''],
    [Text::_('COM_BIOTIME_CARD_PRESENT_DAYS'), $s->presentDays, 'present'],
    [Text::_('COM_BIOTIME_CARD_LATE_COUNT'), $s->lateCount, 'late'],
    [Text::_('COM_BIOTIME_CARD_LATE_MINUTES'), $s->lateMinutes === null ? null : $f->duration($s->lateMinutes), 'late'],
    [Text::_('COM_BIOTIME_CARD_EARLY_COUNT'), $s->earlyCount, 'early'],
    [Text::_('COM_BIOTIME_CARD_EARLY_MINUTES'), $s->earlyMinutes === null ? null : $f->duration($s->earlyMinutes), 'early'],
    [Text::_('COM_BIOTIME_CARD_ABSENT_DAYS'), $s->absentDays, 'absent'],
    [Text::_('COM_BIOTIME_CARD_LEAVE_DAYS'), $s->leaveDays, 'leave'],
    [Text::_('COM_BIOTIME_CARD_WORKED_HOURS'), $s->workedMinutes === null ? null : $f->duration($s->workedMinutes), ''],
    [Text::_('COM_BIOTIME_CARD_OVERTIME_HOURS'), $s->overtimeMinutes === null ? null : $f->duration($s->overtimeMinutes), ''],
    [Text::_('COM_BIOTIME_CARD_PUNCH_DAYS'), $s->punchDays, 'punches'],
], static fn (array $c): bool => $c[1] !== null);

// Monthly overview: counts ZKBioTime returned per status.
$overview = $s->presentDays === null ? [] : [
    ['present', Text::_('COM_BIOTIME_STATUS_PRESENT'), (int) $s->presentDays],
    ['absent', Text::_('COM_BIOTIME_STATUS_ABSENT'), (int) $s->absentDays],
    ['late', Text::_('COM_BIOTIME_OVERVIEW_LATE'), (int) $s->lateCount],
    ['early', Text::_('COM_BIOTIME_OVERVIEW_EARLY'), (int) $s->earlyCount],
    ['leave', Text::_('COM_BIOTIME_STATUS_LEAVE'), (int) $s->leaveDays],
    ['holiday', Text::_('COM_BIOTIME_STATUS_HOLIDAY'), (int) $s->holidays],
    ['rest', Text::_('COM_BIOTIME_STATUS_REST'), (int) $s->restDays],
];
$max = max(1, ...array_map(static fn (array $o): int => $o[2], $overview ?: [[0, 0, 1]]));
?>
<div class="com-biotime biotime-dashboard">
    <?php if ($this->showPageHeading) : ?>
        <h1 class="h2 mb-3"><?php echo $this->escape($this->pageHeading); ?></h1>
    <?php endif; ?>

    <?php echo LayoutHelper::render('biotime.monthnav', ['view' => 'dashboard', 'year' => $this->year, 'month' => $this->month, 'f' => $f]); ?>
    <h2 class="h4"><?php echo $this->escape($this->monthTitle()); ?></h2>
    <?php echo LayoutHelper::render('biotime.notices', ['period' => $this->period, 'zone' => $this->zone, 'updated' => $this->updatedText($this->period->fetchedAt)]); ?>

    <div class="row g-3 mb-4">
        <?php foreach ($cards as [$label, $value, $status]) :
            $url = $link($status);
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
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($overview !== []) : ?>
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
                            <rect x="130" y="<?php echo $y; ?>" width="<?php echo max(2, $w); ?>" height="20" rx="3" class="biotime-bar biotime-bar-<?php echo $key; ?>"></rect>
                            <text x="<?php echo 136 + max(2, $w); ?>" y="<?php echo $y + 15; ?>" class="biotime-chart-label"><?php echo $count; ?></text>
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
                                    <?php if ($this->canAttendance) : ?><a href="<?php echo $this->attendanceUrl($key); ?>"><?php echo $count; ?></a><?php else : ?><?php echo $count; ?><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <h2 class="h4"><?php echo Text::_('COM_BIOTIME_RECENT_ACTIVITY'); ?></h2>
    <?php if ($this->period->error('punches') !== null && $this->period->error('daily') !== null) : ?>
        <p class="text-muted"><?php echo Text::_('COM_BIOTIME_NOTHING_TO_SHOW'); ?></p>
    <?php elseif ($this->recent === []) : ?>
        <p class="text-muted"><?php echo Text::_('COM_BIOTIME_NO_RECENT'); ?></p>
    <?php else : ?>
        <div class="table-responsive mb-2">
            <table class="table table-striped align-middle">
                <caption class="visually-hidden"><?php echo Text::_('COM_BIOTIME_RECENT_ACTIVITY'); ?></caption>
                <thead>
                    <tr>
                        <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_DATE'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_CHECK_IN'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_CHECK_OUT'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_SCHEDULE'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_STATUS'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($this->recent as $day) : ?>
                    <tr>
                        <th scope="row"><?php if ($this->canAttendance || $this->canCalendar) : ?><a href="<?php echo $this->url('view=day&date=' . $day->date); ?>"><?php echo $this->escape($f->date($day->date)); ?></a><?php else : ?><?php echo $this->escape($f->date($day->date)); ?><?php endif; ?>
                            <span class="small text-muted"><?php echo $this->escape($f->weekday($day->weekday(), true)); ?></span></th>
                        <td><?php echo $this->escape($f->time($day->checkIn)); ?></td>
                        <td><?php echo $this->escape($f->time($day->checkOut)); ?></td>
                        <td><?php echo $this->escape($f->text($day->schedule)); ?></td>
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
