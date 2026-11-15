<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZkbPortal\Component\Zkbiotime\Administrator\Controller;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use ZkbPortal\Component\Zkbiotime\Administrator\Api\ConnectionReport;
use ZkbPortal\Component\Zkbiotime\Administrator\Api\Credentials;
use ZkbPortal\Component\Zkbiotime\Administrator\Api\ZkbException;
use ZkbPortal\Component\Zkbiotime\Administrator\Audit\AuditLogInterface;
use ZkbPortal\Component\Zkbiotime\Administrator\Extension\ZkbiotimeComponent;
use ZkbPortal\Component\Zkbiotime\Administrator\Model\IntegrationsettingsModel;
use ZkbPortal\Component\Zkbiotime\Administrator\Settings\ValidationFailed;

/**
 * Integration Settings tasks. Every task needs POST, a valid CSRF token and core.admin.
 */
final class SettingsController extends BaseController
{
    public const STATE_KEY = 'com_zkbiotime.edit.settings.data';

    private const URL = 'index.php?option=com_zkbiotime&view=integrationsettings';

    /** Connection tests and certificate reads per administrator and hour. */
    private const TESTS_PER_HOUR = 20;

    public function save(): void
    {
        $this->guard();

        $data = $this->input->post->get('jform', [], 'array');

        try {
            $this->model()->save($data);
            $this->app->setUserState(self::STATE_KEY, null);
            $this->component()->getAuditLog()->record($this->actorId(), 'settings.save', AuditLogInterface::RESULT_OK);
            $this->app->enqueueMessage(Text::_('COM_ZKBIOTIME_SETTINGS_SAVED'), 'success');
        } catch (ValidationFailed $e) {
            // Keep the entered values for the form, but never a password or token.
            unset($data['api_password'], $data['api_token']);
            $this->app->setUserState(self::STATE_KEY, $data);

            foreach ($e->getKeys() as $key) {
                $this->app->enqueueMessage(Text::_($key), 'error');
            }
        }

        $this->setRedirect(Route::_(self::URL, false));
    }

    /**
     * Log in with the saved settings and read one employee row (count only).
     */
    public function test(): void
    {
        $this->guard();

        if (!$this->allowed('settings.test')) {
            return;
        }

        $audit = $this->component()->getAuditLog();

        try {
            $report = $this->model()->testConnection();
            $audit->record($this->actorId(), 'settings.test', AuditLogInterface::RESULT_OK, $report->method);
            $this->app->enqueueMessage($this->describe($report), 'success');
        } catch (ZkbException $e) {
            $audit->record($this->actorId(), 'settings.test', $e->getKind() === ZkbException::NOT_CONFIGURED ? AuditLogInterface::RESULT_DENIED : AuditLogInterface::RESULT_ERROR, $e->getKind());
            $this->app->enqueueMessage(Text::sprintf('COM_ZKBIOTIME_TEST_FAILED', Text::_($e->getLanguageKey()), Text::_('COM_ZKBIOTIME_TEST_HINT_' . strtoupper($e->getKind()))), 'error');
        }

        $this->setRedirect(Route::_(self::URL, false));
    }

    /**
     * Show the pin of the certificate the saved server presents right now. The administrator must
     * compare it with the value computed on the ZKBioTime server before pasting it into the pin field.
     */
    public function readPin(): void
    {
        $this->guard();

        if (!$this->allowed('settings.readpin')) {
            return;
        }

        try {
            $cert = $this->model()->readServerPin();
            $this->component()->getAuditLog()->record($this->actorId(), 'settings.readpin', AuditLogInterface::RESULT_OK);
            $this->app->enqueueMessage(Text::sprintf(
                'COM_ZKBIOTIME_PIN_READ',
                htmlspecialchars($cert['pin'], ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($cert['subject'] !== '' ? $cert['subject'] : '-', ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($cert['issuer'] !== '' ? $cert['issuer'] : '-', ENT_QUOTES, 'UTF-8'),
                $cert['expires'] ? gmdate('Y-m-d', $cert['expires']) : '-'
            ), 'info');
        } catch (\RuntimeException | \InvalidArgumentException) {
            $this->component()->getAuditLog()->record($this->actorId(), 'settings.readpin', AuditLogInterface::RESULT_ERROR);
            $this->app->enqueueMessage(Text::_('COM_ZKBIOTIME_PIN_READ_FAILED'), 'error');
        }

        $this->setRedirect(Route::_(self::URL, false));
    }

    private function describe(ConnectionReport $report): string
    {
        $method = Text::_($report->method === Credentials::METHOD_JWT ? 'COM_ZKBIOTIME_SETTINGS_METHOD_JWT' : 'COM_ZKBIOTIME_SETTINGS_METHOD_TOKEN');
        $parts  = [Text::sprintf('COM_ZKBIOTIME_TEST_OK', $method, $report->elapsedMs)];

        $parts[] = $report->employeeCount !== null
            ? Text::sprintf('COM_ZKBIOTIME_TEST_EMPLOYEES', $report->employeeCount)
            : Text::_('COM_ZKBIOTIME_TEST_EMPLOYEES_UNKNOWN');

        if ($report->staticToken) {
            $parts[] = Text::_('COM_ZKBIOTIME_TEST_STATIC_TOKEN');
        } elseif ($report->tokenRenewAt !== null) {
            $parts[] = Text::sprintf('COM_ZKBIOTIME_TEST_TOKEN_RENEW', HTMLHelper::_('date', gmdate('Y-m-d H:i:s', $report->tokenRenewAt), Text::_('DATE_FORMAT_LC2')));
        }

        return implode(' ', $parts);
    }

    private function allowed(string $action): bool
    {
        if ($this->component()->getRateLimiter()->allow($this->actorId(), $action, self::TESTS_PER_HOUR)) {
            return true;
        }

        $this->app->enqueueMessage(Text::_('COM_ZKBIOTIME_ERROR_TOO_MANY_TESTS'), 'warning');
        $this->setRedirect(Route::_(self::URL, false));

        return false;
    }

    private function guard(): void
    {
        $this->checkToken();

        if (!$this->app->getIdentity()->authorise('core.admin', 'com_zkbiotime')) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
    }

    private function model(): IntegrationsettingsModel
    {
        /** @var IntegrationsettingsModel $model */
        $model = $this->getModel('Integrationsettings', 'Administrator', ['ignore_request' => true]);

        return $model;
    }

    private function actorId(): int
    {
        return (int) $this->app->getIdentity()->id;
    }

    private function component(): ZkbiotimeComponent
    {
        $component = $this->app->bootComponent('com_zkbiotime');

        if (!$component instanceof ZkbiotimeComponent) {
            throw new \LogicException('Unexpected component class');
        }

        return $component;
    }
}
