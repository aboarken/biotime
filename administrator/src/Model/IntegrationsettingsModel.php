<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Model;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\FormModel;
use BiotimePortal\Component\Biotime\Administrator\Api\ClientConfig;
use BiotimePortal\Component\Biotime\Administrator\Api\ConnectionReport;
use BiotimePortal\Component\Biotime\Administrator\Api\MemoryTokenStore;
use BiotimePortal\Component\Biotime\Administrator\Api\SslPin;
use BiotimePortal\Component\Biotime\Administrator\Controller\SettingsController;
use BiotimePortal\Component\Biotime\Administrator\Extension\BiotimeComponent;
use BiotimePortal\Component\Biotime\Administrator\Settings\Settings;
use BiotimePortal\Component\Biotime\Administrator\Settings\SettingsValidator;
use BiotimePortal\Component\Biotime\Administrator\Settings\ValidationFailed;

final class IntegrationsettingsModel extends FormModel
{
    public function getForm($data = [], $loadData = true)
    {
        $form = $this->loadForm('com_biotime.settings', 'settings', ['control' => 'jform', 'load_data' => $loadData]);

        return $form ?: false;
    }

    protected function loadFormData()
    {
        $state = Factory::getApplication()->getUserState(SettingsController::STATE_KEY, []);
        $data  = \is_array($state) && $state !== [] ? $state : $this->settings()->toFormData();

        // Secret fields are always empty: stored secrets are never sent back to the browser.
        $data['api_password']   = '';
        $data['api_token']      = '';
        $data['clear_password'] = '0';
        $data['clear_token']    = '0';

        return $data;
    }

    public function settings(): Settings
    {
        return $this->component()->getSettingsRepository()->load();
    }

    public function secretSource(): string
    {
        return $this->component()->getClientFactory()->secretSource();
    }

    public function secretEnvTooShort(): bool
    {
        return $this->component()->getClientFactory()->secretEnvTooShort();
    }

    /**
     * Validate, encrypt new secrets and store. The raw post is validated by SettingsValidator, so
     * no form filter can alter a password or token.
     *
     * @param  array<string, mixed>  $data  Raw posted jform data
     *
     * @throws ValidationFailed  carrying language keys
     */
    public function save(array $data): void
    {
        $factory   = $this->component()->getClientFactory();
        $validator = new SettingsValidator(
            static fn (string $plain, string $context): string => $factory->vault()->encrypt($plain, $context)
        );

        $values = $validator->validate($data, $this->settings());

        $this->component()->getSettingsRepository()->save($values);
    }

    /**
     * @throws \BiotimePortal\Component\Biotime\Administrator\Api\ZkbException
     */
    public function testConnection(): ConnectionReport
    {
        // A token store of its own: the test always logs in afresh and leaves the shared cache alone.
        return $this->component()->getClientFactory()->create(new MemoryTokenStore())->testConnection();
    }

    /**
     * @return array{pin: string, subject: string, issuer: string, expires: ?int}
     *
     * @throws \RuntimeException|\InvalidArgumentException
     */
    public function readServerPin(): array
    {
        $settings = $this->settings();

        if ($settings->serverUrl() === '') {
            throw new \RuntimeException('No server URL saved');
        }

        return SslPin::readFromServer(
            ClientConfig::buildBaseUrl($settings->serverUrl(), $settings->serverPort()),
            $settings->connectTimeout(),
            $settings->connectVia()
        );
    }

    private function component(): BiotimeComponent
    {
        $component = Factory::getApplication()->bootComponent('com_biotime');

        if (!$component instanceof BiotimeComponent) {
            throw new \LogicException('Unexpected component class');
        }

        return $component;
    }
}
