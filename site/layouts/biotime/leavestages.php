<?php

/**
 * The approval stages of a leave request (1.1.0), in order: name, status, who decided and when,
 * and the remark. The stage the request is at is marked.
 *
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;

/** @var array{stages: list<array<string, mixed>>, f: \BiotimePortal\Component\Biotime\Administrator\Support\Formatter} $displayData */
$f  = $displayData['f'];
$e  = static fn (?string $t): string => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8');
$at = static fn (?string $t): string => $t === null ? '' : $f->date(substr($t, 0, 10)) . ' ' . $f->time(substr($t, 11, 5) . ':00');
?>
<?php if ($displayData['stages'] === []) : ?>
    <span class="bt-check-none">—</span>
<?php else : ?>
    <ol class="bt-stages">
        <?php foreach ($displayData['stages'] as $s) : ?>
            <li class="<?php echo $s['current'] ? 'is-current' : ''; ?>">
                <span class="bt-stage-name"><?php echo $e($s['name']); ?><?php if ($s['current']) : ?> <span class="bt-stage-current">(<?php echo Text::_('COM_BIOTIME_LEAVE_CURRENT_STAGE'); ?>)</span><?php endif; ?></span>
                <?php echo LayoutHelper::render('biotime.leavestatus', ['status' => $s['status']]); ?>
                <?php if ($s['approver'] !== null || $s['time'] !== null) : ?>
                    <span class="bt-sub"><?php echo $e(implode(' · ', array_filter([$s['approver'], $at($s['time'])]))); ?></span>
                <?php endif; ?>
                <?php if ($s['remark'] !== null) : ?>
                    <span class="bt-stage-remark">“<?php echo $e($s['remark']); ?>”</span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
<?php endif; ?>
