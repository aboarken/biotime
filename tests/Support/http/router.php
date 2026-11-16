<?php

// Router for the PHP built-in server used by CurlTransportTest (test fixture only).
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/redirect') {
    header('Location: http://127.0.0.1:1/elsewhere', true, 302);

    return true;
}

http_response_code($path === '/missing' ? 404 : 200);
header('Content-Type: application/json');
echo json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'path'   => $path,
    'query'  => $_GET,
    'auth'   => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
    'body'   => file_get_contents('php://input'),
]);

return true;
