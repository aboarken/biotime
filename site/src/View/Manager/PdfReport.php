<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Site\View\Manager;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Turns the Manager Dashboard report into a PDF file on the server (Export PDF, 1.2.0), with the
 * bundled mPDF (administrator/vendor): A4 or A3, always landscape, whatever the browser or the
 * printer. The HTML (tmpl/manager/pdf.php) is escaped where it is built; it names no remote
 * resource, and the only image it may contain is the logo, as a file below the site's images/.
 */
final class PdfReport
{
    /** One font family for Latin and Arabic (shaped and right to left: OTL). */
    public const FONT = 'dejavusanscondensed';

    /**
     * @param string $vendorDir administrator/components/com_biotime/vendor
     * @param string $tempDir   a writable folder for mPDF's temporary files (Joomla's tmp path)
     */
    public function __construct(private readonly string $vendorDir, private readonly string $tempDir)
    {
    }

    /** The PDF (bytes) of $html on A4 or A3 landscape. */
    public function render(string $html, string $paper, string $title): string
    {
        require_once $this->vendorDir . '/autoload.php';

        // The largest report (300 employees × 31 days on A3) needs about 160 MB and 10 seconds.
        self::atLeastMemory(512 * 1024 * 1024);

        if (!is_dir($this->tempDir)) {
            @mkdir($this->tempDir, 0755, true);
        }

        $mpdf = new \Mpdf\Mpdf([
            'mode'                 => 'utf-8',
            'format'               => ReportColumns::paper($paper) === 'a3' ? 'A3-L' : 'A4-L',
            'orientation'          => 'L',
            'tempDir'              => $this->tempDir,
            'fontDir'              => [$this->vendorDir . '/mpdf/mpdf/ttfonts'],
            'fontdata'             => [self::FONT => [
                'R'          => 'DejaVuSansCondensed.ttf',
                'B'          => 'DejaVuSansCondensed-Bold.ttf',
                'I'          => 'DejaVuSansCondensed-Oblique.ttf',
                'BI'         => 'DejaVuSansCondensed-BoldOblique.ttf',
                'useOTL'     => 0xFF,
                'useKashida' => 75,
            ]],
            'default_font'         => self::FONT,
            // The top and bottom margins (room for the letterhead and the footer) are set by the
            // report's own @page rule (tmpl/manager/pdf.php), from what its letterhead holds.
            'margin_left'          => 8,
            'margin_right'         => 8,
            // Wide tables (up to 31 days on A3) are made smaller to fit, never cut off.
            'shrink_tables_to_fit' => 3,
            'packTableData'        => true,
            'useSubstitutions'     => false,
            'showImageErrors'      => false,
        ]);

        $mpdf->SetTitle($title);
        $mpdf->SetCreator('ZKBioTime Portal (com_biotime)');

        // mPDF refuses HTML longer than pcre.backtrack_limit (1 000 000 by default), and a table
        // cannot be written in parts: 300 employees × 31 days is about 2.5 MB. Raised for this
        // report only, then restored.
        $limit = (int) ini_get('pcre.backtrack_limit');
        $need  = max($limit, 2 * \strlen($html));

        if ($need > $limit) {
            ini_set('pcre.backtrack_limit', (string) $need);
        }

        try {
            $mpdf->WriteHTML($html);

            return $mpdf->Output('', 'S');
        } finally {
            if ($need > $limit) {
                ini_set('pcre.backtrack_limit', (string) $limit);
            }
        }
    }

    /** Raises PHP's memory limit to $bytes when it is lower (never lowers it; -1 means none). */
    private static function atLeastMemory(int $bytes): void
    {
        $current = trim((string) ini_get('memory_limit'));

        if ($current === '-1') {
            return;
        }

        $value = (int) $current;
        $unit  = strtolower(substr($current, -1));
        $value *= match ($unit) {
            'g'     => 1024 ** 3,
            'm'     => 1024 ** 2,
            'k'     => 1024,
            default => 1,
        };

        if ($value < $bytes) {
            @ini_set('memory_limit', (string) $bytes);
        }
    }

    /** A file name for the download: the title without characters a file system or header could choke on. */
    public static function fileName(string $title): string
    {
        $name = trim((string) preg_replace('/[^\p{L}\p{N} ._-]+/u', ' ', $title));
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        return ($name !== '' ? mb_substr($name, 0, 120) : 'Attendance report') . '.pdf';
    }
}
