<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Tests\Support;

use BiotimePortal\Component\Biotime\Administrator\Api\Transport\TransportException;
use BiotimePortal\Component\Biotime\Administrator\Api\Transport\TransportInterface;
use BiotimePortal\Component\Biotime\Administrator\Api\Transport\TransportResponse;

/**
 * Stands in for ZKBioTime. Each queued answer is a TransportResponse, a TransportException to
 * throw, or a callable(method, url, headers, body) returning one of those. Every request is recorded.
 */
final class FakeTransport implements TransportInterface
{
    /** @var list<TransportResponse|TransportException|callable> */
    private array $queue = [];

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public array $requests = [];

    public function queue(TransportResponse|TransportException|callable ...$answers): self
    {
        foreach ($answers as $answer) {
            $this->queue[] = $answer;
        }

        return $this;
    }

    /** @param array<mixed> $data */
    public function json(array $data, int $status = 200): self
    {
        return $this->queue(new TransportResponse($status, json_encode($data, JSON_THROW_ON_ERROR)));
    }

    public function send(string $method, string $url, array $headers, ?string $body): TransportResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

        if ($this->queue === []) {
            throw new \LogicException('FakeTransport: unexpected request ' . $method . ' ' . $url);
        }

        $answer = array_shift($this->queue);

        if (\is_callable($answer)) {
            $answer = $answer($method, $url, $headers, $body);
        }

        if ($answer instanceof TransportException) {
            throw $answer;
        }

        return $answer;
    }

    public function remaining(): int
    {
        return \count($this->queue);
    }

    /** Path of request $n (0-based), without the query string. */
    public function path(int $n): string
    {
        return (string) parse_url($this->requests[$n]['url'], PHP_URL_PATH);
    }

    /** @return array<string, string> */
    public function query(int $n): array
    {
        parse_str((string) parse_url($this->requests[$n]['url'], PHP_URL_QUERY), $query);

        return $query;
    }
}
