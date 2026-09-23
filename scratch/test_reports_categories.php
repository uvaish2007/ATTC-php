<?php
require_once __DIR__ . '/../php-app/inc/auth.php';
require_once __DIR__ . '/../php-app/inc/db.php';

$admin = ['id' => 1, 'name' => 'Admin User', 'role' => 'Admin', 'department' => null];
$_SESSION['user'] = $admin;
$_SESSION['active_academic_year'] = '2026-27';
$_SESSION['admin_year_gate'] = true;
$_GET = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost:8000';
$_SERVER['SCRIPT_NAME'] = '/reports.php';
$_SERVER['PHP_SELF'] = '/reports.php';

ob_start();
include __DIR__ . '/../php-app/reports.php';
$html = ob_get_clean();

echo "1. Contains toggleCatsAllBtn: " . (strpos($html, 'toggleCatsAllBtn') !== false ? "YES" : "NO") . "\n";
echo "2. Contains expandAllBtn: " . (strpos($html, 'expandAllBtn') !== false ? "YES" : "NO") . "\n";
echo "3. Contains js-cat-btn: " . (strpos($html, 'js-cat-btn') !== false ? "YES" : "NO") . "\n";
echo "4. Contains cat-btn-txt: " . (strpos($html, 'cat-btn-txt') !== false ? "YES" : "NO") . "\n";
echo "5. Contains View only: " . (strpos($html, 'View only') !== false ? "YES" : "NO") . "\n";
