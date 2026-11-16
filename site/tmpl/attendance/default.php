<?php

/**
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;

/** @var \BiotimePortal\Component\Biotime\Site\View\Attendance\HtmlView $this */

$f       = $this->f;
$s       = $this->summary;
$itemId  = Factory::getApplication()->getInput()->getInt('Itemid', 0);
$check   = fn ($day, string $type): string => LayoutHelper::render('biotime.checktime', ['day' => $day, 'f' => $f, 'type' => $type, 'device' => true]);
$filters = [
    ''              => Text::_('COM_BIOTIME_FILTER_ALL'),
    'attended'      => Text::_('COM_BIOTIME_FILTER_ATTENDED'),
    'present'       => Text::_('COM_BIOTIME_STATUS_PRESENT'),
    'incomplete'    => Text::_('COM_BIOTIME_STATUS_INCOMPLETE'),
    'absent'        => Text::_('COM_BIOTIME_STATUS_ABSENT'),
    'weekend'       => Text::_('COM_BIOTIME_STATUS_WEEKEND'),
    'weekendworked' => Text::_('COM_BIOTIME_CARD_WEEKEND_WORKED'),
    'leave'         => Text::_('COM_BIOTIME_STATUS_LEAVE'),
    'exceptions'    => Text::_('COM_BIOTIME_FILTER_EXCEPTIONS'),
    'punches'       => Text::_('COM_BIOTIME_FILTER_PUNCHES'),
    'nopunches'     => Text::_('COM_BIOTIME_FILTER_NO_PUNCHES'),
];
$summary = [
    [Text::_('COM_BIOTIME_CARD_PRESENT_DAYS'), $s->presentDays . ' / ' . $s->workingDays],
    [Text::_('COM_BIOTIME_CARD_ABSENT_DAYS'), $s->absentDays],
    [Text::_('COM_BIOTIME_CARD_INCOMPLETE_DAYS'), $s->incompleteDays],
    [Text::_('COM_BIOTIME_CARD_WEEKEND_WORKED'), $s->weekendWorked],
    [Text::_('COM_BIOTIME_CARD_AVG_CHECK_IN'), $f->time($s->avgCheckIn)],
    [Text::_('COM_BIOTIME_CARD_AVG_CHECK_OUT'), $f->time($s->avgCheckOut)],
    [Text::_('COM_BIOTIME_CARD_TOTAL_SPAN_SHORT'), $f->duration($s->spanMinutes)],
    [Text::_('COM_BIOTIME_CARD_PUNCHES'), $s->punchCount],
];
?>
<div class="com-biotime biotime-attendance">
    <?php if ($this->showPageHeading) : ?>
        <h1 class="h2 mb-3"><?php echo $this->escape($this->pageHeading); ?></h1>
    <?php endif; ?>

    <?php echo LayoutHelper::render('biotime.monthnav', ['view' => 'attendance', 'year' => $this->year, 'month' => $this->month, 'f' => $f]); ?>

    <form class="biotime-filters row g-2 align-items-end mb-3" method="get" action="<?php echo Route::_('index.php?option=com_biotime&view=attendance'); ?>">
        <input type="hidden" name="option" value="com_biotime">
        <input type="hidden" name="view" value="attendance">
        <input type="hidden" name="month" value="<?php echo sprintf('%04d-%02d', $this->year, $this->month); ?>">
        <?php if ($itemId > 0) : ?><input type="hidden" name="Itemid" value="<?php echo $itemId; ?>"><?php endif; ?>
        <div class="col-12 col-sm-6 col-lg-4">
            <label class="form-label" for="biotime-status"><?php echo Text::_('COM_BIOTIME_COL_STATUS'); ?></label>
            <select class="form-select" id="biotime-status" name="status">
                <?php foreach ($filters as $value => $label) : ?>
                    <option value="<?php echo $value; ?>" <?php echo $value === $this->status ? 'selected' : ''; ?>><?php echo $this->escape($label); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-sm-6 col-lg-4">
            <label class="form-label" for="biotime-date"><?php echo Text::_('COM_BIOTIME_SEARCH_DATE'); ?></label>
            <input class="form-control" type="date" id="biotime-date" name="date" value="<?php echo $this->escape($this->date); ?>">
        </div>
        <div class="col-12 col-lg-2">
            <button class="btn btn-secondary biotime-btn-neutral w-100" type="submit"><?php echo Text::_('JSEARCH_FILTER_SUBMIT'); ?></button>
        </div>
    </form>

    <h2 class="h4"><?php echo $this->escape($this->monthTitle()); ?></h2>
    <?php echo $this->notices($this->period); ?>

    <dl class="biotime-summary row row-cols-2 row-cols-md-4 g-2 mb-3">
        <?php foreach ($summary as [$label, $value]) : ?>
            <div class="col"><dt class="small text-muted fw-normal"><?php echo $this->escape($label); ?></dt><dd class="fw-semibold mb-0"><?php echo $this->escape((string) $value); ?></dd></div>
        <?php endforeach; ?>
    </dl>

    <?php if ($this->rows === []) : ?>
        <div class="alert alert-info"><?php echo Text::_('COM_BIOTIME_NO_DAYS'); ?></div>
    <?php else : ?>
        <div class="table-responsive">
            <table class="table table-striped align-middle biotime-table">
                <caption class="visually-hidden"><?php echo $this->escape($this->pageHeading . ' – ' . $this->monthTitle()); ?></caption>
                <thead>
                    <tr>
                        <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_DATE'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_DAY'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_CHECK_IN'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_CHECK_OUT'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_SPAN'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_STATUS'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_PUNCHES'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($this->rows as $day) : ?>
                    <tr class="<?php echo $day->weekend ? 'biotime-row-weekend' : ''; ?><?php echo $day->date === $this->period->today ? ' biotime-row-today' : ''; ?>">
                        <th scope="row" class="text-nowrap"><a href="<?php echo $this->url('view=day&date=' . $day->date); ?>"><?php echo $this->escape($f->date($day->date)); ?></a></th>
                        <td><?php echo $this->escape($f->weekday($day->weekday(), true)); ?></td>
                        <td><?php echo $check($day, 'in'); ?></td>
                        <td><?php echo $check($day, 'out'); ?></td>
                        <td><?php echo $this->escape($f->duration($day->span())); ?></td>
                        <td>
                            <?php echo LayoutHelper::render('biotime.status', ['day' => $day, 'f' => $f]); ?>
                            <?php if ($day->leave !== null && $day->status !== 'leave') : ?>
                                <span class="badge text-bg-light border"><?php echo $this->escape(Text::_('COM_BIOTIME_STATUS_LEAVE') . ': ' . $day->leave); ?></span>
                            <?php endif; ?>
                            <?php foreach ($day->exceptions as $ex) : ?>
                                <?php if (!in_array($ex, ['missing_check_in', 'missing_check_out'], true)) : ?>
                                    <span class="badge text-bg-light border biotime-exception"><?php echo $this->escape($f->exception($ex)); ?></span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </td>
                        <td>
                            <?php if ($day->punches === []) : ?>
                                <span class="text-muted">0</span>
                            <?php else : ?>
                                <details class="biotime-punches">
                                    <summary><?php echo Text::plural('COM_BIOTIME_N_PUNCHES', count($day->punches)); ?></summary>
                                    <ol class="list-unstyled small mb-0">
                                        <?php foreach ($day->punches as $p) : ?>
                                            <li><?php echo $this->escape(implode(' · ', array_filter([$f->time($p->time), $p->state, $p->verify, $p->device, $p->area]))); ?></li>
                                        <?php endforeach; ?>
                                    </ol>
                                </details>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php echo LayoutHelper::render('biotime.pagination', ['page' => $this->page, 'pages' => $this->pages, 'url' => fn (int $p): string => $this->pageUrl($p)]); ?>
    <?php endif; ?>
</div>
