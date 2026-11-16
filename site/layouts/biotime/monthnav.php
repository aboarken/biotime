<?php

/**
 * Previous month / current month / next month, for the page heading row.
 *
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var array{view: string, year: int, month: int, f: \BiotimePortal\Component\Biotime\Administrator\Support\Formatter, extra?: array<string, string>} $displayData */
$view  = $displayData['view'];
$f     = $displayData['f'];
$extra = $displayData['extra'] ?? [];
$e     = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
$first = new DateTimeImmutable(sprintf('%04d-%02d-01', (int) $displayData['year'], (int) $displayData['month']));
$prev  = $first->modify('-1 month');
$next  = $first->modify('+1 month');
$name  = static fn (DateTimeImmutable $d): string => $f->monthName((int) $d->format('n')) . ' ' . $d->format('Y');
$link  = static fn (DateTimeImmutable $d): string => Route::_('index.php?option=com_biotime&view=' . $view . '&' . http_build_query(['month' => $d->format('Y-m')] + $extra));
?>
<nav class="bt-monthnav" aria-label="<?php echo $e(Text::_('COM_BIOTIME_MONTH')); ?>">
    <a class="bt-btn bt-btn--ghost bt-btn--sm" href="<?php echo $link($prev); ?>" aria-label="<?php echo $e(Text::sprintf('COM_BIOTIME_PREV_MONTH_LABEL', $name($prev))); ?>">&larr;<span class="visually-hidden"> <?php echo Text::_('COM_BIOTIME_PREV_MONTH'); ?></span></a>
    <p class="bt-monthnav-title" aria-current="date"><?php echo $e($name($first)); ?></p>
    <a class="bt-btn bt-btn--ghost bt-btn--sm" href="<?php echo $link($next); ?>" aria-label="<?php echo $e(Text::sprintf('COM_BIOTIME_NEXT_MONTH_LABEL', $name($next))); ?>"><span class="visually-hidden"><?php echo Text::_('COM_BIOTIME_NEXT_MONTH'); ?> </span>&rarr;</a>
</nav>
