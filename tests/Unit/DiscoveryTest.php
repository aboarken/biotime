<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Tests\Unit;

use BiotimePortal\Component\Biotime\Administrator\Api\ZkbException;
use BiotimePortal\Component\Biotime\Administrator\Attendance\SourceConfig;
use BiotimePortal\Component\Biotime\Administrator\Discovery\ApiCatalog;
use BiotimePortal\Component\Biotime\Administrator\Discovery\FieldInspector;
use BiotimePortal\Component\Biotime\Tests\Support\Fakes;
use BiotimePortal\Component\Biotime\Tests\Support\RoutingTransport;
use PHPUnit\Framework\TestCase;

/**
 * Find Endpoints: reading the API description, inspecting sample rows without showing personal
 * values, and the suggested settings.
 */
final class DiscoveryTest extends TestCase
{
    private const SWAGGER = [
        'swagger'  => '2.0',
        'basePath' => '/',
        'paths'    => [
            '/att/api/dailyReport/'        => ['get' => ['summary' => 'Daily report', 'parameters' => [
                ['name' => 'emp_code', 'in' => 'query'], ['name' => 'start_date', 'in' => 'query'], ['name' => 'end_date', 'in' => 'query'], ['name' => 'page', 'in' => 'query'],
            ]]],
            '/att/api/dailyReport/{id}/'   => ['get' => ['summary' => 'One row']],
            '/personnel/api/departments/'  => ['get' => ['summary' => 'Departments'], 'post' => []],
            '/att/api/leaves/'             => ['get' => ['tags' => ['att']], 'parameters' => [['name' => 'employee', 'in' => 'query']]],
            '/api-token-auth/'             => ['post' => []],
            '/bad path/'                   => ['get' => []],
        ],
    ];

    public function testOpenApiListEndpointsOnlyAttendanceFirst(): void
    {
        $list = ApiCatalog::parse(self::SWAGGER);

        self::assertSame(['/att/api/dailyReport/', '/att/api/leaves/', '/personnel/api/departments/'], array_column($list, 'path'));
        self::assertSame(['emp_code', 'start_date', 'end_date'], $list[0]['params'], 'paging parameters are left out');
        self::assertSame(['employee'], $list[1]['params'], 'path-level parameters are read');
        self::assertTrue($list[0]['relevant']);
        self::assertFalse($list[2]['relevant']);
    }

    public function testCoreJsonDocument(): void
    {
        $doc = ['_type' => 'document', 'att' => ['report' => ['list' => [
            '_type' => 'link', 'url' => '/att/api/timecard/', 'action' => 'get', 'description' => '<p>Time card</p>',
            'fields' => [['name' => 'emp_code', 'location' => 'query'], ['name' => 'id', 'location' => 'path']],
        ], 'create' => ['_type' => 'link', 'url' => '/att/api/timecard/', 'action' => 'post']]]];

        $list = ApiCatalog::parse($doc);

        self::assertSame([['path' => '/att/api/timecard/', 'summary' => 'Time card', 'params' => ['emp_code'], 'relevant' => true]], $list);
        self::assertTrue(ApiCatalog::looksLikeDescription($doc));
        self::assertFalse(ApiCatalog::looksLikeDescription(['detail' => 'x']));
    }

    public function testParameterSuggestions(): void
    {
        self::assertSame(['emp' => 'emp_code', 'from' => 'start_date', 'to' => 'end_date'], ApiCatalog::suggestParams(['start_date', 'emp_code', 'end_date']));
        self::assertSame(['emp' => 'employee', 'from' => 'begin_time', 'to' => 'end_time'], ApiCatalog::suggestParams(['employee', 'begin_time', 'end_time']));
        self::assertSame(['emp' => 'emp_code', 'from' => 'start_date', 'to' => 'end_date'], ApiCatalog::suggestParams([]));
    }

    public function testClientTriesTheDescriptionFormsInOrder(): void
    {
        $zkb = new RoutingTransport();
        // /api/docs/ answers only in CoreJSON (?format=openapi is not found); personnel docs as OpenAPI.
        $zkb->on('/api/docs/', static fn (array $q): array => ($q['format'] ?? '') === 'corejson'
            ? [200, ['_type' => 'document', 'x' => ['_type' => 'link', 'url' => '/att/api/timecard/', 'action' => 'get']]]
            : [404, ['detail' => 'Not found.']]);
        $zkb->on('/api/personnel_docs/', static fn (): array => [200, self::SWAGGER]);

        $result = Fakes::client($zkb)->apiCatalog();

        self::assertSame(['/api/docs/?format=corejson', '/api/personnel_docs/?format=openapi'], $result['sources']);
        self::assertContains('/att/api/timecard/', array_column($result['endpoints'], 'path'));
        self::assertContains('/att/api/dailyReport/', array_column($result['endpoints'], 'path'));
    }

    /**
     * As seen on a real ZKBioTime server: no OpenAPI (404), and CoreJSON only when the CoreJSON
     * media type is accepted (plain application/json alone is refused with 406).
     */
    public function testCoreJsonIsRequestedWithItsOwnMediaType(): void
    {
        $zkb   = new RoutingTransport();
        $serve = static function (array $q, array $headers): array {
            if (($q['format'] ?? '') !== 'corejson') {
                return [404, ['detail' => 'Not found.']];
            }

            if (!str_contains($headers['Accept'] ?? '', 'application/coreapi+json')) {
                return [406, ['detail' => 'Could not satisfy the request Accept header.']];
            }

            return [200, ['_type' => 'document', 'att' => ['timecard' => ['list' => ['_type' => 'link', 'url' => '/att/api/timecard/', 'fields' => [['name' => 'emp_code', 'location' => 'query']]]]]]];
        };
        $zkb->on('/api/docs/', $serve);
        $zkb->on('/api/personnel_docs/', $serve);

        $result = Fakes::client($zkb)->apiCatalog();

        self::assertSame(['/api/docs/?format=corejson', '/api/personnel_docs/?format=corejson'], $result['sources']);
        self::assertSame(['/att/api/timecard/'], array_column($result['endpoints'], 'path'));
        self::assertSame(['emp_code'], $result['endpoints'][0]['params']);
    }

    public function testAWebPageInsteadOfDataIsUnreadable(): void
    {
        $zkb = new RoutingTransport();
        $zkb->on('/att/api/does_not_exist/', static fn (): array => [200, '<!DOCTYPE html><html><body>ZKBioTime</body></html>']);

        try {
            Fakes::client($zkb)->probe('/att/api/does_not_exist/', ['emp_code' => 'E1']);
            self::fail('Expected an exception');
        } catch (ZkbException $e) {
            self::assertSame(ZkbException::BAD_RESPONSE, $e->getKind());
        }
    }

    public function testNoReadableDescriptionIsReported(): void
    {
        $zkb = new RoutingTransport();
        $zkb->on('/api/docs/', static fn (): array => [200, ['html' => 'not a description']]);

        try {
            Fakes::client($zkb)->apiCatalog();
            self::fail('Expected an exception');
        } catch (ZkbException $e) {
            self::assertContains($e->getKind(), [ZkbException::NOT_FOUND, ZkbException::BAD_RESPONSE]);
        }
    }

    public function testProbeRefusesLoginPaths(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Fakes::client(new RoutingTransport())->probe('/jwt-api-token-auth/', []);
    }

    /** @return list<array<string, mixed>> */
    private static function rows(): array
    {
        return [
            ['id' => 501, 'employee' => ['emp_code' => 'E1001', 'first_name' => 'Fatimah', 'email' => 'fatimah@corp.test'], 'att_date' => '2026-11-02',
                'timetable_name' => 'Morning', 'in_time' => '08:00', 'out_time' => '17:00', 'clock_in' => '2026-11-02 07:58:00', 'clock_out' => '2026-11-02 17:03:00',
                'late' => 0, 'early_leave' => 0, 'work_time' => 545, 'ot' => 3, 'att_status' => 'Normal', 'card_no' => '99887766'],
            ['id' => 502, 'employee' => ['emp_code' => 'E1001', 'first_name' => 'Fatimah', 'email' => 'fatimah@corp.test'], 'att_date' => '2026-11-03',
                'timetable_name' => 'Morning', 'in_time' => '08:00', 'out_time' => '17:00', 'clock_in' => '2026-11-03 08:20:00', 'clock_out' => null,
                'late' => 20, 'early_leave' => 0, 'work_time' => 0, 'ot' => 0, 'att_status' => 'Late', 'card_no' => '99887766'],
            ['id' => 503, 'employee' => ['emp_code' => 'E1001', 'first_name' => 'Fatimah', 'email' => 'fatimah@corp.test'], 'att_date' => '2026-11-04',
                'att_status' => 'Absence', 'card_no' => '99887766'],
        ];
    }

    public function testInspectionShowsNamesAndKindsButNoPersonalValues(): void
    {
        $fields = FieldInspector::inspect(self::rows());

        self::assertSame('date', $fields['att_date']['type']);
        self::assertSame('time', $fields['in_time']['type']);
        self::assertSame('datetime', $fields['clock_in']['type']);
        self::assertSame('number', $fields['late']['type']);
        self::assertSame('text', $fields['employee.first_name']['type']);
        self::assertSame(['Absence', 'Late', 'Normal'], $fields['att_status']['values'], 'status texts are listed');

        $shown = json_encode($fields);
        foreach (['Fatimah', 'fatimah@corp.test', 'E1001', '99887766'] as $personal) {
            self::assertStringNotContainsString($personal, $shown);
        }
    }

    public function testSuggestionsBecomeAValidDailySource(): void
    {
        $fields  = FieldInspector::inspect(self::rows());
        $suggest = FieldInspector::suggestFields('daily', $fields);

        self::assertSame([
            'emp_code' => 'employee.emp_code', 'date' => 'att_date', 'schedule' => 'timetable_name',
            'sched_start' => 'in_time', 'sched_end' => 'out_time', 'check_in' => 'clock_in', 'check_out' => 'clock_out',
            'late' => 'late', 'early' => 'early_leave', 'worked' => 'work_time', 'overtime' => 'ot', 'status' => 'att_status',
        ], $suggest);

        $statuses = FieldInspector::suggestStatuses('daily', $fields['att_status']['values']);
        self::assertSame(['Absence' => 'absent', 'Late' => 'late', 'Normal' => 'present'], $statuses);

        $source = SourceConfig::parse('daily', [
            'path'       => '/att/api/dailyReport/',
            'fields'     => implode("\n", array_map(static fn ($k, $v) => "$k = $v", array_keys($suggest), $suggest)),
            'status_map' => implode("\n", array_map(static fn ($k, $v) => "$k = $v", array_keys($statuses), $statuses)),
        ]);

        self::assertTrue($source->isConfigured());
        self::assertSame('late', $source->status('LATE'));
    }

    public function testLeaveSuggestions(): void
    {
        $fields = FieldInspector::inspect([
            ['emp_code' => 'E1', 'start_time' => '2026-11-10 08:00:00', 'end_time' => '2026-11-11 17:00:00', 'category' => 'Annual', 'days' => 2, 'approval_status' => 'Approved'],
            ['emp_code' => 'E1', 'start_time' => '2026-11-20 08:00:00', 'end_time' => '2026-11-20 17:00:00', 'category' => 'Sick', 'days' => 1, 'approval_status' => 'Rejected'],
        ]);

        self::assertSame(['emp_code' => 'emp_code', 'start' => 'start_time', 'end' => 'end_time', 'type' => 'category', 'duration' => 'days', 'status' => 'approval_status'], FieldInspector::suggestFields('leave', $fields));
        self::assertSame(['Approved' => 'approved', 'Rejected' => 'rejected', 'Waiting' => 'pending'], FieldInspector::suggestStatuses('leave', ['Approved', 'Rejected', 'Waiting']));
    }
}
