<?php

/**
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/** @var \BiotimePortal\Component\Biotime\Site\View\Profile\HtmlView $this */

$labels = [
    'emp_code'   => 'COM_BIOTIME_PROFILE_EMP_CODE',
    'first_name' => 'COM_BIOTIME_PROFILE_FIRST_NAME',
    'last_name'  => 'COM_BIOTIME_PROFILE_LAST_NAME',
    'department' => 'COM_BIOTIME_PROFILE_DEPARTMENT',
    'position'   => 'COM_BIOTIME_PROFILE_POSITION',
    'area'       => 'COM_BIOTIME_PROFILE_AREA',
    'email'      => 'COM_BIOTIME_PROFILE_EMAIL',
    'hire_date'  => 'COM_BIOTIME_PROFILE_HIRE_DATE',
];
?>
<div class="com-biotime biotime-profile">
    <?php if ($this->showPageHeading) : ?>
        <h1 class="h2 mb-3"><?php echo $this->escape($this->pageHeading); ?></h1>
    <?php endif; ?>

    <?php if ($this->error !== '') : ?>
        <div class="alert alert-danger" role="alert"><?php echo $this->escape($this->error); ?></div>
    <?php else : ?>
        <dl class="row biotime-facts">
            <?php foreach ($labels as $field => $key) :
                $value = $this->profile[$field] ?? null;
                if ($field === 'hire_date' && $value !== null && preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
                    $value = $this->f->date(substr($value, 0, 10));
                }
                ?>
                <dt class="col-sm-4 col-lg-3"><?php echo Text::_($key); ?></dt>
                <dd class="col-sm-8 col-lg-9"><?php echo $this->escape($this->f->text($value)); ?></dd>
            <?php endforeach; ?>
        </dl>
        <p class="small text-muted"><?php echo Text::_('COM_BIOTIME_PROFILE_READONLY'); ?></p>
    <?php endif; ?>
</div>
