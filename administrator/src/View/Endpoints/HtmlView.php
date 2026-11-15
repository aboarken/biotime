<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\View\Endpoints;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use BiotimePortal\Component\Biotime\Administrator\Controller\EndpointsController;
use BiotimePortal\Component\Biotime\Administrator\Discovery\ApiCatalog;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * Find Endpoints. Shows the API list read from the server and the last check, both kept in the
 * administrator's session only.
 */
final class HtmlView extends BaseHtmlView
{
    /** @var array{endpoints: list<array{path: string, summary: string, params: list<string>, relevant: bool}>, sources: list<string>, error: ?string, at: int}|null */
    public ?array $catalog = null;

    /** @var array<string, mixed>|null */
    public ?array $check = null;

    public string $filter = '';

    public bool $showAll = false;

    public function display($tpl = null): void
    {
        $app = Factory::getApplication();

        $catalog       = $app->getUserState(EndpointsController::STATE_CATALOG);
        $check         = $app->getUserState(EndpointsController::STATE_CHECK);
        $this->catalog = \is_array($catalog) ? $catalog : null;
        $this->check   = \is_array($check) ? $check : null;
        $this->filter  = mb_substr(trim($app->getInput()->getString('filter', '')), 0, 60);
        $this->showAll = $app->getInput()->getInt('all', 0) === 1;

        ToolbarHelper::title(Text::_('COM_BIOTIME_ENDPOINTS_TITLE'), 'search');

        $toolbar = $this->getDocument()->getToolbar();
        $toolbar->linkButton('settings', 'COM_BIOTIME_SUBMENU_SETTINGS')
            ->url('index.php?option=com_biotime&view=integrationsettings')
            ->icon('icon-cog');
        $toolbar->linkButton('close', 'JTOOLBAR_CLOSE')
            ->url('index.php')
            ->icon('icon-times');
        $toolbar->preferences('com_biotime');

        $assets = $this->getDocument()->getWebAssetManager();
        $assets->getRegistry()->addExtensionRegistryFile('com_biotime');
        $assets->useStyle('com_biotime.admin');

        parent::display($tpl);
    }

    /**
     * Endpoints to list: attendance-related ones unless "show all", filtered by text.
     *
     * @return list<array{path: string, summary: string, params: list<string>, relevant: bool}>
     */
    public function endpoints(): array
    {
        $list = $this->catalog['endpoints'] ?? [];

        return array_values(array_filter($list, function (array $e): bool {
            if (!$this->showAll && !$e['relevant'] && $this->filter === '') {
                return false;
            }

            return $this->filter === '' || str_contains(mb_strtolower($e['path'] . ' ' . $e['summary']), mb_strtolower($this->filter));
        }));
    }

    /** @return array{emp: string, from: string, to: string} */
    public function paramsFor(array $endpoint): array
    {
        return ApiCatalog::suggestParams($endpoint['params']);
    }
}
