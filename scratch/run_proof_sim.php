<?php
/**
 * Subprocess runner for testing proof.php in isolation via base64 encoded input.
 */
if ($argc < 2) {
    exit(1);
}

$raw = base64_decode($argv[1]);
$args = json_decode($raw, true) ?: [];
$userSession = $args['user'] ?? null;
$getParams   = $args['get'] ?? [];

require_once __DIR__ . '/../php-app/inc/config.php';
require_once __DIR__ . '/../php-app/inc/auth.php';

auth_boot();

if ($userSession) {
    $_SESSION['user'] = [
        'id'         => (int) $userSession['id'],
        'name'       => $userSession['name'] ?? 'Test User',
        'email'      => $userSession['email'] ?? 'test@atts.edu',
        'role'       => $userSession['role'] ?? 'Faculty',
        'department' => $userSession['department'] ?? null,
    ];
} else {
    $_SESSION = [];
}

$_GET = $getParams;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost:8000';
$_SERVER['QUERY_STRING'] = http_build_query($getParams);

ob_start();

register_shutdown_function(function() {
    $out = ob_get_clean();
    $status = http_response_code();
    $headers = headers_list();
    $isRedirect = false;
    foreach ($headers as $h) {
        if (stripos($h, 'Location:') === 0) {
            $isRedirect = true;
            break;
        }
    }
    echo json_encode([
        'code'        => $isRedirect ? 302 : ($status ?: 200),
        'len'         => strlen($out),
        'headers'     => $headers,
        'is_redirect' => $isRedirect,
    ]);
});

include __DIR__ . '/../php-app/proof.php';
