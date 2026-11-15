<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Discovery;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Reads the list of GET endpoints from an API description: OpenAPI 3 / Swagger 2 (paths) or
 * Django REST framework CoreJSON (nested links). Only list endpoints are kept: no path parameters
 * such as {id}, and only paths the API client accepts.
 */
final class ApiCatalog
{
    private const PATH = '#^(/[A-Za-z0-9_-]+)+/$#';

    /** Words that make an endpoint likely to hold attendance, schedules or leave. */
    private const RELEVANT = '/att|attendance|report|leave|schedul|shift|timetable|holiday|overtime|card|daily|exception|absen|late/i';

    /**
     * @param array<mixed> $doc decoded API description
     *
     * @return list<array{path: string, summary: string, params: list<string>, relevant: bool}>
     */
    public static function parse(array $doc): array
    {
        return self::merge(isset($doc['paths']) && \is_array($doc['paths']) ? self::openApi($doc) : self::coreJson($doc));
    }

    /**
     * Unique by path (first wins), attendance-related first, then alphabetical; at most 500.
     *
     * @param list<array{path: string, summary: string, params: list<string>, relevant: bool}> $out
     *
     * @return list<array{path: string, summary: string, params: list<string>, relevant: bool}>
     */
    public static function merge(array $out): array
    {
        $byPath = [];

        foreach ($out as $e) {
            $byPath[$e['path']] ??= $e;
        }

        $list = array_values($byPath);
        usort($list, static fn (array $a, array $b): int => [$b['relevant'], $a['path']] <=> [$a['relevant'], $b['path']]);

        return \array_slice($list, 0, 500);
    }

    /** True when the description could be read as one of the two formats. */
    public static function looksLikeDescription(array $doc): bool
    {
        return (isset($doc['paths']) && \is_array($doc['paths'])) || ($doc['_type'] ?? '') === 'document';
    }

    /**
     * @return list<array{path: string, summary: string, params: list<string>, relevant: bool}>
     */
    private static function openApi(array $doc): array
    {
        $base = \is_string($doc['basePath'] ?? null) ? rtrim($doc['basePath'], '/') : '';
        $out  = [];

        foreach ($doc['paths'] as $path => $item) {
            if (!\is_string($path) || !\is_array($item) || !isset($item['get']) || !\is_array($item['get'])) {
                continue;
            }

            $params = [];

            foreach (array_merge((array) ($item['parameters'] ?? []), (array) ($item['get']['parameters'] ?? [])) as $p) {
                if (\is_array($p) && ($p['in'] ?? '') === 'query' && \is_string($p['name'] ?? null)) {
                    $params[] = $p['name'];
                }
            }

            $summary = (string) ($item['get']['summary'] ?? $item['get']['description'] ?? $item['get']['operationId'] ?? '');
            self::add($out, $base . $path, $summary, $params, implode(' ', (array) ($item['get']['tags'] ?? [])));
        }

        return $out;
    }

    /**
     * @return list<array{path: string, summary: string, params: list<string>, relevant: bool}>
     */
    private static function coreJson(array $doc): array
    {
        $out  = [];
        $walk = static function (array $node, string $context) use (&$walk, &$out): void {
            if (($node['_type'] ?? '') === 'link' && \is_string($node['url'] ?? null)) {
                if (strtolower((string) ($node['action'] ?? 'get')) === 'get') {
                    $params = [];

                    foreach ((array) ($node['fields'] ?? []) as $f) {
                        if (\is_array($f) && ($f['location'] ?? 'query') === 'query' && \is_string($f['name'] ?? null)) {
                            $params[] = $f['name'];
                        }
                    }

                    $path = (string) parse_url($node['url'], PHP_URL_PATH);
                    self::add($out, $path, (string) ($node['description'] ?? ''), $params, $context);
                }

                return;
            }

            foreach ($node as $key => $child) {
                if (\is_array($child) && !\in_array($key, ['fields', '_meta'], true)) {
                    $walk($child, $context . ' ' . (\is_string($key) ? $key : ''));
                }
            }
        };

        $walk($doc, '');

        return $out;
    }

    /**
     * @param list<array{path: string, summary: string, params: list<string>, relevant: bool}> $out
     * @param list<string> $params
     */
    private static function add(array &$out, string $path, string $summary, array $params, string $context): void
    {
        if (!preg_match(self::PATH, $path) || \in_array($path, ['/jwt-api-token-auth/', '/api-token-auth/'], true)) {
            return;
        }

        $params = array_values(array_unique(array_filter($params, static fn (string $p): bool => (bool) preg_match('/^[a-z][a-z0-9_]{0,39}$/', $p) && !\in_array($p, ['page', 'page_size', 'format', 'ordering', 'search'], true))));
        $summary = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($summary)) ?? ''), 0, 160);

        $out[] = ['path' => $path, 'summary' => $summary, 'params' => $params, 'relevant' => (bool) preg_match(self::RELEVANT, $path . ' ' . $context)];
    }

    /**
     * Guess which query parameters take the employee code and the start and end date.
     *
     * @param list<string> $params
     *
     * @return array{emp: string, from: string, to: string}
     */
    public static function suggestParams(array $params): array
    {
        $pick = static function (array $patterns, string $default) use ($params): string {
            foreach ($patterns as $pattern) {
                foreach ($params as $p) {
                    if (preg_match($pattern, $p)) {
                        return $p;
                    }
                }
            }

            return $default;
        };

        return [
            'emp'  => $pick(['/^emp_code$/', '/^emp_code/', '/emp.*code|employee.*code/', '/^emp(loyee)?(_id)?$/', '/emp/'], 'emp_code'),
            'from' => $pick(['/^start_date$/', '/^start_time$/', '/(^|_)(start|from|begin)(_|$)/', '/start|from|begin/'], 'start_date'),
            'to'   => $pick(['/^end_date$/', '/^end_time$/', '/(^|_)(end|to|finish)(_|$)/', '/end|finish/'], 'end_date'),
        ];
    }
}
