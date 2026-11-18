<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Tests\Unit;

use BiotimePortal\Component\Biotime\Administrator\Security\KeyVault;
use BiotimePortal\Component\Biotime\Administrator\Settings\Settings;
use BiotimePortal\Component\Biotime\Administrator\Settings\SettingsValidator;
use BiotimePortal\Component\Biotime\Administrator\Settings\ValidationFailed;
use BiotimePortal\Component\Biotime\Site\View\Manager\ReportColumns;
use PHPUnit\Framework\TestCase;

/**
 * Export PDF of the Manager Dashboard (1.0.11): the paper and the letterhead settings.
 */
final class ReportTest extends TestCase
{
    /** @return list<string> */
    private static function days(int $n): array
    {
        return array_map(static fn (int $i): string => \sprintf('2026-10-%02d', $i), range(1, $n));
    }

    public function testPortraitWhenTheDatesFitOtherwiseBalancedLandscapeTables(): void
    {
        self::assertSame([false, [self::days(1)]], ReportColumns::of(self::days(1)));
        self::assertSame([false, [self::days(6)]], ReportColumns::of(self::days(6)));
        self::assertSame([true, [self::days(7)]], ReportColumns::of(self::days(7)));
        self::assertSame([true, [self::days(12)]], ReportColumns::of(self::days(12)));
        self::assertSame([7, 6], array_map('count', ReportColumns::of(self::days(13))[1]));
        self::assertSame([8, 8], array_map('count', ReportColumns::of(self::days(16))[1]));
        self::assertSame([11, 11, 9], array_map('count', ReportColumns::of(self::days(31))[1]), 'every date once, in order');
        self::assertSame(self::days(31), array_merge(...ReportColumns::of(self::days(31))[1]));
        self::assertSame([false, [[]]], ReportColumns::of([]));
    }

    private function validate(array $report): array
    {
        $validator = new SettingsValidator(static fn (string $p, string $c): string => 'enc', static fn (): bool => true);

        return $validator->validate($report + [
            'connect_timeout' => '5', 'timeout' => '30', 'detailed_log' => '1', 'cache_minutes' => '5',
            'date_format' => 'Y-m-d', 'time_format' => 'H:i', 'server_timezone' => '', 'weekend_days' => ['5', '6'],
            'match_field' => 'employeeid', 'auto_match' => '1', 'module_charts' => '1',
        ], new Settings());
    }

    /** @return list<string> */
    private function errors(array $report): array
    {
        try {
            $this->validate($report);
        } catch (ValidationFailed $e) {
            return $e->getKeys();
        }

        return [];
    }

    public function testLetterheadSettingsAreCleaned(): void
    {
        $v = $this->validate([
            'report_logo'         => 'images/school/letter head.png#joomlaImage://local-images/school/letter head.png?width=815&height=112',
            'report_header_left'  => "  L . C . C . NO.\t5203206 <b>x</b> ",
            'report_header_right' => 'ترخيص وزارة التعليم رقم 5203206',
            'report_footer'       => "Tel: 6977033\r\n\r\n  https://www.tis.edu.sa   info@tis.edu.sa \n<script>x</script>\n4\n5\n6\n7",
            'report_color'        => '#1D3F74',
        ]);

        self::assertSame('images/school/letter head.png', $v['report_logo'], 'without the Media field fragment');
        self::assertSame('L . C . C . NO. 5203206 x', $v['report_header_left'], 'no markup, one line');
        self::assertSame('ترخيص وزارة التعليم رقم 5203206', $v['report_header_right']);
        self::assertSame("Tel: 6977033\nhttps://www.tis.edu.sa info@tis.edu.sa\nx\n4\n5\n6", $v['report_footer'], 'no markup, no empty lines, at most 6');
        self::assertSame('#1d3f74', $v['report_color']);

        $head = (new Settings($v))->letterhead();
        self::assertSame(['Tel: 6977033', 'https://www.tis.edu.sa info@tis.edu.sa', 'x', '4', '5', '6'], $head['footer']);
        self::assertSame('images/school/letter head.png', $head['logo']);
    }

    public function testOnlyImagesOfTheImagesFolderAndRealColours(): void
    {
        foreach (['../configuration.php', 'images/../configuration.php', 'images/x.php', '/etc/passwd', 'https://evil.example/x.png', 'media/x.png', 'images/a"b.png', 'images/./x.png'] as $bad) {
            self::assertContains('COM_BIOTIME_SETTINGS_ERR_REPORT_LOGO', $this->errors(['report_logo' => $bad]), $bad);
            self::assertFalse(Settings::isReportLogo($bad), $bad);
        }

        self::assertContains('COM_BIOTIME_SETTINGS_ERR_REPORT_COLOR', $this->errors(['report_color' => 'red;}body{x']));
        self::assertSame([], $this->errors(['report_logo' => '', 'report_color' => '']), 'both may be empty');
        self::assertSame('#1d3f74', $this->validate(['report_color' => ''])['report_color'], 'empty: the default colour');

        // A value stored some other way is not used.
        $head = (new Settings(['report_logo' => 'images/../x.png', 'report_color' => 'url(x)']))->letterhead();
        self::assertSame('', $head['logo']);
        self::assertSame('#1d3f74', $head['color']);
        self::assertSame([], (new Settings())->letterhead()['footer']);
    }
}
