<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Site\Controller;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use BiotimePortal\Component\Biotime\Administrator\Api\ZkbException;
use BiotimePortal\Component\Biotime\Administrator\Audit\AuditLogInterface;
use BiotimePortal\Component\Biotime\Site\Model\ReportsModel;
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;

/**
 * PDF and Excel exports: POST + CSRF token + zkb.report.export + the exports module + a per-user
 * hourly limit. Only the logged-in employee's own records are exported.
 */
final class ReportController extends BaseController
{
    use IdentityGuardTrait;

    public function export(): void
    {
        $this->noCache();

        if (!$this->requireLogin()) {
            return;
        }

        $this->checkToken();

        $user = $this->app->getIdentity();

        if (!$user->authorise('zkb.report.view', 'com_biotime') || !$user->authorise('zkb.report.export', 'com_biotime')) {
            throw new NotAllowed(Text::_('COM_BIOTIME_NOT_AUTHORISED'), 403);
        }

        $biotime  = $this->biotime();
        $settings = $biotime->getSettingsRepository()->load();
        $back     = Route::_('index.php?option=com_biotime&view=reports', false);

        if (!$settings->moduleEnabled('exports')) {
            $this->app->enqueueMessage(Text::_('COM_BIOTIME_FEATURE_OFF'), 'warning');
            $this->setRedirect($back);

            return;
        }

        try {
            $identity = $this->currentIdentity();
        } catch (ZkbException $e) {
            $this->app->enqueueMessage(Text::_($e->getLanguageKey()), 'error');
            $this->setRedirect($back);

            return;
        }

        if (!$identity->isMatched()) {
            $this->app->enqueueMessage(Text::_('COM_BIOTIME_UNMATCHED'), 'warning');
            $this->setRedirect($back);

            return;
        }

        if (!$biotime->getRateLimiter()->allow((int) $user->id, 'report.export', $settings->exportsPerHour())) {
            $this->app->enqueueMessage(Text::_('COM_BIOTIME_EXPORT_RATE_LIMITED'), 'warning');
            $this->setRedirect($back);

            return;
        }

        /** @var ReportsModel $model */
        $model  = $this->getModel('Reports', 'Site', ['ignore_request' => true]);
        $range  = $model->readRange($this->input->post);
        $format = $this->input->post->getCmd('format', '');
        $back   = Route::_('index.php?option=com_biotime&view=reports&' . http_build_query($range['query']), false);

        if ($range['error'] !== null || !\in_array($format, ['pdf', 'xlsx'], true)) {
            $this->app->enqueueMessage(Text::_($range['error'] ?? 'COM_BIOTIME_EXPORT_FORMAT'), 'warning');
            $this->setRedirect($back);

            return;
        }

        $audit = $biotime->getAuditLog();

        try {
            [$content, $mime, $name] = $model->export($identity, $range['from'], $range['to'], $format);
        } catch (\Throwable $e) {
            $audit->record((int) $user->id, 'report.export', AuditLogInterface::RESULT_ERROR, $format);
            $biotime->getLogger()->error(\sprintf('Export (%s) for Joomla user %d failed: %s', $format, (int) $user->id, $e instanceof ZkbException ? $e->getKind() : \get_class($e)));
            $this->app->enqueueMessage(Text::_($e instanceof ZkbException ? $e->getLanguageKey() : 'COM_BIOTIME_EXPORT_FAILED'), 'error');
            $this->setRedirect($back);

            return;
        }

        $audit->record((int) $user->id, 'report.export', AuditLogInterface::RESULT_OK, $format . ' ' . $range['from']->format('Y-m-d') . '..' . $range['to']->format('Y-m-d'));
        $biotime->getLogger()->info(\sprintf('Export %s for Joomla user %d: %s to %s', $format, (int) $user->id, $range['from']->format('Y-m-d'), $range['to']->format('Y-m-d')));

        $this->app->setHeader('Content-Type', $mime, true);
        $this->app->setHeader('Content-Disposition', 'attachment; filename="' . $name . '"', true);
        $this->app->setHeader('Content-Length', (string) \strlen($content), true);
        $this->app->setHeader('X-Content-Type-Options', 'nosniff', true);
        $this->app->sendHeaders();
        echo $content;
        $this->app->close();
    }
}
