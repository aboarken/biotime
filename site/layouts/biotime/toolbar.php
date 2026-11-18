<?php

/**
 * The filter bar of a portal page: month and year, plus the page's own fields (a field
 * ['break' => true] starts a new line). Applied as soon as
 * an option is chosen (media/com_biotime/js/filters.js); without JavaScript a Show button submits it.
 *
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/**
 * Years start at 2025 (FIRST_YEAR). allMonths adds "All months" (value 0) to the month list.
 *
 * @var array{view: string, year?: int, month?: int, allMonths?: bool, f: \BiotimePortal\Component\Biotime\Administrator\Support\Formatter, fields?: list<array{id?: string, label?: string, html?: string, break?: bool}>, hidden?: array<string, string>} $displayData
 */
$view   = $displayData['view'];
$f      = $displayData['f'];
$year   = (int) ($displayData['year'] ?? 0);
$month  = (int) ($displayData['month'] ?? 0);
$e      = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
$itemId = Factory::getApplication()->getInput()->getInt('Itemid', 0);
$now    = (int) date('Y');
$first  = \BiotimePortal\Component\Biotime\Administrator\Attendance\AttendanceService::FIRST_YEAR;
$all    = !empty($displayData['allMonths']);
?>
<form class="bt-toolbar" method="get" data-bt-filters action="<?php echo Route::_('index.php?option=com_biotime&view=' . $view); ?>">
    <input type="hidden" name="option" value="com_biotime">
    <input type="hidden" name="view" value="<?php echo $e($view); ?>">
    <?php if ($itemId > 0) : ?><input type="hidden" name="Itemid" value="<?php echo $itemId; ?>"><?php endif; ?>
    <?php foreach ($displayData['hidden'] ?? [] as $k => $v) : ?><input type="hidden" name="<?php echo $e($k); ?>" value="<?php echo $e($v); ?>"><?php endforeach; ?>
    <?php if (isset($displayData['month'])) : ?>
        <div>
            <label class="bt-label" for="bt-mo-<?php echo $e($view); ?>"><?php echo Text::_('COM_BIOTIME_MONTH'); ?></label>
            <select class="bt-input" id="bt-mo-<?php echo $e($view); ?>" name="mo">
                <?php if ($all) : ?><option value="0" <?php echo $month === 0 ? 'selected' : ''; ?>><?php echo Text::_('COM_BIOTIME_ALL_MONTHS'); ?></option><?php endif; ?>
                <?php for ($m = 1; $m <= 12; $m++) : ?>
                    <option value="<?php echo $m; ?>" <?php echo $m === $month ? 'selected' : ''; ?>><?php echo $e($f->monthName($m)); ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div>
            <label class="bt-label" for="bt-y-<?php echo $e($view); ?>"><?php echo Text::_('COM_BIOTIME_YEAR'); ?></label>
            <select class="bt-input" id="bt-y-<?php echo $e($view); ?>" name="y">
                <?php for ($y = max($now, $year); $y >= min($first, $year > 0 ? $year : $first); $y--) : ?>
                    <option value="<?php echo $y; ?>" <?php echo $y === $year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                <?php endfor; ?>
            </select>
        </div>
    <?php endif; ?>
    <?php foreach ($displayData['fields'] ?? [] as $field) : ?>
        <?php if (!empty($field['break'])) : ?>
            <span class="bt-toolbar-break" aria-hidden="true"></span>
            <?php continue; ?>
        <?php endif; ?>
        <div>
            <label class="bt-label" for="<?php echo $e($field['id']); ?>"><?php echo $e($field['label']); ?></label>
            <?php echo $field['html']; ?>
        </div>
    <?php endforeach; ?>
    <div class="bt-toolbar-submit">
        <button class="bt-btn" type="submit"><?php echo Text::_('COM_BIOTIME_SHOW'); ?></button>
    </div>
</form>
