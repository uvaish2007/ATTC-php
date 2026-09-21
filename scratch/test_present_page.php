<?php
/**
 * Test present-faculty-report.php and individual-faculty-report.php execution for Coordinator
 */
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost:8000';

require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/inc/auth.php';

// Simulate logged in CSE coordinator
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['user'] = [
    'id' => 60013,
    'name' => 'CSE Coord User',
    'role' => 'Coordinator',
    'department' => 'CSE',
    'email' => 'cse_coord@atts.local',
];

// Test 1: present-faculty-report.php
$_GET['id'] = '60007';
$_GET['academic_year'] = '2026-27';

ob_start();
include __DIR__ . '/../php-app/present-faculty-report.php';
$htmlPres = ob_get_clean();

if (strpos($htmlPres, 'Access Denied') !== false) {
    echo "[FAIL] Access Denied encountered on present-faculty-report.php!\n";
    exit(1);
}
echo "[PASS] 1. present-faculty-report.php rendered presentation for Dr. Rajesh Kumar (CSE Coordinator -> Computer Science and Engineering Faculty)!\n";

// Test 2: individual-faculty-report.php
ob_start();
include __DIR__ . '/../php-app/individual-faculty-report.php';
$htmlIndiv = ob_get_clean();

if (strpos($htmlIndiv, 'Access Denied') !== false) {
    echo "[FAIL] Access Denied encountered on individual-faculty-report.php!\n";
    exit(1);
}
echo "[PASS] 2. individual-faculty-report.php rendered individual report for Dr. Rajesh Kumar!\n";

// Test 3: Verify Cross-Department Access Denied remains active
// Trying to access Jack (ID 60021, Aero)
$_GET['id'] = '60021';
ob_start();
include __DIR__ . '/../php-app/present-faculty-report.php';
$htmlDenied = ob_get_clean();

if (strpos($htmlDenied, 'Access Denied') !== false) {
    echo "[PASS] 3. Access Denied correctly blocked CSE Coordinator from accessing Aero faculty presentation!\n";
} else {
    echo "[FAIL] 3. CSE Coordinator should have been blocked from Aero faculty presentation!\n";
    exit(1);
}

echo "=== ALL PAGE-LEVEL COORDINATOR CHECKS PASSED! ===\n";
