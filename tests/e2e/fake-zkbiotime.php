<?php

/**
 * TEST FIXTURE ONLY: a stand-in for a ZKBioTime server, used by tests/e2e/admin-settings.mjs to
 * drive the real Joomla pages and cURL transport without a real server. It answers the forms
 * documented in docs/API-MAPPING.md (all UNVERIFIED until checked on a real 9.5 server) and
 * holds no real data. Never deploy it.
 *
 * Run: php -S 127.0.0.1:18081 tests/e2e/fake-zkbiotime.php
 *
 * @license GNU General Public License version 2 or later
 */

const USER     = 'portal-api';
const PASSWORD = 'Fixture-Pass-123';

$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$b64  = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$out  = static function (int $status, array $data): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
};

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

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

if (!preg_match('/^(JWT|Token) \S+$/', $auth)) {
    $out(401, ['detail' => 'Authentication credentials were not provided.']);

    return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/personnel/api/employees/') {
    $out(200, ['count' => 3, 'next' => null, 'previous' => null, 'msg' => '', 'code' => 0, 'data' => [['id' => 1, 'emp_code' => 'FIXTURE1']]]);

    return true;
}

$out(404, ['detail' => 'Not found.']);

return true;
