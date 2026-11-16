<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Site\View\Devices;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use BiotimePortal\Component\Biotime\Administrator\Devices\Device;
use BiotimePortal\Component\Biotime\Administrator\Devices\DeviceList;
use BiotimePortal\Component\Biotime\Site\Model\DevicesModel;
use BiotimePortal\Component\Biotime\Site\View\PortalViewTrait;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;

/**
 * Biometric Devices: name, location (area) and health of every ZKBioTime device, with filters by
 * health and area. Serial number, IP address and firmware only when the menu item allows it.
 */
final class HtmlView extends BaseHtmlView
{
    use PortalViewTrait;

    public DeviceList $list;

    /** @var list<Device> after the filters */
    public array $rows = [];

    /** @var array<string, int> */
    public array $counts = [];

    /** @var list<string> */
    public array $areas = [];

    public string $health = '';

    public string $area = '';

    public bool $technical = false;

    public function display($tpl = null): void
    {
        /** @var DevicesModel $model */
        $model = $this->getModel();
        $app   = Factory::getApplication();
        $input = $app->getInput();

        $this->list   = $model->devices();
        $this->counts = $this->list->counts();
        $this->areas  = $this->list->areas();

        $health       = $input->getCmd('health', '');
        $this->health = \in_array($health, Device::HEALTHS, true) ? $health : '';
        $area         = $input->getString('area', '');
        $this->area   = \in_array($area, $this->areas, true) ? $area : '';

        $item            = $app->getMenu()->getActive();
        $this->technical = $item !== null && ($item->query['view'] ?? '') === 'devices' && (int) $item->getParams()->get('show_technical', 0) === 1;

        $this->rows = array_values(array_filter(
            $this->list->devices,
            fn (Device $d): bool => ($this->health === '' || $d->health === $this->health) && ($this->area === '' || $d->area === $this->area)
        ));

        $this->preparePage($model, 'devices', 'COM_BIOTIME_DEVICES_TITLE');
        $model->logger()->info(\sprintf(
            'Page devices, Joomla user %d: %s',
            (int) $app->getIdentity()?->id,
            $this->list->error !== null ? 'error ' . $this->list->error : \count($this->list->devices) . ' devices'
        ));

        parent::display($tpl);
    }

    /** "5 min ago", "3 h ago", "2 days ago"; "—" when unknown. */
    public function ago(?int $minutes): string
    {
        return match (true) {
            $minutes === null => '—',
            $minutes < 1      => $this->f->t('COM_BIOTIME_DEVICES_JUST_NOW'),
            $minutes < 60     => \Joomla\CMS\Language\Text::sprintf('COM_BIOTIME_DEVICES_MINUTES_AGO', $minutes),
            $minutes < 1440   => \Joomla\CMS\Language\Text::sprintf('COM_BIOTIME_DEVICES_HOURS_AGO', intdiv($minutes, 60)),
            default           => \Joomla\CMS\Language\Text::sprintf('COM_BIOTIME_DEVICES_DAYS_AGO', intdiv($minutes, 1440)),
        };
    }

    public function filterUrl(string $health, string $area): string
    {
        return $this->url('view=devices' . ($health !== '' ? '&health=' . $health : '') . ($area !== '' ? '&area=' . rawurlencode($area) : ''));
    }
}
