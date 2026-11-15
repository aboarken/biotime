<?php

/**
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/** @var \BiotimePortal\Component\Biotime\Site\View\Unmatched\HtmlView $this */
$key = $this->kind !== '' ? 'COM_BIOTIME_ERROR_' . strtoupper($this->kind) : 'COM_BIOTIME_ERROR_SERVER';
?>
<div class="com-biotime">
    <div class="alert alert-danger" role="alert"><?php echo $this->escape(Text::_($key)); ?></div>
</div>
