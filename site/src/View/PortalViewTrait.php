<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Site\View;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use BiotimePortal\Component\Biotime\Administrator\Support\Formatter;
use BiotimePortal\Component\Biotime\Site\Model\PortalModel;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/**
 * Shared by the portal views (Joomla\CMS\MVC\View\HtmlView):
 *  - heading and browser title from the menu item (title, Page Heading, Browser Page Title,
 *    Show Page Heading), as in Joomla core;
 *  - noindex/nofollow, the portal stylesheet;
 *  - formatter, time zone label, month navigation, "Updated" time and error texts.
 */
trait PortalViewTrait
{
    public string $pageHeading = '';

    public bool $showPageHeading = true;

    public Formatter $f;

    public ?string $zone = null;

    public int $year = 0;

    public int $month = 0;

    protected function preparePage(PortalModel $model, string $view, string $titleKey): void
    {
        $default = Text::_($titleKey);
        $heading = $title = $default;
        $show    = true;
        $item    = Factory::getApplication()->getMenu()->getActive();

        if ($item !== null && ($item->query['option'] ?? '') === 'com_biotime' && ($item->query['view'] ?? '') === $view) {
            $params    = $item->getParams();
            $menuTitle = trim((string) $item->title);

            if ($menuTitle !== '') {
                $heading = $title = $menuTitle;
            }

            $heading = trim((string) $params->get('page_heading', '')) ?: $heading;
            $title   = trim((string) $params->get('page_title', '')) ?: $title;
            $show    = (string) $params->get('show_page_heading', '') !== '0';
        }

        $this->pageHeading     = $heading;
        $this->showPageHeading = $show;
        $this->f               = $model->formatter();
        $this->zone            = $model->zoneLabel();

        $this->setDocumentTitle($title);

        $document = $this->getDocument();
        $document->setMetaData('robots', 'noindex, nofollow');
        $document->getWebAssetManager()->getRegistry()->addExtensionRegistryFile('com_biotime');
        $document->getWebAssetManager()->useStyle('com_biotime.portal');
    }

    public function url(string $query): string
    {
        return Route::_('index.php?option=com_biotime&' . $query);
    }

    public function monthUrl(string $view, int $year, int $month, array $extra = []): string
    {
        $first = new \DateTimeImmutable(\sprintf('%04d-%02d-01', $year, $month));

        return $this->url('view=' . $view . '&' . http_build_query(['month' => $first->format('Y-m')] + $extra));
    }

    /** @return array{0: int, 1: int} */
    public function shiftMonth(int $by): array
    {
        $d = (new \DateTimeImmutable(\sprintf('%04d-%02d-01', $this->year, $this->month)))->modify(($by >= 0 ? '+' : '') . $by . ' month');

        return [(int) $d->format('Y'), (int) $d->format('n')];
    }

    public function monthTitle(): string
    {
        return $this->f->monthName($this->month) . ' ' . $this->year;
    }

    public function errorText(?string $kind): string
    {
        return $kind === null ? '' : Text::_('COM_BIOTIME_ERROR_' . strtoupper($kind));
    }

    /** "Updated 14:05" in the user's own time zone (this is when the portal read ZKBioTime). */
    public function updatedText(?int $timestamp): string
    {
        return $timestamp === null ? '' : Text::sprintf('COM_BIOTIME_UPDATED_AT', HTMLHelper::_('date', gmdate('Y-m-d H:i:s', $timestamp), 'H:i'));
    }
}
