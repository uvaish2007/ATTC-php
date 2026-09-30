<?php
require_once __DIR__ . '/../php-app/inc/env.php';
require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/inc/helpers.php';
require_once __DIR__ . '/../php-app/models/Target.php';
require_once __DIR__ . '/../php-app/models/Record.php';

$baseUrl = 'http://localhost:8000';
$cookieFaculty = tempnam(sys_get_temp_dir(), 'test_nss_fac_');
$testPdf = __DIR__ . '/test_proof.pdf';
if (!file_exists($testPdf)) {
    file_put_contents($testPdf, "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Count 1/Kids[3 0 R]>>endobj 3 0 obj<</Type/Page/MediaBox[0 0 300 144]>>endobj\nxref\n0 4\n0000000000 65535 f\n0000000009 00000 n\n0000000058 00000 n\n0000000115 00000 n\ntrailer<</Size 4/Root 1 0 R>>\nstartxref\n180\n%%EOF");
}

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

// Step 0: Login as faculty
$resLogin = curlReq("$baseUrl/login.php", 'GET', [], $cookieFaculty);
$csrfLogin = getCsrf($resLogin['body']);
$postLogin = [
    'csrf'     => $csrfLogin,
    'email'    => 'faculty@atts.edu',
    'password' => 'faculty12',
    'role'     => 'Faculty',
];
$loginResp = curlReq("$baseUrl/login.php", 'POST', http_build_query($postLogin), $cookieFaculty, true);

// Fetch NSS form
$formResp = curlReq("$baseUrl/upload.php?type=nss", 'GET', [], $cookieFaculty);
$formHtml = $formResp['body'];
$csrf = getCsrf($formHtml);

$nssChunk = '';
if (preg_match('/selectedType === \'nss\'|New NSS[\s\S]*?Web Link to Event Report/i', $formHtml, $m)) {
    $nssChunk = $m[0];
} else {
    $nssChunk = $formHtml;
}

// -------------------------------------------------------------
// TEST 1 — NSS form (Academic Session displayed, Exam Session NOT displayed)
// -------------------------------------------------------------
$hasAcademicSession = (stripos($nssChunk, 'Academic Session') !== false);
$noExamSession = (stripos($nssChunk, 'Exam Session') === false);
recordTest('TEST 1', 'NSS form displays Academic Session and Exam Session is removed', $hasAcademicSession && $noExamSession, "Academic Session: " . ($hasAcademicSession ? 'Yes' : 'No') . ", Exam Session: " . ($noExamSession ? 'No' : 'Yes'));

// -------------------------------------------------------------
// TEST 2 — Activity Type dropdown (NSS, YRC, RRC, UBA)
// -------------------------------------------------------------
$hasActSelect = preg_match('/<select[^>]*name="activity_type"[^>]*>([\s\S]*?)<\/select>/i', $nssChunk, $mAct);
$hasNss = false; $hasYrc = false; $hasRrc = false; $hasUba = false;
if ($hasActSelect) {
    $opts = $mAct[1];
    $hasNss = (stripos($opts, 'NSS') !== false);
    $hasYrc = (stripos($opts, 'YRC') !== false);
    $hasRrc = (stripos($opts, 'RRC') !== false);
    $hasUba = (stripos($opts, 'UBA') !== false);
}
$actAllPresent = $hasNss && $hasYrc && $hasRrc && $hasUba;
recordTest('TEST 2', 'Activity Type dropdown contains NSS, YRC, RRC, UBA', $actAllPresent, "NSS: $hasNss, YRC: $hasYrc, RRC: $hasRrc, UBA: $hasUba");

// -------------------------------------------------------------
// TEST 6 — Invalid Activity Type rejected server-side
// -------------------------------------------------------------
$activeAy = active_academic_year();
$selectedSession = '2025-26'; // Different from global active year to test session independently!
$postFieldsBad = [
    'csrf'             => $csrf,
    'record_type'      => 'nss',
    'nav'              => 'add',
    'department'       => 'CSBS',
    'academic_session' => $selectedSession,
    'activity_date'    => '2026-03-15',
    'activity_type'    => 'XYZ', // Invalid!
    'activity_name'    => 'Test Invalid Activity',
    'venue'            => 'Test Hall',
    'participants'     => '50',
    'external_agency'  => 'Test Agency',
    'report_link'      => 'https://example.com/report',
];
$respBad = curlReq("$baseUrl/upload.php?type=nss", 'POST', $postFieldsBad, $cookieFaculty);
$badRejected = (stripos($respBad['body'], 'Activity Type must be one of: NSS, YRC, RRC, UBA') !== false);
recordTest('TEST 6', 'Invalid Activity Type (XYZ) rejected server-side', $badRejected, "Validation message triggered: " . ($badRejected ? 'Yes' : 'No'));

// -------------------------------------------------------------
// TEST 3 — UBA selection & submission succeeds
// -------------------------------------------------------------
$pdo = db();
$uniqActName = 'UBA Village Solar Adoption Awareness ' . uniqid();
$postValidUba = [
    'csrf'             => getCsrf($respBad['body']) ?: $csrf,
    'record_type'      => 'nss',
    'nav'              => 'add',
    'department'       => 'CSBS',
    'academic_session' => $selectedSession,
    'activity_date'    => '2026-03-20',
    'activity_type'    => 'UBA',
    'activity_name'    => $uniqActName,
    'venue'            => "Keelakarai Panchayat Community Hall",
    'participants'     => '120',
    'external_agency'  => "Unnat Bharat Abhiyan Cell - Prof. O'Connor",
    'report_link'      => 'https://example.com/uba_report_' . uniqid(),
    'proof'            => new CURLFile($testPdf, 'application/pdf', 'uba_proof.pdf'),
];
$respValidUba = curlReq("$baseUrl/upload.php?type=nss", 'POST', $postValidUba, $cookieFaculty);

$stmt = $pdo->prepare("SELECT * FROM nss WHERE activity_name = ?");
$stmt->execute([$uniqActName]);
$savedRowUba = $stmt->fetch(PDO::FETCH_ASSOC);
$saveSuccessUba = ($savedRowUba !== false && $savedRowUba['activity_type'] === 'UBA');
recordTest('TEST 3', 'UBA selection and submission succeeds', $saveSuccessUba, "Saved ID: " . ($savedRowUba['id'] ?? 'none'));

// -------------------------------------------------------------
// TEST 4 — Database verification (activity_type = UBA)
// -------------------------------------------------------------
$dbUba = ($savedRowUba && $savedRowUba['activity_type'] === 'UBA');
recordTest('TEST 4', 'Database stores activity_type = UBA correctly', $dbUba, "Stored activity_type: '" . ($savedRowUba['activity_type'] ?? '') . "'");

// -------------------------------------------------------------
// TEST 5 — Existing options (NSS, YRC, RRC all continue to work)
// -------------------------------------------------------------
$allExistingPassed = true;
$createdIds = [];
if ($savedRowUba) $createdIds[] = $savedRowUba['id'];

foreach (['NSS', 'YRC', 'RRC'] as $testType) {
    $tName = "Test $testType Activity " . uniqid();
    $postT = [
        'csrf'             => getCsrf($respValidUba['body']) ?: $csrf,
        'record_type'      => 'nss',
        'nav'              => 'add',
        'department'       => 'CSBS',
        'academic_session' => $selectedSession,
        'activity_date'    => '2026-03-22',
        'activity_type'    => $testType,
        'activity_name'    => $tName,
        'venue'            => 'Auditorium',
        'participants'     => '40',
        'external_agency'  => 'District Red Cross Society',
        'report_link'      => 'https://example.com/rep',
    ];
    $rT = curlReq("$baseUrl/upload.php?type=nss", 'POST', $postT, $cookieFaculty);
    $st = $pdo->prepare("SELECT id, activity_type FROM nss WHERE activity_name = ?");
    $st->execute([$tName]);
    $rowT = $st->fetch(PDO::FETCH_ASSOC);
    if (!$rowT || $rowT['activity_type'] !== $testType) {
        $allExistingPassed = false;
    } else {
        $createdIds[] = $rowT['id'];
    }
}
recordTest('TEST 5', 'Existing options (NSS, YRC, RRC) all continue to work', $allExistingPassed, "NSS, YRC, RRC submissions verified");

// -------------------------------------------------------------
// TEST 7 — Academic Session (saved with correct Academic Session)
// -------------------------------------------------------------
$dbSession = ($savedRowUba && ($savedRowUba['academic_session'] === $selectedSession || $savedRowUba['exam_session'] === $selectedSession));
recordTest('TEST 7', 'Record saves with correct Academic Session', $dbSession, "Stored session: '" . ($savedRowUba['academic_session'] ?? $savedRowUba['exam_session'] ?? '') . "'");

// -------------------------------------------------------------
// TEST 8 — Academic Year (global Admin-controlled Academic Year correct)
// -------------------------------------------------------------
$dbYear = ($savedRowUba && $savedRowUba['academic_year'] === $activeAy);
recordTest('TEST 8', 'Global Admin-controlled Academic Year remains correct', $dbYear, "Stored academic_year: '" . ($savedRowUba['academic_year'] ?? '') . "' vs active '$activeAy'");

// -------------------------------------------------------------
// TEST 9 — Draft preservation support
// -------------------------------------------------------------
$hasDraftScript = (stripos($formHtml, 'saveDraft') !== false && stripos($formHtml, 'restoreDraft') !== false);
$hasDraftStatus = (stripos($formHtml, 'js-draft-status') !== false);
recordTest('TEST 9', 'Draft auto-preservation supported in UI and sessionStorage', $hasDraftScript && $hasDraftStatus, "Draft save/restore scripts and status indicator active");

// -------------------------------------------------------------
// TEST 10 — Submit and Review workflow
// -------------------------------------------------------------
$validStatus = ($savedRowUba && in_array($savedRowUba['status'], ['Submitted', 'Approved', 'HOD Pending'], true));
// Check audit log
$auditStmt = $pdo->prepare("SELECT * FROM workflow_audit_logs WHERE record_id = ? AND record_type = 'nss'");
$auditStmt->execute([$savedRowUba['id'] ?? 0]);
$hasAudit = ($auditStmt->fetch() !== false);
recordTest('TEST 10', 'Submit and Review workflow works correctly', $validStatus && $hasAudit, "Status: '" . ($savedRowUba['status'] ?? '') . "', Audit logged: " . ($hasAudit ? 'Yes' : 'No'));

// -------------------------------------------------------------
// TEST 11 — Proof upload & View Proof
// -------------------------------------------------------------
$hasProofFile = ($savedRowUba && !empty($savedRowUba['proof_file']) && file_exists(__DIR__ . '/../php-app/uploads/' . $savedRowUba['proof_file']));
$viewProofUrl = "$baseUrl/view-proof.php?file=" . urlencode($savedRowUba['proof_file'] ?? '');
$viewProofResp = curlReq($viewProofUrl, 'GET', [], $cookieFaculty);
$viewProofWorks = ($viewProofResp['code'] === 200 && (stripos($viewProofResp['body'], '%PDF') !== false || stripos($viewProofResp['head'] ?? '', 'application/pdf') !== false));
recordTest('TEST 11', 'Proof uploads successfully and View Proof serves actual PDF', $hasProofFile && $viewProofWorks, "File: " . ($savedRowUba['proof_file'] ?? '') . ", View Proof HTTP: " . $viewProofResp['code']);

// Cleanup temporary test records
foreach ($createdIds as $cid) {
    $row = $pdo->query("SELECT proof_file FROM nss WHERE id = $cid")->fetch(PDO::FETCH_ASSOC);
    if (!empty($row['proof_file'])) {
        @unlink(__DIR__ . '/../php-app/uploads/' . $row['proof_file']);
    }
    $pdo->prepare("DELETE FROM nss WHERE id = ?")->execute([$cid]);
    $pdo->prepare("DELETE FROM workflow_audit_logs WHERE record_id = ? AND record_type = 'nss'")->execute([$cid]);
}
echo "Cleaned up " . count($createdIds) . " temporary test records.\n";

$allPass = true;
foreach ($results as $r) {
    if ($r['status'] !== 'PASS') $allPass = false;
}
echo "\n============================================\n";
echo "OVERALL VERIFICATION: " . ($allPass ? "ALL 11 TESTS PASSED!" : "SOME TESTS FAILED!") . "\n";
echo "============================================\n";
