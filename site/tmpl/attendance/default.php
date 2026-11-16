<?php

/**
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use BiotimePortal\Component\Biotime\Site\View\Ui;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;

/** @var \BiotimePortal\Component\Biotime\Site\View\Attendance\HtmlView $this */

$f       = $this->f;
$s       = $this->summary;
$e       = fn (string $text): string => $this->escape($text);
$check   = fn ($day, string $type): string => LayoutHelper::render('biotime.checktime', ['day' => $day, 'f' => $f, 'type' => $type, 'device' => true]);
$filters = [
    ''              => Text::_('COM_BIOTIME_FILTER_ALL'),
    'attended'      => Text::_('COM_BIOTIME_FILTER_ATTENDED'),
    'present'       => Text::_('COM_BIOTIME_STATUS_PRESENT'),
    'missingpunch'  => Text::_('COM_BIOTIME_MISSING_PUNCH'),
    'absent'        => Text::_('COM_BIOTIME_STATUS_ABSENT'),
    'weekend'       => Text::_('COM_BIOTIME_STATUS_WEEKEND'),
    'weekendworked' => Text::_('COM_BIOTIME_CARD_WEEKEND_WORKED'),
    'exceptions'    => Text::_('COM_BIOTIME_FILTER_EXCEPTIONS'),
    'punches'       => Text::_('COM_BIOTIME_FILTER_PUNCHES'),
    'nopunches'     => Text::_('COM_BIOTIME_FILTER_NO_PUNCHES'),
];

$statusSelect = '<select class="bt-input" id="bt-status" name="status">';

foreach ($filters as $value => $label) {
    $statusSelect .= '<option value="' . $value . '"' . ($value === $this->status ? ' selected' : '') . '>' . $e($label) . '</option>';
}

$statusSelect .= '</select>';
// A date picker in DD/MM/YYYY (media/com_biotime/js/datepicker.js); a browser date field would show
// the browser's own order, e.g. MM/DD/YYYY. Month and weekday names are passed in translated.
$dateInput     = '<input class="bt-input" type="text" id="bt-date" name="date" autocomplete="off" maxlength="10" data-bt-datepicker'
    . ' pattern="\d{1,2}/\d{1,2}/\d{4}" placeholder="' . $e(Text::_('COM_BIOTIME_DATE_HINT')) . '"'
    . ' data-months="' . $e(implode(',', array_map([$f, 'monthName'], range(1, 12)))) . '"'
    . ' data-days="' . $e(implode(',', array_map(static fn (int $d): string => $f->weekday($d, true), range(1, 7)))) . '"'
    . ' data-min="01/01/' . \BiotimePortal\Component\Biotime\Administrator\Attendance\AttendanceService::FIRST_YEAR . '"'
    . ' data-labels="' . $e((string) json_encode([
        'choose' => Text::_('COM_BIOTIME_DATE_CHOOSE'),
        'prev'   => Text::_('COM_BIOTIME_PREV_MONTH'),
        'next'   => Text::_('COM_BIOTIME_NEXT_MONTH'),
        'today'  => Text::_('COM_BIOTIME_DATE_TODAY'),
        'clear'  => Text::_('COM_BIOTIME_DATE_CLEAR'),
    ])) . '"'
    . ' value="' . $e($this->date !== '' ? (new DateTimeImmutable($this->date))->format('d/m/Y') : '') . '">';

// Summary cards: [label, value html, tone, filter]
$cards = [
    [Text::_('COM_BIOTIME_CARD_PRESENT_DAYS'), $s->presentDays . ' <small>/ ' . $s->workingDays . '</small>', 'green', 'attended'],
    [Text::_('COM_BIOTIME_CARD_ABSENT_DAYS'), (string) $s->absentDays, Ui::countTone($s->absentDays, 'red'), 'absent'],
    [Text::_('COM_BIOTIME_CARD_MISSING_PUNCH_DAYS'), (string) $s->missingPunchDays, Ui::countTone($s->missingPunchDays, 'amber'), 'missingpunch'],
    [Text::_('COM_BIOTIME_CARD_WEEKEND_WORKED'), (string) $s->weekendWorked, Ui::countTone($s->weekendWorked, 'purple'), 'weekendworked'],
    [Text::_('COM_BIOTIME_CARD_AVG_CHECK_IN'), $e($f->time($s->avgCheckIn)), 'teal', ''],
    [Text::_('COM_BIOTIME_CARD_AVG_CHECK_OUT'), $e($f->time($s->avgCheckOut)), 'blue', ''],
    [Text::_('COM_BIOTIME_CARD_TOTAL_SPAN_SHORT'), $e($f->duration($s->spanMinutes)), 'slate', ''],
    [Text::_('COM_BIOTIME_CARD_PUNCHES'), (string) $s->punchCount, Ui::countTone($s->punchCount, 'blue'), 'punches'],
];
?>
<div class="com-biotime biotime-attendance">
    <div class="bt-head">
        <?php if ($this->showPageHeading) : ?>
            <h1 class="h2"><?php echo $e($this->pageHeading); ?></h1>
        <?php else : ?>
            <span></span>
        <?php endif; ?>
        <?php echo LayoutHelper::render('biotime.monthnav', ['view' => 'attendance', 'year' => $this->year, 'month' => $this->month, 'f' => $f, 'extra' => array_filter(['status' => $this->status])]); ?>
    </div>

    <?php echo LayoutHelper::render('biotime.toolbar', [
        'view'   => 'attendance',
        'year'   => $this->year,
        'month'  => $this->month,
        'f'      => $f,
        'fields' => [
            ['id' => 'bt-status', 'label' => Text::_('COM_BIOTIME_COL_STATUS'), 'html' => $statusSelect],
            ['id' => 'bt-date', 'label' => Text::_('COM_BIOTIME_SEARCH_DATE'), 'html' => $dateInput],
        ],
    ]); ?>

    <?php echo $this->notices($this->period); ?>

    <div class="bt-stats bt-stats--5">
        <?php foreach ($cards as [$label, $value, $tone, $filter]) :
            $inner = '<span class="bt-stat-label">' . $e($label) . '</span><span class="bt-stat-value">' . $value . '</span>';
            ?>
            <?php if ($filter !== '') : ?>
                <a class="bt-stat bt-tone-<?php echo $tone; ?><?php echo $filter === $this->status ? ' is-active' : ''; ?>" href="<?php echo $this->monthUrl('attendance', $this->year, $this->month, ['status' => $filter]); ?>"><?php echo $inner; ?></a>
            <?php else : ?>
                <div class="bt-stat bt-tone-<?php echo $tone; ?>"><?php echo $inner; ?></div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <section class="bt-panel">
        <div class="bt-panel-head">
            <h2 class="bt-panel-title"><?php echo $e($this->monthTitle()); ?></h2>
            <span class="bt-panel-count"><?php echo $e(Text::plural('COM_BIOTIME_N_DAYS', $this->total)); ?><?php echo $this->status !== '' ? ' · ' . $e($filters[$this->status]) : ''; ?></span>
        </div>
        <?php if ($this->rows === []) : ?>
            <p class="bt-panel-body bt-empty"><?php echo Text::_('COM_BIOTIME_NO_DAYS'); ?></p>
        <?php else : ?>
            <div class="bt-table-wrap">
                <table class="bt-table">
                    <caption class="visually-hidden"><?php echo $e($this->pageHeading . ' – ' . $this->monthTitle()); ?></caption>
                    <thead>
                        <tr>
                            <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_DATE'); ?></th>
                            <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_CHECK_IN'); ?></th>
                            <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_CHECK_OUT'); ?></th>
                            <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_SPAN'); ?></th>
                            <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_STATUS'); ?></th>
                            <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_PUNCHES'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($this->rows as $day) : ?>
                        <tr class="<?php echo $day->weekend ? 'bt-row-weekend' : ''; ?><?php echo $day->date === $this->period->today ? ' bt-row-today' : ''; ?>">
                            <th scope="row"><a href="<?php echo $this->url('view=day&date=' . $day->date); ?>"><?php echo $e($f->date($day->date)); ?></a>
                                <span class="bt-sub"><?php echo $e($f->weekday($day->weekday())); ?></span></th>
                            <td><?php echo $check($day, 'in'); ?></td>
                            <td><?php echo $check($day, 'out'); ?></td>
                            <td><?php echo $e($f->duration($day->span())); ?></td>
                            <td>
                                <div class="bt-badges">
                                    <?php echo LayoutHelper::render('biotime.status', ['day' => $day, 'f' => $f]); ?>
                                    <?php foreach ($day->exceptions as $ex) : ?>
                                        <?php if (!in_array($ex, ['missing_check_in', 'missing_check_out'], true)) : ?>
                                            <span class="bt-badge bt-badge--outline bt-tone-amber"><?php echo $e($f->exception($ex)); ?></span>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                            <td>
                                <?php if ($day->punches === []) : ?>
                                    <span class="bt-check-none">0</span>
                                <?php else : ?>
                                    <details class="bt-punches">
                                        <summary><?php echo Text::plural('COM_BIOTIME_N_PUNCHES', count($day->punches)); ?></summary>
                                        <ol>
                                            <?php foreach ($day->punches as $p) : ?>
                                                <li><?php echo $e(implode(' · ', array_filter([$f->time($p->time), $p->state, $p->verify, $p->device, $p->area]))); ?></li>
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
        <?php endif; ?>
    </section>
    <?php echo LayoutHelper::render('biotime.pagination', ['page' => $this->page, 'pages' => $this->pages, 'url' => fn (int $p): string => $this->pageUrl($p)]); ?>
</div>
