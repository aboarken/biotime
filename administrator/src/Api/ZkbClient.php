<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Api;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Psr\Log\LoggerInterface;
use BiotimePortal\Component\Biotime\Administrator\Api\Transport\TransportException;
use BiotimePortal\Component\Biotime\Administrator\Api\Transport\TransportInterface;
use BiotimePortal\Component\Biotime\Administrator\Api\Transport\TransportResponse;

/**
 * The single gateway to the ZKBioTime REST API. Nothing else in the component talks to ZKBioTime.
 *
 * Read-only by design: the only POST it sends is the login that exchanges the API user's
 * credentials for a token. It never creates, changes or deletes ZKBioTime data.
 *
 * Every endpoint path is named once, in the constants below. VERIFIED means documented in the
 * BioTime 9.5 API User Manual (ZKTeco Middle East, 16 Aug 2024) and, where noted, seen working on a real 9.5 server; anything else is marked UNVERIFIED
 * here and in docs/API-MAPPING.md. That manual documents no calculated-attendance, schedule,
 * report or leave API: only login, devices, personnel and transactions.
 */
final class ZkbClient
{
    /**
     * JWT login: POST {"username", "password"} -> {"token"}; requests then send "Authorization: JWT <token>".
     * VERIFIED: BioTime 9.5 API User Manual (ZKTeco Middle East, 16 Aug 2024), 1.1; works on the real server (2026-11-15).
     */
    private const PATH_JWT_AUTH = '/jwt-api-token-auth/';

    /**
     * General Auth Token login: POST {"username", "password"} -> {"token"}; requests then send
     * "Authorization: Token <token>".
     * VERIFIED: BioTime 9.5 API User Manual (ZKTeco Middle East, 16 Aug 2024), 1.2 and 3.1 (header "Token <token>").
     */
    private const PATH_TOKEN_AUTH = '/api-token-auth/';

    /**
     * Employee list (Personnel API), paged with page / page_size.
     * VERIFIED: BioTime 9.5 API User Manual (ZKTeco Middle East, 16 Aug 2024), 5.1 (filters page, page_size, emp_code, first_name, last_name, department,
     * app_status); works on the real server (423 employees, 2026-11-16).
     */
    private const PATH_EMPLOYEES = '/personnel/api/employees/';

    /**
     * One employee: /personnel/api/employees/{id}/.
     * VERIFIED: BioTime 9.5 API User Manual (ZKTeco Middle East, 16 Aug 2024), 5.2.
     */
    private const PATH_EMPLOYEE = '/personnel/api/employees/%s/';

    /** Employee list filter by employee code. VERIFIED: BioTime 9.5 API User Manual (ZKTeco Middle East, 16 Aug 2024), 5.1. */
    private const PARAM_EMP_CODE = 'emp_code';

    /**
     * Punches (transactions), filtered by employee code and a time range ("YYYY-MM-DD HH:MM:SS").
     * VERIFIED: BioTime 9.5 API User Manual (ZKTeco Middle East, 16 Aug 2024), 9.1; works on the real server (2026-11-15).
     */
    private const PATH_TRANSACTIONS = '/iclock/api/transactions/';

    /** Transaction filters. VERIFIED: BioTime 9.5 API User Manual (ZKTeco Middle East, 16 Aug 2024), 9.1 (also page, page_size, terminal_sn). */
    private const PARAM_TX_EMP   = 'emp_code';
    private const PARAM_TX_START = 'start_time';
    private const PARAM_TX_END   = 'end_time';

    /**
     * API description pages (Find Endpoints). The administrator's manual names /api/docs/ and
     * /api/personnel_docs/. Their machine-readable form depends on the build: OpenAPI/Swagger
     * (?format=openapi) or Django REST framework CoreJSON (?format=corejson) is tried in that
     * order, the next one only when the previous is not found or not readable; the form that
     * worked is logged. VERIFIED on the real server: CoreJSON only (2026-11-15); the 9.5 manual
     * links /api/docs/ for the login endpoints.
     */
    private const DOC_PATHS   = ['/api/docs/', '/api/personnel_docs/'];
    private const DOC_FORMATS = ['openapi', 'corejson'];

    /**
     * Accept header per description form. CoreJSON is served as application/coreapi+json: asking
     * for plain JSON only is refused with 406 (seen on the ams.tis.edu.sa server, 2026-11-15).
     */
    private const DOC_ACCEPT = [
        'openapi'  => 'application/openapi+json, application/json;q=0.9',
        'corejson' => 'application/coreapi+json, application/vnd.coreapi+json;q=0.9, application/json;q=0.8',
    ];

    /**
     * Calculated-attendance paths that Diagnostics also checks, because the API description of
     * the 9.5 server under test lists no attendance endpoints at all (not even transactions).
     * Diagnostics only reports whether they answer and which field names they return; nothing is
     * read from them until the administrator saves one as the Daily Attendance Source.
     * Source: BioTimeCloud API User Manual 1.0 (Aug 2023), "Att API": GET
     * /att/api/totalTimeCardReport/ with page, page_size, start_date, end_date. NOT in the
     * BioTime 9.5 API User Manual (ZKTeco Middle East, 16 Aug 2024), and not present on the real 9.5 server (web page instead of data, 2026-11-16). Kept
     * only so Diagnostics shows the same on other servers (BioTime Cloud, later builds).
     */
    private const DIAG_ATT_PATHS = ['/att/api/totalTimeCardReport/'];

    /** Paging query parameters. VERIFIED: BioTime 9.5 API User Manual (ZKTeco Middle East, 16 Aug 2024), 5.1 and 9.1; work on the real server. */
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
    public function get(string $path, array $query = [], ?string $accept = null): array
    {
        $token = $this->token(false);

        try {
            return $this->request('GET', $path, $query, null, $token, $accept);
        } catch (ZkbException $e) {
            if ($e->getKind() !== ZkbException::AUTH || !$this->credentials->canRenew()) {
                throw $e;
            }
        }

        $this->logger->info(\sprintf('ZKBioTime GET %s answered "unauthorised"; renewing the token once', $path));
        $this->tokens->clear();

        return $this->request('GET', $path, $query, null, $this->token(true), $accept);
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
     * Every employee the API user may read, page by page up to the safety maximum. Used for exact
     * e-mail matching, because the list filters of ZKBioTime may return partial matches.
     *
     * @throws ZkbException
     */
    public function allEmployees(): ListResult
    {
        return $this->listAll(self::PATH_EMPLOYEES);
    }

    /**
     * Employees whose code is $code according to the server's filter. Callers compare emp_code
     * exactly, since a filter may also return partial matches.
     *
     * @return list<array<string, mixed>>
     *
     * @throws ZkbException
     */
    public function employeesByCode(string $code): array
    {
        return $this->listAll(self::PATH_EMPLOYEES, [self::PARAM_EMP_CODE => self::empCode($code)], 50)->items;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ZkbException
     */
    public function employee(string|int $id): array
    {
        $data = $this->get(\sprintf(self::PATH_EMPLOYEE, self::id($id)));

        // Some builds wrap single objects in "data".
        if (isset($data['data']) && \is_array($data['data']) && !array_is_list($data['data'])) {
            $data = $data['data'];
        }

        if (array_is_list($data) || !isset($data['emp_code'])) {
            throw new ZkbException(ZkbException::BAD_RESPONSE, 'Unexpected employee response shape');
        }

        return $data;
    }

    /**
     * Punches of one employee between two server-local times (inclusive).
     *
     * @throws ZkbException
     */
    public function transactions(string $empCode, \DateTimeInterface $from, \DateTimeInterface $to): ListResult
    {
        return $this->listAll(self::PATH_TRANSACTIONS, [
            self::PARAM_TX_EMP   => self::empCode($empCode),
            self::PARAM_TX_START => $from->format('Y-m-d H:i:s'),
            self::PARAM_TX_END   => $to->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Rows of an administrator-configured read-only source (calculated daily attendance or leave),
     * filtered to one employee and a date range. The path and parameter names were validated when
     * the settings were saved and are checked again here.
     *
     * @throws ZkbException
     */
    public function sourceRows(\BiotimePortal\Component\Biotime\Administrator\Attendance\SourceConfig $source, string $empCode, \DateTimeInterface $from, \DateTimeInterface $to): ListResult
    {
        if (!$source->isConfigured()) {
            throw new ZkbException(ZkbException::NOT_CONFIGURED, 'Source not configured');
        }

        if (\in_array($source->path, [self::PATH_JWT_AUTH, self::PATH_TOKEN_AUTH], true)) {
            throw new \InvalidArgumentException('Invalid source path');
        }

        $query = $source->extra;
        $query[$source->paramEmp]  = self::empCode($empCode);
        $query[$source->paramFrom] = $from->format($source->dateFormat);
        $query[$source->paramTo]   = $to->format($source->dateFormat);

        return $this->listAll($source->path, $query);
    }

    /**
     * The GET list endpoints of every API description page that can be read (Find Endpoints).
     *
     * @return array{endpoints: list<array{path: string, summary: string, params: list<string>, relevant: bool}>, sources: list<string>}
     *
     * @throws ZkbException when no description could be read at all (the last error)
     */
    public function apiCatalog(): array
    {
        $endpoints = [];
        $sources   = [];
        $last      = null;

        foreach (self::DOC_PATHS as $path) {
            foreach (self::DOC_FORMATS as $format) {
                try {
                    $doc = $this->get($path, ['format' => $format], self::DOC_ACCEPT[$format]);
                } catch (ZkbException $e) {
                    $last = $e;

                    // Only "not found" or an unreadable / refused form moves on to the next one.
                    if (\in_array($e->getKind(), [ZkbException::NOT_FOUND, ZkbException::BAD_RESPONSE, ZkbException::VALIDATION], true)) {
                        continue;
                    }

                    throw $e;
                }

                if (!\BiotimePortal\Component\Biotime\Administrator\Discovery\ApiCatalog::looksLikeDescription($doc)) {
                    $last = new ZkbException(ZkbException::BAD_RESPONSE, 'Not an API description');

                    continue;
                }

                $found = \BiotimePortal\Component\Biotime\Administrator\Discovery\ApiCatalog::parse($doc);
                $endpoints = array_merge($endpoints, $found);
                $sources[] = $path . '?format=' . $format;
                $this->logger->info(\sprintf('ZKBioTime API description read from %s?format=%s: %d list endpoints', $path, $format, \count($found)));

                break;
            }
        }

        if ($sources === []) {
            $this->logger->warning('No ZKBioTime API description could be read (tried ' . implode(', ', self::DOC_PATHS) . ' as ' . implode(' and ', self::DOC_FORMATS) . ')');

            throw $last ?? new ZkbException(ZkbException::NOT_FOUND, 'No API description');
        }

        return ['endpoints' => \BiotimePortal\Component\Biotime\Administrator\Discovery\ApiCatalog::merge($endpoints), 'sources' => $sources];
    }

    /**
     * A few sample rows of a list endpoint chosen by the administrator (Find Endpoints).
     *
     * @param array<string, string> $query  employee and date filters
     *
     * @throws ZkbException
     */
    public function probe(string $path, array $query, int $rows = 20): ListPage
    {
        if (\in_array($path, [self::PATH_JWT_AUTH, self::PATH_TOKEN_AUTH], true)) {
            throw new \InvalidArgumentException('Invalid path');
        }

        return $this->listPage($path, $query, 1, max(1, min(50, $rows)));
    }

    /**
     * A few punch rows of one employee (or of anyone the API user may see, without a code), for
     * Diagnostics: shows that the punch endpoint answers and which fields it returns.
     *
     * @throws ZkbException
     */
    public function probeTransactions(?string $empCode, \DateTimeImmutable $from, \DateTimeImmutable $to, int $rows = 5): ListPage
    {
        $query = [
            self::PARAM_TX_START => $from->format('Y-m-d 00:00:00'),
            self::PARAM_TX_END   => $to->format('Y-m-d 23:59:59'),
        ];

        if ($empCode !== null && $empCode !== '') {
            $query[self::PARAM_TX_EMP] = self::empCode($empCode);
        }

        return $this->listPage(self::PATH_TRANSACTIONS, $query, 1, max(1, min(50, $rows)));
    }

    /** The employee list's first page (one row), for Diagnostics: total employees the API user sees. */
    public function probeEmployees(): ListPage
    {
        return $this->listPage(self::PATH_EMPLOYEES, [], 1, 1);
    }

    /**
     * Calculated-attendance paths Diagnostics checks besides the ones in the API description.
     *
     * @return list<string>
     */
    public static function diagnosticPaths(): array
    {
        return self::DIAG_ATT_PATHS;
    }

    /** The punch endpoint's path, for display in Diagnostics. */
    public static function transactionsPath(): string
    {
        return self::PATH_TRANSACTIONS;
    }

    /**
     * Paths the portal already reads for its own purpose (login, employees, punches). They hold no
     * calculated attendance or leave, so they cannot be chosen as a data source.
     */
    public static function isBuiltInPath(string $path): bool
    {
        return \in_array($path, [self::PATH_JWT_AUTH, self::PATH_TOKEN_AUTH, self::PATH_EMPLOYEES, self::PATH_TRANSACTIONS], true)
            || str_starts_with($path, self::PATH_EMPLOYEES);
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
    private function request(string $method, string $path, array $query, ?array $json, #[\SensitiveParameter] ?string $token, ?string $accept = null): array
    {
        if (!preg_match('#^(/[A-Za-z0-9_-]+)+/$#', $path)) {
            throw new \InvalidArgumentException('Invalid ZKBioTime API path');
        }

        $url = $this->config->baseUrl . $path;

        if ($query !== []) {
            $url .= '?' . http_build_query(self::checkQuery($query), '', '&', PHP_QUERY_RFC3986);
        }

        $headers = ['Accept' => $accept !== null && preg_match('#^[a-z0-9.+/*;=, -]{1,200}$#i', $accept) ? $accept : 'application/json'];
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

        // VERIFIED (BioTime 9.5 API User Manual (ZKTeco Middle East, 16 Aug 2024), 3.1, 5.1, 9.1): list answers carry "code" and "msg", code 0 for success.
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
