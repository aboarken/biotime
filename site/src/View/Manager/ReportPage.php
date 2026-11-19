<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Site\View\Manager;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The HTML of the report's own page (Export PDF, 1.1.2, written by RawView).
 */
final class ReportPage
{
    /**
     * The whole page: nothing but the report, its stylesheet and its script. The inline style
     * (paper and colour) carries the Content Security Policy nonce when Joomla's HTTP Headers
     * plugin sets one.
     */
    public static function html(string $title, string $langTag, bool $rtl, string $cssUrl, string $jsUrl, string $css, string $nonce, string $body): string
    {
        $e     = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $nonce = $nonce !== '' ? ' nonce="' . $e($nonce) . '"' : '';

        return '<!DOCTYPE html>' . "\n"
            . '<html lang="' . $e($langTag !== '' ? $langTag : 'en') . '" dir="' . ($rtl ? 'rtl' : 'ltr') . '">' . "\n"
            . '<head>' . "\n"
            . '<meta charset="utf-8">' . "\n"
            . '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
            . '<meta name="robots" content="noindex, nofollow">' . "\n"
            . '<title>' . $e($title) . '</title>' . "\n"
            . '<link rel="stylesheet" href="' . $e($cssUrl) . '">' . "\n"
            . '<style' . $nonce . '>' . str_replace('</', '<\/', $css) . '</style>' . "\n"
            . '<script src="' . $e($jsUrl) . '" defer></script>' . "\n"
            . '</head>' . "\n"
            . '<body class="bt-report-page">' . "\n" . $body . "\n" . '</body>' . "\n"
            . '</html>' . "\n";
    }

}
