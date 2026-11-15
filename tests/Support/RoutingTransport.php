<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Tests\Support;

use BiotimePortal\Component\Biotime\Administrator\Api\Transport\TransportInterface;
use BiotimePortal\Component\Biotime\Administrator\Api\Transport\TransportResponse;

/**
 * A stand-in ZKBioTime answering by path. Handlers get the query array and return
 * [status, data]. The login endpoints are answered automatically.
 */
final class RoutingTransport implements TransportInterface
{
    /** @var array<string, callable(array<string, string>): array{0: int, 1: mixed}> */
    private array $routes = [];

    /** @var list<array{path: string, query: array<string, string>}> */
    public array $calls = [];

    public function on(string $path, callable $handler): self
    {
        $this->routes[$path] = $handler;

        return $this;
    }

    /** Serve a list as DRF pages ("data", "next", "count"), honouring page/page_size. */
    public function list(string $path, array $rows, ?callable $filter = null): self
    {
        return $this->on($path, static function (array $q) use ($rows, $filter): array {
            $rows  = array_values($filter ? array_filter($rows, static fn ($r) => $filter($r, $q)) : $rows);
            $size  = (int) ($q['page_size'] ?? 100);
            $page  = (int) ($q['page'] ?? 1);
            $slice = \array_slice($rows, ($page - 1) * $size, $size);

            return [200, ['count' => \count($rows), 'next' => $page * $size < \count($rows) ? 'next' : null, 'previous' => null, 'data' => $slice]];
        });
    }

    public function send(string $method, string $url, array $headers, ?string $body): TransportResponse
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        if ($method === 'POST' && \in_array($path, ['/jwt-api-token-auth/', '/api-token-auth/'], true)) {
            return new TransportResponse(200, json_encode(['token' => ClientBuilder::jwt(2_000_000_000)]));
        }

        $this->calls[] = ['path' => $path, 'query' => $query];
        $handler       = $this->routes[$path] ?? null;

        if ($handler === null) {
            return new TransportResponse(404, '{"detail":"Not found."}');
        }

        [$status, $data] = $handler($query);

        return new TransportResponse($status, json_encode($data));
    }

    /** @return list<array<string, string>> queries sent to $path */
    public function queries(string $path): array
    {
        return array_values(array_map(static fn ($c) => $c['query'], array_filter($this->calls, static fn ($c) => $c['path'] === $path)));
    }
}
