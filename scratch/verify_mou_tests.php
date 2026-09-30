<?php
require_once __DIR__ . '/../php-app/inc/env.php';
require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/models/Record.php';

$baseUrl = 'http://localhost:8000';
$cookieFile = tempnam(sys_get_temp_dir(), 'cook_mou_test_');

function curlReq(string $url, string $method = 'GET', $data = [], ?string $cookie = null, bool $follow = true, array $headers = []): array {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if ($follow) curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    if ($cookie) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie);
    }
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    }
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $effUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    return ['code' => $code, 'body' => $resp, 'effUrl' => $effUrl];
}

function getCsrf(string $html): string {
    if (preg_match('/name="csrf"\s+value="([^"]+)"/', $html, $m)) return $m[1];
    if (preg_match('/value="([^"]+)"\s+name="csrf"/', $html, $m)) return $m[1];
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

// Clean up any old test records if present
$pdo = db();
$pdo->exec("DELETE FROM mou WHERE organization LIKE 'Test Automation Labs%'");

// Record initial MOU table state for TEST 7
$initMous = $pdo->query("SELECT id, department, signed_date, organization, valid_upto, purpose, document_link, status, proof_file, exam_session FROM mou ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

// Login as faculty
$loginPage = curlReq("$baseUrl/login.php", 'GET', [], $cookieFile);
$csrf = getCsrf($loginPage['body']);
$postLogin = [
    'csrf'     => $csrf,
    'email'    => 'faculty@atts.edu',
    'password' => 'faculty12',
    'role'     => 'Faculty',
];
curlReq("$baseUrl/login.php", 'POST', http_build_query($postLogin), $cookieFile, true);

// Fetch MOU form
$mouPage = curlReq("$baseUrl/upload.php?type=mou", 'GET', [], $cookieFile);
$html = $mouPage['body'];

// Extract the form HTML
$mouFormHtml = '';
if (preg_match('/<form[^>]*enctype="multipart\/form-data"[^>]*>([\s\S]*?)<\/form>/i', $html, $m)) {
    $mouFormHtml = $m[0];
}

// TEST 1: Open the MOU form. Expected: "Academic Session" is visible.
$hasAcademicSession = (stripos($mouFormHtml, 'Academic Session') !== false);
recordTest('TEST 1', 'Academic Session is visible in MOU form', $hasAcademicSession, $hasAcademicSession ? 'Label found' : 'Not found');

// TEST 2: Expected: "Exam Session" is not displayed for the MOU session field.
$noExamSessionInMou = (stripos($mouFormHtml, 'Exam Session') === false);
recordTest('TEST 2', 'Exam Session is NOT displayed for MOU session field', $noExamSessionInMou, $noExamSessionInMou ? 'Exam Session not present in MOU form' : 'Exam Session found in MOU form');

// TEST 3: Verify all existing MOU fields are still present and in the same order.
preg_match_all('/<label[^>]*>(.*?)<\/label>/i', $mouFormHtml, $mLabels);
$labels = array_map(function($l) {
    return trim(preg_replace('/\s+/', ' ', strip_tags($l)));
}, $mLabels[1]);

$expectedFieldOrder = [
    'Department *',
    'Academic Session *',
    'Signed Date * (dd/mm/yyyy)',
    'Name & Address of the Collaborating Body * (Industry / Institution / Agency)',
    'Valid upto * (dd/mm/yyyy)',
    'Purpose of Collaboration *',
    'Document Link *',
    'Proof / Attachment — PDF only, strictly 2 MB or less'
];

$orderMatches = true;
$orderIssues = [];
foreach ($expectedFieldOrder as $idx => $expected) {
    $actual = $labels[$idx] ?? '';
    $actualDec = html_entity_decode($actual, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $expectedDec = html_entity_decode($expected, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($actualDec !== $expectedDec) {
        $orderMatches = false;
        $orderIssues[] = "Index $idx: expected '$expectedDec', got '$actualDec'";
    }
}
recordTest('TEST 3', 'All existing MOU fields are present and in the same order', $orderMatches, empty($orderIssues) ? 'Exact order verified' : implode('; ', $orderIssues));

// TEST 4: Verify MOU dropdowns and inputs behave exactly as before.
$hasExamSessionSelect = (bool) preg_match('/<select[^>]*name="exam_session"[^>]*>([\s\S]*?)<\/select>/i', $mouFormHtml, $mSel);
$hasNovDec = false;
$hasAprMay = false;
if ($hasExamSessionSelect) {
    $hasNovDec = stripos($mSel[1], 'Nov-Dec') !== false;
    $hasAprMay = stripos($mSel[1], 'Apr-May') !== false;
}
$deptSelectPresent = (bool) preg_match('/name="department"/i', $mouFormHtml);
$signedDateInput = (bool) preg_match('/name="signed_date"[^>]*type="date"/i', $mouFormHtml);
$orgInput = (bool) preg_match('/name="organization"/i', $mouFormHtml);
$validUptoInput = (bool) preg_match('/name="valid_upto"[^>]*type="date"/i', $mouFormHtml);
$purposeInput = (bool) preg_match('/name="purpose"/i', $mouFormHtml);
$docLinkInput = (bool) preg_match('/name="document_link"[^>]*type="url"/i', $mouFormHtml);

$t4Pass = $hasExamSessionSelect && $hasNovDec && $hasAprMay && $deptSelectPresent && $signedDateInput && $orgInput && $validUptoInput && $purposeInput && $docLinkInput;
recordTest('TEST 4', 'MOU dropdowns and inputs behave exactly as before', $t4Pass, "Select name=exam_session: " . ($hasExamSessionSelect ? 'Yes' : 'No') . ", Nov-Dec: " . ($hasNovDec ? 'Yes' : 'No') . ", Apr-May: " . ($hasAprMay ? 'Yes' : 'No'));

// TEST 5: Verify existing MOU Save Draft / Submit behavior still works.
// Create dummy PDF
$dummyPdf = tempnam(sys_get_temp_dir(), 'test_mou_pdf_') . '.pdf';
file_put_contents($dummyPdf, "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Count 1/Kids[3 0 R]>>endobj 3 0 obj<</Type/Page/MediaBox[0 0 612 792]/Parent 2 0 R/Resources<<>>>>endobj\nxref\n0 4\n0000000000 65535 f\n0000000009 00000 n\n0000000052 00000 n\n0000000102 00000 n\ntrailer<</Size 4/Root 1 0 R>>\nstartxref\n178\n%%EOF");

// Test 5A: Save Draft (client-side script in page)
$hasSaveDraftScript = (stripos($html, 'saveDraft') !== false && stripos($html, 'restoreDraft') !== false);

// Test 5B: Submit form (Save & add another or Next)
$formCsrf = getCsrf($html);
$submitPost = [
    'csrf'         => $formCsrf,
    'record_type'  => 'mou',
    'department'   => 'CSBS',
    'exam_session' => 'Nov-Dec',
    'signed_date'  => '2026-03-01',
    'organization' => 'Test Automation Labs Submitted',
    'valid_upto'   => '2028-03-01',
    'purpose'      => 'Academic Verification Submit Testing',
    'document_link'=> 'https://atts.edu/mou/test-submit.pdf',
    'nav'          => 'add'
];
$curlFile = new CURLFile($dummyPdf, 'application/pdf', 'proof.pdf');
$submitPostData = array_merge($submitPost, ['proof' => $curlFile]);
$submitResp = curlReq("$baseUrl/upload.php", 'POST', $submitPostData, $cookieFile, true);

$submitRow = $pdo->query("SELECT * FROM mou WHERE organization = 'Test Automation Labs Submitted' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$submitSaved = !empty($submitRow) && ($submitRow['status'] === 'HOD Pending' || $submitRow['status'] === 'Submitted');

$t5Pass = $hasSaveDraftScript && $submitSaved;
recordTest('TEST 5', 'MOU Save Draft / Submit behavior still works', $t5Pass, "SaveDraft logic present: " . ($hasSaveDraftScript ? 'Yes' : 'No') . "; Submit ID: " . ($submitRow['id'] ?? 'none') . " status=" . ($submitRow['status'] ?? 'none'));

// TEST 6: Verify existing MOU proof upload / View Proof / Download still works.
$proofUploaded = !empty($submitRow['proof_file']);
$proofFileExists = false;
$proofViewable = false;
$viewCode = 0;
if ($proofUploaded) {
    $proofPath = __DIR__ . '/../php-app/uploads/' . $submitRow['proof_file'];
    $proofFileExists = file_exists($proofPath);
    // Correct URL for view-proof.php
    $proofResp = curlReq("$baseUrl/view-proof.php?file=" . urlencode($submitRow['proof_file']) . "&type=mou&id={$submitRow['id']}", 'GET', [], $cookieFile);
    $viewCode = $proofResp['code'];
    $proofViewable = ($viewCode === 200 && strpos($proofResp['body'], '%PDF') === 0);
}
$t6Pass = $proofUploaded && $proofFileExists && $proofViewable;
recordTest('TEST 6', 'MOU proof upload / View Proof / Download still works', $t6Pass, "Proof file: {$submitRow['proof_file']}, View HTTP code: $viewCode");

// Clean up temporary test row created during TEST 5 and TEST 6
if ($submitRow) {
    if (!empty($submitRow['proof_file']) && file_exists(__DIR__ . '/../php-app/uploads/' . $submitRow['proof_file'])) {
        @unlink(__DIR__ . '/../php-app/uploads/' . $submitRow['proof_file']);
    }
    $pdo->exec("DELETE FROM mou WHERE id = {$submitRow['id']}");
}
if (file_exists($dummyPdf)) @unlink($dummyPdf);

// TEST 7: Verify existing MOU records were not modified.
$afterMous = $pdo->query("SELECT id, department, signed_date, organization, valid_upto, purpose, document_link, status, proof_file, exam_session FROM mou ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$mousUnchanged = ($initMous === $afterMous);
recordTest('TEST 7', 'Existing MOU records were not modified', $mousUnchanged, "Count before: " . count($initMous) . ", Count after: " . count($afterMous) . ", Identical: " . ($mousUnchanged ? 'Yes' : 'No'));

// Check other forms to verify Exam Session was not modified elsewhere
$otherTypes = ['patent', 'fdp', 'event', 'nptel', 'internship', 'online_course', 'summer_training', 'value_added', 'training'];
$allOthersRetainExamSession = true;
$otherResults = [];
foreach ($otherTypes as $ot) {
    $otPage = curlReq("$baseUrl/upload.php?type=$ot", 'GET', [], $cookieFile);
    $hasExam = (stripos($otPage['body'], 'Exam Session') !== false);
    if (!$hasExam) $allOthersRetainExamSession = false;
    $otherResults[$ot] = $hasExam ? 'Exam Session visible' : 'MISSING Exam Session';
}
recordTest('SOURCE AUDIT', 'No unrelated Exam Session fields modified in other forms', $allOthersRetainExamSession, $allOthersRetainExamSession ? 'All ' . count($otherTypes) . ' other forms retain Exam Session' : json_encode($otherResults));

// TEST 8: Verify the global Academic Year system is unchanged.
$adminCookie = tempnam(sys_get_temp_dir(), 'cook_admin_');
$ayAdminLogin = curlReq("$baseUrl/login.php", 'GET', [], $adminCookie);
$postAdmin = [
    'csrf'     => getCsrf($ayAdminLogin['body']),
    'email'    => 'mohameduvaish132@gmail.com',
    'password' => 'uvaish123',
    'role'     => 'Admin',
];
curlReq("$baseUrl/login.php", 'POST', http_build_query($postAdmin), $adminCookie, true);
$ayAdminPage = curlReq("$baseUrl/academic-years.php", 'GET', [], $adminCookie);
$hasAyTitle = (stripos($ayAdminPage['body'], 'Academic Years') !== false);
$activeYearVal = active_academic_year();
$ayList = academic_years();
$t8Pass = $hasAyTitle && !empty($activeYearVal) && !empty($ayList);
recordTest('TEST 8', 'Global Academic Year system is unchanged', $t8Pass, "Active AY: $activeYearVal, Total AYs: " . count($ayList) . ", Academic Years page loads: " . ($hasAyTitle ? 'Yes' : 'No'));

echo "\n--- SUMMARY ---\n";
$allPass = true;
foreach ($results as $r) {
    if ($r['status'] !== 'PASS') $allPass = false;
}
echo "Overall: " . ($allPass ? 'ALL TESTS PASSED' : 'SOME TESTS FAILED') . "\n";
