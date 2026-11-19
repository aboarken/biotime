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
 * The Manager Dashboard report without the site template (format=raw): as a PDF file made on the
 * server (layout=pdf, 1.2.0, the Export PDF buttons) or as a page to print (layout=print, 1.1.2).
 *
 * 1.1.2: on its own page. Inside the site
 * template (tmpl=component) the site's cookie notices, pop-ups and their scripts were printed with
 * the report and slowed the print dialog. This page holds only the report: the portal's
 * stylesheet, the report itself and the script that opens the print dialog. The data, the
 * permission checks and the limits are those of the Manager Dashboard (HtmlView).
 */
final class RawView extends HtmlView
{
    public function display($tpl = null): void
    {
        // 1.2.0: layout=pdf gives a PDF file made on the server; anything else the page report.
        $pdf = $this->getLayout() === 'pdf';
        $this->setLayout($pdf ? 'pdf' : 'print');

        ob_start();
        parent::display($tpl);
        $body = (string) ob_get_clean();

        $app  = Factory::getApplication();
        $lang = $app->getLanguage();

        if ($pdf) {
            $this->sendPdf($body);

            return;
        }

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

    /**
     * The PDF file as a download (1.2.0): A4 or A3 landscape, made by the bundled mPDF. A big
     * team takes a few seconds; the time limit is raised for it.
     */
    private function sendPdf(string $html): void
    {
        $app   = Factory::getApplication();
        $title = (string) $this->getDocument()->getTitle();

        if (\function_exists('set_time_limit')) {
            @set_time_limit(180);
        }

        $tmp   = rtrim((string) $app->get('tmp_path', JPATH_ROOT . '/tmp'), '/\\') . '/com_biotime_pdf';
        $bytes = (new PdfReport(JPATH_ADMINISTRATOR . '/components/com_biotime/vendor', $tmp))->render($html, $this->paper, $title);
        $file  = PdfReport::fileName($title);

        $this->getDocument()->setMimeEncoding('application/pdf');
        $app->setHeader('Content-Disposition', 'attachment; filename="' . preg_replace('/[^A-Za-z0-9 ._-]/', '_', $file) . '"; filename*=UTF-8\'\'' . rawurlencode($file), true);
        $app->setHeader('X-Content-Type-Options', 'nosniff', true);

        echo $bytes;
    }

    /** A file of the component's media folder, with its modification time against old caches. */
    private static function media(string $file): string
    {
        $time = @filemtime(JPATH_ROOT . '/media/com_biotime/' . $file);

        return Uri::root(true) . '/media/com_biotime/' . $file . ($time ? '?' . $time : '');
    }
}
