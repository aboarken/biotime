<?php

/**
 * The last Test Database report: names only (server version, encryption, permissions, table and
 * column names). Never any row of ZKBioTime data.
 *
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

/** @var \BiotimePortal\Component\Biotime\Administrator\View\Integrationsettings\HtmlView $this */

$r     = $this->dbReport;
$e     = fn (string $t): string => $this->escape($t);
$yesNo = static fn (?bool $v, string $yes = 'JYES', string $no = 'JNO'): string => $v === null ? Text::_('COM_BIOTIME_DB_UNKNOWN') : Text::_($v ? $yes : $no);
$when  = HTMLHelper::_('date', gmdate('Y-m-d H:i:s', (int) ($r['at'] ?? time())), Text::_('DATE_FORMAT_LC2'));
?>
<section class="card mb-4" id="biotime-db-report">
    <div class="card-body">
        <h2 class="h4 card-title"><?php echo Text::_('COM_BIOTIME_DB_REPORT_TITLE'); ?> <small class="text-muted"><?php echo $e($when); ?></small></h2>

        <?php if (($r['error'] ?? null) !== null) : ?>
            <div class="alert alert-danger">
                <p class="mb-1"><strong><?php echo Text::_('COM_BIOTIME_DB_ERR_' . strtoupper((string) $r['error'])); ?></strong></p>
                <p class="mb-1"><?php echo Text::_('COM_BIOTIME_DB_HINT_' . strtoupper((string) $r['error'])); ?></p>
                <?php if (($r['detail'] ?? '') !== '') : ?>
                    <p class="mb-0 small"><?php echo Text::_('COM_BIOTIME_DB_SERVER_SAID'); ?> <code><?php echo $e((string) $r['detail']); ?></code></p>
                <?php endif; ?>
            </div>
        <?php else :
            $writable = (array) $r['writable'];
            $summary  = [
                'ZKBioTime database report (names only)',
                'Engine: ' . $r['engine'] . ' ' . $r['version'],
                'SSL: ' . ($r['ssl'] === null ? 'unknown' : ($r['ssl'] ? 'yes ' . $r['ssl_detail'] : 'no')) . ' (mode ' . $r['ssl_mode'] . ')',
                'Administrator: ' . var_export($r['superuser'], true) . '; read-only by default: ' . var_export($r['role_read_only'], true)
                    . '; can create: ' . var_export($r['can_create'], true) . '; tables it may change: ' . ($writable === [] ? 'none' : implode(', ', $writable)),
                '',
            ];

            foreach ((array) $r['tables'] as $t) {
                $summary[] = $t['name'] . ($t['readable'] ? ' [read]' : ' [no access]') . ': ' . implode(', ', array_map(static fn (array $c): string => $c[0] . ' ' . $c[1], $t['columns']));
            }

            $summary[] = '';
            $summary[] = 'Other tables: ' . implode(', ', (array) $r['others']);
            ?>
            <table class="table table-sm w-auto">
                <tbody>
                    <tr><th scope="row"><?php echo Text::_('COM_BIOTIME_DB_SERVER'); ?></th><td><?php echo $e($r['engine'] . ' ' . $r['version'] . ' · ' . $r['host'] . ':' . $r['port'] . (($r['via'] ?? '') !== '' ? ' → ' . $r['via'] : '') . ' / ' . $r['database'] . ' · ' . $r['user']); ?></td></tr>
                    <tr><th scope="row"><?php echo Text::_('COM_BIOTIME_DB_ENCRYPTED'); ?></th>
                        <td class="<?php echo $r['ssl'] === false ? 'text-danger' : ''; ?>"><?php echo $e($yesNo($r['ssl']) . ($r['ssl_detail'] !== '' ? ' (' . $r['ssl_detail'] . ')' : '')); ?></td></tr>
                    <tr><th scope="row"><?php echo Text::_('COM_BIOTIME_DB_ADMIN'); ?></th>
                        <td class="<?php echo $r['superuser'] !== false ? 'text-danger' : ''; ?>"><?php echo $e($yesNo($r['superuser'])); ?></td></tr>
                    <tr><th scope="row"><?php echo Text::_('COM_BIOTIME_DB_READ_ONLY_ROLE'); ?></th><td><?php echo $e($yesNo($r['role_read_only'])); ?></td></tr>
                    <tr><th scope="row"><?php echo Text::_('COM_BIOTIME_DB_WRITABLE'); ?></th>
                        <td class="<?php echo $writable !== [] ? 'text-danger' : ''; ?>"><?php echo $e($writable === [] ? Text::_('COM_BIOTIME_DB_NONE') : implode(', ', $writable)); ?></td></tr>
                </tbody>
            </table>

            <?php if (!$r['safe']) : ?>
                <div class="alert alert-danger"><?php echo Text::_('COM_BIOTIME_DB_NOT_SAFE'); ?></div>
            <?php elseif ($r['ssl'] === false) : ?>
                <div class="alert alert-warning"><?php echo Text::_('COM_BIOTIME_DB_NOT_ENCRYPTED'); ?></div>
            <?php else : ?>
                <div class="alert alert-success"><?php echo Text::_('COM_BIOTIME_DB_SAFE'); ?></div>
            <?php endif; ?>

            <h3 class="h5"><?php echo Text::_('COM_BIOTIME_DB_EXPECTED'); ?></h3>
            <ul>
                <?php foreach ((array) $r['expected'] as $x) : ?>
                    <li><code><?php echo $e($x['name']); ?></code> (<?php echo Text::_('COM_BIOTIME_DB_USE_' . strtoupper($x['use'])); ?>):
                        <strong class="<?php echo $x['readable'] ? 'text-success' : 'text-danger'; ?>"><?php echo Text::_(!$x['found'] ? 'COM_BIOTIME_DB_MISSING' : ($x['readable'] ? 'COM_BIOTIME_DB_READABLE' : 'COM_BIOTIME_DB_NOT_GRANTED')); ?></strong></li>
                <?php endforeach; ?>
            </ul>
            <?php if ($r['hidden_names']) : ?>
                <p class="small"><?php echo Text::_('COM_BIOTIME_DB_HIDDEN_NAMES'); ?></p>
            <?php endif; ?>

            <details class="mb-3">
                <summary><?php echo Text::sprintf('COM_BIOTIME_DB_TABLES', count((array) $r['tables'])); ?></summary>
                <ul class="small">
                    <?php foreach ((array) $r['tables'] as $t) : ?>
                        <li><code><?php echo $e($t['name']); ?></code> <?php echo $t['readable'] ? '✓' : '—'; ?>: <?php echo $e(implode(', ', array_map(static fn (array $c): string => $c[0], $t['columns']))); ?></li>
                    <?php endforeach; ?>
                </ul>
            </details>

            <label class="form-label" for="biotime-db-summary"><?php echo Text::_('COM_BIOTIME_DB_COPY'); ?></label>
            <textarea class="form-control font-monospace small" id="biotime-db-summary" rows="8" readonly><?php echo $e(implode("\n", $summary)); ?></textarea>
        <?php endif; ?>
    </div>
</section>
