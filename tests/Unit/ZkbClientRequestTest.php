<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use BiotimePortal\Component\Biotime\Administrator\Api\CachedToken;
use BiotimePortal\Component\Biotime\Administrator\Api\Transport\TransportException;
use BiotimePortal\Component\Biotime\Administrator\Api\Transport\TransportResponse;
use BiotimePortal\Component\Biotime\Administrator\Api\ZkbClient;
use BiotimePortal\Component\Biotime\Administrator\Api\ZkbException;
use BiotimePortal\Component\Biotime\Tests\Support\ClientBuilder;

/**
 * Error mapping, retries, paging and input validation.
 */
final class ZkbClientRequestTest extends TestCase
{
    private ClientBuilder $b;

    protected function setUp(): void
    {
        $this->b = new ClientBuilder();
        // A valid cached token, so these tests start with the data call.
        $this->b->tokens->save(new CachedToken('cached.jwt.token', $this->b->now + 3000));
    }

    /** @return array<string, array{int, string}> */
    public static function statuses(): array
    {
        return [
            '400 validation'   => [400, ZkbException::VALIDATION],
            '403 permission'   => [403, ZkbException::PERMISSION],
            '404 not found'    => [404, ZkbException::NOT_FOUND],
            '405 not found'    => [405, ZkbException::NOT_FOUND],
            '422 validation'   => [422, ZkbException::VALIDATION],
            '429 rate limited' => [429, ZkbException::RATE_LIMITED],
            '503 server'       => [503, ZkbException::SERVER],
        ];
    }

    #[DataProvider('statuses')]
    public function testHttpErrorsMapToKinds(int $status, string $kind): void
    {
        // A server error is retried once for a GET.
        $this->b->transport->json(['detail' => 'x'], $status);

        if ($status >= 500) {
            $this->b->transport->json(['detail' => 'x'], $status);
        }

        try {
            $this->b->client()->get('/personnel/api/employees/');
            self::fail('Expected an exception');
        } catch (ZkbException $e) {
            self::assertSame($kind, $e->getKind());
            self::assertSame($status, $e->getHttpStatus());
            self::assertSame('COM_BIOTIME_ERROR_' . strtoupper($kind), $e->getLanguageKey());
        }
    }

    public function testEveryKindHasItsOwnLanguageKey(): void
    {
        $keys = array_map(static fn (string $k): string => (new ZkbException($k, 'x'))->getLanguageKey(), ZkbException::KINDS);

        self::assertCount(9, array_unique($keys));
    }

    public function testNetworkErrorOnGetIsRetriedOnce(): void
    {
        $this->b->transport
            ->queue(new TransportException('Could not reach ZKBioTime (connection failed: refused)'))
            ->json(['count' => 0, 'next' => null, 'data' => []]);

        $this->b->client()->get('/personnel/api/employees/');

        self::assertCount(2, $this->b->transport->requests);
        self::assertTrue($this->b->logger->has('warning', '(trying again)'));
    }

    public function testNetworkErrorTwiceIsFinal(): void
    {
        $this->b->transport->queue(new TransportException('timeout'), new TransportException('timeout'));

        try {
            $this->b->client()->get('/personnel/api/employees/');
            self::fail('Expected an exception');
        } catch (ZkbException $e) {
            self::assertSame(ZkbException::NETWORK, $e->getKind());
        }

        self::assertCount(2, $this->b->transport->requests);
    }

    public function testServerErrorThenSuccessIsRetried(): void
    {
        $this->b->transport->json(['detail' => 'boom'], 502)->json(['data' => [], 'next' => null]);

        $this->b->client()->get('/personnel/api/employees/');

        self::assertCount(2, $this->b->transport->requests);
    }

    public function testClientErrorsAreNotRetried(): void
    {
        $this->b->transport->json(['detail' => 'nope'], 403);

        try {
            $this->b->client()->get('/personnel/api/employees/');
        } catch (ZkbException) {
        }

        self::assertCount(1, $this->b->transport->requests);
    }

    public function testNonJsonBodyIsUnreadable(): void
    {
        $this->b->transport->queue(new TransportResponse(200, '<html>Login</html>'));

        try {
            $this->b->client()->get('/personnel/api/employees/');
            self::fail('Expected an exception');
        } catch (ZkbException $e) {
            self::assertSame(ZkbException::BAD_RESPONSE, $e->getKind());
        }
    }

    public function testRedirectIsUnreadable(): void
    {
        $this->b->transport->queue(new TransportResponse(302, ''));

        $this->expectExceptionObject(new ZkbException(ZkbException::BAD_RESPONSE, 'Unexpected redirect from ZKBioTime'));
        $this->b->client()->get('/personnel/api/employees/');
    }

    public function testNonZeroCodeWithoutDataIsRefused(): void
    {
        $this->b->transport->json(['code' => 1, 'msg' => 'Parameter error']);

        try {
            $this->b->client()->get('/personnel/api/employees/');
            self::fail('Expected an exception');
        } catch (ZkbException $e) {
            self::assertSame(ZkbException::VALIDATION, $e->getKind());
            self::assertSame('Parameter error', $e->getServerMessage());
        }
    }

    public function testPagingReadsUntilNextIsEmpty(): void
    {
        $this->b->transport
            ->json(['count' => 5, 'next' => 'https://x/?page=2', 'previous' => null, 'data' => [['id' => 1], ['id' => 2]]])
            ->json(['count' => 5, 'next' => 'https://x/?page=3', 'previous' => 'p', 'data' => [['id' => 3], ['id' => 4]]])
            ->json(['count' => 5, 'next' => null, 'previous' => 'p', 'data' => [['id' => 5]]]);

        $result = $this->b->client(pageSize: 2)->listAll('/iclock/api/transactions/', ['emp_code' => 'E001']);

        self::assertSame([1, 2, 3, 4, 5], array_column($result->items, 'id'));
        self::assertSame(5, $result->count);
        self::assertFalse($result->truncated);
        self::assertSame(3, $result->pagesRead);
        self::assertSame(['page' => '2', 'page_size' => '2', 'emp_code' => 'E001'], $this->b->transport->query(1));
        self::assertTrue($this->b->logger->has('info', '"data" (as documented)'));
    }

    public function testPagingStopsAtSafetyMaximumAndSaysSo(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->b->transport->json(['count' => 100, 'next' => 'more', 'data' => [['id' => $i]]]);
        }

        $result = $this->b->client(pageSize: 1, maxPages: 3)->listAll('/personnel/api/employees/');

        self::assertCount(3, $result->items);
        self::assertTrue($result->truncated);
        self::assertSame(0, $this->b->transport->remaining());
        self::assertTrue($this->b->logger->has('warning', 'safety maximum'));
    }

    public function testPagingStopsAtMaxItems(): void
    {
        $this->b->transport->json(['count' => 10, 'next' => 'more', 'data' => [['id' => 1], ['id' => 2], ['id' => 3]]]);

        $result = $this->b->client(pageSize: 3)->listAll('/personnel/api/employees/', [], 2);

        self::assertSame([1, 2], array_column($result->items, 'id'));
        self::assertTrue($result->truncated);
    }

    public function testPagingUsesCountWhenNextIsMissing(): void
    {
        $this->b->transport
            ->json(['count' => 3, 'data' => [['id' => 1], ['id' => 2]]])
            ->json(['count' => 3, 'data' => [['id' => 3]]]);

        $result = $this->b->client(pageSize: 2)->listAll('/personnel/api/employees/');

        self::assertCount(3, $result->items);
        self::assertFalse($result->truncated);
    }

    public function testResultsKeyIsAcceptedAndLogged(): void
    {
        $this->b->transport->json(['count' => 1, 'next' => null, 'results' => [['id' => 9]]]);

        $page = $this->b->client()->listPage('/personnel/api/employees/', [], 1);

        self::assertSame([['id' => 9]], $page->items);
        self::assertTrue($this->b->logger->has('info', '"results" (not as documented)'));
    }

    public function testBareListIsAccepted(): void
    {
        $this->b->transport->json([['id' => 1], ['id' => 2], 'junk']);

        $page = $this->b->client()->listPage('/personnel/api/employees/', [], 1);

        self::assertCount(2, $page->items);
        self::assertFalse($page->hasNext);
    }

    public function testListWithoutCollectionIsUnreadable(): void
    {
        $this->b->transport->json(['count' => 1, 'rows' => []]);

        $this->expectExceptionObject(new ZkbException(ZkbException::BAD_RESPONSE, 'Unexpected list response shape'));
        $this->b->client()->listPage('/personnel/api/employees/', [], 1);
    }

    public function testEmptyPageEndsPagingEvenIfNextIsSet(): void
    {
        $this->b->transport->json(['count' => 9, 'next' => 'more', 'data' => []]);

        $result = $this->b->client()->listAll('/personnel/api/employees/');

        self::assertSame([], $result->items);
        self::assertFalse($result->truncated);
    }

    /** @return array<string, array{string}> */
    public static function badPaths(): array
    {
        return [
            'no trailing slash' => ['/personnel/api/employees'],
            'traversal'         => ['/personnel/../admin/'],
            'query in path'     => ['/personnel/api/employees/?x=1'],
            'absolute url'      => ['https://evil.example/'],
            'empty'             => [''],
        ];
    }

    #[DataProvider('badPaths')]
    public function testInvalidPathsAreRefusedBeforeAnyRequest(string $path): void
    {
        try {
            $this->b->client()->get($path);
            self::fail('Expected an exception');
        } catch (\InvalidArgumentException) {
        }

        self::assertSame([], $this->b->transport->requests);
    }

    public function testInvalidQueryParametersAreRefused(): void
    {
        foreach ([['Bad-Key' => 'x'], ['ok' => "line\nbreak"], ['ok' => str_repeat('a', 201)], ['ok' => ['nested']]] as $query) {
            try {
                $this->b->client()->get('/personnel/api/employees/', $query);
                self::fail('Expected an exception for ' . json_encode($query));
            } catch (\InvalidArgumentException) {
            }
        }

        self::assertSame([], $this->b->transport->requests);
    }

    public function testQueryValuesAreEncoded(): void
    {
        $this->b->transport->json(['data' => [], 'next' => null]);

        $this->b->client()->get('/personnel/api/employees/', ['email' => 'a+b@example.com']);

        self::assertStringContainsString('email=a%2Bb%40example.com', $this->b->transport->requests[0]['url']);
    }

    public function testIdValidation(): void
    {
        self::assertSame('42', ZkbClient::id(42));
        self::assertSame('12345678901234567890', ZkbClient::id('12345678901234567890'));

        foreach (['0', '-1', '1.5', '1 OR 1=1', '../1', '', '012', '123456789012345678901'] as $bad) {
            try {
                ZkbClient::id($bad);
                self::fail('Accepted ' . $bad);
            } catch (\InvalidArgumentException) {
            }
        }
    }

    public function testEmployeeCodeValidation(): void
    {
        self::assertSame('E-001.a_b', ZkbClient::empCode('E-001.a_b'));

        foreach (['', ' E1', 'E1;', '../E1', 'E1&page_size=1000', str_repeat('9', 31), '-E1'] as $bad) {
            try {
                ZkbClient::empCode($bad);
                self::fail('Accepted ' . $bad);
            } catch (\InvalidArgumentException) {
            }
        }
    }

    public function testLogLinesNameQueryKeysButNotValues(): void
    {
        $this->b->transport->json(['data' => [], 'next' => null]);

        $this->b->client()->get('/personnel/api/employees/', ['emp_code' => 'E777', 'email' => 'person@example.com']);

        $log = $this->b->logger->text();
        self::assertStringContainsString('[emp_code,email]', $log);
        self::assertStringNotContainsString('E777', $log);
        self::assertStringNotContainsString('person@example.com', $log);
    }
}
