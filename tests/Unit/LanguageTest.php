<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Tests\Unit;

use PHPUnit\Framework\TestCase;
use BiotimePortal\Component\Biotime\Administrator\Support\SourceException;

/**
 * Every COM_BIOTIME_ language key used in code, templates and XML exists in a language file,
 * and every language file parses.
 */
final class LanguageTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /** @return array<string, string> */
    private static function strings(): array
    {
        $all = [];

        foreach (glob(self::ROOT . '/{administrator,site}/language/en-GB/*.ini', GLOB_BRACE) ?: [] as $file) {
            $parsed = parse_ini_file($file, false, INI_SCANNER_RAW);
            self::assertIsArray($parsed, 'Cannot parse ' . $file);
            $all += $parsed;
        }

        return $all;
    }

    /** @return array<string, string> */
    private static function file(string $path): array
    {
        $parsed = parse_ini_file(self::ROOT . '/' . $path, false, INI_SCANNER_RAW);
        self::assertIsArray($parsed, 'Cannot parse ' . $path);

        return $parsed;
    }

    /**
     * Keys used by code running on the site (portal) must be in the site language file; keys used
     * by the administrator pages in the administrator files; menu item XML keys in the .sys.ini.
     */
    public function testEveryUsedKeyExistsForItsClient(): void
    {
        $site  = self::file('site/language/en-GB/com_biotime.ini');
        $admin = self::file('administrator/language/en-GB/com_biotime.ini') + self::file('administrator/language/en-GB/com_biotime.sys.ini');
        $sys   = self::file('administrator/language/en-GB/com_biotime.sys.ini');

        // Shared classes that render text on the site.
        $siteShared = ['administrator/src/Support/'];
        $missing    = [];
        $root       = str_replace('\\', '/', (string) realpath(self::ROOT));
        $files      = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $checked    = 0;

        foreach ($files as $file) {
            $path = ltrim(substr(str_replace('\\', '/', $file->getPathname()), \strlen($root)), '/');

            if (!preg_match('/\.(php|xml)$/', $path) || preg_match('#^(vendor|tests|dist|build|\.git)/|/lib/#', $path) || $path === 'biotime.xml') {
                continue;
            }

            preg_match_all('/\bCOM_BIOTIME_[A-Z0-9_]+\b/', (string) file_get_contents($file->getPathname()), $m);

            $isMenuXml = str_starts_with($path, 'site/tmpl/') && str_ends_with($path, '.xml');
            $isSite    = !$isMenuXml && (str_starts_with($path, 'site/') || \in_array(true, array_map(static fn ($p) => str_starts_with($path, $p), $siteShared), true));
            $isAdmin   = str_starts_with($path, 'administrator/') || $path === 'script.php';

            $checked += ($isSite || $isAdmin || $isMenuXml) ? 1 : 0;

            foreach (array_unique($m[0]) as $key) {
                if (str_ends_with($key, '_')) {
                    continue; // prefix completed at run time
                }

                if ($isMenuXml && !isset($sys[$key])) {
                    $missing[$key] = $path . ' (sys.ini)';
                }

                if ($isSite && !isset($site[$key])) {
                    $missing[$key] = $path . ' (site)';
                }

                if ($isAdmin && !$isSite && !isset($admin[$key])) {
                    $missing[$key] = $path . ' (administrator)';
                }
            }
        }

        self::assertGreaterThan(50, $checked, 'the scan must actually reach the component files');
        self::assertSame([], $missing);
    }

    public function testRunTimeKeysExist(): void
    {
        $site  = self::file('site/language/en-GB/com_biotime.ini');
        $admin = self::file('administrator/language/en-GB/com_biotime.ini');
        $need  = [];

        foreach (\BiotimePortal\Component\Biotime\Administrator\Attendance\Day::EXCEPTIONS as $ex) {
            $need[] = ['COM_BIOTIME_EXCEPTION_' . strtoupper($ex), $site];
        }

        foreach (\BiotimePortal\Component\Biotime\Administrator\Attendance\Day::STATUSES as $st) {
            $need[] = ['COM_BIOTIME_STATUS_' . strtoupper($st), $site];
        }



        foreach (['PUNCHES', 'DAILY'] as $part) {
            $need[] = ['COM_BIOTIME_PART_' . $part, $site];
        }

        $need[] = ['COM_BIOTIME_SETTINGS_FIELDSET_DATABASE_HELP', $admin];

        foreach (\BiotimePortal\Component\Biotime\Administrator\Database\DbException::KINDS as $kind) {
            $need[] = ['COM_BIOTIME_DB_ERR_' . strtoupper($kind), $admin];
            $need[] = ['COM_BIOTIME_DB_HINT_' . strtoupper($kind), $admin];
        }

        foreach (\BiotimePortal\Component\Biotime\Administrator\Identity\UserMapping::STATUSES as $st) {
            $need[] = ['COM_BIOTIME_MAPSTATUS_' . strtoupper($st), $admin];
        }

        $missing = array_values(array_filter(array_map(static fn ($n) => isset($n[1][$n[0]]) ? null : $n[0], $need)));
        self::assertSame([], $missing);
    }

    public function testEveryErrorKindHasAPortalMessage(): void
    {
        $strings = self::strings();

        $admin = self::file('administrator/language/en-GB/com_biotime.ini');
        $site  = self::file('site/language/en-GB/com_biotime.ini');

        foreach (SourceException::KINDS as $kind) {
            $error = (new SourceException($kind, 'x'))->getLanguageKey();
            self::assertArrayHasKey($error, $admin, 'User Mappings needs ' . $error);
            self::assertStringContainsString('ZKBioTime', $admin[$error], $error . ' must name ZKBioTime');
            self::assertArrayHasKey($error, $site, 'portal needs ' . $error);
        }

        // Every database error is shown as one of these kinds on the portal pages.
        foreach (\BiotimePortal\Component\Biotime\Administrator\Database\DbException::KINDS as $kind) {
            self::assertContains(SourceException::kindOf(new \BiotimePortal\Component\Biotime\Administrator\Database\DbException($kind, 'x')), SourceException::KINDS);
        }
    }
}
