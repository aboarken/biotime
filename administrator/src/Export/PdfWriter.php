<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Export;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects


/**
 * Writes a ReportTable as PDF with the bundled TCPDF (administrator/lib/tcpdf, LGPL-3.0-or-later)
 * and the DejaVu Sans font, which covers Latin, Arabic and other scripts.
 */
final class PdfWriter
{
    public function __construct(private readonly string $cacheDir)
    {
    }

    public function write(ReportTable $report): string
    {
        self::load($this->cacheDir);

        $pdf = new \TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('com_biotime');
        $pdf->SetTitle($report->title);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(true);
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(true, 12);
        $pdf->SetFont('dejavusans', '', 8);
        $pdf->AddPage();

        $e    = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<h2>' . $e($report->title) . '</h2><p>' . $e($report->subtitle) . '</p>';

        if ($report->summary !== []) {
            $html .= '<table cellpadding="2" border="0">';

            foreach ($report->summary as [$label, $value]) {
                $html .= '<tr><td width="45%">' . $e($label) . '</td><td width="55%"><b>' . $e($value) . '</b></td></tr>';
            }

            $html .= '</table><br>';
        }

        $html .= '<table cellpadding="2" border="1"><thead><tr style="background-color:#e9ecef;font-weight:bold;">';

        foreach ($report->headers as $header) {
            $html .= '<th>' . $e($header) . '</th>';
        }

        $html .= '</tr></thead><tbody>';

        foreach ($report->rows as $row) {
            $html .= '<tr>';

            foreach ($row as $cell) {
                $html .= '<td>' . $e($cell) . '</td>';
            }

            $html .= '</tr>';
        }

        $html .= '</tbody></table>';

        foreach ($report->notes as $note) {
            $html .= '<p><i>' . $e($note) . '</i></p>';
        }

        $pdf->writeHTML($html, true, false, true, false, '');

        return $pdf->Output('report.pdf', 'S');
    }

    private static function load(string $cacheDir): void
    {
        if (class_exists(\TCPDF::class, false)) {
            return;
        }

        $base = \dirname(__DIR__, 2) . '/lib/tcpdf/';

        // Our own configuration: no remote images, no calls from HTML, fonts and cache in known places.
        \defined('K_TCPDF_EXTERNAL_CONFIG') || \define('K_TCPDF_EXTERNAL_CONFIG', true);
        \defined('K_PATH_MAIN') || \define('K_PATH_MAIN', $base);
        \defined('K_PATH_FONTS') || \define('K_PATH_FONTS', $base . 'fonts/');
        \defined('K_PATH_CACHE') || \define('K_PATH_CACHE', rtrim($cacheDir, '/') . '/');
        \defined('K_PATH_URL') || \define('K_PATH_URL', '');
        \defined('K_PATH_IMAGES') || \define('K_PATH_IMAGES', $base);
        \defined('K_BLANK_IMAGE') || \define('K_BLANK_IMAGE', '_blank.png');
        \defined('K_TCPDF_CALLS_IN_HTML') || \define('K_TCPDF_CALLS_IN_HTML', false);
        \defined('K_ALLOWED_TCPDF_TAGS') || \define('K_ALLOWED_TCPDF_TAGS', '');

        require_once $base . 'tcpdf.php';
    }
}
