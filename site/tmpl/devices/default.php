<?php

/**
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use BiotimePortal\Component\Biotime\Administrator\Devices\Device;
use BiotimePortal\Component\Biotime\Administrator\Devices\DeviceService;
use BiotimePortal\Component\Biotime\Site\View\Ui;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;

/** @var \BiotimePortal\Component\Biotime\Site\View\Devices\HtmlView $this */

$f     = $this->f;
$e     = fn (string $text): string => $this->escape($text);
$num   = static fn (?int $n): string => $n === null ? '—' : number_format($n);
$total = count($this->list->devices);

$healthSelect = '<select class="bt-input" id="bt-health" name="health"><option value="">' . $e(Text::_('COM_BIOTIME_DEVICES_ALL_HEALTH')) . '</option>';

foreach (Device::HEALTHS as $h) {
    $healthSelect .= '<option value="' . $h . '"' . ($h === $this->health ? ' selected' : '') . '>' . $e(Text::_('COM_BIOTIME_HEALTH_' . strtoupper($h))) . '</option>';
}

$healthSelect .= '</select>';
$areaSelect    = '<select class="bt-input" id="bt-area" name="area"><option value="">' . $e(Text::_('COM_BIOTIME_DEVICES_ALL_AREAS')) . '</option>';

foreach ($this->areas as $a) {
    $areaSelect .= '<option value="' . $e($a) . '"' . ($a === $this->area ? ' selected' : '') . '>' . $e($a) . '</option>';
}

$areaSelect .= '</select>';

// Health cards: [health filter, label, count, tone]
$cards = [
    ['', Text::_('COM_BIOTIME_DEVICES_TOTAL'), $total, 'blue'],
    [Device::ONLINE, Text::_('COM_BIOTIME_HEALTH_ONLINE'), $this->counts[Device::ONLINE], 'green'],
    [Device::DELAYED, Text::_('COM_BIOTIME_HEALTH_DELAYED'), $this->counts[Device::DELAYED], 'amber'],
    [Device::OFFLINE, Text::_('COM_BIOTIME_HEALTH_OFFLINE'), $this->counts[Device::OFFLINE], 'red'],
];

if ($this->counts[Device::UNKNOWN] > 0) {
    $cards[] = [Device::UNKNOWN, Text::_('COM_BIOTIME_HEALTH_UNKNOWN'), $this->counts[Device::UNKNOWN], 'muted'];
}
?>
<div class="com-biotime biotime-devices">
    <?php if ($this->showPageHeading) : ?>
        <div class="bt-head"><h1 class="h2"><?php echo $e($this->pageHeading); ?></h1></div>
    <?php endif; ?>

    <?php if ($this->list->error !== null) : ?>
        <div class="bt-alert bt-tone-red" role="alert"><strong><?php echo Text::_('COM_BIOTIME_PART_DEVICES'); ?>:</strong> <?php echo $e($this->errorText($this->list->error)); ?></div>
    <?php else : ?>
        <?php if ($this->list->truncated) : ?>
            <div class="bt-alert bt-tone-amber" role="alert"><?php echo Text::_('COM_BIOTIME_TRUNCATED'); ?></div>
        <?php endif; ?>

        <div class="bt-stats">
            <?php foreach ($cards as [$h, $label, $count, $tone]) : ?>
                <a class="bt-stat bt-tone-<?php echo $h === '' ? $tone : Ui::countTone($count, $tone); ?><?php echo $h === $this->health ? ' is-active' : ''; ?>" href="<?php echo $this->filterUrl($h, $this->area); ?>"<?php echo $h === $this->health ? ' aria-current="true"' : ''; ?>>
                    <span class="bt-stat-label"><?php echo $e($label); ?></span>
                    <span class="bt-stat-value"><?php echo (int) $count; ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <?php echo LayoutHelper::render('biotime.toolbar', [
            'view'   => 'devices',
            'f'      => $f,
            'fields' => array_values(array_filter([
                ['id' => 'bt-health', 'label' => Text::_('COM_BIOTIME_COL_HEALTH'), 'html' => $healthSelect],
                count($this->areas) > 1 ? ['id' => 'bt-area', 'label' => Text::_('COM_BIOTIME_COL_LOCATION'), 'html' => $areaSelect] : null,
            ])),
        ]); ?>

        <p class="bt-note">
            <?php echo $e(Text::sprintf('COM_BIOTIME_DEVICES_HEALTH_RULE', DeviceService::ONLINE_MINUTES)); ?>
            <?php if ($this->zone !== null) : ?><?php echo $e(Text::sprintf('COM_BIOTIME_TIMES_IN_ZONE', $this->zone)); ?><?php endif; ?>
            <?php echo $e($this->updatedText($this->list->fetchedAt)); ?>
        </p>

        <section class="bt-panel">
            <div class="bt-panel-head">
                <h2 class="bt-panel-title"><?php echo Text::_('COM_BIOTIME_DEVICES_LIST'); ?></h2>
                <span class="bt-panel-count"><?php echo $e(Text::sprintf('COM_BIOTIME_DEVICES_N_OF_M', count($this->rows), $total)); ?></span>
            </div>
            <?php if ($this->rows === []) : ?>
                <p class="bt-panel-body bt-empty"><?php echo Text::_($total === 0 ? 'COM_BIOTIME_DEVICES_NONE' : 'COM_BIOTIME_DEVICES_NONE_MATCH'); ?></p>
            <?php else : ?>
                <div class="bt-table-wrap">
                    <table class="bt-table">
                        <caption class="visually-hidden"><?php echo $e($this->pageHeading); ?></caption>
                        <thead>
                            <tr>
                                <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_DEVICE_NAME'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_LOCATION'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_HEALTH'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_LAST_ACTIVITY'); ?></th>
                                <th scope="col" class="bt-num"><?php echo Text::_('COM_BIOTIME_COL_USERS'); ?></th>
                                <th scope="col" class="bt-num"><?php echo Text::_('COM_BIOTIME_COL_FINGERPRINTS'); ?></th>
                                <th scope="col" class="bt-num"><?php echo Text::_('COM_BIOTIME_COL_FACES'); ?></th>
                                <th scope="col" class="bt-num"><?php echo Text::_('COM_BIOTIME_COL_TRANSACTIONS'); ?></th>
                                <?php if ($this->technical) : ?>
                                    <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_SERIAL'); ?></th>
                                    <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_IP'); ?></th>
                                    <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_FIRMWARE'); ?></th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($this->rows as $d) : ?>
                            <tr>
                                <th scope="row">
                                    <?php echo $e($d->name); ?>
                                    <?php if ($d->model !== null && $d->model !== $d->name) : ?><span class="bt-sub"><?php echo $e($d->model); ?></span><?php endif; ?>
                                </th>
                                <td><?php echo $e($f->text($d->area)); ?></td>
                                <td>
                                    <span class="bt-badge bt-tone-<?php echo Ui::healthTone($d->health); ?>"><span class="bt-dot" aria-hidden="true"></span><?php echo Text::_('COM_BIOTIME_HEALTH_' . strtoupper($d->health)); ?></span>
                                    <?php if ($d->stateText !== null) : ?><span class="bt-sub"><?php echo $e(Text::sprintf('COM_BIOTIME_DEVICES_STATE', $d->stateText)); ?></span><?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($d->lastActivity !== null) : ?>
                                        <?php echo $e($this->ago($d->minutesSince)); ?>
                                        <span class="bt-sub"><?php echo $e($f->date(substr($d->lastActivity, 0, 10)) . ' ' . $f->time(substr($d->lastActivity, 11))); ?></span>
                                    <?php else : ?>—<?php endif; ?>
                                </td>
                                <td class="bt-num"><?php echo $num($d->users); ?></td>
                                <td class="bt-num"><?php echo $num($d->fingerprints); ?></td>
                                <td class="bt-num"><?php echo $num($d->faces); ?></td>
                                <td class="bt-num"><?php echo $num($d->transactions); ?></td>
                                <?php if ($this->technical) : ?>
                                    <td><?php echo $e($f->text($d->sn)); ?></td>
                                    <td><?php echo $e($f->text($d->ip)); ?></td>
                                    <td><?php echo $e($f->text($d->firmware)); ?></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <div class="bt-panel-foot bt-empty"><?php echo Text::_('COM_BIOTIME_DEVICES_READONLY'); ?></div>
        </section>
    <?php endif; ?>
</div>
