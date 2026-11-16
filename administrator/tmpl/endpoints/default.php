<?php

/**
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use BiotimePortal\Component\Biotime\Administrator\Attendance\SourceConfig;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var \BiotimePortal\Component\Biotime\Administrator\View\Endpoints\HtmlView $this */

$action = Route::_('index.php?option=com_biotime');
$token  = HTMLHelper::_('form.token');
$check  = $this->check;
$e      = fn (string $t): string => $this->escape($t);
$types  = [
    'date' => 'COM_BIOTIME_ENDPOINTS_TYPE_DATE', 'datetime' => 'COM_BIOTIME_ENDPOINTS_TYPE_DATETIME', 'time' => 'COM_BIOTIME_ENDPOINTS_TYPE_TIME',
    'number' => 'COM_BIOTIME_ENDPOINTS_TYPE_NUMBER', 'bool' => 'COM_BIOTIME_ENDPOINTS_TYPE_BOOL', 'text' => 'COM_BIOTIME_ENDPOINTS_TYPE_TEXT', 'empty' => 'COM_BIOTIME_ENDPOINTS_TYPE_EMPTY',
];
$portalFields = static fn (string $kind): array => $kind === 'leave' ? SourceConfig::LEAVE_FIELDS : SourceConfig::DAILY_FIELDS;
?>
<div class="biotime-endpoints">
    <p><?php echo Text::_('COM_BIOTIME_ENDPOINTS_INTRO'); ?></p>

    <h2 class="h4"><?php echo Text::_('COM_BIOTIME_ENDPOINTS_STEP1'); ?></h2>
    <form method="post" action="<?php echo $action; ?>" class="mb-3">
        <input type="hidden" name="task" value="endpoints.catalog">
        <?php echo $token; ?>
        <button class="btn btn-primary" type="submit"><span class="icon-search" aria-hidden="true"></span> <?php echo Text::_('COM_BIOTIME_ENDPOINTS_READ_LIST'); ?></button>
    </form>

    <?php if ($this->catalog !== null) : ?>
        <?php if ($this->catalog['error'] !== null) : ?>
            <div class="alert alert-warning"><?php echo Text::_('COM_BIOTIME_ENDPOINTS_NO_DOCS'); ?></div>
        <?php else : ?>
            <p class="small text-muted"><?php echo Text::sprintf('COM_BIOTIME_ENDPOINTS_SOURCES', $e(implode(', ', $this->catalog['sources'])), count($this->catalog['endpoints'])); ?></p>
            <form method="get" action="<?php echo Route::_('index.php'); ?>" class="row g-2 align-items-end mb-2">
                <input type="hidden" name="option" value="com_biotime">
                <input type="hidden" name="view" value="endpoints">
                <div class="col-auto">
                    <label class="form-label" for="biotime-filter"><?php echo Text::_('JSEARCH_FILTER'); ?></label>
                    <input class="form-control" id="biotime-filter" name="filter" value="<?php echo $e($this->filter); ?>" placeholder="att, report, leave">
                </div>
                <div class="col-auto form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="biotime-all" name="all" value="1" <?php echo $this->showAll ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="biotime-all"><?php echo Text::_('COM_BIOTIME_ENDPOINTS_SHOW_ALL'); ?></label>
                </div>
                <div class="col-auto"><button class="btn btn-secondary" type="submit"><?php echo Text::_('JSEARCH_FILTER_SUBMIT'); ?></button></div>
            </form>
            <div class="table-responsive mb-4">
                <table class="table table-striped table-sm align-middle">
                    <caption class="visually-hidden"><?php echo Text::_('COM_BIOTIME_ENDPOINTS_STEP1'); ?></caption>
                    <thead><tr>
                        <th scope="col"><?php echo Text::_('COM_BIOTIME_ENDPOINTS_PATH'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_BIOTIME_ENDPOINTS_SUMMARY'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_BIOTIME_ENDPOINTS_PARAMS'); ?></th>
                        <th scope="col"><span class="visually-hidden"><?php echo Text::_('COM_BIOTIME_ENDPOINTS_CHECK'); ?></span></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($this->endpoints() as $ep) :
                        $p = $this->paramsFor($ep);
                        ?>
                        <tr>
                            <th scope="row"><code><?php echo $e($ep['path']); ?></code><?php if ($ep['relevant']) : ?> <span class="badge bg-info text-dark"><?php echo Text::_('COM_BIOTIME_ENDPOINTS_LIKELY'); ?></span><?php endif; ?></th>
                            <td class="small"><?php echo $e($ep['summary']); ?></td>
                            <td class="small"><?php echo $e(implode(', ', $ep['params'])); ?></td>
                            <td class="text-nowrap">
                                <a class="btn btn-sm btn-outline-primary" href="<?php echo Route::_('index.php?option=com_biotime&view=endpoints&' . http_build_query(['use' => $ep['path'], 'pe' => $p['emp'], 'pf' => $p['from'], 'pt' => $p['to']])); ?>#biotime-check"><?php echo Text::_('COM_BIOTIME_ENDPOINTS_SELECT'); ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($this->endpoints() === []) : ?><p class="text-muted"><?php echo Text::_('COM_BIOTIME_ENDPOINTS_NONE_SHOWN'); ?></p><?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php
    // Step 2: the check form, pre-filled from "Select" (GET) or from the last check.
    $in   = \Joomla\CMS\Factory::getApplication()->getInput();
    $form = [
        'kind'        => $check['kind'] ?? 'daily',
        'path'        => $in->getString('use', '') ?: ($check['path'] ?? ''),
        'param_emp'   => $in->getCmd('pe', '') ?: ($check['param_emp'] ?? 'emp_code'),
        'param_from'  => $in->getCmd('pf', '') ?: ($check['param_from'] ?? 'start_date'),
        'param_to'    => $in->getCmd('pt', '') ?: ($check['param_to'] ?? 'end_date'),
        'emp_code'    => $check['emp_code'] ?? '',
        'from'        => $check['from'] ?? date('Y-m-01'),
        'to'          => $check['to'] ?? date('Y-m-t'),
        'date_format' => $check['date_format'] ?? 'Y-m-d',
    ];
    ?>
    <h2 class="h4" id="biotime-check"><?php echo Text::_('COM_BIOTIME_ENDPOINTS_STEP2'); ?></h2>
    <form method="post" action="<?php echo $action; ?>" class="row g-2 align-items-end mb-3">
        <input type="hidden" name="task" value="endpoints.check">
        <?php echo $token; ?>
        <div class="col-12 col-lg-4">
            <label class="form-label" for="biotime-path"><?php echo Text::_('COM_BIOTIME_ENDPOINTS_PATH'); ?></label>
            <input class="form-control" id="biotime-path" name="path" required pattern="(/[A-Za-z0-9_\-]+)+/" value="<?php echo $e($form['path']); ?>" placeholder="/…/api/…/" list="biotime-paths">
            <datalist id="biotime-paths"><?php foreach (($this->catalog['endpoints'] ?? []) as $ep) : ?><option value="<?php echo $e($ep['path']); ?>"></option><?php endforeach; ?></datalist>
        </div>
        <div class="col-6 col-lg-2">
            <label class="form-label" for="biotime-kind"><?php echo Text::_('COM_BIOTIME_ENDPOINTS_KIND'); ?></label>
            <select class="form-select" id="biotime-kind" name="kind">
                <option value="daily" <?php echo $form['kind'] === 'daily' ? 'selected' : ''; ?>><?php echo Text::_('COM_BIOTIME_SETTINGS_FIELDSET_DAILY'); ?></option>
                <option value="leave" <?php echo $form['kind'] === 'leave' ? 'selected' : ''; ?>><?php echo Text::_('COM_BIOTIME_SETTINGS_FIELDSET_LEAVE'); ?></option>
            </select>
        </div>
        <div class="col-6 col-lg-2">
            <label class="form-label" for="biotime-emp"><?php echo Text::_('COM_BIOTIME_ENDPOINTS_EMP_CODE'); ?></label>
            <input class="form-control" id="biotime-emp" name="emp_code" maxlength="30" value="<?php echo $e($form['emp_code']); ?>">
        </div>
        <div class="col-6 col-lg-2">
            <label class="form-label" for="biotime-from"><?php echo Text::_('COM_BIOTIME_REPORT_FROM'); ?></label>
            <input class="form-control" type="date" id="biotime-from" name="from" value="<?php echo $e($form['from']); ?>">
        </div>
        <div class="col-6 col-lg-2">
            <label class="form-label" for="biotime-to"><?php echo Text::_('COM_BIOTIME_REPORT_TO'); ?></label>
            <input class="form-control" type="date" id="biotime-to" name="to" value="<?php echo $e($form['to']); ?>">
        </div>
        <div class="col-6 col-lg-3">
            <label class="form-label" for="biotime-pe"><?php echo Text::_('COM_BIOTIME_SETTINGS_SOURCE_PARAM_EMP'); ?></label>
            <input class="form-control" id="biotime-pe" name="param_emp" value="<?php echo $e($form['param_emp']); ?>">
        </div>
        <div class="col-6 col-lg-3">
            <label class="form-label" for="biotime-pf"><?php echo Text::_('COM_BIOTIME_SETTINGS_SOURCE_PARAM_FROM'); ?></label>
            <input class="form-control" id="biotime-pf" name="param_from" value="<?php echo $e($form['param_from']); ?>">
        </div>
        <div class="col-6 col-lg-3">
            <label class="form-label" for="biotime-pt"><?php echo Text::_('COM_BIOTIME_SETTINGS_SOURCE_PARAM_TO'); ?></label>
            <input class="form-control" id="biotime-pt" name="param_to" value="<?php echo $e($form['param_to']); ?>">
        </div>
        <div class="col-6 col-lg-2">
            <label class="form-label" for="biotime-df"><?php echo Text::_('COM_BIOTIME_SETTINGS_SOURCE_DATE_FORMAT'); ?></label>
            <select class="form-select" id="biotime-df" name="date_format">
                <option value="Y-m-d" <?php echo $form['date_format'] === 'Y-m-d' ? 'selected' : ''; ?>>YYYY-MM-DD</option>
                <option value="Y-m-d H:i:s" <?php echo $form['date_format'] === 'Y-m-d H:i:s' ? 'selected' : ''; ?>>YYYY-MM-DD HH:MM:SS</option>
            </select>
        </div>
        <div class="col-12 col-lg-1"><button class="btn btn-primary w-100" type="submit"><?php echo Text::_('COM_BIOTIME_ENDPOINTS_CHECK'); ?></button></div>
        <p class="col-12 small text-muted mb-0"><?php echo Text::_('COM_BIOTIME_ENDPOINTS_CHECK_HELP'); ?></p>
    </form>

    <?php if ($check !== null && $check['error'] === null) :
        $kind     = $check['kind'];
        $suggest  = $check['suggest'];
        $lines    = [];
        foreach ($portalFields($kind) as $pf) {
            $lines[] = $pf . ' = ' . ($suggest[$pf] ?? '');
        }
        $fieldsText = implode("\n", array_filter($lines, static fn (string $l): bool => !str_ends_with($l, '= ')));
        $statusText = implode("\n", array_map(static fn ($k, $v) => $k . ' = ' . $v, array_keys($check['statuses']), $check['statuses']));
        ?>
        <h3 class="h5"><?php echo Text::sprintf('COM_BIOTIME_ENDPOINTS_RESULT', $e($check['path']), (int) $check['rows'], $check['count'] === null ? '—' : (int) $check['count']); ?></h3>
        <?php if ($check['fields'] === []) : ?>
            <div class="alert alert-info"><?php echo Text::_('COM_BIOTIME_ENDPOINTS_NO_ROWS'); ?></div>
        <?php else : ?>
            <div class="table-responsive mb-3">
                <table class="table table-striped table-sm">
                    <caption class="visually-hidden"><?php echo Text::_('COM_BIOTIME_ENDPOINTS_FIELDS'); ?></caption>
                    <thead><tr>
                        <th scope="col"><?php echo Text::_('COM_BIOTIME_ENDPOINTS_FIELD'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_BIOTIME_ENDPOINTS_KIND_OF_VALUE'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_BIOTIME_ENDPOINTS_SUGGESTED_FOR'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_BIOTIME_ENDPOINTS_VALUES'); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($check['fields'] as $path => $info) :
                        $for = array_search($path, $suggest, true);
                        ?>
                        <tr>
                            <th scope="row"><code><?php echo $e($path); ?></code></th>
                            <td><?php echo Text::_($types[$info['type']] ?? 'COM_BIOTIME_ENDPOINTS_TYPE_TEXT'); ?></td>
                            <td><?php echo $for !== false ? '<code>' . $e((string) $for) . '</code>' : ''; ?></td>
                            <td class="small"><?php echo $e(implode(' · ', $info['values'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="small text-muted"><?php echo Text::_('COM_BIOTIME_ENDPOINTS_PRIVACY'); ?></p>
        <?php endif; ?>

        <h3 class="h5"><?php echo Text::_('COM_BIOTIME_ENDPOINTS_STEP3'); ?></h3>
        <?php if (\BiotimePortal\Component\Biotime\Administrator\Api\ZkbClient::isBuiltInPath((string) $check['path'])) : ?>
            <div class="alert alert-info"><?php echo Text::_('COM_BIOTIME_ENDPOINTS_BUILTIN_NOTE'); ?></div>
        <?php else : ?>
        <form method="post" action="<?php echo $action; ?>">
            <input type="hidden" name="task" value="endpoints.save">
            <input type="hidden" name="kind" value="<?php echo $e($kind); ?>">
            <?php echo $token; ?>
            <?php foreach (['path', 'param_emp', 'param_from', 'param_to', 'date_format'] as $h) : ?>
                <input type="hidden" name="<?php echo $h; ?>" value="<?php echo $e((string) $check[$h]); ?>">
            <?php endforeach; ?>
            <div class="row g-3">
                <div class="col-12 col-lg-6">
                    <label class="form-label" for="biotime-fields"><?php echo Text::_('COM_BIOTIME_SETTINGS_SOURCE_FIELDS'); ?></label>
                    <textarea class="form-control font-monospace" id="biotime-fields" name="fields" rows="12"><?php echo $e($fieldsText); ?></textarea>
                    <div class="form-text"><?php echo Text::_($kind === 'leave' ? 'COM_BIOTIME_SETTINGS_LEAVE_FIELDS_DESC' : 'COM_BIOTIME_SETTINGS_DAILY_FIELDS_DESC'); ?></div>
                </div>
                <div class="col-12 col-lg-6">
                    <label class="form-label" for="biotime-status"><?php echo Text::_('COM_BIOTIME_SETTINGS_SOURCE_STATUS_MAP'); ?></label>
                    <textarea class="form-control font-monospace" id="biotime-status" name="status_map" rows="8"><?php echo $e($statusText); ?></textarea>
                    <div class="form-text"><?php echo Text::_($kind === 'leave' ? 'COM_BIOTIME_SETTINGS_LEAVE_STATUS_MAP_DESC' : 'COM_BIOTIME_SETTINGS_DAILY_STATUS_MAP_DESC'); ?></div>
                    <label class="form-label mt-2" for="biotime-unit"><?php echo Text::_('COM_BIOTIME_SETTINGS_SOURCE_UNIT'); ?></label>
                    <select class="form-select" id="biotime-unit" name="duration_unit">
                        <option value="minutes"><?php echo Text::_('COM_BIOTIME_SETTINGS_UNIT_MINUTES'); ?></option>
                        <option value="hours" <?php echo $kind === 'leave' ? 'selected' : ''; ?>><?php echo Text::_('COM_BIOTIME_SETTINGS_UNIT_HOURS'); ?></option>
                        <option value="seconds"><?php echo Text::_('COM_BIOTIME_SETTINGS_UNIT_SECONDS'); ?></option>
                    </select>
                    <label class="form-label mt-2" for="biotime-extra"><?php echo Text::_('COM_BIOTIME_SETTINGS_SOURCE_EXTRA'); ?></label>
                    <textarea class="form-control font-monospace" id="biotime-extra" name="extra" rows="2" placeholder="name = value"></textarea>
                </div>
            </div>
            <p class="small text-muted mt-2"><?php echo Text::_('COM_BIOTIME_ENDPOINTS_REVIEW'); ?></p>
            <button class="btn btn-success" type="submit"><?php echo Text::_($kind === 'leave' ? 'COM_BIOTIME_ENDPOINTS_USE_LEAVE' : 'COM_BIOTIME_ENDPOINTS_USE_DAILY'); ?></button>
        </form>
        <?php endif; ?>
    <?php endif; ?>
</div>
