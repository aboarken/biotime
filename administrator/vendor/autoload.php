<?php

/**
 * Class loader of the bundled PDF library (administrator/vendor, copied by build/vendor-pdf.sh):
 * mPDF and the classes it needs. Only these namespaces; psr/log and psr/http-message come from
 * Joomla itself, so no second copy of them can be loaded.
 *
 * @license GNU General Public License version 2 or later (this file); the libraries keep their own
 *          licences (see the LICENSE files next to them)
 */

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

if (!\defined('BIOTIME_PDF_AUTOLOAD')) {
    \define('BIOTIME_PDF_AUTOLOAD', 1);

    spl_autoload_register(static function (string $class): void {
        // Longest prefixes first: Mpdf\PsrLogAwareTrait\ before Mpdf\.
        static $map = [
            'Mpdf\\PsrLogAwareTrait\\'   => __DIR__ . '/mpdf/psr-log-aware-trait/src/',
            'Mpdf\\PsrHttpMessageShim\\' => __DIR__ . '/mpdf/psr-http-message-shim/src/',
            'Mpdf\\'                     => __DIR__ . '/mpdf/mpdf/src/',
            'setasign\\Fpdi\\'           => __DIR__ . '/setasign/fpdi/src/',
            'DeepCopy\\'                 => __DIR__ . '/myclabs/deep-copy/src/DeepCopy/',
        ];

        foreach ($map as $prefix => $dir) {
            if (str_starts_with($class, $prefix)) {
                $file = $dir . str_replace('\\', '/', substr($class, \strlen($prefix))) . '.php';

                if (is_file($file)) {
                    require $file;
                }

                return;
            }
        }
    });

    require_once __DIR__ . '/mpdf/mpdf/src/functions.php';
    require_once __DIR__ . '/myclabs/deep-copy/src/DeepCopy/deep_copy.php';
}
