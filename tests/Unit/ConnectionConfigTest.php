<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZkbPortal\Component\Zkbiotime\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZkbPortal\Component\Zkbiotime\Administrator\Api\ClientConfig;
use ZkbPortal\Component\Zkbiotime\Administrator\Api\Redactor;
use ZkbPortal\Component\Zkbiotime\Administrator\Api\SslPin;

final class ConnectionConfigTest extends TestCase
{
    private const PIN = 'sha256//AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';

    public function testBaseUrlCombinesUrlAndPort(): void
    {
        self::assertSame('https://biotime.example.com:8443', ClientConfig::buildBaseUrl('https://BioTime.example.com/', 8443));
        self::assertSame('http://10.0.0.5:8081', ClientConfig::buildBaseUrl('http://10.0.0.5:8081'));
        self::assertSame('http://10.0.0.5:8081', ClientConfig::buildBaseUrl('http://10.0.0.5:8081', 8081));
        self::assertSame('https://biotime.example.com', ClientConfig::buildBaseUrl('https://biotime.example.com', 443));
        self::assertSame('https://example.com/biotime', ClientConfig::buildBaseUrl('https://example.com/biotime/'));
    }

    public function testInvalidUrlsAreRefused(): void
    {
        $bad = [
            ['', 0], ['biotime.example.com', 0], ['ftp://x', 0], ['https://u:p@x', 0], ['https://x/?a=1', 0],
            ['https://x/#f', 0], ['https://x/a b', 0], ['https://x', 70000], ['https://x:8081', 8082],
        ];

        foreach ($bad as [$url, $port]) {
            try {
                ClientConfig::buildBaseUrl($url, $port);
                self::fail('Accepted ' . $url . ' / ' . $port);
            } catch (\InvalidArgumentException) {
            }
        }

        self::assertTrue(true);
    }

    public function testTimeoutsAndPinRules(): void
    {
        $config = new ClientConfig('https://x.example', 0, 30, 5, true, '', self::PIN);
        self::assertTrue($config->isPinned());
        self::assertSame(443, $config->port());
        self::assertSame('x.example', $config->host());

        foreach ([[0, 5], [301, 5], [30, 0], [10, 20], [30, 61]] as [$total, $connect]) {
            try {
                new ClientConfig('https://x.example', 0, $total, $connect);
                self::fail("Accepted timeouts $total/$connect");
            } catch (\InvalidArgumentException) {
            }
        }

        $this->expectException(\InvalidArgumentException::class);
        new ClientConfig('http://x.example', 0, 30, 5, true, '', self::PIN);
    }

    public function testPinNormalisation(): void
    {
        self::assertSame('', SslPin::normalize('  '));
        self::assertSame(self::PIN, SslPin::normalize(' ' . self::PIN . ' ; ' . self::PIN));

        $this->expectException(\InvalidArgumentException::class);
        SslPin::normalize('sha256//short');
    }

    public function testRedactorRemovesSecretsAndTokens(): void
    {
        $text = 'Authorization: JWT eyJhbGciOi.eyJleHAiOjF9.c2ln password=hunter22 {"token": "abcdef123456"} Token 0123456789abcdef raw-secret';
        $out  = Redactor::scrub($text, ['raw-secret']);

        foreach (['eyJhbGciOi', 'hunter22', 'abcdef123456', '0123456789abcdef', 'raw-secret'] as $secret) {
            self::assertStringNotContainsString($secret, $out);
        }

        self::assertSame(301, mb_strlen(Redactor::scrub(str_repeat('a', 500))));
        self::assertSame('a b', Redactor::scrub("<b>a</b>\n\n b"));
    }
}
