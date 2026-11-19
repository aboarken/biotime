<?php

/**
 * A ZKBioTime approval status (leave request or stage, 1.1.0) as a badge: colour and text.
 *
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use BiotimePortal\Component\Biotime\Site\View\Ui;
use Joomla\CMS\Language\Text;

/** @var array{status: int} $displayData */
$status        = (int) $displayData['status'];
[$key, $tone]  = Ui::leaveStatus($status);
$label         = $key === 'COM_BIOTIME_LEAVE_STATUS_OTHER' ? Text::sprintf($key, $status) : Text::_($key);
?>
<span class="bt-badge bt-tone-<?php echo $tone; ?>"><span class="bt-dot" aria-hidden="true"></span><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></span>
