<?php

/**
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;

/** @var \BiotimePortal\Component\Biotime\Site\View\Approvals\HtmlView $this */

/*
 * My Approvals (1.1.0): cards, the leave requests waiting at the employee's stage in ZKBioTime
 * (the longest waiting first), then the stages they approved or rejected. Read only: requests are
 * approved in ZKBioTime.
 */

$f    = $this->f;
$e    = fn (?string $text): string => $this->escape((string) $text);
$at   = static fn (?string $t): string => $t === null ? '—' : $f->date(substr($t, 0, 10)) . ' ' . $f->time(substr($t, 11, 5) . ':00');
$days = static fn (?float $d): string => $d === null ? '—' : rtrim(rtrim(number_format($d, 2, '.', ''), '0'), '.');
$who  = fn (array $r): string => $e($r['employee']) . '<span class="bt-sub">' . $e(implode(' · ', array_filter([$r['emp_code'], $r['department']]))) . '</span>';

$cards = [
    [Text::_('COM_BIOTIME_APPROVALS_CARD_WAITING'), (string) count($this->waiting), 'amber'],
    [Text::_('COM_BIOTIME_APPROVALS_CARD_DECIDED'), (string) count($this->decided), 'ink'],
    [Text::_('COM_BIOTIME_APPROVALS_CARD_APPROVED'), (string) $this->decidedCount(1), 'green'],
    [Text::_('COM_BIOTIME_APPROVALS_CARD_REJECTED'), (string) $this->decidedCount(2), 'red'],
];
?>
<div class="com-biotime biotime-approvals" data-bt-root="approvals">
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
        <div class="bt-stats">
            <?php foreach ($cards as [$label, $value, $tone]) : ?>
                <div class="bt-stat bt-tone-<?php echo $tone; ?>"><span class="bt-stat-label"><?php echo $e($label); ?></span><span class="bt-stat-value"><?php echo $e($value); ?></span></div>
            <?php endforeach; ?>
        </div>

        <section class="bt-panel" aria-labelledby="bt-waiting-title">
            <div class="bt-panel-head">
                <h2 class="bt-panel-title" id="bt-waiting-title"><?php echo Text::_('COM_BIOTIME_APPROVALS_WAITING'); ?></h2>
                <span class="bt-panel-count"><?php echo $e(Text::plural('COM_BIOTIME_LEAVES_N', count($this->waiting))); ?></span>
            </div>
            <?php if ($this->waiting === []) : ?>
                <p class="bt-panel-body bt-empty"><?php echo Text::_('COM_BIOTIME_APPROVALS_NONE_WAITING'); ?></p>
            <?php else : ?>
                <div class="bt-table-wrap">
                    <table class="bt-table bt-leaves-table">
                        <caption class="visually-hidden"><?php echo Text::_('COM_BIOTIME_APPROVALS_WAITING'); ?></caption>
                        <thead>
                            <tr>
                                <th scope="col"><?php echo Text::_('COM_BIOTIME_MANAGER_EMPLOYEE'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_BIOTIME_LEAVE_TYPE'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_BIOTIME_DATE_FROM'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_BIOTIME_DATE_TO'); ?></th>
                                <th scope="col" class="bt-num"><?php echo Text::_('COM_BIOTIME_LEAVE_DAYS'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_BIOTIME_LEAVE_STAGES'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_BIOTIME_LEAVE_APPLIED'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_BIOTIME_LEAVE_REASON'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_BIOTIME_LEAVE_ATTACHMENT'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($this->waiting as $r) : ?>
                            <tr>
                                <th scope="row" class="bt-mgr-name"><?php echo $who($r); ?></th>
                                <td><?php echo $e($r['leave'] !== '' ? $r['leave'] : '—'); ?></td>
                                <td class="bt-nowrap"><?php echo $e($at($r['from'])); ?></td>
                                <td class="bt-nowrap"><?php echo $e($at($r['to'])); ?></td>
                                <td class="bt-num"><?php echo $e($days($r['days'])); ?></td>
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

        <section class="bt-panel" aria-labelledby="bt-decided-title">
            <div class="bt-panel-head">
                <h2 class="bt-panel-title" id="bt-decided-title"><?php echo Text::_('COM_BIOTIME_APPROVALS_DECIDED'); ?></h2>
                <span class="bt-panel-count"><?php echo $e(Text::plural('COM_BIOTIME_LEAVES_N', count($this->decided))); ?></span>
            </div>
            <?php if ($this->decided === []) : ?>
                <p class="bt-panel-body bt-empty"><?php echo Text::_('COM_BIOTIME_APPROVALS_NONE_DECIDED'); ?></p>
            <?php else : ?>
                <div class="bt-table-wrap">
                    <table class="bt-table bt-leaves-table">
                        <caption class="visually-hidden"><?php echo Text::_('COM_BIOTIME_APPROVALS_DECIDED'); ?></caption>
                        <thead>
                            <tr>
                                <th scope="col"><?php echo Text::_('COM_BIOTIME_MANAGER_EMPLOYEE'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_BIOTIME_LEAVE_TYPE'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_BIOTIME_DATE_FROM'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_BIOTIME_DATE_TO'); ?></th>
                                <th scope="col" class="bt-num"><?php echo Text::_('COM_BIOTIME_LEAVE_DAYS'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_BIOTIME_APPROVALS_YOUR_STAGE'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_BIOTIME_APPROVALS_YOUR_DECISION'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_BIOTIME_APPROVALS_DECIDED_ON'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_BIOTIME_LEAVE_REMARK'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_BIOTIME_APPROVALS_REQUEST_STATUS'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($this->decided as $r) : ?>
                            <tr>
                                <th scope="row" class="bt-mgr-name"><?php echo $who($r); ?></th>
                                <td><?php echo $e($r['leave'] !== '' ? $r['leave'] : '—'); ?></td>
                                <td class="bt-nowrap"><?php echo $e($at($r['from'])); ?></td>
                                <td class="bt-nowrap"><?php echo $e($at($r['to'])); ?></td>
                                <td class="bt-num"><?php echo $e($days($r['days'])); ?></td>
                                <td><?php echo $e($r['decision']['stage'] !== '' ? $r['decision']['stage'] : '—'); ?></td>
                                <td><?php echo LayoutHelper::render('biotime.leavestatus', ['status' => $r['decision']['status']]); ?></td>
                                <td class="bt-nowrap"><?php echo $e($at($r['decision']['time'])); ?></td>
                                <td><?php echo $e($r['decision']['remark'] ?? '—'); ?></td>
                                <td><?php echo LayoutHelper::render('biotime.leavestatus', ['status' => $r['status']]); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>
