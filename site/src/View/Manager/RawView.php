<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Site\View\Manager;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;

/**
 * The Manager Dashboard report on its own page (Export PDF, 1.1.2: format=raw). Inside the site
 * template (tmpl=component) the site's cookie notices, pop-ups and their scripts were printed with
 * the report and slowed the print dialog. This page holds only the report: the portal's
 * stylesheet, the report itself and the script that opens the print dialog. The data, the
 * permission checks and the limits are those of the Manager Dashboard (HtmlView).
 */
final class RawView extends HtmlView
{
    public function display($tpl = null): void
    {
        $this->setLayout('print');

        ob_start();
        parent::display($tpl);
        $body = (string) ob_get_clean();

        $app  = Factory::getApplication();
        $lang = $app->getLanguage();

        echo ReportPage::html(
            (string) $this->getDocument()->getTitle(),
            (string) $lang->getTag(),
            $lang->isRtl(),
            self::media('css/portal.css'),
            self::media('js/report.js'),
            $this->reportCss(),
            (string) $app->get('csp_nonce', ''),
            $body
        );
    }

    /** A file of the component's media folder, with its modification time against old caches. */
    private static function media(string $file): string
    {
        $time = @filemtime(JPATH_ROOT . '/media/com_biotime/' . $file);

        return Uri::root(true) . '/media/com_biotime/' . $file . ($time ? '?' . $time : '');
    }
}
