<?php

/**
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use BiotimePortal\Component\Biotime\Administrator\Database\LeaveReader;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;

/** @var \BiotimePortal\Component\Biotime\Site\View\Leaves\HtmlView $this */

/*
 * My Leaves (1.1.0): cards (requests, pending, approved, rejected, approved days), then one row
 * per leave request from ZKBioTime with its approval stages. Read only.
 */

$f    = $this->f;
$e    = fn (?string $text): string => $this->escape((string) $text);
$at   = static fn (?string $t): string => $t === null ? '—' : $f->date(substr($t, 0, 10)) . ' ' . $f->time(substr($t, 11, 5) . ':00');
$days = static fn (?float $d): string => $d === null ? '—' : rtrim(rtrim(number_format($d, 2, '.', ''), '0'), '.');

$cards = [
    [Text::_('COM_BIOTIME_LEAVES_CARD_REQUESTS'), (string) count($this->requests), 'ink'],
    [Text::_('COM_BIOTIME_LEAVE_STATUS_PENDING'), (string) $this->count(LeaveReader::PENDING), 'amber'],
    [Text::_('COM_BIOTIME_LEAVE_STATUS_APPROVED'), (string) $this->count(LeaveReader::APPROVED), 'green'],
    [Text::_('COM_BIOTIME_LEAVE_STATUS_REJECTED'), (string) $this->count(LeaveReader::REJECTED), 'red'],
    [Text::_('COM_BIOTIME_LEAVES_CARD_DAYS'), $days($this->approvedDays()), 'teal'],
];
?>
<div class="com-biotime biotime-leaves" data-bt-root="leaves">
    <div class="bt-head">
        <?php if ($this->showPageHeading) : ?>
            <h1 class="h2"><?php echo $e($this->pageHeading); ?></h1>
        <?php else : ?>
            <span></span>
        <?php endif; ?>
    </div>

    <?php if ($this->problem !== '') : ?>
        <div class="bt-alert bt-tone-<?php echo $this->problem === 'notgranted' ? 'amber' : 'red'; ?>" role="alert">
            <?php echo $e(Text::_($this->problem === 'notgranted' ? 'COM_BIOTIME_LEAVES_NOT_GRANTED' : 'COM_BIOTIME_ERROR_' . strtoupper($this->problem))); ?>
        </div>
    <?php else : ?>
        <div class="bt-stats bt-stats--5">
            <?php foreach ($cards as [$label, $value, $tone]) : ?>
                <div class="bt-stat bt-tone-<?php echo $tone; ?>"><span class="bt-stat-label"><?php echo $e($label); ?></span><span class="bt-stat-value"><?php echo $e($value); ?></span></div>
            <?php endforeach; ?>
        </div>

        <section class="bt-panel" aria-labelledby="bt-leaves-title">
            <div class="bt-panel-head">
                <h2 class="bt-panel-title" id="bt-leaves-title"><?php echo Text::_('COM_BIOTIME_LEAVES_LIST'); ?></h2>
                <span class="bt-panel-count"><?php echo $e(Text::plural('COM_BIOTIME_LEAVES_N', count($this->requests))); ?></span>
            </div>
            <?php if ($this->requests === []) : ?>
                <p class="bt-panel-body bt-empty"><?php echo Text::_('COM_BIOTIME_LEAVES_NONE'); ?></p>
            <?php else : ?>
                <div class="bt-table-wrap">
                    <table class="bt-table bt-leaves-table">
                        <caption class="visually-hidden"><?php echo Text::_('COM_BIOTIME_LEAVES_LIST'); ?></caption>
                        <thead>
                            <tr>
                                <th scope="col"><?php echo Text::_('COM_BIOTIME_LEAVE_TYPE'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_BIOTIME_DATE_FROM'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_BIOTIME_DATE_TO'); ?></th>
                                <th scope="col" class="bt-num"><?php echo Text::_('COM_BIOTIME_LEAVE_DAYS'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_STATUS'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_BIOTIME_LEAVE_STAGES'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_BIOTIME_LEAVE_APPLIED'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_BIOTIME_LEAVE_REASON'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_BIOTIME_LEAVE_ATTACHMENT'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($this->requests as $r) : ?>
                            <tr>
                                <th scope="row"><?php echo $e($r['leave'] !== '' ? $r['leave'] : '—'); ?></th>
                                <td class="bt-nowrap"><?php echo $e($at($r['from'])); ?></td>
                                <td class="bt-nowrap"><?php echo $e($at($r['to'])); ?></td>
                                <td class="bt-num"><?php echo $e($days($r['days'])); ?></td>
                                <td><?php echo LayoutHelper::render('biotime.leavestatus', ['status' => $r['status']]); ?></td>
                                <td><?php echo LayoutHelper::render('biotime.leavestages', ['stages' => $r['stages'], 'f' => $f]); ?></td>
                                <td class="bt-nowrap"><?php echo $e($at($r['applied'])); ?></td>
                                <td><?php echo $e($r['reason'] ?? '—'); ?></td>
                                <td><?php echo $r['attachment'] !== null ? '<span class="bt-attachment">' . $e($r['attachment']) . '</span>' : '<span class="bt-check-none">—</span>'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>
