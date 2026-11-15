<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Export;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects


/**
 * Writes a ReportTable as an Office Open XML workbook (.xlsx) with two sheets (Summary, Days).
 * Every cell is an inline string, so nothing can be read as a formula.
 */
final class XlsxWriter
{
    /**
     * @throws \RuntimeException when ZipArchive is missing or the file cannot be written
     */
    public function write(ReportTable $report): string
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('The PHP zip extension is needed for Excel exports');
        }

        $file = tempnam(sys_get_temp_dir(), 'bxl');

        if ($file === false) {
            throw new \RuntimeException('No temporary file');
        }

        $zip = new \ZipArchive();

        if ($zip->open($file, \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Cannot create the workbook');
        }

        $summary = array_merge([[$report->title], [$report->subtitle], []], array_map(static fn (array $p): array => [$p[0], $p[1]], $report->summary));

        foreach ($report->notes as $note) {
            $summary[] = [$note];
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Summary" sheetId="1" r:id="rId1"/><sheet name="Days" sheetId="2" r:id="rId2"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs>'
            . '<cellXfs count="2"><xf fontId="0"/><xf fontId="1" applyFont="1"/></cellXfs></styleSheet>');
        $zip->addFromString('xl/worksheets/sheet1.xml', self::sheet($summary, 0));
        $zip->addFromString('xl/worksheets/sheet2.xml', self::sheet(array_merge([$report->headers], $report->rows), 1));
        $zip->close();

        $data = (string) file_get_contents($file);
        @unlink($file);

        return $data;
    }

    /**
     * @param list<list<string>> $rows
     */
    private static function sheet(array $rows, int $boldRows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        foreach ($rows as $r => $cells) {
            $xml .= '<row r="' . ($r + 1) . '">';

            foreach (array_values($cells) as $c => $text) {
                $style = $r < $boldRows ? ' s="1"' : '';
                $xml  .= '<c r="' . self::column($c) . ($r + 1) . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">' . self::xml($text) . '</t></is></c>';
            }

            $xml .= '</row>';
        }

        return $xml . '</sheetData></worksheet>';
    }

    private static function column(int $index): string
    {
        $name = '';

        for ($i = $index + 1; $i > 0; $i = intdiv($i - 1, 26)) {
            $name = \chr(65 + ($i - 1) % 26) . $name;
        }

        return $name;
    }

    private static function xml(string $text): string
    {
        $text = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text) ?? '';

        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
