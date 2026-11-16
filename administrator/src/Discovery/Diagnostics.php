<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Discovery;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use BiotimePortal\Component\Biotime\Administrator\Api\ListPage;
use BiotimePortal\Component\Biotime\Administrator\Api\ZkbClient;
use BiotimePortal\Component\Biotime\Administrator\Api\ZkbException;

/**
 * One-click Diagnostics (Find Endpoints): runs the connection, API list, punch and
 * calculated-attendance checks from the Joomla server and returns a plain-text report the
 * administrator can copy and send to support.
 *
 * The report holds only paths, HTTP results, row counts, field names, kinds of values and
 * status-like texts (see FieldInspector). Never credentials, tokens, names, codes, times or other
 * personal values. Only GET requests are sent.
 */
final class Diagnostics
{
    /** Most calculated-attendance paths checked in one run. */
    private const MAX_PROBES = 12;

    /** Paths in the API description that may hold calculated attendance, reports or leave. */
    private const ATT_PATH = '#^/att/|report|leave|timecard|time_card#i';

    /**
     * @param array<string, string> $context  "label => value" lines for the report head (version, settings)
     */
    public static function run(ZkbClient $client, ?string $empCode, \DateTimeImmutable $from, \DateTimeImmutable $to, array $context = []): string
    {
        $out   = ['ZKBioTime portal diagnostics', str_repeat('=', 28)];
        $out[] = 'Run at: ' . gmdate('Y-m-d H:i') . ' UTC';
        $out[] = 'Period checked: ' . $from->format('Y-m-d') . ' to ' . $to->format('Y-m-d');
        $out[] = 'Employee code given: ' . ($empCode !== null && $empCode !== '' ? 'yes' : 'no (rows of any employee; field names only)');

        foreach ($context as $label => $value) {
            $out[] = $label . ': ' . $value;
        }

        // 1. Connection.
        $out[] = '';
        $out[] = '1. Connection (employee list)';

        try {
            $page  = $client->probeEmployees();
            $out[] = '   OK, ' . ($page->count === null ? 'count not given' : $page->count . ' employees');
        } catch (ZkbException $e) {
            $out[] = '   FAILED: ' . self::error($e);
            $out[] = '';
            $out[] = 'Stopped: without a working connection the other checks cannot run.';

            return implode("\n", $out);
        }

        // 2. API description.
        $out[]   = '';
        $out[]   = '2. API list (/api/docs/, /api/personnel_docs/)';
        $catalog = [];

        try {
            $result  = $client->apiCatalog();
            $catalog = $result['endpoints'];
            $out[]   = '   Read from: ' . implode(', ', $result['sources']) . ' (' . \count($catalog) . ' list endpoints)';

            foreach ($catalog as $ep) {
                $out[] = '   - ' . $ep['path'] . ($ep['params'] !== [] ? '  [' . implode(', ', \array_slice($ep['params'], 0, 12)) . ']' : '');
            }
        } catch (ZkbException $e) {
            $out[] = '   FAILED: ' . self::error($e);
        }

        // 3. Punches.
        $out[] = '';
        $out[] = '3. Punches ' . ZkbClient::transactionsPath();

        try {
            $out = array_merge($out, self::describe($client->probeTransactions($empCode, $from, $to)));
        } catch (ZkbException $e) {
            $out[] = '   FAILED: ' . self::error($e);
        }

        // 4. Calculated attendance candidates: attendance-like paths of the API list, then the
        // documented ones. Built-in paths are never candidates.
        $paths = [];

        foreach ($catalog as $ep) {
            if (preg_match(self::ATT_PATH, $ep['path']) && !ZkbClient::isBuiltInPath($ep['path'])) {
                $paths[$ep['path']] = ApiCatalog::suggestParams($ep['params']);
            }
        }

        foreach (ZkbClient::diagnosticPaths() as $path) {
            $paths[$path] ??= ['emp' => 'emp_code', 'from' => 'start_date', 'to' => 'end_date'];
        }

        $out[] = '';
        $out[] = '4. Calculated attendance';

        foreach (\array_slice($paths, 0, self::MAX_PROBES, true) as $path => $p) {
            $query = [$p['from'] => $from->format('Y-m-d'), $p['to'] => $to->format('Y-m-d')];

            if ($empCode !== null && $empCode !== '') {
                $query[$p['emp']] = $empCode;
            }

            $out[] = '   ' . $path . '  (' . implode(', ', array_keys($query)) . ')';

            try {
                $out = array_merge($out, self::describe($client->probe($path, $query, 10), '      '));
            } catch (ZkbException $e) {
                $out[] = '      FAILED: ' . self::error($e);
            }
        }

        return implode("\n", $out);
    }

    /**
     * @return list<string>
     */
    private static function describe(ListPage $page, string $indent = '   '): array
    {
        $lines  = [$indent . 'OK, ' . \count($page->items) . ' sample rows (' . ($page->count === null ? 'total not given' : $page->count . ' in total') . ')'];
        $fields = FieldInspector::inspect($page->items);

        if ($fields === []) {
            $lines[] = $indent . 'No fields (empty answer).';

            return $lines;
        }

        foreach ($fields as $name => $info) {
            $lines[] = $indent . '- ' . $name . ' (' . $info['type'] . ')' . ($info['values'] !== [] ? ': ' . implode(' | ', \array_slice($info['values'], 0, 10)) : '');
        }

        return $lines;
    }

    private static function error(ZkbException $e): string
    {
        $text = $e->getKind();

        if ($e->getHttpStatus() > 0) {
            $text .= ', HTTP ' . $e->getHttpStatus();
        }

        if ($e->getServerMessage() !== '') {
            $text .= ', server said: ' . mb_substr($e->getServerMessage(), 0, 200);
        }

        $hint = match ($e->getKind()) {
            ZkbException::BAD_RESPONSE => ' (not API data, usually a web page: this path does not exist on this server)',
            ZkbException::NOT_FOUND    => ' (this path does not exist on this server)',
            ZkbException::PERMISSION   => ' (the API user may not read this)',
            default                    => '',
        };

        return $text . $hint;
    }
}
