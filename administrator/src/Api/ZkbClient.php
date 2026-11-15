<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZkbPortal\Component\Zkbiotime\Administrator\Api;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Psr\Log\LoggerInterface;
use ZkbPortal\Component\Zkbiotime\Administrator\Api\Transport\TransportException;
use ZkbPortal\Component\Zkbiotime\Administrator\Api\Transport\TransportInterface;
use ZkbPortal\Component\Zkbiotime\Administrator\Api\Transport\TransportResponse;

/**
 * The single gateway to the ZKBioTime REST API. Nothing else in the component talks to ZKBioTime.
 *
 * Read-only by design: the only POST it sends is the login that exchanges the API user's
 * credentials for a token. It never creates, changes or deletes ZKBioTime data.
 *
 * Every endpoint path is named once, in the constants below. Paths not yet confirmed on a real
 * ZKBioTime 9.5 server are marked UNVERIFIED here and in docs/API-MAPPING.md.
 */
final class ZkbClient
{
    /**
     * JWT login: POST {"username", "password"} -> {"token"}; requests then send "Authorization: JWT <token>".
     * UNVERIFIED on 9.5. Source: ZKBioTime API User Manual, "Authentication" (to be confirmed on /api/docs/).
     */
    private const PATH_JWT_AUTH = '/jwt-api-token-auth/';

    /**
     * General Auth Token login: POST {"username", "password"} -> {"token"}; requests then send
     * "Authorization: Token <token>".
     * UNVERIFIED on 9.5. Source: ZKBioTime API User Manual, "Authentication" (to be confirmed on /api/docs/).
     */
    private const PATH_TOKEN_AUTH = '/api-token-auth/';

    /**
     * Employee list (Personnel API), paged with page / page_size.
     * UNVERIFIED on 9.5. Source: ZKBioTime API User Manual, "Employee" (to be confirmed on /api/personnel_docs/).
     * Stage 1 only reads one row of it, to count the employees the API user may see.
     */
    private const PATH_EMPLOYEES = '/personnel/api/employees/';

    /** Paging query parameters. UNVERIFIED on 9.5 (manual, list endpoints). */
    private const PARAM_PAGE      = 'page';
    private const PARAM_PAGE_SIZE = 'page_size';

    /** Extra attempts for a GET that fails on the network or with a server error, and the pause before it. */
    private const GET_RETRIES = 1;

    /**
     * Fallback token lifetimes when the server does not say (seconds). A JWT normally carries an
     * "exp" claim and that is used instead; 300 s is the default of the Django REST framework JWT
     * library. A General Auth Token has no expiry; it is renewed after 12 hours or on "unauthorised".
     */
    private const JWT_DEFAULT_LIFETIME   = 300;
    private const TOKEN_DEFAULT_LIFETIME = 43200;

    /** Renew this many seconds before the token expires (at most half of its lifetime). */
    private const RENEW_MARGIN = 60;

    public const MAX_PAGE_SIZE = 1000;
    public const MAX_PAGES     = 1000;

    /** @var \Closure(): int */
    private \Closure $clock;

    /** @var list<string> */
    private array $secrets;

    /** Collection key that worked ("data" or "results"), logged once per request. */
    private ?string $collectionKey = null;

    /**
     * @param  int                    $pageSize      Rows per list page (Integration Settings)
     * @param  int                    $maxPages      Safety maximum of pages per list read
     * @param  (\Closure(): int)|null $clock         Unix time; injected for tests
     * @param  int                    $retryDelayUs  Pause before the GET retry (microseconds)
     */
    public function __construct(
        private readonly TransportInterface $transport,
        private readonly ClientConfig $config,
        private readonly Credentials $credentials,
        private readonly TokenStoreInterface $tokens,
        private readonly LoggerInterface $logger,
        private readonly int $pageSize = 100,
        private readonly int $maxPages = 50,
        ?\Closure $clock = null,
        private readonly int $retryDelayUs = 300_000
    ) {
        if ($pageSize < 1 || $pageSize > self::MAX_PAGE_SIZE || $maxPages < 1 || $maxPages > self::MAX_PAGES) {
            throw new \InvalidArgumentException('Invalid paging limits');
        }

        $this->clock   = $clock ?? static fn (): int => time();
        $this->secrets = $credentials->secrets();
    }

    /**
     * "Test Connection": log in afresh (the cached token is discarded first, so the stored
     * credentials are really checked), then read one employee row to see that the API user may
     * read the Personnel API. Only the reported count is kept; no employee data is returned.
     *
     * @throws ZkbException
     */
    public function testConnection(): ConnectionReport
    {
        $started = hrtime(true);

        $this->tokens->clear();
        $token = $this->credentials->canRenew() ? $this->login() : null;

        if ($token !== null) {
            $this->tokens->save($token);
        }

        $page = $this->listPage(self::PATH_EMPLOYEES, [], 1, 1);

        return new ConnectionReport(
            $this->credentials->method,
            !$this->credentials->canRenew(),
            $token?->renewAt,
            $page->count,
            (int) ((hrtime(true) - $started) / 1_000_000)
        );
    }

    /**
     * Authorised GET of one endpoint. An "unauthorised" answer renews the token once and repeats
     * the call; a second "unauthorised" answer is final.
     *
     * @param  array<string, string|int>  $query
     *
     * @return array<mixed>  Decoded JSON (object or list)
     *
     * @throws ZkbException
     */
    public function get(string $path, array $query = []): array
    {
        $token = $this->token(false);

        try {
            return $this->request('GET', $path, $query, null, $token);
        } catch (ZkbException $e) {
            if ($e->getKind() !== ZkbException::AUTH || !$this->credentials->canRenew()) {
                throw $e;
            }
        }

        $this->logger->info(\sprintf('ZKBioTime GET %s answered "unauthorised"; renewing the token once', $path));
        $this->tokens->clear();

        return $this->request('GET', $path, $query, null, $this->token(true));
    }

    /**
     * One page of a list endpoint.
     *
     * The envelope is read as documented ({"count", "next", "previous", "data": [...]}). If a
     * build answers with "results" instead of "data", or with a bare list, that is accepted and
     * logged, so the mapping document can be corrected.
     *
     * @param  array<string, string|int>  $query
     *
     * @throws ZkbException
     */
    public function listPage(string $path, array $query, int $page, ?int $pageSize = null): ListPage
    {
        $size = max(1, min(self::MAX_PAGE_SIZE, $pageSize ?? $this->pageSize));
        $data = $this->get($path, [self::PARAM_PAGE => max(1, $page), self::PARAM_PAGE_SIZE => $size] + $query);

        if (array_is_list($data)) {
            $this->noteCollectionKey($path, '(bare list)');

            return new ListPage(array_values(array_filter($data, 'is_array')), null, false);
        }

        $key = null;

        foreach (['data', 'results'] as $candidate) {
            if (\array_key_exists($candidate, $data)) {
                $key = $candidate;

                break;
            }
        }

        $items = $key !== null ? $data[$key] : null;

        if (!\is_array($items) || !array_is_list($items)) {
            $this->logger->warning(\sprintf('ZKBioTime GET %s: no list under "data" or "results" (keys: %s)', $path, implode(',', \array_slice(array_map('strval', array_keys($data)), 0, 10))));

            throw new ZkbException(ZkbException::BAD_RESPONSE, 'Unexpected list response shape');
        }

        $this->noteCollectionKey($path, (string) $key);

        $items = array_values(array_filter($items, 'is_array'));
        $count = isset($data['count']) && is_numeric($data['count']) ? max(0, (int) $data['count']) : null;

        if (\array_key_exists('next', $data)) {
            $hasNext = \is_string($data['next']) && $data['next'] !== '';
        } elseif ($count !== null) {
            $hasNext = $page * $size < $count;
        } else {
            $hasNext = \count($items) >= $size;
        }

        return new ListPage($items, $count, $hasNext && $items !== []);
    }

    /**
     * Read pages until the last one, $maxItems rows, or the safety maximum of pages. When the
     * safety maximum stops the reading early, the result says so (truncated) and a warning is logged.
     *
     * @param  array<string, string|int>  $query
     *
     * @throws ZkbException
     */
    public function listAll(string $path, array $query = [], ?int $maxItems = null): ListResult
    {
        $all       = [];
        $count     = null;
        $truncated = false;
        $pages     = 0;

        for ($page = 1; ; $page++) {
            $result = $this->listPage($path, $query, $page);
            $pages++;
            $count ??= $result->count;

            foreach ($result->items as $item) {
                $all[] = $item;
            }

            if ($maxItems !== null && \count($all) >= $maxItems) {
                $truncated = $result->hasNext || \count($all) > $maxItems;
                $all       = \array_slice($all, 0, $maxItems);

                break;
            }

            if (!$result->hasNext) {
                break;
            }

            if ($pages >= $this->maxPages) {
                $truncated = true;

                break;
            }
        }

        if ($truncated) {
            $this->logger->warning(\sprintf('ZKBioTime GET %s: stopped after %d pages / %d rows (safety maximum); results are incomplete', $path, $pages, \count($all)));
        }

        return new ListResult($all, $count, $truncated, $pages);
    }

    /**
     * Validate a numeric ZKBioTime id (from ZKBioTime or from a URL) before it goes into a path or query.
     *
     * @throws \InvalidArgumentException
     */
    public static function id(string|int $id): string
    {
        $id = (string) $id;

        if (!preg_match('/^[1-9][0-9]{0,19}$/', $id)) {
            throw new \InvalidArgumentException('Invalid ZKBioTime id');
        }

        return $id;
    }

    /**
     * Validate an employee code (emp_code) before it goes into a query.
     *
     * @throws \InvalidArgumentException
     */
    public static function empCode(string $code): string
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,29}$/', $code)) {
            throw new \InvalidArgumentException('Invalid employee code');
        }

        return $code;
    }

    /**
     * A usable token: the cached one, or a new one from the login endpoint.
     *
     * @throws ZkbException
     */
    private function token(bool $forceRenew): string
    {
        $static = $this->credentials->staticToken();

        if ($static !== '') {
            return $static;
        }

        $now    = ($this->clock)();
        $cached = $forceRenew ? null : $this->tokens->load();

        if ($cached !== null && $cached->isUsable($now)) {
            return $cached->token;
        }

        $token = $this->login();
        $this->tokens->save($token);

        return $token->token;
    }

    /**
     * Exchange the API user's credentials for a token. Never retried on network errors.
     *
     * @throws ZkbException
     */
    private function login(): CachedToken
    {
        $jwt  = $this->credentials->method === Credentials::METHOD_JWT;
        $path = $jwt ? self::PATH_JWT_AUTH : self::PATH_TOKEN_AUTH;

        try {
            $data = $this->request('POST', $path, [], [
                'username' => $this->credentials->username,
                'password' => $this->credentials->password(),
            ], null);
        } catch (ZkbException $e) {
            // Wrong credentials are answered with 400 ("Unable to log in with provided credentials").
            if (\in_array($e->getKind(), [ZkbException::VALIDATION, ZkbException::PERMISSION], true)) {
                $this->logger->warning(\sprintf('ZKBioTime login (%s) refused the API credentials (HTTP %d); reported as an authentication error', $jwt ? 'JWT' : 'General Auth Token', $e->getHttpStatus()));

                throw new ZkbException(ZkbException::AUTH, 'ZKBioTime refused the API credentials', $e->getHttpStatus(), $e->getServerMessage(), $e);
            }

            throw $e;
        }

        $token = $data['token'] ?? null;

        if (!\is_string($token) || $token === '' || \strlen($token) > 4096 || !preg_match('/^[A-Za-z0-9._~+\/=-]+$/', $token)) {
            $this->logger->warning(\sprintf('ZKBioTime POST %s: no usable "token" in the answer', $path));

            throw new ZkbException(ZkbException::BAD_RESPONSE, 'ZKBioTime returned no token');
        }

        $this->secrets[] = $token;

        $now      = ($this->clock)();
        $expires  = $jwt ? self::jwtExpiry($token) : null;
        $lifetime = $expires !== null ? $expires - $now : ($jwt ? self::JWT_DEFAULT_LIFETIME : self::TOKEN_DEFAULT_LIFETIME);
        $renewAt  = $now + max(0, $lifetime - min(self::RENEW_MARGIN, intdiv(max(0, $lifetime), 2)));

        $this->logger->info(\sprintf(
            'ZKBioTime login (%s) succeeded; token renewed after %s',
            $jwt ? 'JWT' : 'General Auth Token',
            gmdate('Y-m-d H:i:s', $renewAt) . ' UTC' . ($expires === null ? ' (server gave no expiry; default lifetime used)' : '')
        ));

        return new CachedToken($token, $renewAt);
    }

    /**
     * The "exp" claim of a JWT (not verified: only used to know when to renew).
     */
    public static function jwtExpiry(string $jwt): ?int
    {
        $parts = explode('.', $jwt);

        if (\count($parts) !== 3) {
            return null;
        }

        $json = base64_decode(strtr($parts[1], '-_', '+/') . str_repeat('=', (4 - \strlen($parts[1]) % 4) % 4), true);

        if ($json === false) {
            return null;
        }

        try {
            $claims = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($claims) && isset($claims['exp']) && is_numeric($claims['exp']) ? (int) $claims['exp'] : null;
    }

    /**
     * @param  array<string, string|int>       $query
     * @param  array<string, string>|null      $json   JSON body (login only)
     *
     * @return array<mixed>
     *
     * @throws ZkbException
     */
    private function request(string $method, string $path, array $query, ?array $json, #[\SensitiveParameter] ?string $token): array
    {
        if (!preg_match('#^(/[A-Za-z0-9_-]+)+/$#', $path)) {
            throw new \InvalidArgumentException('Invalid ZKBioTime API path');
        }

        $url = $this->config->baseUrl . $path;

        if ($query !== []) {
            $url .= '?' . http_build_query(self::checkQuery($query), '', '&', PHP_QUERY_RFC3986);
        }

        $headers = ['Accept' => 'application/json'];
        $body    = null;

        if ($token !== null) {
            $headers['Authorization'] = $this->credentials->scheme() . ' ' . $token;
            $this->secrets[]          = $token;
        }

        if ($json !== null) {
            $headers['Content-Type'] = 'application/json';
            $body                    = json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $secrets  = array_values(array_unique($this->secrets));
        $started  = hrtime(true);
        $attempts = $method === 'GET' ? 1 + self::GET_RETRIES : 1;
        $logPath  = $path . ($query !== [] ? ' [' . implode(',', array_keys($query)) . ']' : '');

        for ($attempt = 1; ; $attempt++) {
            $retry = $attempt < $attempts;

            try {
                $response = $this->transport->send($method, $url, $headers, $body);
            } catch (TransportException $e) {
                $this->logger->warning(\sprintf('ZKBioTime %s %s failed%s: %s', $method, $logPath, $retry ? ' (trying again)' : '', Redactor::scrub($e->getMessage(), $secrets)));

                if (!$retry) {
                    throw new ZkbException(ZkbException::NETWORK, 'Could not reach ZKBioTime', 0, '', $e);
                }

                $this->pause();

                continue;
            }

            if ($response->status >= 500 && $retry) {
                $this->logger->warning(\sprintf('ZKBioTime %s %s -> HTTP %d (trying again)', $method, $logPath, $response->status));
                $this->pause();

                continue;
            }

            break;
        }

        $elapsed = (int) ((hrtime(true) - $started) / 1_000_000);

        return $this->interpret($method, $logPath, $response, $elapsed, $secrets);
    }

    /**
     * @param  list<string>  $secrets
     *
     * @return array<mixed>
     *
     * @throws ZkbException
     */
    private function interpret(string $method, string $logPath, TransportResponse $response, int $elapsed, array $secrets): array
    {
        $decoded = self::decode($response->body);

        if ($response->status >= 300) {
            $message   = Redactor::scrub(self::serverMessage($decoded), $secrets);
            $exception = $response->status < 400
                ? new ZkbException(ZkbException::BAD_RESPONSE, 'Unexpected redirect from ZKBioTime', $response->status, $message)
                : ZkbException::fromStatus($response->status, $message);

            $this->logger->warning(\sprintf('ZKBioTime %s %s -> HTTP %d, kind %s, %d ms%s', $method, $logPath, $response->status, $exception->getKind(), $elapsed, $message !== '' ? ': ' . $message : ''));

            throw $exception;
        }

        if ($decoded === null) {
            $this->logger->warning(\sprintf('ZKBioTime %s %s -> HTTP %d with a body that is not JSON, %d ms', $method, $logPath, $response->status, $elapsed));

            throw new ZkbException(ZkbException::BAD_RESPONSE, 'ZKBioTime returned an unreadable response', $response->status);
        }

        // UNVERIFIED: the manual shows "code" and "msg" in list answers, with code 0 for success.
        // A non-zero code with no data is treated as a refused request.
        if (!array_is_list($decoded) && isset($decoded['code']) && is_numeric($decoded['code']) && (int) $decoded['code'] !== 0
            && !\array_key_exists('data', $decoded) && !\array_key_exists('token', $decoded)) {
            $message = Redactor::scrub(self::serverMessage($decoded), $secrets);
            $this->logger->warning(\sprintf('ZKBioTime %s %s -> HTTP %d with code %d, %d ms%s', $method, $logPath, $response->status, (int) $decoded['code'], $elapsed, $message !== '' ? ': ' . $message : ''));

            throw new ZkbException(ZkbException::VALIDATION, 'ZKBioTime refused the request', $response->status, $message);
        }

        $this->logger->info(\sprintf('ZKBioTime %s %s -> HTTP %d, %d ms', $method, $logPath, $response->status, $elapsed));

        return $decoded;
    }

    /**
     * @param  array<string, string|int>  $query
     *
     * @return array<string, string>
     */
    private static function checkQuery(array $query): array
    {
        $clean = [];

        foreach ($query as $key => $value) {
            if (!\is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key) || !(\is_string($value) || \is_int($value))) {
                throw new \InvalidArgumentException('Invalid ZKBioTime query parameter');
            }

            $value = (string) $value;

            if (\strlen($value) > 200 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
                throw new \InvalidArgumentException('Invalid ZKBioTime query value');
            }

            $clean[$key] = $value;
        }

        return $clean;
    }

    /**
     * @return array<mixed>|null  null when the body is not JSON
     */
    private static function decode(string $body): ?array
    {
        if (trim($body) === '') {
            return null;
        }

        try {
            $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($data) ? $data : null;
    }

    /**
     * Error text from a Django REST framework style answer ("detail", "msg", "non_field_errors").
     *
     * @param  array<mixed>|null  $data
     */
    private static function serverMessage(?array $data): string
    {
        if ($data === null || array_is_list($data)) {
            return '';
        }

        foreach (['detail', 'msg', 'message', 'non_field_errors', 'error'] as $key) {
            $value = $data[$key] ?? null;

            if (\is_array($value)) {
                $value = implode(' ', array_filter($value, 'is_string'));
            }

            if (\is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return '';
    }

    private function noteCollectionKey(string $path, string $key): void
    {
        if ($this->collectionKey !== $key) {
            $this->collectionKey = $key;
            $this->logger->info(\sprintf('ZKBioTime GET %s: list read from %s', $path, $key === 'data' ? '"data" (as documented)' : ($key === '(bare list)' ? 'a bare list (not as documented)' : '"' . $key . '" (not as documented)')));
        }
    }

    private function pause(): void
    {
        if ($this->retryDelayUs > 0) {
            usleep($this->retryDelayUs);
        }
    }

    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->config->baseUrl, 'method' => $this->credentials->method, 'credentials' => '[redacted]'];
    }

    public function __serialize(): array
    {
        throw new \LogicException('ZkbClient cannot be serialised');
    }
}
