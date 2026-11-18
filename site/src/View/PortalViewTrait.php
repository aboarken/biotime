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
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/**
 * Shared by the portal views (Joomla\CMS\MVC\View\HtmlView):
 *  - heading and browser title from the menu item (title, Page Heading, Browser Page Title,
 *    Show Page Heading), as in Joomla core;
 *  - noindex/nofollow, the portal stylesheet and the filter bar script;
 *  - formatter, time zone label, month navigation and error texts.
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

        $this->setPortalTitle($title);

        $document = $this->getDocument();
        $document->setMetaData('robots', 'noindex, nofollow');
        $document->getWebAssetManager()->getRegistry()->addExtensionRegistryFile('com_biotime');
        $document->getWebAssetManager()->useStyle('com_biotime.portal');
        $document->getWebAssetManager()->useScript('com_biotime.filters');
        $document->getWebAssetManager()->useScript('com_biotime.datepicker');
    }

    /**
     * The browser title, with the site name before or after it as the Global Configuration asks
     * (Site Name in Page Titles), always joined with " - " (never the language's own separator,
     * which can be "|").
     */
    public function setPortalTitle(string $title): void
    {
        $app  = Factory::getApplication();
        $site = trim((string) $app->get('sitename', ''));

        $title = match ($site === '' ? 0 : (int) $app->get('sitename_pagetitles', 0)) {
            1       => $site . ' - ' . $title,
            2       => $title . ' - ' . $site,
            default => $title,
        };

        $this->getDocument()->setTitle($title);
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

    public function monthTitle(): string
    {
        return $this->f->monthName($this->month) . ' ' . $this->year;
    }

    /** Notices shown above attendance data (errors, cut-off lists, the time zone). */
    public function notices(\BiotimePortal\Component\Biotime\Administrator\Attendance\Period $period): string
    {
        return \Joomla\CMS\Layout\LayoutHelper::render('biotime.notices', [
            'period' => $period,
            'zone'   => $this->zone,
        ]);
    }
}
