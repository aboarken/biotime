<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZkbPortal\Component\Zkbiotime\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZkbPortal\Component\Zkbiotime\Administrator\Api\CachedToken;
use ZkbPortal\Component\Zkbiotime\Administrator\Api\Credentials;
use ZkbPortal\Component\Zkbiotime\Administrator\Api\Transport\TransportException;
use ZkbPortal\Component\Zkbiotime\Administrator\Api\ZkbClient;
use ZkbPortal\Component\Zkbiotime\Administrator\Api\ZkbException;
use ZkbPortal\Component\Zkbiotime\Tests\Support\ClientBuilder;

/**
 * Authentication, token caching and renewal.
 */
final class ZkbClientAuthTest extends TestCase
{
    private ClientBuilder $b;

    protected function setUp(): void
    {
        $this->b = new ClientBuilder();
    }

    private function list(): array
    {
        return ['count' => 0, 'next' => null, 'previous' => null, 'data' => []];
    }

    public function testJwtLoginSendsCredentialsAsJsonAndUsesJwtHeader(): void
    {
        $jwt = ClientBuilder::jwt($this->b->now + 3600);
        $this->b->transport->json(['token' => $jwt])->json($this->list());

        $this->b->client()->get('/personnel/api/employees/');

        $login = $this->b->transport->requests[0];
        self::assertSame('POST', $login['method']);
        self::assertSame('https://biotime.example.com:8443/jwt-api-token-auth/', $login['url']);
        self::assertSame('application/json', $login['headers']['Content-Type']);
        self::assertSame(['username' => 'apiuser', 'password' => ClientBuilder::PASSWORD], json_decode((string) $login['body'], true));
        self::assertArrayNotHasKey('Authorization', $login['headers']);

        $read = $this->b->transport->requests[1];
        self::assertSame('GET', $read['method']);
        self::assertSame('JWT ' . $jwt, $read['headers']['Authorization']);
    }

    public function testGeneralTokenLoginUsesTokenEndpointAndTokenHeader(): void
    {
        $this->b->transport->json(['token' => 'abcdef0123456789abcdef0123456789'])->json($this->list());

        $this->b->client(new Credentials(Credentials::METHOD_TOKEN, 'apiuser', ClientBuilder::PASSWORD))->get('/personnel/api/employees/');

        self::assertSame('/api-token-auth/', $this->b->transport->path(0));
        self::assertSame('Token abcdef0123456789abcdef0123456789', $this->b->transport->requests[1]['headers']['Authorization']);
    }

    public function testStaticTokenNeedsNoLogin(): void
    {
        $this->b->transport->json($this->list());

        $this->b->client(new Credentials(Credentials::METHOD_TOKEN, '', '', 'static-token-0123456789'))->get('/personnel/api/employees/');

        self::assertCount(1, $this->b->transport->requests);
        self::assertSame('Token static-token-0123456789', $this->b->transport->requests[0]['headers']['Authorization']);
    }

    public function testTokenIsCachedBetweenCallsAndClients(): void
    {
        $this->b->transport->json(['token' => ClientBuilder::jwt($this->b->now + 3600)])->json($this->list())->json($this->list())->json($this->list());

        $this->b->client()->get('/personnel/api/employees/');
        $this->b->client()->get('/personnel/api/employees/');
        $this->b->client()->get('/personnel/api/employees/');

        self::assertCount(4, $this->b->transport->requests, 'one login, three reads');
        self::assertSame(0, $this->b->transport->remaining());
    }

    public function testTokenIsRenewedShortlyBeforeItExpires(): void
    {
        $first  = ClientBuilder::jwt($this->b->now + 600, 'first');
        $second = ClientBuilder::jwt($this->b->now + 1200, 'second');
        $this->b->transport->json(['token' => $first])->json($this->list())->json(['token' => $second])->json($this->list());

        $client = $this->b->client();
        $client->get('/personnel/api/employees/');

        // 30 s before expiry: inside the 60 s renewal margin.
        $this->b->now += 570;
        $client->get('/personnel/api/employees/');

        self::assertSame('/jwt-api-token-auth/', $this->b->transport->path(2));
        self::assertSame('JWT ' . $second, $this->b->transport->requests[3]['headers']['Authorization']);
    }

    public function testTokenStillValidOutsideTheMarginIsReused(): void
    {
        $this->b->transport->json(['token' => ClientBuilder::jwt($this->b->now + 600)])->json($this->list())->json($this->list());

        $client = $this->b->client();
        $client->get('/personnel/api/employees/');
        $this->b->now += 500;
        $client->get('/personnel/api/employees/');

        self::assertCount(3, $this->b->transport->requests);
    }

    public function testJwtWithoutExpiryUsesShortDefaultLifetime(): void
    {
        $this->b->transport->json(['token' => 'no.expiry-claim.here0123'])->json($this->list());
        $this->b->client()->get('/personnel/api/employees/');

        $cached = $this->b->tokens->load();
        self::assertInstanceOf(CachedToken::class, $cached);
        self::assertSame($this->b->now + 240, $cached->renewAt, '300 s default minus 60 s margin');
        self::assertTrue($this->b->logger->has('info', 'default lifetime used'));
    }

    public function testUnauthorisedAnswerRenewsTokenOnceAndRetries(): void
    {
        $this->b->tokens->save(new CachedToken('revoked.token.value', $this->b->now + 3000));
        $fresh = ClientBuilder::jwt($this->b->now + 3600, 'fresh');
        $this->b->transport
            ->json(['detail' => 'Signature has expired.'], 401)
            ->json(['token' => $fresh])
            ->json($this->list());

        $data = $this->b->client()->get('/personnel/api/employees/');

        self::assertSame([], $data['data']);
        self::assertSame('JWT revoked.token.value', $this->b->transport->requests[0]['headers']['Authorization']);
        self::assertSame('/jwt-api-token-auth/', $this->b->transport->path(1));
        self::assertSame('JWT ' . $fresh, $this->b->transport->requests[2]['headers']['Authorization']);
        self::assertSame($fresh, $this->b->tokens->load()?->token);
    }

    public function testSecondUnauthorisedAnswerIsFinal(): void
    {
        $this->b->transport
            ->json(['token' => ClientBuilder::jwt($this->b->now + 3600, 'a')])
            ->json(['detail' => 'no'], 401)
            ->json(['token' => ClientBuilder::jwt($this->b->now + 3600, 'b')])
            ->json(['detail' => 'still no'], 401);

        try {
            $this->b->client()->get('/personnel/api/employees/');
            self::fail('Expected an exception');
        } catch (ZkbException $e) {
            self::assertSame(ZkbException::AUTH, $e->getKind());
            self::assertSame('COM_ZKBIOTIME_ERROR_AUTH', $e->getLanguageKey());
        }

        self::assertCount(4, $this->b->transport->requests, 'login, read, one renewal, one retry; nothing more');
    }

    public function testStaticTokenIsNotRenewedOnUnauthorised(): void
    {
        $this->b->transport->json(['detail' => 'Invalid token.'], 401);

        $this->expectExceptionObject(new ZkbException(ZkbException::AUTH, 'ZKBioTime returned an error (auth)'));

        try {
            $this->b->client(new Credentials(Credentials::METHOD_TOKEN, '', '', 'static-token-0123456789'))->get('/personnel/api/employees/');
        } finally {
            self::assertCount(1, $this->b->transport->requests);
        }
    }

    public function testWrongCredentialsAreAnAuthenticationError(): void
    {
        $this->b->transport->json(['non_field_errors' => ['Unable to log in with provided credentials.']], 400);

        try {
            $this->b->client()->get('/personnel/api/employees/');
            self::fail('Expected an exception');
        } catch (ZkbException $e) {
            self::assertSame(ZkbException::AUTH, $e->getKind());
            self::assertSame(400, $e->getHttpStatus());
            self::assertStringContainsString('Unable to log in', $e->getServerMessage());
        }
    }

    public function testLoginAnswerWithoutTokenIsUnreadable(): void
    {
        $this->b->transport->json(['user' => 'x']);

        $this->expectException(ZkbException::class);
        $this->expectExceptionMessage('ZKBioTime returned no token');
        $this->b->client()->get('/personnel/api/employees/');
    }

    public function testLoginIsNotRetriedOnNetworkError(): void
    {
        $this->b->transport->queue(new TransportException('Could not reach ZKBioTime (timeout: timed out)'));

        try {
            $this->b->client()->get('/personnel/api/employees/');
            self::fail('Expected an exception');
        } catch (ZkbException $e) {
            self::assertSame(ZkbException::NETWORK, $e->getKind());
        }

        self::assertCount(1, $this->b->transport->requests);
    }

    public function testSecretsNeverReachTheLog(): void
    {
        $jwt = ClientBuilder::jwt($this->b->now + 3600);
        $this->b->transport
            ->json(['token' => $jwt])
            ->json(['detail' => 'Bad header: JWT ' . $jwt . ' password=' . ClientBuilder::PASSWORD], 403);

        try {
            $this->b->client()->get('/personnel/api/employees/');
        } catch (ZkbException) {
        }

        $log = $this->b->logger->text();
        self::assertStringNotContainsString(ClientBuilder::PASSWORD, $log);
        self::assertStringNotContainsString($jwt, $log);
        self::assertStringNotContainsString(explode('.', $jwt)[2], $log);
        self::assertStringContainsString('kind permission', $log);
    }

    public function testClientAndCredentialsHideSecretsWhenDumped(): void
    {
        $credentials = new Credentials(Credentials::METHOD_JWT, 'apiuser', ClientBuilder::PASSWORD);
        $client      = $this->b->client($credentials);

        self::assertStringNotContainsString(ClientBuilder::PASSWORD, print_r($credentials, true));
        self::assertStringNotContainsString(ClientBuilder::PASSWORD, print_r($client, true));
        self::assertStringNotContainsString(ClientBuilder::PASSWORD, var_export($credentials->__debugInfo(), true));

        $this->expectException(\LogicException::class);
        serialize($client);
    }

    public function testJwtExpiryParsing(): void
    {
        self::assertSame(1234, ZkbClient::jwtExpiry(ClientBuilder::jwt(1234)));
        self::assertNull(ZkbClient::jwtExpiry('not-a-jwt'));
        self::assertNull(ZkbClient::jwtExpiry('a.!!!.c'));
        self::assertNull(ZkbClient::jwtExpiry('a.' . rtrim(base64_encode('{"sub":1}'), '=') . '.c'));
    }

    public function testCredentialsRequireWhatTheMethodNeeds(): void
    {
        self::assertTrue((new Credentials(Credentials::METHOD_TOKEN, 'u', 'p'))->canRenew());
        self::assertFalse((new Credentials(Credentials::METHOD_TOKEN, '', '', 'tok'))->canRenew());
        self::assertSame('', (new Credentials(Credentials::METHOD_JWT, 'u', 'p', 'ignored'))->staticToken());

        $this->expectException(\InvalidArgumentException::class);
        new Credentials(Credentials::METHOD_JWT, 'u', '');
    }

    public function testConnectionTestLogsInAfreshAndReportsCountOnly(): void
    {
        $this->b->tokens->save(new CachedToken('old.cached.token', $this->b->now + 3000));
        $this->b->transport
            ->json(['token' => ClientBuilder::jwt($this->b->now + 3600)])
            ->json(['count' => 412, 'next' => 'https://x/?page=2', 'previous' => null, 'data' => [['id' => 1, 'emp_code' => 'E1', 'first_name' => 'Secret']]]);

        $report = $this->b->client()->testConnection();

        self::assertSame('/jwt-api-token-auth/', $this->b->transport->path(0));
        self::assertSame('/personnel/api/employees/', $this->b->transport->path(1));
        self::assertSame(['page' => '1', 'page_size' => '1'], $this->b->transport->query(1));
        self::assertSame(412, $report->employeeCount);
        self::assertSame('jwt', $report->method);
        self::assertFalse($report->staticToken);
        self::assertSame($this->b->now + 3540, $report->tokenRenewAt);
        self::assertStringNotContainsString('Secret', print_r($report, true));
    }
}
