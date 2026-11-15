<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZkbPortal\Component\Zkbiotime\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZkbPortal\Component\Zkbiotime\Administrator\Api\ZkbException;

/**
 * Every COM_ZKBIOTIME_ language key used in code, templates and XML exists in a language file,
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

    public function testEveryUsedKeyExists(): void
    {
        $strings = self::strings();
        $files   = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT, \FilesystemIterator::SKIP_DOTS));
        $missing = [];

        foreach ($files as $file) {
            $path = str_replace('\\', '/', $file->getPathname());

            if (!preg_match('/\.(php|xml)$/', $path) || preg_match('#/(vendor|tests|\.git|dist)/#', $path)) {
                continue;
            }

            preg_match_all('/\bCOM_ZKBIOTIME_[A-Z0-9_]+\b/', (string) file_get_contents($path), $m);

            foreach ($m[0] as $key) {
                // Prefixes completed at run time.
                if (\in_array($key, ['COM_ZKBIOTIME_ERROR_', 'COM_ZKBIOTIME_TEST_HINT_'], true) || str_ends_with($key, '_')) {
                    continue;
                }

                if (!isset($strings[$key])) {
                    $missing[$key] = $path;
                }
            }
        }

        self::assertSame([], $missing);
    }

    public function testEveryErrorKindHasAMessageAndAHint(): void
    {
        $strings = self::strings();

        foreach (ZkbException::KINDS as $kind) {
            $error = (new ZkbException($kind, 'x'))->getLanguageKey();
            self::assertArrayHasKey($error, $strings);
            self::assertStringContainsString('ZKBioTime', $strings[$error], $error . ' must name ZKBioTime');
            self::assertArrayHasKey('COM_ZKBIOTIME_TEST_HINT_' . strtoupper($kind), $strings);
        }
    }
}
