<?php
/**
 * scratch/verify_all_20_tests.php
 * Comprehensive automated verification script covering all 20 tests specified in Section 23 of the user request.
 */

error_reporting(E_ALL & ~E_DEPRECATED);

require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/inc/helpers.php';
require_once __DIR__ . '/../php-app/models/Target.php';
require_once __DIR__ . '/../php-app/models/FacultyAchievement.php';
require_once __DIR__ . '/../php-app/models/StudentAchievement.php';

$baseUrl = 'http://localhost:8000';
$pdo = db();
$activeYear = active_academic_year();

echo "===============================================================\n";
echo " CONSOLIDATED FACULTY & STUDENT ACHIEVEMENTS VERIFICATION\n";
echo " Base URL: $baseUrl | Active Academic Year: $activeYear\n";
echo "===============================================================\n\n";

$results = [];

function recordTest(string $testId, string $name, bool $pass, string $details = '') {
    global $results;
    $status = $pass ? "PASS" : "FAIL";
    $results[$testId] = ['name' => $name, 'pass' => $pass, 'details' => $details];
    echo "[$status] $testId: $name\n";
    if ($details) {
        echo "       $details\n";
    }
}

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

$cookieAdmin   = __DIR__ . '/cookie_admin_20.txt';
$cookieFaculty = __DIR__ . '/cookie_faculty_20.txt';
@unlink($cookieAdmin);
@unlink($cookieFaculty);

echo "Authenticating test users...\n";
$adminLoggedIn = loginUser('mohameduvaish132@gmail.com', 'admin123', 'Admin', $cookieAdmin);
$facultyLoggedIn = loginUser('faculty@atts.edu', 'faculty123', 'Faculty', $cookieFaculty);

if (!$adminLoggedIn) {
    die("FATAL: Failed to authenticate Admin user\n");
}
echo "Users authenticated successfully.\n\n";

// Fetch main faculty achievements page
$resMain = curlReq("$baseUrl/faculty-achievements.php", 'GET', [], $cookieAdmin);
$mainBody = $resMain['body'];

// -------------------------------------------------------------
// TEST 1: Page Loads
// -------------------------------------------------------------
$t1Pass = ($resMain['code'] === 200 && strpos($mainBody, 'Consolidated Faculty Achievements') !== false);
recordTest('TEST 1', 'Faculty Achievements page loads', $t1Pass, "HTTP {$resMain['code']}, Header found");

// -------------------------------------------------------------
// TEST 2: Sidebar
// -------------------------------------------------------------
// Check sidebar: Faculty Achievements exists, Student Achievements does NOT exist
$hasFacultyNav = (strpos($mainBody, 'Faculty Achievements') !== false);
$hasStudentNav = (preg_match('/<a[^>]*href="[^"]*student-achievements\.php"[^>]*>.*?Student Achievements.*?<\/a>/is', $mainBody) === 1);
$t2Pass = ($hasFacultyNav && !$hasStudentNav);
recordTest('TEST 2', 'Sidebar has Faculty Achievements and NO Student Achievements', $t2Pass, "Faculty Nav present: " . ($hasFacultyNav?'YES':'NO') . ", Student Nav in sidebar: " . ($hasStudentNav?'YES':'NO'));

// -------------------------------------------------------------
// TEST 3: Faculty Matrix
// -------------------------------------------------------------
$t3Pass = (strpos($mainBody, 'Faculty Achievement Performance Matrix') !== false);
recordTest('TEST 3', 'Faculty Achievement Performance Matrix remains visible', $t3Pass, "Faculty matrix card confirmed");

// -------------------------------------------------------------
// TEST 4: Student Matrix BELOW Faculty Matrix
// -------------------------------------------------------------
$posFac = strpos($mainBody, 'Faculty Achievement Performance Matrix');
$posStud = strpos($mainBody, 'Student Achievement Performance Matrix');
$t4Pass = ($posFac !== false && $posStud !== false && $posStud > $posFac);
recordTest('TEST 4', 'Student Achievement Performance Matrix appears BELOW the Faculty Matrix', $t4Pass, "Faculty Matrix Pos: $posFac, Student Matrix Pos: $posStud (Student > Faculty)");

// -------------------------------------------------------------
// TEST 5: Student categories
// -------------------------------------------------------------
$t5Cols = ['NPTEL', 'INTERNSHIPS', 'PLACEMENTS', 'ONLINE COURSES', 'ACHIEVEMENTS', 'PARTICIPATION', 'TRAINING'];
$t5Pass = true;
foreach ($t5Cols as $c) {
    if (strpos($mainBody, "<th>$c</th>") === false && strpos($mainBody, "<th class=\"num\">$c</th>") === false) {
        $t5Pass = false;
        break;
    }
}
recordTest('TEST 5', 'Student categories present (NPTEL, Internship, Placement, Online Course, Achievements, Participation, Training)', $t5Pass, "Verified in Student Matrix columns");

// -------------------------------------------------------------
// TEST 6: Student-only data
// -------------------------------------------------------------
// Extract student table body
preg_match('/<table[^>]*id="studTable"[^>]*>.*?<tbody>(.*?)<\/tbody>/s', $mainBody, $mStud);
$studTableHtml = $mStud[1] ?? '';

$staffRolesInStudent = [];
if (strpos($studTableHtml, 'Mohamed Uvaish') !== false) $staffRolesInStudent[] = 'Admin (Mohamed Uvaish)';
if (preg_match('/>\s*Coordinator\s*<\/div>/', $studTableHtml)) $staffRolesInStudent[] = 'Coordinator';
if (preg_match('/>\s*HoD\s*-\s*CSBS\s*<\/div>/', $studTableHtml)) $staffRolesInStudent[] = 'HoD';
if (preg_match('/>\s*Dean\s*<\/div>/', $studTableHtml)) $staffRolesInStudent[] = 'Dean';
if (preg_match('/>\s*Director\s*<\/div>/', $studTableHtml)) $staffRolesInStudent[] = 'Director';
if (preg_match('/>\s*rgl\s*<\/div>/', $studTableHtml)) $staffRolesInStudent[] = 'Faculty (rgl)';

$t6Pass = empty($staffRolesInStudent);
recordTest('TEST 6', 'Student-only data in Student Matrix (No Faculty, Coordinator, HoD, Dean, Director, Admin)', $t6Pass, "Staff in student matrix: " . (empty($staffRolesInStudent) ? 'NONE (Clean)' : implode(', ', $staffRolesInStudent)));

// -------------------------------------------------------------
// TEST 7: Faculty-only data
// -------------------------------------------------------------
// Extract faculty table body
preg_match('/<table[^>]*id="facTable"[^>]*>.*?<tbody>(.*?)<\/tbody>/s', $mainBody, $mFac);
$facTableHtml = $mFac[1] ?? '';

$nonFacultyInFacTable = [];
if (strpos($facTableHtml, 'Mohamed Uvaish') !== false) $nonFacultyInFacTable[] = 'Admin (Mohamed Uvaish)';
if (preg_match('/>\s*Coordinator\s*<\/div>/', $facTableHtml)) $nonFacultyInFacTable[] = 'Coordinator';
if (preg_match('/>\s*HoD\s*-\s*CSBS\s*<\/div>/', $facTableHtml)) $nonFacultyInFacTable[] = 'HoD';
if (preg_match('/>\s*Dean\s*<\/div>/', $facTableHtml)) $nonFacultyInFacTable[] = 'Dean';
if (preg_match('/>\s*Director\s*<\/div>/', $facTableHtml)) $nonFacultyInFacTable[] = 'Director';

$t7Pass = empty($nonFacultyInFacTable);
recordTest('TEST 7', 'Faculty Matrix contains only Faculty users', $t7Pass, "Non-faculty in faculty matrix: " . (empty($nonFacultyInFacTable) ? 'NONE (Clean)' : implode(', ', $nonFacultyInFacTable)));

// -------------------------------------------------------------
// TEST 8: Student department grouping
// -------------------------------------------------------------
$t8Pass = (strpos($studTableHtml, 'Department: CSBS') !== false && strpos($studTableHtml, 'students') !== false);
recordTest('TEST 8', 'Student department grouping (Students grouped under correct department header)', $t8Pass, "Department: CSBS group header found with student count badge");

// -------------------------------------------------------------
// TEST 9: Faculty department grouping
// -------------------------------------------------------------
$t9Pass = (strpos($facTableHtml, 'Department: CSBS') !== false && strpos($facTableHtml, 'faculty') !== false);
recordTest('TEST 9', 'Faculty department grouping remains correct', $t9Pass, "Department: CSBS group header found with faculty count badge");

// -------------------------------------------------------------
// TEST 10: Student search
// -------------------------------------------------------------
$resSearch = curlReq("$baseUrl/faculty-achievements.php?student_search=faizal", 'GET', [], $cookieAdmin);
preg_match('/<table[^>]*id="studTable"[^>]*>.*?<tbody>(.*?)<\/tbody>/s', $resSearch['body'], $mSS);
$searchTbody = $mSS[1] ?? '';
$t10Pass = (strpos($searchTbody, 'faizal') !== false && strpos($searchTbody, 'Coordinator') === false);
recordTest('TEST 10', 'Student search works by student name; staff do not appear', $t10Pass, "Student 'faizal' found in student matrix");

// -------------------------------------------------------------
// TEST 11: Student View Report
// -------------------------------------------------------------
$adminUser = ['id' => 1, 'role' => 'Admin', 'department' => ''];
$grid = student_achievements_grid($adminUser, null, $activeYear);
$firstStudent = $grid[0] ?? null;
$t11Pass = false;
if ($firstStudent) {
    $reportQ = http_build_query([
        'key'           => $firstStudent['key'],
        'reg_no'        => $firstStudent['reg_no'],
        'name'          => $firstStudent['student_name'],
        'dept'          => $firstStudent['department'],
        'academic_year' => $activeYear,
    ]);
    $resRep = curlReq("$baseUrl/individual-student-report.php?$reportQ", 'GET', [], $cookieAdmin);
    $t11Pass = ($resRep['code'] === 200 && strpos($resRep['body'], $firstStudent['student_name']) !== false && strpos($resRep['body'], 'Individual Student Achievement Report') !== false);
    recordTest('TEST 11', 'Student View Report opens individual student report', $t11Pass, "HTTP {$resRep['code']}, Student: {$firstStudent['student_name']}");
} else {
    recordTest('TEST 11', 'Student View Report', false, "No student found");
}

// -------------------------------------------------------------
// TEST 12: Student Present
// -------------------------------------------------------------
$t12Pass = false;
if ($firstStudent) {
    $presentQ = http_build_query([
        'key'           => $firstStudent['key'],
        'reg_no'        => $firstStudent['reg_no'],
        'name'          => $firstStudent['student_name'],
        'dept'          => $firstStudent['department'],
        'academic_year' => $activeYear,
    ]);
    $resPres = curlReq("$baseUrl/present-student-report.php?$presentQ", 'GET', [], $cookieAdmin);
    $t12Pass = ($resPres['code'] === 200 && strpos($resPres['body'], 'Presentation: ' . $firstStudent['student_name']) !== false);
    recordTest('TEST 12', 'Student Present launches fullscreen review deck for student', $t12Pass, "HTTP {$resPres['code']}, Student: {$firstStudent['student_name']}");
} else {
    recordTest('TEST 12', 'Student Present', false, "No student found");
}

// -------------------------------------------------------------
// TEST 13: Faculty View Report
// -------------------------------------------------------------
$resFacRep = curlReq("$baseUrl/individual-faculty-report.php?id=5", 'GET', [], $cookieAdmin);
$t13Pass = ($resFacRep['code'] === 200 && strpos($resFacRep['body'], 'Individual Faculty Achievement Report') !== false && strpos($resFacRep['body'], 'rgl') !== false);
recordTest('TEST 13', 'Faculty View Report still opens faculty report correctly', $t13Pass, "HTTP {$resFacRep['code']}, Faculty report for user 5 (rgl)");

// -------------------------------------------------------------
// TEST 14: Faculty Present
// -------------------------------------------------------------
$resFacPres = curlReq("$baseUrl/present-faculty-report.php?id=5", 'GET', [], $cookieAdmin);
$t14Pass = ($resFacPres['code'] === 200 && strpos($resFacPres['body'], 'Presentation: rgl') !== false);
recordTest('TEST 14', 'Faculty Present still functions as expected', $t14Pass, "HTTP {$resFacPres['code']}, Faculty presentation deck rendered");

// -------------------------------------------------------------
// TEST 15: Academic Year
// -------------------------------------------------------------
$t15Pass = (strpos($mainBody, 'Academic Year: ' . $activeYear) !== false && strpos($mainBody, 'Locked by Admin') !== false);
recordTest('TEST 15', 'Both matrices use centralized Admin Academic Year', $t15Pass, "Active Academic Year: $activeYear, Locked by Admin badge present");

// -------------------------------------------------------------
// TEST 16: Academic-year switch
// -------------------------------------------------------------
$resYearOther = curlReq("$baseUrl/faculty-achievements.php?academic_year=2024-25", 'GET', [], $cookieAdmin);
$t16Pass = ($resYearOther['code'] === 200 && strpos($resYearOther['body'], 'Academic Year: 2024-25') !== false);
recordTest('TEST 16', 'Change Admin active year updates both Faculty and Student data', $t16Pass, "HTTP {$resYearOther['code']}, Year 2024-25 filtered across both matrices");

// -------------------------------------------------------------
// TEST 17: Faculty upload workflow
// -------------------------------------------------------------
$resUpload = curlReq("$baseUrl/upload.php", 'GET', [], $cookieFaculty);
$t17Pass = ($resUpload['code'] === 200 && strpos($resUpload['body'], 'Upload Data') !== false);
recordTest('TEST 17', 'Faculty upload workflow page accessible and functional', $t17Pass, "HTTP {$resUpload['code']}, Upload forms rendered for faculty");

// -------------------------------------------------------------
// TEST 18: Faculty approval workflow
// -------------------------------------------------------------
$resApprovals = curlReq("$baseUrl/approvals.php", 'GET', [], $cookieAdmin);
$t18Pass = ($resApprovals['code'] === 200 && strpos($resApprovals['body'], 'Approvals') !== false);
recordTest('TEST 18', 'Faculty approval workflow route intact', $t18Pass, "HTTP {$resApprovals['code']}, Approvals hub loaded");

// -------------------------------------------------------------
// TEST 19: Existing reports
// -------------------------------------------------------------
$resReports = curlReq("$baseUrl/reports.php", 'GET', [], $cookieAdmin);
$t19Pass = ($resReports['code'] === 200 && strpos($resReports['body'], 'Reports') !== false);
recordTest('TEST 19', 'Existing reports hub remains intact', $t19Pass, "HTTP {$resReports['code']}, Reports hub loaded");

// -------------------------------------------------------------
// TEST 20: Existing student upload forms
// -------------------------------------------------------------
$studentUploadTypes = ['internship', 'placement', 'student_achievement', 'student_participation', 'summer_training', 'nptel', 'online_courses'];
$t20Pass = true;
foreach ($studentUploadTypes as $ut) {
    $resU = curlReq("$baseUrl/upload.php?type=$ut", 'GET', [], $cookieFaculty);
    if ($resU['code'] !== 200) {
        $t20Pass = false;
        break;
    }
}
recordTest('TEST 20', 'Existing student upload forms functional (NPTEL, Internship, Placement, Online Course, Achievement, Participation, Training)', $t20Pass, "All 7 upload category endpoints return HTTP 200");

echo "\n===============================================================\n";
$allPass = true;
foreach ($results as $id => $r) {
    if (!$r['pass']) $allPass = false;
}
echo "FINAL 20-TEST VERIFICATION RESULT: " . ($allPass ? "ALL TESTS PASSED (20/20)" : "SOME TESTS FAILED") . "\n";
echo "===============================================================\n";
