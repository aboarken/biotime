<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Tests\Unit;

use BiotimePortal\Component\Biotime\Administrator\Database\DbConfig;
use BiotimePortal\Component\Biotime\Administrator\Database\DbConnector;
use BiotimePortal\Component\Biotime\Administrator\Database\DbException;
use BiotimePortal\Component\Biotime\Administrator\Database\LeaveReader;
use BiotimePortal\Component\Biotime\Tests\Support\CatalogueQuery;
use PHPUnit\Framework\TestCase;

/**
 * My Leaves and My Approvals (1.1.0): leave requests and their stages, read only.
 */
final class LeaveReaderTest extends TestCase
{
    private function real(): LeaveReader
    {
        $env = getenv('BIOTIME_TEST_PG');

        if (!\is_string($env) || $env === '' || !\extension_loaded('pdo_pgsql')) {
            self::markTestSkipped('BIOTIME_TEST_PG not set');
        }

        [$host, $port, $db, $user, $password] = explode(':', $env, 5);
        $config = new DbConfig('pgsql', $host, (int) $port, $db, $user, $password, DbConfig::SSL_REQUIRE);

        return new LeaveReader(static fn () => (new DbConnector())->connect($config), 'Asia/Riyadh');
    }

    /** Against the PostgreSQL fixture (tests/Support/sql/zkbiotime-pgsql.sql), when BIOTIME_TEST_PG is set. */
    public function testMyLeavesAreTheEmployeesOwnWithTheirStages(): void
    {
        $bob = $this->real()->requestsOf(2);

        self::assertSame([500, 503, 504, 501], array_column($bob, 'id'), 'newest first, only Bob\'s');
        self::assertSame(['Annual Leave', 'Annual Leave', 'Annual Leave', 'Sick Leave'], array_column($bob, 'leave'));

        $first = $bob[0];
        self::assertSame(['2026-11-24 08:00', '2026-11-24 16:00', '2026-11-10 09:30'], [$first['from'], $first['to'], $first['applied']], 'in the ZKBioTime server time zone');
        self::assertSame('Family event', $first['reason'], 'no markup');
        self::assertSame('ticket.pdf', $first['attachment'], 'the file name only');
        self::assertSame(1.0, $first['days']);
        self::assertSame(0, $first['status']);
        self::assertSame([['Head of Department', 1, 0, true], ['HR', 2, 0, false]], array_map(static fn (array $s): array => [$s['name'], $s['order'], $s['status'], $s['current']], $first['stages']));

        $approved = $bob[3];
        self::assertSame(1, $approved['status']);
        self::assertSame(['Alice A', 'Alice A'], array_column($approved['stages'], 'approver'));
        self::assertSame('Get well', $approved['stages'][0]['remark']);

        self::assertSame([502], array_column($this->real()->requestsOf(1), 'id'), 'Alice\'s own');
        self::assertSame([], $this->real()->requestsOf(999));
    }

    public function testMyApprovalsAreTheRequestsAtTheEmployeesStage(): void
    {
        $waiting = $this->real()->waitingFor(1);

        // 500: stage 1 lists Alice's role; 503: stage 2 was sent to Alice; 502 is her own; 501 and
        // 504 are decided.
        self::assertSame([503, 500], array_column($waiting, 'id'), 'the longest waiting first');
        self::assertSame(['Bob B', 'E2', 'Human Resources'], [$waiting[0]['employee'], $waiting[0]['emp_code'], $waiting[0]['department']]);
        self::assertSame([], $this->real()->waitingFor(2), 'Bob approves nothing');

        $decided = $this->real()->decidedBy(1);
        self::assertSame([[503, 'Head of Department', 1], [504, 'Head of Department', 2], [501, 'HR', 1], [501, 'Head of Department', 1]], array_map(static fn (array $r): array => [$r['id'], $r['decision']['stage'], $r['decision']['status']], $decided));
        self::assertSame('No cover', $decided[1]['decision']['remark']);
    }

    public function testWithoutTheGrantTheErrorSaysSo(): void
    {
        $q      = new CatalogueQuery('pgsql', ['FROM att_leave' => static fn (): array => throw new DbException(DbException::QUERY, 'permission denied for table att_leave')]);
        $reader = new LeaveReader(static fn () => $q);

        $this->expectException(DbException::class);
        $reader->requestsOf(1);
    }
}
