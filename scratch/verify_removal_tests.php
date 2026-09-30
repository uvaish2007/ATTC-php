<?php
require_once __DIR__ . '/../php-app/inc/env.php';
require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/inc/helpers.php';
require_once __DIR__ . '/../php-app/models/Target.php';
require_once __DIR__ . '/../php-app/models/Record.php';

$baseUrl = 'http://localhost:8000';
$cookieFaculty = tempnam(sys_get_temp_dir(), 'test_rem_fac_');
$cookieCoord   = tempnam(sys_get_temp_dir(), 'test_rem_coord_');

function curlReq(string $url, string $method = 'GET', $fields = [], string $cookieF = '', bool $headerOut = false): array {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HEADER         => $headerOut,
    ];
    if ($cookieF) {
        $opts[CURLOPT_COOKIEFILE] = $cookieF;
        $opts[CURLOPT_COOKIEJAR]  = $cookieF;
    }
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = $fields;
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $effUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    if ($headerOut) {
        $hSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $head  = substr($resp, 0, $hSize);
        $body  = substr($resp, $hSize);
        return ['code' => $code, 'head' => $head, 'body' => $body, 'effUrl' => $effUrl];
    }
    return ['code' => $code, 'body' => $resp, 'effUrl' => $effUrl];
}

function getCsrf(string $html): string {
    if (preg_match('/name="csrf"\s+value="([^"]+)"/', $html, $m)) {
        return $m[1];
    }
    if (preg_match('/value="([^"]+)"\s+name="csrf"/', $html, $m)) {
        return $m[1];
    }
    return '';
}

function loginUser(string $baseUrl, string $cookieFile, string $email, string $password, string $role): bool {
    $res = curlReq("$baseUrl/login.php", 'GET', [], $cookieFile);
    $csrf = getCsrf($res['body']);
    $post = [
        'csrf'     => $csrf,
        'email'    => $email,
        'password' => $password,
        'role'     => $role,
    ];
    $loginResp = curlReq("$baseUrl/login.php", 'POST', http_build_query($post), $cookieFile, true);
    return ($loginResp['code'] === 200 || $loginResp['code'] === 302);
}

$results = [];
function recordTest(string $id, string $title, bool $pass, string $details = '') {
    global $results;
    $results[] = [
        'id' => $id,
        'title' => $title,
        'status' => $pass ? 'PASS' : 'FAIL',
        'details' => $details
    ];
    echo sprintf("[%s] %s: %s (%s)\n", $pass ? 'PASS' : 'FAIL', $id, $title, $details);
}

// Logins
loginUser($baseUrl, $cookieFaculty, 'faculty@atts.edu', 'faculty12', 'Faculty');

// Find a Coordinator user
$pdo = db();
$coordUser = $pdo->query("SELECT email, password FROM users WHERE role = 'Coordinator' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$coordEmail = $coordUser['email'] ?? 'coordinator@atts.edu';
loginUser($baseUrl, $cookieCoord, $coordEmail, 'coord12', 'Coordinator');

// -------------------------------------------------------------
// TEST 1 — Student Data category list (Student Participation is NOT present)
// -------------------------------------------------------------
$uploadResp = curlReq("$baseUrl/upload.php", 'GET', [], $cookieFaculty);
$pageHtml = $uploadResp['body'];
$hasParticipationTab = (stripos($pageHtml, 'Student Participation') !== false);
recordTest('TEST 1', 'Student Participation is NOT present in Upload Data category tabs', !$hasParticipationTab, "Tab present: " . ($hasParticipationTab ? 'Yes' : 'No'));

// -------------------------------------------------------------
// TEST 2 — Direct route (/upload.php?type=student_participation)
// -------------------------------------------------------------
$directResp = curlReq("$baseUrl/upload.php?type=student_participation", 'GET', [], $cookieFaculty, true);
// Should redirect safely or show unavailable message, definitely not 500, fatal, or the form
$noFatal = (stripos($directResp['body'], 'Fatal error') === false && stripos($directResp['body'], 'Stack trace') === false);
$noForm = (stripos($directResp['body'], 'New Student Participation') === false);
$safeRedirectOrNotice = ($directResp['code'] === 200 && ($noForm && $noFatal));
recordTest('TEST 2', 'Direct route handles safely without opening form or errors', $safeRedirectOrNotice, "HTTP code: {$directResp['code']}, Effective URL: {$directResp['effUrl']}, Form present: " . ($noForm ? 'No' : 'Yes'));

// -------------------------------------------------------------
// TEST 3 & 4 — Next & Previous navigation skips student_participation
// -------------------------------------------------------------
// Open student_achievement form and inspect Next/Previous buttons
$achieveResp = curlReq("$baseUrl/upload.php?type=student_achievement", 'GET', [], $cookieFaculty);
$achHtml = $achieveResp['body'];
$hasNextParticipation = (preg_match('/name="nav"\s+value="next"[\s\S]*?Student Participation/i', $achHtml) || stripos($achHtml, 'upload.php?type=student_participation') !== false);
$hasPrevParticipation = (preg_match('/class="btn btn-ghost"[\s\S]*?upload.php\?type=student_participation/i', $achHtml));

recordTest('TEST 3', 'Next navigation skips student_participation completely', !$hasNextParticipation, "Next points to student_participation: " . ($hasNextParticipation ? 'Yes' : 'No'));
recordTest('TEST 4', 'Previous navigation skips student_participation completely', !$hasPrevParticipation, "Previous points to student_participation: " . ($hasPrevParticipation ? 'Yes' : 'No'));

// -------------------------------------------------------------
// TEST 5 — Remaining student categories still open correctly
// -------------------------------------------------------------
$checkCategories = ['nptel', 'internship', 'placement', 'online_course', 'student_achievement', 'summer_training'];
$allCatsWork = true;
$missingCats = [];
foreach ($checkCategories as $cat) {
    $r = curlReq("$baseUrl/upload.php?type=$cat", 'GET', [], $cookieFaculty);
    if ($r['code'] !== 200 || stripos($r['body'], 'Fatal error') !== false || stripos($r['body'], 'New ') === false) {
        $allCatsWork = false;
        $missingCats[] = $cat;
    }
}
recordTest('TEST 5', 'Remaining categories open correctly', $allCatsWork, "Checked: " . implode(', ', $checkCategories) . (empty($missingCats) ? ' (all OK)' : ' (failed: ' . implode(', ', $missingCats) . ')'));

// -------------------------------------------------------------
// TEST 6 — Faculty Upload Data (Student Participation not visible)
// -------------------------------------------------------------
$facUpload = curlReq("$baseUrl/upload.php", 'GET', [], $cookieFaculty);
$facHasPart = (stripos($facUpload['body'], 'Student Participation') !== false || stripos($facUpload['body'], 'student_participation') !== false);
recordTest('TEST 6', 'Faculty Upload Data: Student Participation is not visible', !$facHasPart, "Participation visible to faculty: " . ($facHasPart ? 'Yes' : 'No'));

// -------------------------------------------------------------
// TEST 7 — Coordinator Upload Data (Student Participation not visible)
// -------------------------------------------------------------
$coordUpload = curlReq("$baseUrl/upload.php", 'GET', [], $cookieCoord);
$coordHasPart = (stripos($coordUpload['body'], 'Student Participation') !== false || stripos($coordUpload['body'], 'student_participation') !== false);
recordTest('TEST 7', 'Coordinator Upload Data: Student Participation is not visible', !$coordHasPart, "Participation visible to coordinator: " . ($coordHasPart ? 'Yes' : 'No'));

// -------------------------------------------------------------
// TEST 8 — Academic Year displays correctly
// -------------------------------------------------------------
$activeAy = active_academic_year();
$hasAyBadge = (stripos($facUpload['body'], $activeAy) !== false);
recordTest('TEST 8', 'Active Academic Year displays correctly', $hasAyBadge, "Active AY $activeAy displayed: " . ($hasAyBadge ? 'Yes' : 'No'));

// -------------------------------------------------------------
// TEST 9 — Existing student data forms still function normally
// -------------------------------------------------------------
$nssResp = curlReq("$baseUrl/upload.php?type=nss", 'GET', [], $cookieFaculty);
$nssOk = ($nssResp['code'] === 200 && stripos($nssResp['body'], 'Academic Session') !== false && stripos($nssResp['body'], 'UBA') !== false);
$placResp = curlReq("$baseUrl/upload.php?type=placement", 'GET', [], $cookieFaculty);
$placOk = ($placResp['code'] === 200 && stripos($placResp['body'], 'Pay Scale') !== false && stripos($placResp['body'], 'Job Role') !== false);
recordTest('TEST 9', 'Existing student and activity forms (Placement, NSS, etc.) function normally', $nssOk && $placOk, "NSS OK: " . ($nssOk ? 'Yes' : 'No') . ", Placement OK: " . ($placOk ? 'Yes' : 'No'));

// -------------------------------------------------------------
// TEST 10 — Reports (no broken links or fatal errors)
// -------------------------------------------------------------
$repResp = curlReq("$baseUrl/reports.php", 'GET', [], $cookieFaculty);
$repOk = ($repResp['code'] === 200 && stripos($repResp['body'], 'Fatal error') === false);
$recRepResp = curlReq("$baseUrl/record-report.php?type=student_achievement&format=word", 'GET', [], $cookieFaculty);
$recRepOk = ($recRepResp['code'] === 200 && stripos($recRepResp['body'], 'Fatal error') === false);
recordTest('TEST 10', 'Report pages open without broken links or fatal errors', $repOk && $recRepOk, "reports.php HTTP: {$repResp['code']}, record-report.php HTTP: {$recRepResp['code']}");

// -------------------------------------------------------------
// TEST 11 — Database safety (student_participations table intact)
// -------------------------------------------------------------
$tableExists = false;
$tStmt = $pdo->query("SHOW TABLES LIKE 'student_participations'");
if ($tStmt->fetch()) {
    $tableExists = true;
}
recordTest('TEST 11', 'Historical student_participations database table remains safely intact', $tableExists, "Table exists: " . ($tableExists ? 'Yes' : 'No'));

$allPass = true;
foreach ($results as $r) {
    if ($r['status'] !== 'PASS') $allPass = false;
}
echo "\n============================================\n";
echo "OVERALL VERIFICATION: " . ($allPass ? "ALL 11 TESTS PASSED!" : "SOME TESTS FAILED!") . "\n";
echo "============================================\n";
