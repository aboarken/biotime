<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Tests\Unit;

use BiotimePortal\Component\Biotime\Administrator\Api\ClientConfig;
use BiotimePortal\Component\Biotime\Administrator\Api\Transport\CurlTransport;
use BiotimePortal\Component\Biotime\Administrator\Api\Transport\TransportException;
use PHPUnit\Framework\TestCase;

/**
 * The cURL transport against a local PHP server: methods, headers, body, status codes, network
 * errors and no redirects, with one transport (one cURL handle) for several requests.
 */
final class CurlTransportTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;

    private static int $port = 0;

    public static function setUpBeforeClass(): void
    {
        $socket     = stream_socket_server('tcp://127.0.0.1:0');
        self::$port = (int) substr((string) stream_socket_get_name($socket, false), strrpos((string) stream_socket_get_name($socket, false), ':') + 1);
        fclose($socket);

        $router       = __DIR__ . '/../Support/http/router.php';
        self::$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . self::$port, $router], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);

        for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', self::$port) === false; $i++) {
            usleep(100_000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (\is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
    }

    private function transport(string $url = ''): CurlTransport
    {
        return new CurlTransport(new ClientConfig($url !== '' ? $url : 'http://127.0.0.1:' . self::$port, 0, 10, 5, true, '', '', ''));
    }

    public function testSeveralRequestsOverOneTransport(): void
    {
        $t    = $this->transport();
        $base = 'http://127.0.0.1:' . self::$port;

        $get = $t->send('GET', $base . '/personnel/api/employees/?page=1&page_size=1', ['Authorization' => 'JWT abc'], null);
        self::assertSame(200, $get->status);
        self::assertSame(['method' => 'GET', 'path' => '/personnel/api/employees/', 'query' => ['page' => '1', 'page_size' => '1'], 'auth' => 'JWT abc', 'body' => ''], json_decode($get->body, true));

        $post = $t->send('POST', $base . '/jwt-api-token-auth/', ['Content-Type' => 'application/json'], '{"username":"u"}');
        self::assertSame(['POST', '{"username":"u"}'], [json_decode($post->body, true)['method'], json_decode($post->body, true)['body']]);

        $again = $t->send('GET', $base . '/iclock/api/transactions/', [], null);
        self::assertSame('GET', json_decode($again->body, true)['method'], 'a GET after a POST is a GET again');

        self::assertSame(404, $t->send('GET', $base . '/missing', [], null)->status);
        self::assertSame(302, $t->send('GET', $base . '/redirect', [], null)->status, 'redirects are not followed');
    }

    public function testUnreachableServerIsATransportError(): void
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionMessageMatches('/^Could not reach ZKBioTime \(connection failed: /');

        $this->transport('http://127.0.0.1:1')->send('GET', 'http://127.0.0.1:1/x', [], null);
    }

    public function testOnlyGetAndPost(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->transport()->send('DELETE', 'http://127.0.0.1:' . self::$port . '/x', [], null);
    }
}
