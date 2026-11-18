<?php

/**
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use BiotimePortal\Component\Biotime\Administrator\Attendance\AttendanceService;
use BiotimePortal\Component\Biotime\Site\View\Ui;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;

/** @var \BiotimePortal\Component\Biotime\Site\View\Manager\HtmlView $this */

/*
 * Manager Dashboard (1.0.0): filter bar (department, employee, status / From, To), cards
 * (workforce and the figures of My Attendance for everyone shown; a card with a filter shows only
 * the matching days and jumps to them), then one row per employee per day.
 */

$f     = $this->f;
$s     = $this->summary;
$e     = fn (string $text): string => $this->escape($text);
$check = fn ($day, string $type): string => LayoutHelper::render('biotime.checktime', ['day' => $day, 'f' => $f, 'type' => $type, 'compact' => true]);
$min   = fn (?int $minutes): string => LayoutHelper::render('biotime.minutes', ['minutes' => $minutes, 'f' => $f]);

// Department list: the manager's department, then those below it (indented).
$deptSelect = '<select class="bt-input" id="bt-dept" name="dept"><option value="">' . $e(Text::_('COM_BIOTIME_MANAGER_ALL_DEPARTMENTS')) . '</option>';

foreach ($this->departments as $d) {
    $deptSelect .= '<option value="' . $d['id'] . '"' . ($d['id'] === $this->dept ? ' selected' : '') . '>' . str_repeat('&nbsp;&nbsp;', $d['depth']) . $e($d['name']) . '</option>';
}

$deptSelect .= '</select>';

// Employee list: those of the chosen department (or all the manager's).
$empSelect = '<select class="bt-input" id="bt-emp" name="emp"><option value="">' . $e(Text::_('COM_BIOTIME_MANAGER_ALL_EMPLOYEES')) . '</option>';

foreach ($this->dept > 0 ? $this->employees : $this->team as $emp) {
    $empSelect .= '<option value="' . $e($emp['emp_code']) . '"' . ($emp['emp_code'] === $this->emp ? ' selected' : '') . '>' . $e($emp['name'] . ' (' . $emp['emp_code'] . ')') . '</option>';
}

$empSelect .= '</select>';

$filters = [
    ''                 => Text::_('COM_BIOTIME_FILTER_ALL'),
    'attended'         => Text::_('COM_BIOTIME_FILTER_ATTENDED'),
    'absent'           => Text::_('COM_BIOTIME_STATUS_ABSENT'),
    'missingmorning'   => Text::_('COM_BIOTIME_CARD_MISSING_MORNING'),
    'missingafternoon' => Text::_('COM_BIOTIME_CARD_MISSING_AFTERNOON'),
    'late'             => Text::_('COM_BIOTIME_FILTER_LATE'),
    'early'            => Text::_('COM_BIOTIME_FILTER_EARLY'),
    'holiday'          => Text::_('COM_BIOTIME_STATUS_HOLIDAY'),
    'weekend'          => Text::_('COM_BIOTIME_STATUS_WEEKEND'),
    'weekendworked'    => Text::_('COM_BIOTIME_CARD_WEEKEND_WORKED'),
];
$statusSelect = '<select class="bt-input" id="bt-status" name="status">';

foreach ($filters as $value => $label) {
    $statusSelect .= '<option value="' . $value . '"' . ($value === $this->status ? ' selected' : '') . '>' . $e($label) . '</option>';
}

$statusSelect .= '</select>';

$dateInput = fn (string $id, string $name, string $value): string => '<input class="bt-input" type="text" id="' . $id . '" name="' . $name . '" autocomplete="off" maxlength="10" data-bt-datepicker'
    . ' pattern="\d{1,2}/\d{1,2}/\d{4}" placeholder="' . $e(Text::_('COM_BIOTIME_DATE_HINT')) . '"'
    . ' data-months="' . $e(implode(',', array_map([$f, 'monthName'], range(1, 12)))) . '"'
    . ' data-days="' . $e(implode(',', array_map(static fn (int $d): string => $f->weekday($d, true), range(1, 7)))) . '"'
    . ' data-month="' . sprintf('%02d/%04d', $this->month, $this->year) . '"'
    . ' data-min="01/01/' . AttendanceService::FIRST_YEAR . '"'
    . ' data-labels="' . $e((string) json_encode([
        'choose' => Text::_('COM_BIOTIME_DATE_CHOOSE'),
        'prev'   => Text::_('COM_BIOTIME_PREV_MONTH'),
        'next'   => Text::_('COM_BIOTIME_NEXT_MONTH'),
        'today'  => Text::_('COM_BIOTIME_DATE_TODAY'),
        'clear'  => Text::_('COM_BIOTIME_DATE_CLEAR'),
    ])) . '"'
    . ' value="' . $e((new DateTimeImmutable($value))->format('d/m/Y')) . '">';

// Cards: [label, value html, tone, filter]; '*' = every day (clears the status filter).
$cards = $s === null ? [] : [
    [Text::_('COM_BIOTIME_MANAGER_WORKFORCE'), (string) \count($this->employees), 'blue', '*'],
    [Text::_('COM_BIOTIME_CARD_PRESENT_DAYS'), $s->presentDays . ' <small>/ ' . $s->workingDays . '</small>', 'green', 'attended'],
    [Text::_('COM_BIOTIME_CARD_ABSENT_DAYS'), (string) $s->absentDays, Ui::countTone($s->absentDays, 'red'), 'absent'],
    [Text::_('COM_BIOTIME_CARD_MISSING_MORNING'), (string) $s->missingMorningDays, Ui::countTone($s->missingMorningDays, 'amber'), 'missingmorning'],
    [Text::_('COM_BIOTIME_CARD_MISSING_AFTERNOON'), (string) $s->missingAfternoonDays, Ui::countTone($s->missingAfternoonDays, 'amber'), 'missingafternoon'],
    // Days with a check-in (morning punch) and with a check-out (afternoon punch), 1.0.2.
    [Text::_('COM_BIOTIME_MANAGER_MORNING_PUNCHES'), (string) $this->morningPunches, Ui::countTone($this->morningPunches, 'teal'), ''],
    [Text::_('COM_BIOTIME_MANAGER_AFTERNOON_PUNCHES'), (string) $this->afternoonPunches, Ui::countTone($this->afternoonPunches, 'blue'), ''],
    [Text::_('COM_BIOTIME_CARD_HOLIDAYS'), (string) $s->holidayDays, Ui::countTone($s->holidayDays, 'purple'), 'holiday'],
    [Text::_('COM_BIOTIME_CARD_WEEKEND_WORKED'), (string) $s->weekendWorked, Ui::countTone($s->weekendWorked, 'sky'), 'weekendworked'],
    [Text::_('COM_BIOTIME_CARD_RATE'), $s->rate === null ? '—' : $s->rate . ' <small>%</small>', $s->rate === null ? 'muted' : ($s->rate >= 90 ? 'green' : ($s->rate >= 75 ? 'amber' : 'red')), ''],
    [Text::_('COM_BIOTIME_CARD_AVG_CHECK_IN'), $e($f->time($s->avgCheckIn)), 'teal', ''],
    [Text::_('COM_BIOTIME_CARD_AVG_CHECK_OUT'), $e($f->time($s->avgCheckOut)), 'blue', ''],
    [Text::_('COM_BIOTIME_CARD_LATE_TOTAL'), $e($f->duration($s->lateMinutes)) . ' <small>' . $e(Text::plural('COM_BIOTIME_N_DAYS', $s->lateDays)) . '</small>', Ui::countTone($s->lateMinutes, 'amber'), 'late'],
    [Text::_('COM_BIOTIME_CARD_EARLY_TOTAL'), $e($f->duration($s->earlyMinutes)) . ' <small>' . $e(Text::plural('COM_BIOTIME_N_DAYS', $s->earlyDays)) . '</small>', Ui::countTone($s->earlyMinutes, 'amber'), 'early'],
    [Text::_('COM_BIOTIME_CARD_LATE_EARLY'), $e($f->duration($s->lateEarlyMinutes())), Ui::countTone($s->lateEarlyMinutes(), 'red'), ''],
    [Text::_('COM_BIOTIME_CARD_WORKED'), $e($f->duration($s->workedMinutes)), 'blue', ''],
    [Text::_('COM_BIOTIME_CARD_OVERTIME'), $e($f->duration($s->overtimeMinutes)), Ui::countTone($s->overtimeMinutes, 'teal'), ''],
];
$range = $this->from === $this->to ? $f->date($this->from) : $f->date($this->from) . ' – ' . $f->date($this->to);
?>
<div class="com-biotime biotime-manager" data-bt-root="manager">
    <div class="bt-head">
        <?php if ($this->showPageHeading) : ?>
            <h1 class="h2"><?php echo $e($this->pageHeading); ?></h1>
        <?php else : ?>
            <span></span>
        <?php endif; ?>
    </div>

    <?php if ($this->problem !== '') : ?>
        <div class="bt-alert bt-tone-<?php echo $this->problem === 'nodepartment' ? 'amber' : 'red'; ?>" role="alert">
            <?php echo $e(Text::_($this->problem === 'nodepartment' ? 'COM_BIOTIME_MANAGER_NO_DEPARTMENT' : 'COM_BIOTIME_ERROR_' . strtoupper($this->problem))); ?>
        </div>
    <?php else : ?>
        <?php echo LayoutHelper::render('biotime.toolbar', [
            'view'   => 'manager',
            'f'      => $f,
            'fields' => [
                ['id' => 'bt-dept', 'label' => Text::_('COM_BIOTIME_MANAGER_DEPARTMENT'), 'html' => $deptSelect],
                ['id' => 'bt-emp', 'label' => Text::_('COM_BIOTIME_MANAGER_EMPLOYEE'), 'html' => $empSelect],
                ['id' => 'bt-status', 'label' => Text::_('COM_BIOTIME_COL_STATUS'), 'html' => $statusSelect],
                ['break' => true],
                ['id' => 'bt-from', 'label' => Text::_('COM_BIOTIME_DATE_FROM'), 'html' => $dateInput('bt-from', 'from', $this->from)],
                ['id' => 'bt-to', 'label' => Text::_('COM_BIOTIME_DATE_TO'), 'html' => $dateInput('bt-to', 'to', $this->to)],
            ],
        ]); ?>

        <?php foreach ($this->errors as $type => $kind) : ?>
            <div class="bt-alert bt-tone-red" role="alert">
                <strong><?php echo $e(Text::_('COM_BIOTIME_PART_' . strtoupper($type))); ?>:</strong>
                <?php echo $e(Text::_('COM_BIOTIME_ERROR_' . strtoupper($kind))); ?>
            </div>
        <?php endforeach; ?>

        <div class="bt-stats bt-stats--5">
            <?php foreach ($cards as [$label, $value, $tone, $filter]) :
                $inner = '<span class="bt-stat-label">' . $e($label) . '</span><span class="bt-stat-value">' . $value . '</span>';
                ?>
                <?php if ($filter !== '') :
                    $value  = $filter === '*' ? '' : $filter;
                    $active = $value === $this->status;
                    ?>
                    <a class="bt-stat bt-tone-<?php echo $tone; ?><?php echo $active ? ' is-active' : ''; ?>" href="<?php echo $this->filterUrl(['status' => $value]); ?>#bt-records"<?php echo $active ? ' aria-current="true"' : ''; ?>><?php echo $inner; ?></a>
                <?php else : ?>
                    <div class="bt-stat bt-tone-<?php echo $tone; ?>"><?php echo $inner; ?></div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <section class="bt-panel" id="bt-records">
            <div class="bt-panel-head">
                <h2 class="bt-panel-title" id="bt-mgr-range"><?php echo $e($range); ?></h2>
                <span class="bt-panel-count"><?php echo $e(Text::plural('COM_BIOTIME_MANAGER_N_EMPLOYEES', \count($this->rows))); ?> · <?php echo $e(Text::plural('COM_BIOTIME_N_DAYS', \count($this->dates))); ?><?php echo $this->status !== '' ? ' · ' . $e($filters[$this->status] ?? '') : ''; ?></span>
            </div>
            <?php if ($this->rows === [] || $this->dates === []) : ?>
                <p class="bt-panel-body bt-empty"><?php echo Text::_('COM_BIOTIME_NO_DAYS'); ?></p>
            <?php else : ?>
                <?php // Its own scroll area: the sideways scrollbar stays in view, the header row and the employee column stay put (1.0.2). ?>
                <div class="bt-table-wrap bt-table-wrap--fixed" tabindex="0" role="region" aria-labelledby="bt-mgr-range">
                    <table class="bt-table bt-manager-table">
                        <caption class="visually-hidden"><?php echo $e(Text::_('COM_BIOTIME_MANAGER_TITLE') . ': ' . $range); ?></caption>
                        <thead>
                            <tr>
                                <th scope="col" class="bt-mgr-name"><?php echo Text::_('COM_BIOTIME_MANAGER_EMPLOYEE'); ?></th>
                                <?php foreach ($this->dates as $date) :
                                    $dt = new DateTimeImmutable($date);
                                    ?>
                                    <th scope="col" class="bt-mgr-date"><?php echo $e($f->date($date)); ?><span class="bt-sub"><?php echo $e($f->weekday((int) $dt->format('N'))); ?></span></th>
                                <?php endforeach; ?>
                                <th scope="col" class="bt-num"><?php echo Text::_('COM_BIOTIME_COL_LATE'); ?></th>
                                <th scope="col" class="bt-num"><?php echo Text::_('COM_BIOTIME_COL_EARLY'); ?></th>
                                <th scope="col" class="bt-num"><?php echo Text::_('COM_BIOTIME_MANAGER_TOTAL'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($this->rows as $row) :
                            $emp = $row['employee'];
                            ?>
                            <tr>
                                <th scope="row" class="bt-mgr-name">
                                    <?php echo $e($emp['name']); ?>
                                    <span class="bt-sub"><?php echo $e(implode(' · ', array_filter([$emp['emp_code'], $emp['department']]))); ?></span>
                                </th>
                                <?php foreach ($this->dates as $date) :
                                    $day   = $row['days'][$date] ?? null;
                                    $late  = $day?->late ?? 0;
                                    $early = $day?->early ?? 0;
                                    // The day's late-in and early-out in the cell's tooltip; the cell is amber when there are any.
                                    $tip   = $day === null ? '' : implode(' · ', array_filter([
                                        $day->checkIn !== null ? Text::_('COM_BIOTIME_COL_CHECK_IN') . ' ' . $f->time($day->checkIn) : '',
                                        $day->checkOut !== null ? Text::_('COM_BIOTIME_COL_CHECK_OUT') . ' ' . $f->time($day->checkOut) : '',
                                        $late > 0 ? Text::_('COM_BIOTIME_COL_LATE') . ' ' . $f->duration($late) : '',
                                        $early > 0 ? Text::_('COM_BIOTIME_COL_EARLY') . ' ' . $f->duration($early) : '',
                                    ]));
                                    $class = $day === null ? '' : ($day->status === 'holiday' ? 'bt-row-holiday' : ($day->weekend ? 'bt-row-weekend' : ($late + $early > 0 ? 'bt-mgr-late' : '')));
                                    ?>
                                    <td class="bt-inout <?php echo $class; ?>"<?php echo $tip !== '' ? ' title="' . $e($tip) . '"' : ''; ?>>
                                        <?php if ($day === null) : ?>
                                            <span class="bt-check-none">—</span>
                                        <?php elseif ($day->punches === [] && $day->status !== null) : ?>
                                            <?php echo LayoutHelper::render('biotime.status', ['day' => $day, 'f' => $f]); ?>
                                        <?php else : ?>
                                            <span class="bt-inout-line"><?php echo $check($day, 'in'); ?></span>
                                            <span class="bt-inout-line"><?php echo $check($day, 'out'); ?></span>
                                            <?php if ($late + $early > 0) : ?>
                                                <span class="bt-mgr-mins"><?php echo $e($f->duration($late + $early)); ?></span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                                <td class="bt-num"><?php echo $min($row['late']); ?></td>
                                <td class="bt-num"><?php echo $min($row['early']); ?></td>
                                <td class="bt-num"><?php echo $row['late'] + $row['early'] > 0 ? '<strong class="bt-late">' . $e($f->duration($row['late'] + $row['early'])) . '</strong>' : '<span class="bt-check-none">—</span>'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>
