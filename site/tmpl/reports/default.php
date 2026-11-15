<?php

/**
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;

/** @var \BiotimePortal\Component\Biotime\Site\View\Reports\HtmlView $this */

$f      = $this->f;
$r      = $this->range;
$itemId = Factory::getApplication()->getInput()->getInt('Itemid', 0);
$month  = $r['from']->format('Y-m');
$hidden = static function (array $query) use ($itemId): string {
    $html = '';

    foreach ($query + ($itemId > 0 ? ['Itemid' => (string) $itemId] : []) as $k => $v) {
        $html .= '<input type="hidden" name="' . htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8') . '">';
    }

    return $html;
};
$sectionTitles = [
    'late'       => 'COM_BIOTIME_REPORT_LATE',
    'early'      => 'COM_BIOTIME_REPORT_EARLY',
    'absent'     => 'COM_BIOTIME_REPORT_ABSENCE',
    'overtime'   => 'COM_BIOTIME_REPORT_OVERTIME',
    'exceptions' => 'COM_BIOTIME_REPORT_EXCEPTIONS',
];
$dayLink = fn (string $date): string => '<a href="' . $this->url('view=day&date=' . $date) . '">' . $this->escape($f->date($date)) . '</a>';
?>
<div class="com-biotime biotime-reports">
    <?php if ($this->showPageHeading) : ?>
        <h1 class="h2 mb-3"><?php echo $this->escape($this->pageHeading); ?></h1>
    <?php endif; ?>

    <form class="biotime-filters row g-2 align-items-end mb-3" method="get" action="<?php echo Route::_('index.php?option=com_biotime&view=reports'); ?>">
        <input type="hidden" name="option" value="com_biotime">
        <input type="hidden" name="view" value="reports">
        <?php if ($itemId > 0) : ?><input type="hidden" name="Itemid" value="<?php echo $itemId; ?>"><?php endif; ?>
        <fieldset class="col-12 col-md-auto">
            <legend class="form-label fs-6 mb-1"><?php echo Text::_('COM_BIOTIME_REPORT_PERIOD'); ?></legend>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="mode" id="biotime-mode-month" value="month" <?php echo $r['mode'] === 'month' ? 'checked' : ''; ?>>
                <label class="form-check-label" for="biotime-mode-month"><?php echo Text::_('COM_BIOTIME_REPORT_MODE_MONTH'); ?></label>
            </div>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="mode" id="biotime-mode-range" value="range" <?php echo $r['mode'] === 'range' ? 'checked' : ''; ?>>
                <label class="form-check-label" for="biotime-mode-range"><?php echo Text::_('COM_BIOTIME_REPORT_MODE_RANGE'); ?></label>
            </div>
        </fieldset>
        <div class="col-12 col-sm-4 col-md-2">
            <label class="form-label" for="biotime-month"><?php echo Text::_('COM_BIOTIME_MONTH'); ?></label>
            <input class="form-control" type="month" id="biotime-month" name="month" value="<?php echo $this->escape($month); ?>" pattern="\d{4}-\d{2}" placeholder="YYYY-MM">
        </div>
        <div class="col-6 col-sm-4 col-md-2">
            <label class="form-label" for="biotime-from"><?php echo Text::_('COM_BIOTIME_REPORT_FROM'); ?></label>
            <input class="form-control" type="date" id="biotime-from" name="from" value="<?php echo $this->escape($r['from']->format('Y-m-d')); ?>">
        </div>
        <div class="col-6 col-sm-4 col-md-2">
            <label class="form-label" for="biotime-to"><?php echo Text::_('COM_BIOTIME_REPORT_TO'); ?></label>
            <input class="form-control" type="date" id="biotime-to" name="to" value="<?php echo $this->escape($r['to']->format('Y-m-d')); ?>">
        </div>
        <div class="col-12 col-md-2">
            <button class="btn btn-secondary biotime-btn-neutral w-100" type="submit"><?php echo Text::_('COM_BIOTIME_SHOW'); ?></button>
        </div>
        <p class="col-12 small text-muted mb-0"><?php echo Text::sprintf('COM_BIOTIME_REPORT_MAX_DAYS', $this->maxDays); ?></p>
    </form>

    <?php if ($this->rangeError !== '') : ?>
        <div class="alert alert-warning" role="alert"><?php echo $this->escape($this->rangeError); ?></div>
    <?php elseif ($this->period !== null && $this->summary !== null) :
        $s = $this->summary;
        $summary = array_filter([
            ['COM_BIOTIME_CARD_WORKING_DAYS', $s->workingDays],
            ['COM_BIOTIME_CARD_PRESENT_DAYS', $s->presentDays],
            ['COM_BIOTIME_CARD_WORKED_HOURS', $s->workedMinutes === null ? null : $f->duration($s->workedMinutes)],
            ['COM_BIOTIME_CARD_LATE_COUNT', $s->lateCount],
            ['COM_BIOTIME_CARD_LATE_MINUTES', $s->lateMinutes === null ? null : $f->duration($s->lateMinutes)],
            ['COM_BIOTIME_CARD_EARLY_COUNT', $s->earlyCount],
            ['COM_BIOTIME_CARD_EARLY_MINUTES', $s->earlyMinutes === null ? null : $f->duration($s->earlyMinutes)],
            ['COM_BIOTIME_CARD_ABSENT_DAYS', $s->absentDays],
            ['COM_BIOTIME_CARD_LEAVE_DAYS', $s->leaveDays],
            ['COM_BIOTIME_CARD_OVERTIME_HOURS', $s->overtimeMinutes === null ? null : $f->duration($s->overtimeMinutes)],
            ['COM_BIOTIME_CARD_PUNCH_DAYS', $s->punchDays],
            ['COM_BIOTIME_CARD_EXCEPTIONS', $s->exceptionCount],
        ], static fn (array $p): bool => $p[1] !== null);
        ?>
        <h2 class="h4"><?php echo $this->escape($f->date($this->period->from) . ' – ' . $f->date($this->period->to)); ?></h2>
        <?php echo LayoutHelper::render('biotime.notices', ['period' => $this->period, 'zone' => $this->zone, 'updated' => $this->updatedText($this->period->fetchedAt)]); ?>

        <?php if ($this->canExport) : ?>
            <form class="d-flex flex-wrap gap-2 mb-3" method="post" action="<?php echo Route::_('index.php?option=com_biotime'); ?>">
                <?php echo $hidden(['task' => 'report.export'] + $r['query']); ?>
                <?php echo HTMLHelper::_('form.token'); ?>
                <button class="btn btn-primary" type="submit" name="format" value="pdf"><?php echo Text::_('COM_BIOTIME_EXPORT_PDF'); ?></button>
                <button class="btn btn-primary" type="submit" name="format" value="xlsx"><?php echo Text::_('COM_BIOTIME_EXPORT_XLSX'); ?></button>
            </form>
        <?php elseif ($this->exportsOff) : ?>
            <p class="text-muted"><?php echo Text::_('COM_BIOTIME_EXPORTS_OFF'); ?></p>
        <?php endif; ?>

        <h3 class="h5"><?php echo Text::_('COM_BIOTIME_REPORT_SUMMARY'); ?></h3>
        <div class="table-responsive mb-3">
            <table class="table table-striped table-sm biotime-narrow">
                <caption class="visually-hidden"><?php echo Text::_('COM_BIOTIME_REPORT_SUMMARY'); ?></caption>
                <tbody>
                <?php foreach ($summary as [$key, $value]) : ?>
                    <tr><th scope="row"><?php echo Text::_($key); ?></th><td class="text-end"><?php echo $this->escape((string) $value); ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php foreach ($sectionTitles as $section => $titleKey) :
            $days = $this->sections[$section];
            if (in_array($section, ['late', 'early', 'absent', 'overtime'], true) && !$this->period->daily->isConfigured()) {
                continue;
            }
            ?>
            <h3 class="h5"><?php echo Text::_($titleKey); ?> <span class="badge text-bg-secondary"><?php echo count($days); ?></span></h3>
            <?php if ($days === []) : ?>
                <p class="text-muted"><?php echo Text::_('COM_BIOTIME_REPORT_NONE'); ?></p>
            <?php else : ?>
                <div class="table-responsive mb-3">
                    <table class="table table-striped table-sm">
                        <caption class="visually-hidden"><?php echo Text::_($titleKey); ?></caption>
                        <thead><tr>
                            <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_DATE'); ?></th>
                            <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_SCHEDULE'); ?></th>
                            <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_CHECK_IN'); ?></th>
                            <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_CHECK_OUT'); ?></th>
                            <th scope="col"><?php echo Text::_($section === 'exceptions' ? 'COM_BIOTIME_COL_EXCEPTIONS' : ($section === 'absent' ? 'COM_BIOTIME_COL_STATUS' : 'COM_BIOTIME_COL_' . strtoupper($section === 'overtime' ? 'overtime' : $section))); ?></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($days as $day) : ?>
                            <tr>
                                <th scope="row" class="text-nowrap"><?php echo $dayLink($day->date); ?></th>
                                <td><?php echo $this->escape($f->text($day->schedule)); ?></td>
                                <td><?php echo $this->escape($f->time($day->checkIn)); ?></td>
                                <td><?php echo $this->escape($f->time($day->checkOut)); ?></td>
                                <td><?php echo $this->escape(match ($section) {
                                    'late'       => $f->duration($day->late),
                                    'early'      => $f->duration($day->early),
                                    'overtime'   => $f->duration($day->overtime),
                                    'absent'     => $f->status($day),
                                    default      => implode(', ', array_map([$f, 'exception'], $day->exceptions)),
                                }); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>

        <?php if ($this->leaveOff) : ?>
            <h3 class="h5"><?php echo Text::_('COM_BIOTIME_REPORT_LEAVE'); ?></h3>
            <p class="text-muted"><?php echo Text::_('COM_BIOTIME_LEAVE_OFF'); ?></p>
        <?php elseif ($this->period->leaveSource->isConfigured()) : ?>
            <h3 class="h5"><?php echo Text::_('COM_BIOTIME_REPORT_LEAVE'); ?> <span class="badge text-bg-secondary"><?php echo count($this->period->leave); ?></span></h3>
            <?php if ($this->period->leave === []) : ?>
                <p class="text-muted"><?php echo Text::_('COM_BIOTIME_REPORT_NONE'); ?></p>
            <?php else : ?>
                <div class="table-responsive mb-3">
                    <table class="table table-striped table-sm">
                        <caption class="visually-hidden"><?php echo Text::_('COM_BIOTIME_REPORT_LEAVE'); ?></caption>
                        <thead><tr>
                            <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_FROM'); ?></th>
                            <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_TO'); ?></th>
                            <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_LEAVE_TYPE'); ?></th>
                            <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_DURATION'); ?></th>
                            <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_APPROVAL'); ?></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($this->period->leave as $l) : ?>
                            <tr>
                                <th scope="row"><?php echo $this->escape($f->date($l->start)); ?></th>
                                <td><?php echo $this->escape($f->date($l->end ?? $l->start)); ?></td>
                                <td><?php echo $this->escape($f->text($l->type)); ?></td>
                                <td><?php echo $this->escape($l->durationMinutes !== null ? $f->duration($l->durationMinutes) : $f->text($l->durationText)); ?></td>
                                <td><?php echo $this->escape($l->status !== null ? Text::_('COM_BIOTIME_LEAVE_' . strtoupper($l->status)) : $f->text($l->statusRaw)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <h3 class="h5"><?php echo Text::_('COM_BIOTIME_REPORT_DAYS'); ?></h3>
        <div class="table-responsive">
            <table class="table table-striped align-middle">
                <caption class="visually-hidden"><?php echo Text::_('COM_BIOTIME_REPORT_DAYS'); ?></caption>
                <thead><tr>
                    <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_DATE'); ?></th>
                    <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_DAY'); ?></th>
                    <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_SCHEDULE'); ?></th>
                    <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_CHECK_IN'); ?></th>
                    <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_CHECK_OUT'); ?></th>
                    <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_WORKED'); ?></th>
                    <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_STATUS'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($this->rows as $day) : ?>
                    <tr>
                        <th scope="row" class="text-nowrap"><?php echo $dayLink($day->date); ?></th>
                        <td><?php echo $this->escape($f->weekday($day->weekday(), true)); ?></td>
                        <td><?php echo $this->escape($f->text($day->schedule)); ?></td>
                        <td><?php echo $this->escape($f->time($day->checkIn)); ?></td>
                        <td><?php echo $this->escape($f->time($day->checkOut)); ?></td>
                        <td><?php echo $this->escape($f->duration($day->worked)); ?></td>
                        <td><?php echo LayoutHelper::render('biotime.status', ['day' => $day, 'f' => $f]); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php echo LayoutHelper::render('biotime.pagination', ['page' => $this->page, 'pages' => $this->pages, 'url' => fn (int $p): string => $this->pageUrl($p)]); ?>
    <?php endif; ?>
</div>
