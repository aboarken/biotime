<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Tests\Unit;

use BiotimePortal\Component\Biotime\Administrator\Discovery\Diagnostics;
use BiotimePortal\Component\Biotime\Tests\Support\Fakes;
use BiotimePortal\Component\Biotime\Tests\Support\RoutingTransport;
use PHPUnit\Framework\TestCase;

/**
 * One-click Diagnostics: every check in one report, with field names and kinds only.
 */
final class DiagnosticsTest extends TestCase
{
    private function server(): RoutingTransport
    {
        $zkb = new RoutingTransport();
        $zkb->list('/personnel/api/employees/', [['id' => 1, 'emp_code' => 'E1', 'first_name' => 'Secret Name', 'email' => 'secret@example.com']]);
        $zkb->on('/api/docs/', static fn (): array => [406, ['detail' => 'Could not satisfy the request Accept header.']]);
        $zkb->on('/api/personnel_docs/', static fn (array $q): array => ($q['format'] ?? '') === 'corejson'
            ? [200, ['_type' => 'document', 'p' => ['_type' => 'link', 'url' => '/personnel/api/areas/', 'action' => 'get', 'fields' => [['name' => 'area_code', 'location' => 'query']]]]]
            : [404, ['detail' => 'Not found.']]);
        $zkb->list('/iclock/api/transactions/', [['emp_code' => 'E1', 'punch_time' => '2026-11-02 07:45:00', 'punch_state' => '0', 'punch_state_display' => 'Check In']]);
        $zkb->list('/att/api/totalTimeCardReport/', [['emp_code' => 'E1', 'att_date' => '2026-11-02', 'late' => 5, 'status' => 'Late']]);

        return $zkb;
    }

    public function testReportHasEveryCheckAndNoPersonalValues(): void
    {
        $zkb    = $this->server();
        $report = Diagnostics::run(Fakes::client($zkb), 'E1', new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-15'), ['Component version' => '0.3.3']);

        self::assertStringContainsString('Component version: 0.3.3', $report);
        self::assertStringContainsString('1 employees', $report);
        self::assertStringContainsString('/api/personnel_docs/?format=corejson', $report);
        self::assertStringContainsString('- /personnel/api/areas/  [area_code]', $report);
        self::assertStringContainsString('- punch_state_display (text): Check In', $report);
        self::assertStringContainsString('/att/api/totalTimeCardReport/  (start_date, end_date, emp_code)', $report);
        self::assertStringContainsString('- att_date (date)', $report);
        self::assertStringContainsString('- status (text): Late', $report);

        foreach (['Secret Name', 'secret@example.com', 'E1', '07:45', 'apiuser', 'JWT '] as $private) {
            self::assertStringNotContainsString($private, $report);
        }

        self::assertEquals([['page' => '1', 'page_size' => '10', 'start_date' => '2026-11-01', 'end_date' => '2026-11-15', 'emp_code' => 'E1']], $zkb->queries('/att/api/totalTimeCardReport/'));
    }

    public function testMissingEndpointAndStopOnConnectionError(): void
    {
        $zkb = new RoutingTransport();
        $zkb->list('/personnel/api/employees/', []);

        $report = Diagnostics::run(Fakes::client($zkb), null, new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-15'));

        self::assertStringContainsString('Employee code given: no', $report);
        self::assertMatchesRegularExpression('#/att/api/totalTimeCardReport/  \(start_date, end_date\)\n\s+FAILED: not_found, HTTP 404#', $report);

        $down = new RoutingTransport();
        $down->on('/personnel/api/employees/', static fn (): array => [503, 'down']);
        $report = Diagnostics::run(Fakes::client($down), null, new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-15'));

        self::assertStringContainsString('FAILED: server, HTTP 503', $report);
        self::assertStringContainsString('Stopped:', $report);
        self::assertSame([], $down->queries('/att/api/totalTimeCardReport/'));
    }
}
