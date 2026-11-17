<?php

/**
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use BiotimePortal\Component\Biotime\Administrator\Security\SecretProvider;

/** @var \BiotimePortal\Component\Biotime\Administrator\View\Integrationsettings\HtmlView $this */

$this->getDocument()->getWebAssetManager()
    ->useScript('keepalive')
    ->useScript('form.validate');

$secretLabel = match ($this->secretSource) {
    SecretProvider::SOURCE_ENV    => 'COM_BIOTIME_SETTINGS_SECRET_ENV',
    SecretProvider::SOURCE_JOOMLA => 'COM_BIOTIME_SETTINGS_SECRET_JOOMLA',
    default                       => 'COM_BIOTIME_SETTINGS_SECRET_NONE',
};
$stored = static fn (bool $set): string => Text::_($set ? 'COM_BIOTIME_SETTINGS_STORED' : 'COM_BIOTIME_SETTINGS_NOT_SET');
?>
<form action="<?php echo Route::_('index.php?option=com_biotime&view=integrationsettings'); ?>"
      method="post" name="adminForm" id="adminForm" class="form-validate" autocomplete="off">

    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h5"><?php echo Text::_('COM_BIOTIME_SETTINGS_STATUS'); ?></h2>
            <ul class="list-unstyled mb-0">
                <li><?php echo Text::_('COM_BIOTIME_SETTINGS_PASSWORD_STATUS'); ?>: <strong><?php echo $stored($this->hasPassword); ?></strong></li>
                <li><?php echo Text::_('COM_BIOTIME_SETTINGS_TOKEN_STATUS'); ?>: <strong><?php echo $stored($this->hasToken); ?></strong></li>
                <li><?php echo Text::_('COM_BIOTIME_SETTINGS_DB_PASSWORD_STATUS'); ?>: <strong><?php echo $stored($this->hasDbPassword); ?></strong></li>
                <li><?php echo Text::_('COM_BIOTIME_SETTINGS_SECRET_SOURCE'); ?>: <strong><?php echo Text::_($secretLabel); ?></strong></li>
            </ul>
        </div>
    </div>

    <?php if ($this->isHttp) : ?>
        <div class="alert alert-warning"><?php echo Text::_('COM_BIOTIME_SETTINGS_WARN_HTTP'); ?></div>
    <?php endif; ?>

    <?php if ($this->insecureTls) : ?>
        <div class="alert alert-danger"><?php echo Text::_('COM_BIOTIME_SETTINGS_WARN_NO_VERIFY'); ?></div>
    <?php elseif ($this->isPinned) : ?>
        <div class="alert alert-success"><?php echo Text::_('COM_BIOTIME_SETTINGS_PINNED'); ?></div>
    <?php endif; ?>

    <?php if ($this->secretEnvTooShort) : ?>
        <div class="alert alert-warning"><?php echo Text::sprintf('COM_BIOTIME_SETTINGS_WARN_ENV_SHORT', SecretProvider::ENV_NAME, SecretProvider::MIN_ENV_LENGTH); ?></div>
    <?php elseif ($this->secretSource !== SecretProvider::SOURCE_ENV) : ?>
        <div class="alert alert-info"><?php echo Text::sprintf('COM_BIOTIME_SETTINGS_HINT_ENV_SECRET', SecretProvider::ENV_NAME, SecretProvider::MIN_ENV_LENGTH); ?></div>
    <?php endif; ?>

    <fieldset class="options-form">
        <legend><?php echo Text::_('COM_BIOTIME_SETTINGS_FIELDSET_CONNECTION'); ?></legend>
        <?php echo $this->form->renderFieldset('connection'); ?>
    </fieldset>

    <fieldset class="options-form">
        <legend><?php echo Text::_('COM_BIOTIME_SETTINGS_FIELDSET_CREDENTIALS'); ?></legend>
        <?php echo $this->form->renderFieldset('credentials'); ?>
    </fieldset>

    <fieldset class="options-form">
        <legend><?php echo Text::_('COM_BIOTIME_SETTINGS_FIELDSET_TLS'); ?></legend>
        <?php echo $this->form->renderFieldset('tls'); ?>
    </fieldset>

    <?php foreach (['mapping', 'modules', 'daily'] as $set) : ?>
        <fieldset class="options-form">
            <legend><?php echo Text::_('COM_BIOTIME_SETTINGS_FIELDSET_' . strtoupper($set)); ?></legend>
            <?php if ($set === 'daily') : ?>
                <p class="small"><?php echo Text::_('COM_BIOTIME_SETTINGS_FIELDSET_' . strtoupper($set) . '_HELP'); ?></p>
                <p><a class="btn btn-sm btn-outline-primary" href="<?php echo Route::_('index.php?option=com_biotime&view=endpoints'); ?>"><span class="icon-search" aria-hidden="true"></span> <?php echo Text::_('COM_BIOTIME_ENDPOINTS_BUTTON'); ?></a></p>
            <?php endif; ?>
            <?php echo $this->form->renderFieldset($set); ?>
        </fieldset>
    <?php endforeach; ?>

    <fieldset class="options-form" id="biotime-database">
        <legend><?php echo Text::_('COM_BIOTIME_SETTINGS_FIELDSET_DATABASE'); ?></legend>
        <p class="small"><?php echo Text::_('COM_BIOTIME_SETTINGS_FIELDSET_DATABASE_HELP'); ?></p>
        <?php echo $this->form->renderFieldset('database'); ?>
    </fieldset>

    <?php if ($this->dbReport !== null) : ?>
        <?php echo $this->loadTemplate('database'); ?>
    <?php endif; ?>

    <fieldset class="options-form">
        <legend><?php echo Text::_('COM_BIOTIME_SETTINGS_FIELDSET_SYSTEM'); ?></legend>
        <?php echo $this->form->renderFieldset('system'); ?>
    </fieldset>

    <input type="hidden" name="task" value="">
    <?php echo HTMLHelper::_('form.token'); ?>
</form>
