<?php

/**
 * TEST FIXTURE ONLY: a stand-in for a ZKBioTime server, used by tests/e2e/*.mjs to drive the real
 * Joomla pages and cURL transport without a real server. It answers the forms documented in
 * docs/API-MAPPING.md (all UNVERIFIED until checked on a real 9.5 server) and holds invented
 * fixture data only. The /att/api/fixture_daily/ path does not exist in ZKBioTime: it stands in for
 * whatever endpoint the administrator configures as the daily source. Never deploy it.
 *
 * Run: php -S 127.0.0.1:18081 tests/e2e/fake-zkbiotime.php
 *
 * @license GNU General Public License version 2 or later
 */

const USER     = 'portal-api';
const PASSWORD = 'Fixture-Pass-123';

$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$q    = $_GET;
$b64  = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$out  = static function (int $status, array $data): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
};

// Lets the browser check confirm that "Connect via" keeps the server URL's name in the Host header.
error_log('fake-zkbiotime Host: ' . ($_SERVER['HTTP_HOST'] ?? '-') . ' ' . $path);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && \in_array($path, ['/jwt-api-token-auth/', '/api-token-auth/'], true)) {
    $in = json_decode((string) file_get_contents('php://input'), true) ?: [];

    if (($in['username'] ?? '') !== USER || ($in['password'] ?? '') !== PASSWORD) {
        $out(400, ['non_field_errors' => ['Unable to log in with provided credentials.']]);

        return true;
    }

    $token = $path === '/api-token-auth/'
        ? 'fixturetoken0123456789abcdef'
        : $b64('{"alg":"HS256","typ":"JWT"}') . '.' . $b64(json_encode(['user_id' => 1, 'username' => USER, 'exp' => time() + 300])) . '.' . $b64('fixture');

    $out(200, ['token' => $token]);

    return true;
}

if (!preg_match('/^(JWT|Token) \S+$/', $_SERVER['HTTP_AUTHORIZATION'] ?? '')) {
    $out(401, ['detail' => 'Authentication credentials were not provided.']);

    return true;
}

// The API description, as the real server answers: no OpenAPI (404); CoreJSON (?format=corejson)
// only when the CoreJSON media type is accepted, otherwise 406.
if (\in_array($path, ['/api/docs/', '/api/personnel_docs/'], true)) {
    if (($q['format'] ?? '') !== 'corejson') {
        $out(404, ['detail' => 'Not found.']);

        return true;
    }

    if (!str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/coreapi+json')) {
        $out(406, ['detail' => 'Could not satisfy the request Accept header.']);

        return true;
    }

    $link = static fn (string $url, string $title, string ...$params): array => ['_type' => 'link', 'url' => $url, 'action' => 'get', 'description' => $title,
        'fields' => array_map(static fn ($n) => ['name' => $n, 'location' => 'query'], $params)];
    $doc  = $path === '/api/personnel_docs/'
        ? ['_type' => 'document', 'employees' => ['list' => $link('/personnel/api/employees/', 'Employee list', 'emp_code', 'page', 'page_size'), 'read' => $link('/personnel/api/employees/{id}/', 'Employee')]]
        : ['_type' => 'document',
            'transactions' => ['list' => $link('/iclock/api/transactions/', 'Transactions', 'emp_code', 'start_time', 'end_time')],
            'att'          => [
                'fixture_daily' => ['list' => $link('/att/api/fixture_daily/', 'Fixture daily attendance', 'emp_code', 'start_date', 'end_date')],
            ]];

    http_response_code(200);
    header('Content-Type: application/coreapi+json');
    echo json_encode($doc);

    return true;
}

$employees = [
    ['id' => 1, 'emp_code' => 'FIXTURE1', 'first_name' => 'Sara', 'last_name' => 'Fixture', 'email' => 'sara@example.test', 'hire_date' => '2021-03-01',
        'department' => ['id' => 1, 'dept_name' => 'Finance'], 'position' => ['id' => 2, 'position_name' => 'Accountant'], 'area' => [['id' => 1, 'area_name' => 'HQ']],
        'card_no' => '44556677', 'ssn' => '1112223334', 'device_password' => '1234'],
    ['id' => 2, 'emp_code' => 'FIXTURE2', 'first_name' => 'Omar', 'last_name' => 'Other', 'email' => 'omar@example.test', 'department' => ['dept_name' => 'IT']],
    ['id' => 3, 'emp_code' => 'FIXTURE3', 'first_name' => 'Same', 'last_name' => 'One', 'email' => 'shared@example.test'],
    ['id' => 4, 'emp_code' => 'FIXTURE4', 'first_name' => 'Same', 'last_name' => 'Two', 'email' => 'shared@example.test'],
];

$page = static function (array $rows) use ($q, $out): void {
    $size = max(1, (int) ($q['page_size'] ?? 10));
    $p    = max(1, (int) ($q['page'] ?? 1));
    $out(200, ['count' => \count($rows), 'next' => $p * $size < \count($rows) ? 'next-page' : null, 'previous' => null, 'msg' => '', 'code' => 0, 'data' => array_values(\array_slice($rows, ($p - 1) * $size, $size))]);
};

if ($path === '/personnel/api/employees/') {
    // Like a search API: a prefix match on emp_code.
    $page(array_filter($employees, static fn ($e) => !isset($q['emp_code']) || str_starts_with($e['emp_code'], (string) $q['emp_code'])));

    return true;
}

if (preg_match('#^/personnel/api/employees/(\d+)/$#', $path, $m)) {
    foreach ($employees as $e) {
        if ($e['id'] === (int) $m[1]) {
            $out(200, $e);

            return true;
        }
    }

    $out(404, ['detail' => 'Not found.']);

    return true;
}

// Fixture month: the current month, so "today" logic is exercised.
$ym = date('Y-m');

if ($path === '/iclock/api/transactions/') {
    $rows = [
        ['emp_code' => 'FIXTURE1', 'punch_time' => "$ym-02 07:58:10", 'punch_state_display' => 'Check In', 'verify_type_display' => 'Face', 'terminal_alias' => 'Main Gate', 'area_alias' => 'HQ'],
        ['emp_code' => 'FIXTURE1', 'punch_time' => "$ym-02 17:02:00", 'punch_state_display' => 'Check Out', 'verify_type_display' => 'Fingerprint', 'terminal_alias' => 'Main Gate', 'area_alias' => 'HQ'],
        ['emp_code' => 'FIXTURE1', 'punch_time' => "$ym-03 09:20:00", 'punch_state_display' => 'Check In', 'terminal_alias' => 'Side Door'],
        ['emp_code' => 'FIXTURE1', 'punch_time' => "$ym-03 09:20:20", 'punch_state_display' => 'Check In', 'terminal_alias' => 'Side Door'],
        ['emp_code' => 'FIXTURE1', 'punch_time' => "$ym-03 15:30:00", 'punch_state_display' => 'Check Out', 'terminal_alias' => 'Main Gate'],
        // A day with only a check-out punch (and no calculated row): must show under Check-Out.
        ['emp_code' => 'FIXTURE1', 'punch_time' => "$ym-09 17:10:00", 'punch_state' => '1', 'punch_state_display' => 'Check Out', 'terminal_alias' => 'Main Gate'],
        // As the real terminals record: "Check In" (0) then "Unknown" (255): 07:45 in, 15:20 out.
        ['emp_code' => 'FIXTURE1', 'punch_time' => "$ym-12 07:45:00", 'punch_state' => 0, 'punch_state_display' => 'Check In', 'terminal_alias' => 'Main Gate'],
        ['emp_code' => 'FIXTURE1', 'punch_time' => "$ym-12 15:20:00", 'punch_state' => 255, 'punch_state_display' => 'Unknown', 'terminal_alias' => 'Main Gate'],
        // Another employee's punch, as if the server ignored the filter: the portal must hide it.
        ['emp_code' => 'FIXTURE2', 'punch_time' => "$ym-04 06:06:06", 'punch_state_display' => 'OTHER-EMPLOYEE-PUNCH'],
    ];
    $page(array_filter($rows, static fn ($r) => str_starts_with($r['punch_time'], substr((string) ($q['start_time'] ?? ''), 0, 7))));

    return true;
}

if ($path === '/att/api/fixture_daily/') {
    $rows = [
        ['employee' => ['emp_code' => 'FIXTURE1'], 'att_date' => "$ym-02", 'timetable' => 'Day Shift', 'check_in_time' => '08:00', 'check_out_time' => '17:00', 'clock_in' => '07:58', 'clock_out' => '17:02', 'late' => 0, 'early' => 0, 'work_time' => 540, 'ot' => 2, 'att_status' => 'Normal'],
        ['employee' => ['emp_code' => 'FIXTURE1'], 'att_date' => "$ym-03", 'timetable' => 'Late Shift', 'check_in_time' => '09:00', 'check_out_time' => '18:00', 'clock_in' => '09:20', 'clock_out' => '15:30', 'late' => 20, 'early' => 150, 'work_time' => 370, 'ot' => 0, 'att_status' => 'Late'],
        ['employee' => ['emp_code' => 'FIXTURE1'], 'att_date' => "$ym-04", 'timetable' => 'Day Shift', 'check_in_time' => '08:00', 'check_out_time' => '17:00', 'att_status' => 'Absent'],
        ['employee' => ['emp_code' => 'FIXTURE1'], 'att_date' => "$ym-05", 'att_status' => 'Weekend'],
        ['employee' => ['emp_code' => 'FIXTURE2'], 'att_date' => "$ym-06", 'timetable' => 'OTHER-EMPLOYEE-SHIFT', 'att_status' => 'Normal'],
    ];
    $page(array_filter($rows, static fn ($r) => $r['att_date'] >= ($q['start_date'] ?? '') && $r['att_date'] <= ($q['end_date'] ?? '9999')));

    return true;
}

// Unknown paths: the real server answered a web page (HTTP 200, not JSON).
http_response_code(200);
header('Content-Type: text/html');
echo '<!DOCTYPE html><html><body>ZKBioTime</body></html>';

return true;
