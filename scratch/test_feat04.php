<?php
/**
 * Automated Verification Script for FEAT-04: Department & Faculty Achievements Report
 */

require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/models/User.php';
require_once __DIR__ . '/../php-app/models/Target.php';

$baseUrl = 'http://localhost:8000';

function runCurl($path, $cookieFile = null, $postFields = null) {
    global $baseUrl;
    $ch = curl_init($baseUrl . '/' . ltrim($path, '/'));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    if ($cookieFile) {
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    }
    if ($postFields !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    }
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $header = substr($resp, 0, $headerSize);
    $body = substr($resp, $headerSize);
    curl_close($ch);
    return ['code' => $httpCode, 'header' => $header, 'body' => $body];
}

function loginAs($email, $password, $role) {
    $cookie = tempnam(sys_get_temp_dir(), 'test_cookie_');
    // Get csrf from login page
    $getResp = runCurl('login.php', $cookie);
    preg_match('/name="csrf"\s+value="([^"]+)"/', $getResp['body'], $m);
    $csrf = $m[1] ?? '';

    $loginResp = runCurl('login.php', $cookie, [
        'csrf' => $csrf,
        'email' => $email,
        'password' => $password,
        'role' => $role
    ]);
    return $cookie;
}

echo "=== FEAT-04 AUTOMATED TEST SUITE ===\n\n";

// Get user accounts from DB
$admin = db()->query("SELECT * FROM users WHERE role = 'Admin' LIMIT 1")->fetch();
$principal = db()->query("SELECT * FROM users WHERE role = 'Principal' OR role = 'Director' LIMIT 1")->fetch();
$dean = db()->query("SELECT * FROM users WHERE role = 'Dean' LIMIT 1")->fetch();
$hods = db()->query("SELECT * FROM users WHERE role = 'HoD' ORDER BY id ASC")->fetchAll();
$faculties = db()->query("SELECT * FROM users WHERE role = 'Faculty' ORDER BY id ASC")->fetchAll();
$coordinators = db()->query("SELECT * FROM users WHERE role = 'Coordinator' ORDER BY id ASC")->fetchAll();

$hod1 = $hods[0] ?? null;
$hod2 = $hods[1] ?? null;
$faculty1 = $faculties[0] ?? null;

echo "1. Current Active Academic Year: " . active_academic_year() . "\n\n";

// -------------------------------------------------------------
// TEST 1: ADMIN ACCESS
// -------------------------------------------------------------
echo "[TEST 1] Admin access to reports.php & FEAT-04 card\n";
$adminCookie = loginAs($admin['email'], 'admin123', 'Admin');
$res = runCurl('reports.php', $adminCookie);
assert(strpos($res['body'], 'Department &amp; Faculty Achievements Report') !== false, "FEAT-04 box missing for Admin");
assert(strpos($res['body'], 'Download Excel') !== false, "Download Excel button missing");
assert(strpos($res['body'], 'Download PDF') !== false, "Download PDF button missing");
assert(strpos($res['body'], 'Download Word') !== false, "Download Word button missing");
assert(strpos($res['body'], 'View Report') !== false, "View Report button missing");
assert(strpos($res['body'], 'individual-faculty-report.php?id=') !== false, "View Report link missing");
echo "-> PASS: Admin sees FEAT-04 report with departments, faculty list, and View Report buttons.\n\n";

// -------------------------------------------------------------
// TEST 2: PRINCIPAL ACCESS
// -------------------------------------------------------------
echo "[TEST 2] Principal access\n";
$principalCookie = loginAs('principal@atts.edu', 'principal123', 'Principal');
$res = runCurl('reports.php', $principalCookie);
assert(strpos($res['body'], 'Department &amp; Faculty Achievements Report') !== false, "FEAT-04 box missing for Principal");
assert(strpos($res['body'], 'View Report') !== false, "View Report button missing for Principal");
echo "-> PASS: Principal sees FEAT-04 report and View Report buttons.\n\n";

// -------------------------------------------------------------
// TEST 3: DEAN ACCESS
// -------------------------------------------------------------
echo "[TEST 3] Dean access\n";
$deanCookie = loginAs($dean['email'], 'dean1234', 'Dean');
$res = runCurl('reports.php', $deanCookie);
assert(strpos($res['body'], 'Department &amp; Faculty Achievements Report') !== false, "FEAT-04 box missing for Dean");
assert(strpos($res['body'], 'View Report') !== false, "View Report button missing for Dean");
echo "-> PASS: Dean sees FEAT-04 report and View Report buttons.\n\n";

// -------------------------------------------------------------
// TEST 4: HOD ACCESS & DEPARTMENT ISOLATION
// -------------------------------------------------------------
echo "[TEST 4] HoD access and strict department isolation\n";
echo "HoD 1: {$hod1['name']} ({$hod1['department']})\n";
$hod1Cookie = loginAs($hod1['email'], 'hod12345', 'HoD');
$res = runCurl('reports.php', $hod1Cookie);
assert(strpos($res['body'], 'Department &amp; Faculty Achievements Report') !== false, "FEAT-04 box missing for HoD");
assert(strpos($res['body'], $hod1['department'] . ' only') !== false || strpos($res['body'], $hod1['department']) !== false, "HoD dept restriction missing");

// Ensure other departments are NOT shown to HoD 1
if ($hod2 && $hod2['department'] !== $hod1['department']) {
    echo "Checking HoD 1 cannot see HoD 2's department ({$hod2['department']})...\n";
    assert(strpos($res['body'], 'Department: ' . $hod2['department']) === false, "HoD 1 should not see HoD 2's department header");
    echo "-> PASS: HoD 1 only sees their own department.\n";
}

// -------------------------------------------------------------
// TEST 5 & 6: FACULTY & COORDINATOR DENIAL
// -------------------------------------------------------------
echo "\n[TEST 5 & 6] Faculty & Coordinator cannot access FEAT-04 export\n";
if ($faculty1) {
    $facCookie = loginAs($faculty1['email'], 'faculty123', 'Faculty');
    // Faculty directly hitting export-faculty-achievements.php
    $res = runCurl('export-faculty-achievements.php?format=excel', $facCookie);
    assert($res['code'] === 403, "Faculty should receive 403 on export-faculty-achievements.php, got: " . $res['code']);
    echo "-> PASS: Faculty direct access to export-faculty-achievements.php is 403 Forbidden.\n";
}

if (!empty($coordinators[0])) {
    $coordCookie = loginAs($coordinators[0]['email'], 'coord1234', 'Coordinator');
    $res = runCurl('export-faculty-achievements.php?format=excel', $coordCookie);
    assert($res['code'] === 403, "Coordinator should receive 403 on export-faculty-achievements.php, got: " . $res['code']);
    echo "-> PASS: Coordinator direct access to export-faculty-achievements.php is 403 Forbidden.\n";
}

// Ensure test faculty exist in AI & DS and CSE for multi-department tests
$pwHash = password_hash('faculty123', PASSWORD_DEFAULT);
$aidsFac = db()->query("SELECT * FROM users WHERE email = 'test_aids@atts.edu'")->fetch();
if (!$aidsFac) {
    db()->prepare("INSERT INTO users (name, email, password, role, department, status) VALUES (?, ?, ?, 'Faculty', 'AI & DS', 1)")
        ->execute(['Prof. Anita Sharma', 'test_aids@atts.edu', $pwHash]);
    $aidsFac = db()->query("SELECT * FROM users WHERE email = 'test_aids@atts.edu'")->fetch();
}

$cseFac = db()->query("SELECT * FROM users WHERE email = 'test_cse@atts.edu'")->fetch();
if (!$cseFac) {
    db()->prepare("INSERT INTO users (name, email, password, role, department, status) VALUES (?, ?, ?, 'Faculty', 'Computer Science and Engineering', 1)")
        ->execute(['Dr. Rajesh Kumar', 'test_cse@atts.edu', $pwHash]);
    $cseFac = db()->query("SELECT * FROM users WHERE email = 'test_cse@atts.edu'")->fetch();
}

// -------------------------------------------------------------
// TEST 8: MULTIPLE DEPARTMENTS & FACULTY GROUPING
// -------------------------------------------------------------
echo "\n[TEST 8] Multi-Department Grouping in Admin View\n";
$adminReport = runCurl('reports.php', $adminCookie);
assert(strpos($adminReport['body'], 'Prof. Anita Sharma') !== false, "AI & DS Faculty missing from Admin report");
assert(strpos($adminReport['body'], 'Dr. Rajesh Kumar') !== false, "CSE Faculty missing from Admin report");
assert(strpos($adminReport['body'], 'VR') !== false, "CSBS Faculty missing from Admin report");
assert(strpos($adminReport['body'], 'Artificial Intelligence and Data Science') !== false || strpos($adminReport['body'], 'AI &amp; DS') !== false, "AI & DS department header missing");
assert(strpos($adminReport['body'], 'Department: Computer Science and Engineering') !== false, "CSE department header missing");
assert(strpos($adminReport['body'], 'Department: CSBS') !== false || strpos($adminReport['body'], 'Computer Science and Business Systems') !== false, "CSBS department header missing");
echo "-> PASS: All 3 departments and individual faculty members appear under correct department headers.\n";

// -------------------------------------------------------------
// TEST 4B: HOD DOES NOT SEE OTHER DEPARTMENTS
// -------------------------------------------------------------
echo "\n[TEST 4B] HoD Isolation Verification\n";
$hodReport = runCurl('reports.php', $hod1Cookie);
assert(strpos($hodReport['body'], 'Prof. Anita Sharma') === false, "HoD should NOT see AI & DS faculty");
assert(strpos($hodReport['body'], 'Dr. Rajesh Kumar') === false, "HoD should NOT see CSE faculty");
assert(strpos($hodReport['body'], 'VR') !== false, "HoD should see CSBS faculty");
echo "-> PASS: HoD only sees their own department (CSBS) and cannot see AI & DS or CSE.\n";

// -------------------------------------------------------------
// TEST 11: SECURITY & IDOR PREVENTION
// -------------------------------------------------------------
echo "\n[TEST 11] IDOR Prevention in individual-faculty-report.php\n";
echo "HoD 1 ({$hod1['department']}) attempting to access AI & DS Faculty (ID: {$aidsFac['id']})...\n";
$idorRes = runCurl('individual-faculty-report.php?id=' . $aidsFac['id'], $hod1Cookie);
assert($idorRes['code'] === 403, "IDOR check failed! Expected 403, got: " . $idorRes['code']);
echo "-> PASS: IDOR attempt by HoD to access AI & DS faculty was rejected with HTTP 403 Forbidden.\n";

echo "HoD 1 ({$hod1['department']}) attempting to access CSE Faculty (ID: {$cseFac['id']})...\n";
$idorRes2 = runCurl('individual-faculty-report.php?id=' . $cseFac['id'], $hod1Cookie);
assert($idorRes2['code'] === 403, "IDOR check failed! Expected 403, got: " . $idorRes2['code']);
echo "-> PASS: IDOR attempt by HoD to access CSE faculty was rejected with HTTP 403 Forbidden.\n";

// Admin viewing individual reports
$adminViewRes1 = runCurl('individual-faculty-report.php?id=' . $aidsFac['id'], $adminCookie);
assert($adminViewRes1['code'] === 200, "Admin should view AI & DS faculty");
assert(strpos($adminViewRes1['body'], 'Prof. Anita Sharma') !== false, "Faculty name missing");
assert(strpos($adminViewRes1['body'], 'Back to Reports') !== false, "Back to Reports button missing");

$adminViewRes2 = runCurl('individual-faculty-report.php?id=' . $cseFac['id'], $adminCookie);
assert($adminViewRes2['code'] === 200, "Admin should view CSE faculty");
assert(strpos($adminViewRes2['body'], 'Dr. Rajesh Kumar') !== false, "Faculty name missing");
assert(strpos($adminViewRes2['body'], 'Back to Reports') !== false, "Back to Reports button missing");
echo "-> PASS: Admin can view any faculty report (200 OK) with Back to Reports navigation.\n";

// -------------------------------------------------------------
// TEST 7: ACTIVE ACADEMIC YEAR INTEGRATION
// -------------------------------------------------------------
echo "\n[TEST 7] Active Academic Year integration (FEAT-02)\n";
$currYear = active_academic_year();
assert(strpos($adminReport['body'], 'Academic Year: ' . $currYear) !== false, "Active academic year missing in FEAT-04 card");
echo "-> PASS: Report automatically locks to active academic year ($currYear) without client selector.\n";

// -------------------------------------------------------------
// EXPORTS CHECK (EXCEL, PDF, WORD)
// -------------------------------------------------------------
echo "\n[EXPORTS CHECK] FEAT-04 exports\n";
$excelRes = runCurl('export-faculty-achievements.php?format=excel', $adminCookie);
assert($excelRes['code'] === 200, "Excel export failed");
assert(strpos($excelRes['header'], 'spreadsheetml.sheet') !== false, "Excel content-type missing");
echo "-> PASS: Excel export returned 200 with application/vnd.openxmlformats-officedocument.spreadsheetml.sheet\n";

$wordRes = runCurl('export-faculty-achievements.php?format=word', $adminCookie);
assert($wordRes['code'] === 200, "Word export failed");
assert(strpos($wordRes['header'], 'application/msword') !== false, "Word content-type missing");
echo "-> PASS: Word export returned 200 with application/msword\n";

$pdfRes = runCurl('export-faculty-achievements.php?format=pdf', $adminCookie);
assert($pdfRes['code'] === 200, "PDF view failed");
assert(strpos($pdfRes['body'], 'DEPARTMENT &amp; FACULTY ACHIEVEMENTS REPORT') !== false, "PDF report content missing");
echo "-> PASS: PDF view returned 200 with printable HTML\n";

// -------------------------------------------------------------
// FEAT-03 CONSOLIDATED REPORT INTEGRITY CHECK
// -------------------------------------------------------------
echo "\n[FEAT-03 INTEGRITY CHECK] Consolidated Report export\n";
$consExcelRes = runCurl('consolidated-report.php?format=excel', $adminCookie);
assert($consExcelRes['code'] === 200, "Consolidated report excel export failed");
assert(strpos($consExcelRes['header'], 'spreadsheetml.sheet') !== false, "Consolidated excel content-type missing");
echo "-> PASS: Consolidated Report Excel returned 200 without breaking\n";

echo "\n=== ALL TESTS COMPLETED SUCCESSFULLY! ===\n";
