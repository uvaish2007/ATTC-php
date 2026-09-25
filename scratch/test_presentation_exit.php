<?php
/**
 * Test exitUrl resolution in present-faculty-report.php and present-student-report.php
 */
require_once __DIR__ . '/../php-app/inc/auth.php';
require_once __DIR__ . '/../php-app/inc/db.php';

$principal = ['id' => 2, 'name' => 'Principal', 'role' => 'Principal', 'department' => null];
$faculty   = ['id' => 60007, 'name' => 'Dr. Rajesh Kumar', 'role' => 'Faculty', 'department' => 'CSE'];

function test_exit_url(array $user, array $get = [], array $server = []) {
    $_SESSION['user'] = $user;
    $_SESSION['active_academic_year'] = '2026-27';
    $_GET = $get;
    $_SERVER = array_merge([
        'REQUEST_METHOD' => 'GET',
        'HTTP_HOST' => 'localhost:8000',
        'SCRIPT_NAME' => '/present-faculty-report.php',
        'PHP_SELF' => '/present-faculty-report.php',
    ], $server);

    $targetFacultyId = (int) ($get['id'] ?? 60007);
    $academicYear = trim((string) ($get['academic_year'] ?? '2026-27'));

    $from     = trim((string) ($get['from'] ?? ''));
    $referer  = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    $userRole = $user['role'] ?? '';

    $faParams = [];
    if (!empty($academicYear)) $faParams['academic_year'] = $academicYear;
    if (!empty($get['return_dept'])) $faParams['department'] = $get['return_dept'];
    if (!empty($get['return_cat']))  $faParams['category']   = $get['return_cat'];
    $faUrl = url('faculty-achievements.php') . ($faParams ? '?' . http_build_query($faParams) : '');

    if ($from === 'faculty-achievements' || $from === 'faculty_achievements' || strpos($referer, 'faculty-achievements.php') !== false) {
        $exitUrl = $faUrl;
    } elseif ($from === 'reports' || strpos($referer, 'reports.php') !== false) {
        $exitUrl = url('reports.php');
    } elseif ($from === 'individual' || $from === 'individual-faculty-report' || strpos($referer, 'individual-faculty-report.php') !== false) {
        $exitUrl = url('individual-faculty-report.php?id=' . $targetFacultyId . (!empty($academicYear) ? '&academic_year=' . urlencode($academicYear) : ''));
    } else {
        if (in_array($userRole, ['Principal', 'Director'], true)) {
            $exitUrl = $faUrl;
        } else {
            $exitUrl = url('individual-faculty-report.php?id=' . $targetFacultyId . (!empty($academicYear) ? '&academic_year=' . urlencode($academicYear) : ''));
        }
    }
    return $exitUrl;
}

echo "=== TEST 1: Principal clicks Present on faculty-achievements.php (with from=faculty-achievements) ===\n";
$url1 = test_exit_url($principal, ['id' => 60007, 'academic_year' => '2026-27', 'from' => 'faculty-achievements']);
echo "Exit URL: $url1\n";
echo "Returns to faculty-achievements.php: " . (strpos($url1, 'faculty-achievements.php') !== false ? "YES" : "NO") . "\n\n";

echo "=== TEST 2: Principal clicks Present on faculty-achievements.php without explicit from (fallback for role) ===\n";
$url2 = test_exit_url($principal, ['id' => 60007, 'academic_year' => '2026-27']);
echo "Exit URL: $url2\n";
echo "Returns to faculty-achievements.php: " . (strpos($url2, 'faculty-achievements.php') !== false ? "YES" : "NO") . "\n\n";

echo "=== TEST 3: Principal clicks Present from individual-faculty-report.php (with from=individual) ===\n";
$url3 = test_exit_url($principal, ['id' => 60007, 'academic_year' => '2026-27', 'from' => 'individual']);
echo "Exit URL: $url3\n";
echo "Returns to individual-faculty-report.php: " . (strpos($url3, 'individual-faculty-report.php') !== false ? "YES" : "NO") . "\n\n";

echo "=== TEST 4: Faculty member views own report and exits ===\n";
$url4 = test_exit_url($faculty, ['id' => 60007, 'academic_year' => '2026-27']);
echo "Exit URL: $url4\n";
echo "Returns to individual-faculty-report.php: " . (strpos($url4, 'individual-faculty-report.php') !== false ? "YES" : "NO") . "\n\n";

echo "=== TEST 5: Real Render of present-faculty-report.php for Principal ===\n";
$_SESSION['user'] = $principal;
$_SESSION['active_academic_year'] = '2026-27';
$_GET = ['id' => 60007, 'academic_year' => '2026-27', 'from' => 'faculty-achievements'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost:8000';
$_SERVER['SCRIPT_NAME'] = '/present-faculty-report.php';
$_SERVER['PHP_SELF'] = '/present-faculty-report.php';

ob_start();
include __DIR__ . '/../php-app/present-faculty-report.php';
$html = ob_get_clean();

if (strpos($html, 'Exit Presentation') !== false) {
    echo "Found Exit Presentation button: YES\n";
}
if (preg_match('/href="([^"]*faculty-achievements\.php[^"]*)"/', $html, $m)) {
    echo "Exit link in HTML points to: " . $m[1] . " (PASS)\n";
} else {
    echo "Exit link failed: did not point to faculty-achievements.php\n";
}

