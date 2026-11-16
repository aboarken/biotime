<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Controller;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use BiotimePortal\Component\Biotime\Administrator\Api\ZkbClient;
use BiotimePortal\Component\Biotime\Administrator\Api\ZkbException;
use BiotimePortal\Component\Biotime\Administrator\Attendance\SourceConfig;
use BiotimePortal\Component\Biotime\Administrator\Audit\AuditLogInterface;
use BiotimePortal\Component\Biotime\Administrator\Discovery\Diagnostics;
use BiotimePortal\Component\Biotime\Administrator\Discovery\FieldInspector;
use BiotimePortal\Component\Biotime\Administrator\Extension\BiotimeComponent;
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;

/**
 * Find Endpoints: read the server's API list, check one endpoint (field names and kinds only,
 * plus status texts), and save it as the Daily Attendance or Leave Source. Every task needs POST,
 * a valid CSRF token and core.admin; only GET requests are sent to ZKBioTime.
 */
final class EndpointsController extends BaseController
{
    public const STATE_CATALOG = 'com_biotime.endpoints.catalog';
    public const STATE_CHECK   = 'com_biotime.endpoints.check';
    public const STATE_DIAG    = 'com_biotime.endpoints.diagnostics';

    private const URL = 'index.php?option=com_biotime&view=endpoints';

    /** API list reads and endpoint checks per administrator and hour. */
    private const PER_HOUR = 60;

    public function catalog(): void
    {
        $this->guard();

        if (!$this->allowed()) {
            return;
        }

        try {
            $result = $this->component()->getClientFactory()->create()->apiCatalog();
            $this->app->setUserState(self::STATE_CATALOG, $result + ['error' => null, 'at' => time()]);
            $this->audit(AuditLogInterface::RESULT_OK, 'catalog ' . \count($result['endpoints']));
            $this->app->enqueueMessage(Text::sprintf('COM_BIOTIME_ENDPOINTS_READ', \count($result['endpoints'])), 'success');
        } catch (ZkbException $e) {
            $this->app->setUserState(self::STATE_CATALOG, ['endpoints' => [], 'sources' => [], 'error' => $e->getKind(), 'at' => time()]);
            $this->audit(AuditLogInterface::RESULT_ERROR, 'catalog ' . $e->getKind());
            $noDocs = \in_array($e->getKind(), [ZkbException::NOT_FOUND, ZkbException::VALIDATION, ZkbException::BAD_RESPONSE], true);
            $this->app->enqueueMessage(Text::_($noDocs ? 'COM_BIOTIME_ENDPOINTS_NO_DOCS' : $e->getLanguageKey()) . $this->serverSaid($e), 'warning');
        }

        $this->setRedirect(Route::_(self::URL, false));
    }

    public function check(): void
    {
        $this->guard();

        if (!$this->allowed()) {
            return;
        }

        $in    = $this->input->post;
        $kind  = $in->getCmd('kind', 'daily') === 'leave' ? 'leave' : 'daily';
        $path  = trim($in->getString('path', ''));
        $param = static fn (string $v, string $d): string => preg_match('/^[a-z][a-z0-9_]{0,39}$/', $v) ? $v : $d;
        $emp   = $param(trim($in->getString('param_emp', '')), 'emp_code');
        $pFrom = $param(trim($in->getString('param_from', '')), 'start_date');
        $pTo   = $param(trim($in->getString('param_to', '')), 'end_date');
        $code  = trim($in->getString('emp_code', ''));
        $from  = $this->date($in->getString('from', '')) ?? new \DateTimeImmutable('first day of this month');
        $to    = $this->date($in->getString('to', '')) ?? new \DateTimeImmutable('last day of this month');
        $fmt   = \in_array($in->getString('date_format', ''), SourceConfig::DATE_FORMATS, true) ? $in->getString('date_format') : 'Y-m-d';

        $form = ['kind' => $kind, 'path' => $path, 'param_emp' => $emp, 'param_from' => $pFrom, 'param_to' => $pTo, 'emp_code' => $code, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'date_format' => $fmt];

        if (!preg_match('#^(/[A-Za-z0-9_-]+)+/$#', $path) || \in_array($path, ['/jwt-api-token-auth/', '/api-token-auth/'], true)) {
            $this->failCheck($form, 'COM_BIOTIME_ENDPOINTS_ERR_PATH');

            return;
        }

        $query = [$pFrom => $from->format($fmt), $pTo => $to->format($fmt)];

        if ($code !== '') {
            try {
                $query[$emp] = ZkbClient::empCode($code);
            } catch (\InvalidArgumentException) {
                $this->failCheck($form, 'COM_BIOTIME_MAPPING_ERR_CODE');

                return;
            }
        }

        try {
            $page = $this->component()->getClientFactory()->create()->probe($path, $query);
        } catch (ZkbException $e) {
            $this->audit(AuditLogInterface::RESULT_ERROR, 'check ' . $path . ' ' . $e->getKind());

            $key = match ($e->getKind()) {
                ZkbException::BAD_RESPONSE => 'COM_BIOTIME_ENDPOINTS_ERR_NOT_DATA',
                ZkbException::NOT_FOUND    => 'COM_BIOTIME_ENDPOINTS_ERR_NO_PATH',
                ZkbException::VALIDATION   => 'COM_BIOTIME_ENDPOINTS_ERR_REFUSED',
                default                    => $e->getLanguageKey(),
            };

            $this->failCheck($form, $key, $e->getKind(), $this->serverSaid($e));

            return;
        }

        $fields   = FieldInspector::inspect($page->items);
        $suggest  = FieldInspector::suggestFields($kind, $fields);
        $statuses = isset($suggest['status']) ? FieldInspector::suggestStatuses($kind, $fields[$suggest['status']]['values'] ?? []) : [];

        $this->app->setUserState(self::STATE_CHECK, $form + [
            'rows'     => \count($page->items),
            'count'    => $page->count,
            'fields'   => $fields,
            'suggest'  => $suggest,
            'statuses' => $statuses,
            'error'    => null,
        ]);

        $this->audit(AuditLogInterface::RESULT_OK, 'check ' . $path . ' rows ' . \count($page->items));
        $this->app->enqueueMessage(Text::sprintf('COM_BIOTIME_ENDPOINTS_CHECKED', \count($page->items), \count($fields)), \count($page->items) > 0 ? 'success' : 'warning');
        $this->setRedirect(Route::_(self::URL . '#biotime-check', false));
    }

    /**
     * Diagnostics: every check in one run, as a text report to copy (paths, results, field names
     * and kinds only).
     */
    public function diagnose(): void
    {
        $this->guard();

        if (!$this->allowed()) {
            return;
        }

        $in   = $this->input->post;
        $code = trim($in->getString('emp_code', ''));

        if ($code !== '') {
            try {
                $code = ZkbClient::empCode($code);
            } catch (\InvalidArgumentException) {
                $this->app->enqueueMessage(Text::_('COM_BIOTIME_MAPPING_ERR_CODE'), 'warning');
                $this->setRedirect(Route::_(self::URL . '#biotime-diag', false));

                return;
            }
        }

        $from      = $this->date($in->getString('from', '')) ?? new \DateTimeImmutable('first day of this month');
        $to        = $this->date($in->getString('to', '')) ?? new \DateTimeImmutable('today');
        $component = $this->component();
        $settings  = $component->getSettingsRepository()->load();

        try {
            $client = $component->getClientFactory()->create();
            $report = Diagnostics::run($client, $code !== '' ? $code : null, $from, $to, [
                'Component version'     => $this->version(),
                'Joomla / PHP'          => JVERSION . ' / ' . PHP_VERSION,
                'Login method'          => $settings->get('auth_method') ?: '—',
                'Connect via set'       => $settings->get('connect_via') !== '' ? 'yes' : 'no',
                'Daily source API path' => $settings->get('daily_path') ?: '(empty)',
                'Leave source API path' => $settings->get('leave_path') ?: '(empty)',
            ]);
        } catch (ZkbException $e) {
            $report = 'ZKBioTime portal diagnostics' . "\n" . 'FAILED before the first request: ' . $e->getKind();
        }

        $this->app->setUserState(self::STATE_DIAG, ['report' => $report, 'emp_code' => $code, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')]);
        $this->audit(AuditLogInterface::RESULT_OK, 'diagnostics');
        $this->app->enqueueMessage(Text::_('COM_BIOTIME_DIAG_DONE'), 'success');
        $this->setRedirect(Route::_(self::URL . '#biotime-diag', false));
    }

    private function version(): string
    {
        $db    = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
        $query = $db->createQuery()
            ->select($db->quoteName('manifest_cache'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('com_biotime'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('component'));
        $cache = json_decode((string) $db->setQuery($query)->loadResult(), true);

        return \is_array($cache) && \is_string($cache['version'] ?? null) ? $cache['version'] : '—';
    }

    /**
     * Save the reviewed values as the Daily Attendance or Leave Source.
     */
    public function save(): void
    {
        $this->guard();

        $in   = $this->input->post;
        $kind = $in->getCmd('kind', 'daily') === 'leave' ? 'leave' : 'daily';
        $raw  = [];

        foreach (['path', 'param_emp', 'param_from', 'param_to', 'date_format', 'extra', 'fields', 'duration_unit', 'status_map'] as $name) {
            $raw[$name] = mb_substr($in->getRaw($name, '') ?? '', 0, 4000);
        }

        try {
            $source = SourceConfig::parse($kind, $raw);
        } catch (\InvalidArgumentException $e) {
            $this->app->enqueueMessage(Text::_('COM_BIOTIME_SETTINGS_' . strtoupper($kind) . '_' . (str_starts_with($e->getMessage(), 'ERR_') ? $e->getMessage() : 'ERR_LINE')), 'error');
            $this->setRedirect(Route::_(self::URL . '#biotime-check', false));

            return;
        }

        if (!$source->isConfigured()) {
            $this->app->enqueueMessage(Text::_('COM_BIOTIME_SETTINGS_' . strtoupper($kind) . '_ERR_FIELDS_REQUIRED'), 'error');
            $this->setRedirect(Route::_(self::URL . '#biotime-check', false));

            return;
        }

        $this->component()->getSettingsRepository()->save([
            $kind . '_path'          => $source->path,
            $kind . '_param_emp'     => $source->paramEmp,
            $kind . '_param_from'    => $source->paramFrom,
            $kind . '_param_to'      => $source->paramTo,
            $kind . '_date_format'   => $source->dateFormat,
            $kind . '_duration_unit' => $source->durationUnit,
            $kind . '_extra'         => trim(str_replace("\r", '', $raw['extra'])),
            $kind . '_fields'        => trim(str_replace("\r", '', $raw['fields'])),
            $kind . '_status_map'    => trim(str_replace("\r", '', $raw['status_map'])),
        ]);

        $this->component()->getAuditLog()->record($this->actorId(), 'settings.source', AuditLogInterface::RESULT_OK, $kind . ' ' . $source->path);
        $this->app->enqueueMessage(Text::_($kind === 'daily' ? 'COM_BIOTIME_ENDPOINTS_SAVED_DAILY' : 'COM_BIOTIME_ENDPOINTS_SAVED_LEAVE'), 'success');
        $this->setRedirect(Route::_('index.php?option=com_biotime&view=integrationsettings', false));
    }

    /**
     * @param array<string, string> $form
     */
    private function failCheck(array $form, string $key, ?string $kind = null, string $detail = ''): void
    {
        $this->app->setUserState(self::STATE_CHECK, $form + ['rows' => 0, 'count' => null, 'fields' => [], 'suggest' => [], 'statuses' => [], 'error' => $kind ?? 'input']);
        $this->app->enqueueMessage(Text::_($key) . $detail, 'warning');
        $this->setRedirect(Route::_(self::URL . '#biotime-check', false));
    }

    /**
     * " ZKBioTime answered HTTP 400: <its message>" for the administrator (already scrubbed of
     * secrets by the client), escaped for the message area.
     */
    private function serverSaid(ZkbException $e): string
    {
        if ($e->getHttpStatus() === 0 && $e->getServerMessage() === '') {
            return '';
        }

        return ' ' . htmlspecialchars(Text::sprintf(
            'COM_BIOTIME_ENDPOINTS_SERVER_SAID',
            $e->getHttpStatus() > 0 ? (string) $e->getHttpStatus() : '—',
            $e->getServerMessage() !== '' ? $e->getServerMessage() : '—'
        ), ENT_QUOTES, 'UTF-8');
    }

    private function date(string $value): ?\DateTimeImmutable
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? new \DateTimeImmutable($value) : null;
    }

    private function allowed(): bool
    {
        if ($this->component()->getRateLimiter()->allow($this->actorId(), 'settings.explore', self::PER_HOUR)) {
            return true;
        }

        $this->app->enqueueMessage(Text::_('COM_BIOTIME_ERROR_TOO_MANY_TESTS'), 'warning');
        $this->setRedirect(Route::_(self::URL, false));

        return false;
    }

    private function audit(string $result, string $detail): void
    {
        $this->component()->getAuditLog()->record($this->actorId(), 'settings.explore', $result, $detail);
    }

    private function guard(): void
    {
        $this->checkToken();

        if (!$this->app->getIdentity()->authorise('core.admin', 'com_biotime')) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
    }

    private function actorId(): int
    {
        return (int) $this->app->getIdentity()->id;
    }

    private function component(): BiotimeComponent
    {
        $component = $this->app->bootComponent('com_biotime');

        if (!$component instanceof BiotimeComponent) {
            throw new \LogicException('Unexpected component class');
        }

        return $component;
    }
}
