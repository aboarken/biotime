<?php

/**
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use BiotimePortal\Component\Biotime\Site\View\Ui;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;

/** @var \BiotimePortal\Component\Biotime\Site\View\Punches\HtmlView $this */

$f     = $this->f;
$e     = fn (string $text): string => $this->escape($text);
$num   = static fn (int $n): string => number_format($n);
$t     = $this->selected->total;
$other = $this->yearStats->total['other'] > 0;
$scope = $this->monthOnly > 0 ? $f->monthName($this->monthOnly) . ' ' . $this->yearNumber : (string) $this->yearNumber;

// Summary cards: [label, value, tone]
$cards = [
    [Text::_('COM_BIOTIME_PUNCHES_TOTAL'), $num($t['total']), Ui::countTone($t['total'], 'blue')],
    [Text::_('COM_BIOTIME_VERIFY_FINGERPRINT'), $num($t['fingerprint']), Ui::countTone($t['fingerprint'], 'purple')],
    [Text::_('COM_BIOTIME_VERIFY_FACE'), $num($t['face']), Ui::countTone($t['face'], 'teal')],
    [Text::_('COM_BIOTIME_PUNCHES_DEVICES'), (string) count($this->selected->devices), Ui::countTone(count($this->selected->devices), 'slate')],
    [Text::_('COM_BIOTIME_FILTER_PUNCHES'), (string) $t['days'], Ui::countTone($t['days'], 'green')],
];
?>
<div class="com-biotime biotime-punches">
    <div class="bt-head">
        <?php if ($this->showPageHeading) : ?>
            <h1 class="h2"><?php echo $e($this->pageHeading); ?></h1>
        <?php else : ?>
            <span></span>
        <?php endif; ?>
    </div>

    <?php echo LayoutHelper::render('biotime.toolbar', ['view' => 'punches', 'year' => $this->yearNumber, 'month' => $this->monthOnly, 'allMonths' => true, 'f' => $f]); ?>
    <?php echo $this->notices($this->period, false); ?>

    <div class="bt-stats bt-stats--5">
        <?php foreach ($cards as [$label, $value, $tone]) : ?>
            <div class="bt-stat bt-tone-<?php echo $tone; ?>">
                <span class="bt-stat-label"><?php echo $e($label); ?></span>
                <span class="bt-stat-value"><?php echo $value; ?></span>
                <span class="bt-stat-meta"><?php echo $e($scope); ?></span>
            </div>
        <?php endforeach; ?>
    </div>

    <section class="bt-panel">
        <div class="bt-panel-head">
            <h2 class="bt-panel-title"><?php echo $e(Text::sprintf('COM_BIOTIME_PUNCHES_PER_MONTH', $this->yearNumber)); ?></h2>
        </div>
        <?php if ($this->yearStats->months === []) : ?>
            <p class="bt-panel-body bt-empty"><?php echo Text::_('COM_BIOTIME_PUNCHES_NONE'); ?></p>
        <?php else : ?>
            <div class="bt-table-wrap">
                <table class="bt-table">
                    <caption class="visually-hidden"><?php echo $e(Text::sprintf('COM_BIOTIME_PUNCHES_PER_MONTH', $this->yearNumber)); ?></caption>
                    <thead>
                        <tr>
                            <th scope="col"><?php echo Text::_('COM_BIOTIME_MONTH'); ?></th>
                            <th scope="col" class="bt-num"><?php echo Text::_('COM_BIOTIME_VERIFY_FINGERPRINT'); ?></th>
                            <th scope="col" class="bt-num"><?php echo Text::_('COM_BIOTIME_VERIFY_FACE'); ?></th>
                            <?php if ($other) : ?><th scope="col" class="bt-num"><?php echo Text::_('COM_BIOTIME_VERIFY_OTHER'); ?></th><?php endif; ?>
                            <th scope="col" class="bt-num"><?php echo Text::_('COM_BIOTIME_PUNCHES_TOTAL'); ?></th>
                            <th scope="col" class="bt-num"><?php echo Text::_('COM_BIOTIME_FILTER_PUNCHES'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($this->yearStats->months as $m => $row) : ?>
                        <tr class="<?php echo $m === $this->monthOnly ? 'bt-row-today' : ''; ?>">
                            <th scope="row"><a href="<?php echo $this->monthLink($m); ?>"><?php echo $e($f->monthName($m)); ?></a></th>
                            <td class="bt-num"><?php echo $num($row['fingerprint']); ?></td>
                            <td class="bt-num"><?php echo $num($row['face']); ?></td>
                            <?php if ($other) : ?><td class="bt-num"><?php echo $num($row['other']); ?></td><?php endif; ?>
                            <td class="bt-num"><strong><?php echo $num($row['total']); ?></strong></td>
                            <td class="bt-num"><?php echo $num($row['days']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="bt-row-total">
                            <th scope="row"><?php echo Text::_('COM_BIOTIME_PUNCHES_TOTAL'); ?></th>
                            <td class="bt-num"><?php echo $num($this->yearStats->total['fingerprint']); ?></td>
                            <td class="bt-num"><?php echo $num($this->yearStats->total['face']); ?></td>
                            <?php if ($other) : ?><td class="bt-num"><?php echo $num($this->yearStats->total['other']); ?></td><?php endif; ?>
                            <td class="bt-num"><?php echo $num($this->yearStats->total['total']); ?></td>
                            <td class="bt-num"><?php echo $num($this->yearStats->total['days']); ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="bt-panel">
        <div class="bt-panel-head">
            <h2 class="bt-panel-title"><?php echo $e(Text::sprintf('COM_BIOTIME_PUNCHES_PER_DEVICE', $scope)); ?></h2>
            <span class="bt-panel-count"><?php echo $e(Text::plural('COM_BIOTIME_N_DEVICES', count($this->selected->devices))); ?></span>
        </div>
        <?php if ($this->selected->devices === []) : ?>
            <p class="bt-panel-body bt-empty"><?php echo Text::_('COM_BIOTIME_PUNCHES_NONE'); ?></p>
        <?php else : ?>
            <div class="bt-table-wrap">
                <table class="bt-table">
                    <caption class="visually-hidden"><?php echo $e(Text::sprintf('COM_BIOTIME_PUNCHES_PER_DEVICE', $scope)); ?></caption>
                    <thead>
                        <tr>
                            <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_DEVICE'); ?></th>
                            <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_AREA'); ?></th>
                            <th scope="col" class="bt-num"><?php echo Text::_('COM_BIOTIME_VERIFY_FINGERPRINT'); ?></th>
                            <th scope="col" class="bt-num"><?php echo Text::_('COM_BIOTIME_VERIFY_FACE'); ?></th>
                            <?php if ($other) : ?><th scope="col" class="bt-num"><?php echo Text::_('COM_BIOTIME_VERIFY_OTHER'); ?></th><?php endif; ?>
                            <th scope="col" class="bt-num"><?php echo Text::_('COM_BIOTIME_PUNCHES_TOTAL'); ?></th>
                            <th scope="col"><?php echo Text::_('COM_BIOTIME_PUNCHES_LAST_USED'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($this->selected->devices as $d) : ?>
                        <tr>
                            <th scope="row"><?php echo $e($d['device']); ?></th>
                            <td><?php echo $e($f->text($d['area'])); ?></td>
                            <td class="bt-num"><?php echo $num($d['fingerprint']); ?></td>
                            <td class="bt-num"><?php echo $num($d['face']); ?></td>
                            <?php if ($other) : ?><td class="bt-num"><?php echo $num($d['other']); ?></td><?php endif; ?>
                            <td class="bt-num"><strong><?php echo $num($d['total']); ?></strong></td>
                            <td><?php echo $e($f->date(substr($d['last'], 0, 10)) . ' ' . $f->time(substr($d['last'], 11))); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
