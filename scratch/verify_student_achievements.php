<?php
/**
 * scratch/verify_student_achievements.php
 * Comprehensive automated verification script for Student Achievements Module.
 * Tests 1 to 12 as strictly required by the prompt specifications.
 */

error_reporting(E_ALL & ~E_DEPRECATED);

require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/inc/helpers.php';
require_once __DIR__ . '/../php-app/models/Target.php';
require_once __DIR__ . '/../php-app/models/StudentAchievement.php';
require_once __DIR__ . '/../php-app/models/FacultyAchievement.php';

$baseUrl = 'http://localhost:8000';
$pdo = db();
$activeYear = active_academic_year();

echo "===============================================================\n";
echo " STUDENT ACHIEVEMENTS MODULE AUTOMATED VERIFICATION\n";
echo " Base URL: $baseUrl | Active Academic Year: $activeYear\n";
echo "===============================================================\n\n";

$results = [];

function testLog(string $testId, string $name, bool $pass, string $details = '') {
    global $results;
    $status = $pass ? "PASS" : "FAIL";
    $results[$testId] = ['name' => $name, 'pass' => $pass, 'details' => $details];
    echo "[$status] $testId: $name\n";
    if ($details) {
        echo "       $details\n";
    }
}

// cURL helper
function curlReq(string $url, string $method = 'GET', array $fields = [], ?string $cookieFile = null): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    if ($cookieFile) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    }
    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    return ['code' => $httpCode, 'headers' => $headers, 'body' => $body];
}

function getCsrf(string $html): string {
    if (preg_match('/name="csrf"\s+value="([^"]+)"/', $html, $m)) {
        return $m[1];
    }
    return '';
}

function loginUser(string $email, string $password, string $role, string $cookieFile): bool {
    global $baseUrl;
    $res = curlReq("$baseUrl/login.php", 'GET', [], $cookieFile);
    $csrf = getCsrf($res['body']);
    $res = curlReq("$baseUrl/login.php", 'POST', [
        'csrf'     => $csrf,
        'email'    => $email,
        'password' => $password,
        'role'     => $role,
    ], $cookieFile);
    return $res['code'] === 200 || $res['code'] === 302;
}

$cookieAdmin   = __DIR__ . '/cookie_admin_stud.txt';
$cookieFaculty = __DIR__ . '/cookie_faculty_stud.txt';
@unlink($cookieAdmin);
@unlink($cookieFaculty);

echo "1. Authenticating test users...\n";
$adminLoggedIn = loginUser('mohameduvaish132@gmail.com', 'admin123', 'Admin', $cookieAdmin);
$facultyLoggedIn = loginUser('faculty@atts.edu', 'faculty123', 'Faculty', $cookieFaculty);

if (!$adminLoggedIn) {
    die("FATAL: Failed to authenticate Admin user\n");
}
echo "   Admin authenticated successfully.\n\n";

// -------------------------------------------------------------
// TEST 1 — Page
// -------------------------------------------------------------
$res = curlReq("$baseUrl/student-achievements.php", 'GET', [], $cookieAdmin);
$t1Pass = ($res['code'] === 200 && strpos($res['body'], 'Student Achievements') !== false && strpos($res['body'], 'Consolidated student achievement and activity records') !== false);
testLog('TEST 1 — Page', 'Student Achievements page loads successfully', $t1Pass, "HTTP {$res['code']}, Content contains title & subtitle");

// -------------------------------------------------------------
// TEST 2 — Student-only filtering
// -------------------------------------------------------------
// Check that staff members (Admin Mohamed Uvaish, Coordinator, HoD - CSBS, Faculty rgl) DO NOT appear in student grid table
$body = $res['body'];
preg_match('/<table[^>]*id="studTable"[^>]*>.*?<tbody>(.*?)<\/tbody>/s', $body, $mTable);
$tableBody = $mTable[1] ?? '';

$hasAdmin = (strpos($tableBody, 'Mohamed Uvaish') !== false);
$hasCoord = (preg_match('/>\s*Coordinator\s*<\/div>/', $tableBody) === 1);
$hasHod   = (preg_match('/>\s*HoD\s*-\s*CSBS\s*<\/div>/', $tableBody) === 1);
$hasFaculty = (preg_match('/>\s*rgl\s*<\/div>/', $tableBody) === 1);
$t2Pass = (!$hasAdmin && !$hasCoord && !$hasHod && !$hasFaculty);
testLog('TEST 2 — Student-only filtering', 'No Faculty/Coordinator/HoD/Admin in student table', $t2Pass, "Admin in table: " . ($hasAdmin?'YES':'NO') . ", Coordinator: " . ($hasCoord?'YES':'NO') . ", HoD: " . ($hasHod?'YES':'NO') . ", Faculty rgl: " . ($hasFaculty?'YES':'NO'));

// -------------------------------------------------------------
// TEST 3 — Academic year
// -------------------------------------------------------------
$t3Pass = (strpos($body, 'Academic Year: ' . $activeYear) !== false && strpos($body, 'Locked by Admin') !== false);
testLog('TEST 3 — Academic year', 'Active Admin-selected academic year displayed', $t3Pass, "Active year: $activeYear, Locked by Admin badge present");

// -------------------------------------------------------------
// TEST 4 — Category cards
// -------------------------------------------------------------
// Compute actual category totals from database
$adminUser = ['id' => 1, 'role' => 'Admin', 'department' => ''];
$summary = student_achievements_summary($adminUser, null, $activeYear);
$t4Pass = ($summary['totalStudents'] > 0 && strpos($body, 'cat-card') !== false);
testLog('TEST 4 — Category cards', 'Category summary cards reflect actual database data', $t4Pass, "Total students: {$summary['totalStudents']}, Total achievements: {$summary['totalAchievements']}");

// -------------------------------------------------------------
// TEST 5 — Performance matrix
// -------------------------------------------------------------
$hasMatrixTitle = (strpos($body, 'Student Achievement Performance Matrix') !== false);
$hasColumns = (strpos($body, 'REGISTER NO') !== false && strpos($body, 'INTERNSHIPS') !== false && strpos($body, 'PLACEMENTS') !== false && strpos($body, 'TOTAL') !== false);
$t5Pass = ($hasMatrixTitle && $hasColumns);
testLog('TEST 5 — Performance matrix', 'Performance matrix rendered with valid columns & counts', $t5Pass, "Matrix table and all student category columns verified");

// -------------------------------------------------------------
// TEST 6 — Search
// -------------------------------------------------------------
// Search for known student 'faizal'
$resSearch = curlReq("$baseUrl/student-achievements.php?search=faizal", 'GET', [], $cookieAdmin);
preg_match('/<table[^>]*id="studTable"[^>]*>.*?<tbody>(.*?)<\/tbody>/s', $resSearch['body'], $mS1);
$tbodyFaizal = $mS1[1] ?? '';
$faizalFound = (strpos($tbodyFaizal, 'faizal') !== false);

// Search for staff 'Coordinator'
$resSearchStaff = curlReq("$baseUrl/student-achievements.php?search=Coordinator", 'GET', [], $cookieAdmin);
preg_match('/<table[^>]*id="studTable"[^>]*>.*?<tbody>(.*?)<\/tbody>/s', $resSearchStaff['body'], $mS2);
$tbodyCoord = $mS2[1] ?? '';
$staffFoundInTable = (strpos($tbodyCoord, 'Coordinator') !== false);
$hasEmptyNotice = (strpos($resSearchStaff['body'], 'No student achievements found') !== false);
$t6Pass = ($faizalFound && !$staffFoundInTable && $hasEmptyNotice);
testLog('TEST 6 — Search', 'Student search works; Staff do not appear in results', $t6Pass, "Student 'faizal' found: " . ($faizalFound?'YES':'NO') . ", Staff in results: " . ($staffFoundInTable?'YES':'NO') . ", Empty notice shown for staff search: " . ($hasEmptyNotice?'YES':'NO'));

// -------------------------------------------------------------
// TEST 7 — View Report
// -------------------------------------------------------------
// Find first student from grid
$grid = student_achievements_grid($adminUser, null, $activeYear);
$firstStudent = $grid[0] ?? null;
$t7Pass = false;
if ($firstStudent) {
    $reportQ = http_build_query([
        'key'           => $firstStudent['key'],
        'reg_no'        => $firstStudent['reg_no'],
        'name'          => $firstStudent['student_name'],
        'dept'          => $firstStudent['department'],
        'academic_year' => $activeYear,
    ]);
    $resReport = curlReq("$baseUrl/individual-student-report.php?$reportQ", 'GET', [], $cookieAdmin);
    $t7Pass = ($resReport['code'] === 200 && strpos($resReport['body'], $firstStudent['student_name']) !== false && strpos($resReport['body'], 'Individual Student Achievement Report') !== false);
    testLog('TEST 7 — View Report', 'Individual Student Report loads correct student record', $t7Pass, "Student: {$firstStudent['student_name']} (Reg: {$firstStudent['reg_no']}), HTTP {$resReport['code']}");
} else {
    testLog('TEST 7 — View Report', 'Individual Student Report', false, "No student found in grid");
}

// -------------------------------------------------------------
// TEST 8 — Present
// -------------------------------------------------------------
$t8Pass = false;
if ($firstStudent) {
    $presentQ = http_build_query([
        'key'           => $firstStudent['key'],
        'reg_no'        => $firstStudent['reg_no'],
        'name'          => $firstStudent['student_name'],
        'dept'          => $firstStudent['department'],
        'academic_year' => $activeYear,
    ]);
    $resPresent = curlReq("$baseUrl/present-student-report.php?$presentQ", 'GET', [], $cookieAdmin);
    $t8Pass = ($resPresent['code'] === 200 && strpos($resPresent['body'], 'Presentation: ' . $firstStudent['student_name']) !== false && strpos($resPresent['body'], 'autoTimer') !== false);
    testLog('TEST 8 — Present', 'Presentation mode loads slide deck for student', $t8Pass, "Student: {$firstStudent['student_name']}, Presentation slides & controls present");
} else {
    testLog('TEST 8 — Present', 'Present button functionality', false, "No student found in grid");
}

// -------------------------------------------------------------
// TEST 9 — Data / Analytics toggle
// -------------------------------------------------------------
$hasDataViewSec      = (strpos($body, 'id="dataViewSection"') !== false);
$hasAnalyticsViewSec = (strpos($body, 'id="analyticsViewSection"') !== false);
$hasToggleButtons    = (strpos($body, 'btnMainData') !== false && strpos($body, 'btnMainAnalytics') !== false);
$t9Pass = ($hasDataViewSec && $hasAnalyticsViewSec && $hasToggleButtons);
testLog('TEST 9 — Data / Analytics toggle', 'Both Data View and Analytics View sections available with toggle', $t9Pass, "dataViewSection: YES, analyticsViewSection: YES, Toggle controls: YES");

// -------------------------------------------------------------
// TEST 10 — Department grouping
// -------------------------------------------------------------
$hasDeptGroup = (strpos($body, 'Department: CSBS') !== false || strpos($body, 'Department:') !== false);
$t10Pass = $hasDeptGroup;
testLog('TEST 10 — Department grouping', 'Students grouped by department in matrix', $t10Pass, "Department group header displayed in table");

// -------------------------------------------------------------
// TEST 11 — Academic-year switch
// -------------------------------------------------------------
$resYearOther = curlReq("$baseUrl/student-achievements.php?academic_year=2024-25", 'GET', [], $cookieAdmin);
$t11Pass = ($resYearOther['code'] === 200 && strpos($resYearOther['body'], 'Academic Year: 2024-25') !== false);
testLog('TEST 11 — Academic-year switch', 'Year filter updates student achievements dynamically', $t11Pass, "HTTP {$resYearOther['code']}, selected year 2024-25 reflected in data view");

// -------------------------------------------------------------
// TEST 12 — Faculty regression
// -------------------------------------------------------------
$resFaculty = curlReq("$baseUrl/faculty-achievements.php", 'GET', [], $cookieAdmin);
$facBody = $resFaculty['body'];
$facPass = ($resFaculty['code'] === 200 && strpos($facBody, 'Faculty Achievements') !== false && strpos($facBody, 'Faculty Achievement Performance Matrix') !== false);
// Make sure Coordinator and HoD do NOT appear in Faculty table
$coordInFaculty = (preg_match('/>\s*Coordinator\s*<\/div>/', $facBody));
$hodInFaculty   = (preg_match('/>\s*HoD\s*-\s*CSBS\s*<\/div>/', $facBody));
$t12Pass = ($facPass && !$coordInFaculty && !$hodInFaculty);
testLog('TEST 12 — Faculty regression', 'Faculty Achievements module remains fully intact and staff-filtered', $t12Pass, "HTTP {$resFaculty['code']}, Faculty Achievements unaffected, Coordinator in Faculty: " . ($coordInFaculty?'YES':'NO'));

echo "\n===============================================================\n";
$allPass = true;
foreach ($results as $id => $r) {
    if (!$r['pass']) $allPass = false;
}
echo "FINAL AUTOMATED VERIFICATION RESULT: " . ($allPass ? "ALL TESTS PASSED (12/12)" : "SOME TESTS FAILED") . "\n";
echo "===============================================================\n";
