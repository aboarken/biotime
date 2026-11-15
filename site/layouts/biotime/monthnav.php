<?php

/**
 * Previous / next month buttons and a month + year selector (GET).
 *
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var array{view: string, year: int, month: int, f: \BiotimePortal\Component\Biotime\Administrator\Support\Formatter, extra?: array<string, string>} $displayData */
$view  = $displayData['view'];
$year  = (int) $displayData['year'];
$month = (int) $displayData['month'];
$f     = $displayData['f'];
$extra = $displayData['extra'] ?? [];
$e     = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
$first = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
$prev  = $first->modify('-1 month');
$next  = $first->modify('+1 month');
$link  = static fn (DateTimeImmutable $d): string => Route::_('index.php?option=com_biotime&view=' . $view . '&' . http_build_query(['month' => $d->format('Y-m')] + $extra));
$itemId = Factory::getApplication()->getInput()->getInt('Itemid', 0);
?>
<div class="biotime-monthnav d-flex flex-wrap align-items-end gap-2 mb-3">
    <a class="btn btn-outline-secondary" href="<?php echo $link($prev); ?>" aria-label="<?php echo $e(Text::sprintf('COM_BIOTIME_PREV_MONTH_LABEL', $f->monthName((int) $prev->format('n')) . ' ' . $prev->format('Y'))); ?>">‹ <?php echo Text::_('COM_BIOTIME_PREV_MONTH'); ?></a>
    <form class="d-flex flex-wrap align-items-end gap-2" method="get" action="<?php echo Route::_('index.php?option=com_biotime&view=' . $view); ?>">
        <input type="hidden" name="option" value="com_biotime">
        <input type="hidden" name="view" value="<?php echo $e($view); ?>">
        <?php if ($itemId > 0) : ?><input type="hidden" name="Itemid" value="<?php echo $itemId; ?>"><?php endif; ?>
        <?php foreach ($extra as $k => $v) : ?><input type="hidden" name="<?php echo $e($k); ?>" value="<?php echo $e($v); ?>"><?php endforeach; ?>
        <div>
            <label class="form-label" for="biotime-mo-<?php echo $e($view); ?>"><?php echo Text::_('COM_BIOTIME_MONTH'); ?></label>
            <select class="form-select" id="biotime-mo-<?php echo $e($view); ?>" name="mo">
                <?php for ($m = 1; $m <= 12; $m++) : ?>
                    <option value="<?php echo $m; ?>" <?php echo $m === $month ? 'selected' : ''; ?>><?php echo $e($f->monthName($m)); ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div>
            <label class="form-label" for="biotime-y-<?php echo $e($view); ?>"><?php echo Text::_('COM_BIOTIME_YEAR'); ?></label>
            <select class="form-select" id="biotime-y-<?php echo $e($view); ?>" name="y">
                <?php for ($y = (int) date('Y') + 1; $y >= (int) date('Y') - 5; $y--) : ?>
                    <option value="<?php echo $y; ?>" <?php echo $y === $year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <button class="btn btn-secondary biotime-btn-neutral" type="submit"><?php echo Text::_('COM_BIOTIME_SHOW'); ?></button>
    </form>
    <a class="btn btn-outline-secondary" href="<?php echo $link($next); ?>" aria-label="<?php echo $e(Text::sprintf('COM_BIOTIME_NEXT_MONTH_LABEL', $f->monthName((int) $next->format('n')) . ' ' . $next->format('Y'))); ?>"><?php echo Text::_('COM_BIOTIME_NEXT_MONTH'); ?> ›</a>
</div>
