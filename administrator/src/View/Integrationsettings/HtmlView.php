<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZkbPortal\Component\Zkbiotime\Administrator\View\Integrationsettings;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use ZkbPortal\Component\Zkbiotime\Administrator\Model\IntegrationsettingsModel;

final class HtmlView extends BaseHtmlView
{
    public Form $form;

    public bool $hasPassword = false;

    public bool $hasToken = false;

    public bool $isHttp = false;

    public bool $isPinned = false;

    public bool $insecureTls = false;

    public string $secretSource = '';

    public bool $secretEnvTooShort = false;

    public function display($tpl = null): void
    {
        /** @var IntegrationsettingsModel $model */
        $model    = $this->getModel();
        $settings = $model->settings();

        $this->form              = $model->getForm();
        $this->hasPassword       = $settings->hasPassword();
        $this->hasToken          = $settings->hasStaticToken();
        $this->isHttp            = $settings->serverUrl() !== '' && !$settings->isHttps();
        $this->isPinned          = $settings->tlsPin() !== '';
        $this->insecureTls       = !$settings->verifyTls() && !$this->isPinned && $settings->isHttps();
        $this->secretSource      = $model->secretSource();
        $this->secretEnvTooShort = $model->secretEnvTooShort();

        $this->addToolbar();

        $assets = $this->getDocument()->getWebAssetManager();
        $assets->getRegistry()->addExtensionRegistryFile('com_zkbiotime');
        $assets->useStyle('com_zkbiotime.admin');

        parent::display($tpl);
    }

    private function addToolbar(): void
    {
        ToolbarHelper::title(Text::_('COM_ZKBIOTIME_SETTINGS_TITLE'), 'cog');

        $toolbar = $this->getDocument()->getToolbar();

        $toolbar->apply('settings.save', 'JTOOLBAR_APPLY');

        $toolbar->standardButton('test', 'COM_ZKBIOTIME_TEST_CONNECTION')
            ->task('settings.test')
            ->icon('icon-plug')
            ->listCheck(false);

        $toolbar->standardButton('readpin', 'COM_ZKBIOTIME_READ_PIN')
            ->task('settings.readPin')
            ->icon('icon-lock')
            ->listCheck(false);

        // Back to the administrator dashboard; unsaved changes are discarded.
        $toolbar->linkButton('close', 'JTOOLBAR_CLOSE')
            ->url('index.php')
            ->icon('icon-times');

        $toolbar->preferences('com_zkbiotime');
    }
}
