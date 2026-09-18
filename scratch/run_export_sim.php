<?php
/**
 * Subprocess runner for export scripts via base64 encoded input.
 */
if ($argc < 3) {
    exit(1);
}

$scriptName = $argv[1];
$getParams  = json_decode(base64_decode($argv[2]), true) ?: [];
$destFile   = $argv[3] ?? null;

require_once __DIR__ . '/../php-app/inc/config.php';
require_once __DIR__ . '/../php-app/inc/auth.php';

auth_boot();

$_SESSION['user'] = [
    'id'         => 1,
    'name'       => 'Admin',
    'email'      => 'admin@atts.edu',
    'role'       => 'Admin',
    'department' => null,
];

$_GET = $getParams;
$_POST = [];
$_REQUEST = $getParams;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost:8000';
$_SERVER['QUERY_STRING'] = http_build_query($getParams);

ob_start();

register_shutdown_function(function() use ($destFile) {
    $out = ob_get_clean();
    if ($destFile) {
        file_put_contents($destFile, $out);
    } else {
        echo $out;
    }
});

include __DIR__ . '/../php-app/' . $scriptName;
