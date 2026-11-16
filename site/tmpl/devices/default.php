<?php

/**
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/** @var \BiotimePortal\Component\Biotime\Site\View\Devices\HtmlView $this */

$f       = $this->f;
$num     = static fn (?int $n): string => $n === null ? '—' : number_format($n);
$total   = count($this->list->devices);
$symbols = ['online' => '●', 'delayed' => '◐', 'offline' => '○', 'unknown' => '?'];
?>
<div class="com-biotime biotime-devices">
    <?php if ($this->showPageHeading) : ?>
        <h1 class="h2 mb-3"><?php echo $this->escape($this->pageHeading); ?></h1>
    <?php endif; ?>

    <?php if ($this->list->error !== null) : ?>
        <div class="alert alert-danger" role="alert"><?php echo $this->escape(Text::_('COM_BIOTIME_PART_DEVICES') . ': ' . $this->errorText($this->list->error)); ?></div>
    <?php else : ?>
        <?php if ($this->list->truncated) : ?>
            <div class="alert alert-warning" role="alert"><?php echo Text::_('COM_BIOTIME_TRUNCATED'); ?></div>
        <?php endif; ?>

        <p class="small text-muted mb-2">
            <?php echo $this->escape(Text::sprintf('COM_BIOTIME_DEVICES_HEALTH_RULE', \BiotimePortal\Component\Biotime\Administrator\Devices\DeviceService::ONLINE_MINUTES)); ?>
            <?php if ($this->zone !== null) : ?><?php echo $this->escape(Text::sprintf('COM_BIOTIME_TIMES_IN_ZONE', $this->zone)); ?><?php endif; ?>
            <?php echo $this->escape($this->updatedText($this->list->fetchedAt)); ?>
        </p>

        <div class="row g-3 mb-3" role="list" aria-label="<?php echo $this->escape(Text::_('COM_BIOTIME_DEVICES_SUMMARY')); ?>">
            <div class="col-6 col-md-3" role="listitem">
                <a class="card h-100 biotime-stat text-reset text-decoration-none<?php echo $this->health === '' ? ' border-primary' : ''; ?>" href="<?php echo $this->filterUrl('', $this->area); ?>">
                    <div class="card-body"><div class="small text-muted"><?php echo Text::_('COM_BIOTIME_DEVICES_TOTAL'); ?></div><div class="fs-3 fw-semibold"><?php echo $total; ?></div></div>
                </a>
            </div>
            <?php foreach (['online', 'delayed', 'offline'] as $h) : ?>
                <div class="col-6 col-md-3" role="listitem">
                    <a class="card h-100 biotime-stat biotime-health-card-<?php echo $h; ?> text-reset text-decoration-none<?php echo $this->health === $h ? ' border-primary' : ''; ?>" href="<?php echo $this->filterUrl($h, $this->area); ?>">
                        <div class="card-body">
                            <div class="small text-muted"><span aria-hidden="true"><?php echo $symbols[$h]; ?></span> <?php echo Text::_('COM_BIOTIME_HEALTH_' . strtoupper($h)); ?></div>
                            <div class="fs-3 fw-semibold"><?php echo (int) $this->counts[$h]; ?></div>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if (count($this->areas) > 1) : ?>
            <form class="row g-2 align-items-end mb-3" method="get" action="<?php echo $this->url('view=devices'); ?>">
                <input type="hidden" name="option" value="com_biotime">
                <input type="hidden" name="view" value="devices">
                <?php if ($this->health !== '') : ?><input type="hidden" name="health" value="<?php echo $this->escape($this->health); ?>"><?php endif; ?>
                <div class="col-12 col-sm-6 col-lg-4">
                    <label class="form-label" for="biotime-area"><?php echo Text::_('COM_BIOTIME_COL_LOCATION'); ?></label>
                    <select class="form-select" id="biotime-area" name="area">
                        <option value=""><?php echo Text::_('COM_BIOTIME_DEVICES_ALL_AREAS'); ?></option>
                        <?php foreach ($this->areas as $a) : ?>
                            <option value="<?php echo $this->escape($a); ?>" <?php echo $a === $this->area ? 'selected' : ''; ?>><?php echo $this->escape($a); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-lg-2">
                    <button class="btn btn-secondary biotime-btn-neutral w-100" type="submit"><?php echo Text::_('COM_BIOTIME_SHOW'); ?></button>
                </div>
            </form>
        <?php endif; ?>

        <?php if ($this->rows === []) : ?>
            <div class="alert alert-info"><?php echo Text::_($total === 0 ? 'COM_BIOTIME_DEVICES_NONE' : 'COM_BIOTIME_DEVICES_NONE_MATCH'); ?></div>
        <?php else : ?>
            <div class="table-responsive">
                <table class="table table-striped align-middle biotime-table">
                    <caption class="visually-hidden"><?php echo $this->escape($this->pageHeading); ?></caption>
                    <thead>
                        <tr>
                            <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_DEVICE_NAME'); ?></th>
                            <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_LOCATION'); ?></th>
                            <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_HEALTH'); ?></th>
                            <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_LAST_ACTIVITY'); ?></th>
                            <th scope="col" class="text-end"><?php echo Text::_('COM_BIOTIME_COL_USERS'); ?></th>
                            <th scope="col" class="text-end"><?php echo Text::_('COM_BIOTIME_COL_FINGERPRINTS'); ?></th>
                            <th scope="col" class="text-end"><?php echo Text::_('COM_BIOTIME_COL_FACES'); ?></th>
                            <th scope="col" class="text-end"><?php echo Text::_('COM_BIOTIME_COL_TRANSACTIONS'); ?></th>
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
                                <?php echo $this->escape($d->name); ?>
                                <?php if ($d->model !== null && $d->model !== $d->name) : ?><div class="small text-muted fw-normal"><?php echo $this->escape($d->model); ?></div><?php endif; ?>
                            </th>
                            <td><?php echo $this->escape($f->text($d->area)); ?></td>
                            <td>
                                <span class="biotime-status biotime-health-<?php echo $this->escape($d->health); ?>"><span aria-hidden="true"><?php echo $symbols[$d->health]; ?></span> <?php echo Text::_('COM_BIOTIME_HEALTH_' . strtoupper($d->health)); ?></span>
                                <?php if ($d->stateText !== null) : ?><div class="small text-muted"><?php echo $this->escape(Text::sprintf('COM_BIOTIME_DEVICES_STATE', $d->stateText)); ?></div><?php endif; ?>
                            </td>
                            <td>
                                <?php if ($d->lastActivity !== null) : ?>
                                    <?php echo $this->escape($f->date(substr($d->lastActivity, 0, 10)) . ' ' . $f->time(substr($d->lastActivity, 11))); ?>
                                    <div class="small text-muted"><?php echo $this->escape($this->ago($d->minutesSince)); ?></div>
                                <?php else : ?>—<?php endif; ?>
                            </td>
                            <td class="text-end"><?php echo $num($d->users); ?></td>
                            <td class="text-end"><?php echo $num($d->fingerprints); ?></td>
                            <td class="text-end"><?php echo $num($d->faces); ?></td>
                            <td class="text-end"><?php echo $num($d->transactions); ?></td>
                            <?php if ($this->technical) : ?>
                                <td><?php echo $this->escape($f->text($d->sn)); ?></td>
                                <td><?php echo $this->escape($f->text($d->ip)); ?></td>
                                <td><?php echo $this->escape($f->text($d->firmware)); ?></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <p class="small text-muted mt-2"><?php echo Text::_('COM_BIOTIME_DEVICES_READONLY'); ?></p>
    <?php endif; ?>
</div>
