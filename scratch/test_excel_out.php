<?php
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

$_GET = ['type' => 'journal', 'format' => 'excel'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost:8000';

ob_start();
try {
    include __DIR__ . '/../php-app/record-report.php';
} catch (\Throwable $t) {
    echo "ERROR: " . $t->getMessage() . "\n" . $t->getTraceAsString();
}
$out = ob_get_clean();
echo "Length: " . strlen($out) . "\n";
echo "Prefix: " . substr($out, 0, 50) . "\n";
