<?php

/**
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

?>
<div class="com-biotime" data-bt-root="unmatched">
    <div class="bt-alert bt-tone-amber" role="alert"><?php echo Text::_($this->pending ? 'COM_BIOTIME_UNMATCHED_PENDING' : 'COM_BIOTIME_UNMATCHED'); ?></div>
</div>
