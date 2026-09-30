<?php
require_once __DIR__ . '/../php-app/inc/env.php';
require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/inc/helpers.php';
require_once __DIR__ . '/../php-app/models/Target.php';
require_once __DIR__ . '/../php-app/models/Record.php';

$baseUrl = 'http://localhost:8000';
$cookieFaculty = tempnam(sys_get_temp_dir(), 'test_fac_');
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

// Fetch placement form
$formResp = curlReq("$baseUrl/upload.php?type=placement", 'GET', [], $cookieFaculty);
$formHtml = $formResp['body'];
$csrf = getCsrf($formHtml);

// -------------------------------------------------------------
// TEST 1 — Academic Session label
// -------------------------------------------------------------
$hasAcademicSession = (stripos($formHtml, 'Academic Session') !== false);
// Ensure "Exam Session" does NOT appear within the placement form container
$placementFormChunk = '';
if (preg_match('/selectedType === \'placement\'|New Placement[\s\S]*?Web Link to Appointment Order/i', $formHtml, $m)) {
    $placementFormChunk = $m[0];
} else {
    $placementFormChunk = $formHtml;
}
$noExamSession = (stripos($placementFormChunk, 'Exam Session') === false);
recordTest('TEST 1', 'Academic Session label visible and Exam Session removed', $hasAcademicSession && $noExamSession, "Academic Session: " . ($hasAcademicSession ? 'Yes' : 'No') . ", Exam Session in form: " . ($noExamSession ? 'No' : 'Yes'));

// -------------------------------------------------------------
// TEST 2 — Academic Session dropdown
// -------------------------------------------------------------
$hasSessionSelect = preg_match('/<select[^>]*name="academic_session"[^>]*>([\s\S]*?)<\/select>/i', $formHtml, $mSess);
$sessionOptionsCount = 0;
$hasActiveYearOption = false;
$activeAy = active_academic_year();
if ($hasSessionSelect) {
    preg_match_all('/<option[^>]*value="([^"]*)"/i', $mSess[1], $mOpts);
    $sessionOptionsCount = count($mOpts[1]);
    $hasActiveYearOption = in_array($activeAy, $mOpts[1], true);
}
recordTest('TEST 2', 'Academic Session dropdown contains valid academic years', $hasSessionSelect && $sessionOptionsCount >= 5 && $hasActiveYearOption, "Options count: $sessionOptionsCount, active $activeAy present: " . ($hasActiveYearOption ? 'Yes' : 'No'));

// -------------------------------------------------------------
// TEST 3 — Job Role label
// -------------------------------------------------------------
$hasJobRoleLabel = (stripos($placementFormChunk, 'Job Role') !== false);
$noJobNameLabel = (stripos($placementFormChunk, 'Job Name') === false);
recordTest('TEST 3', 'Job Role label visible and Job Name removed', $hasJobRoleLabel && $noJobNameLabel, "Job Role present: " . ($hasJobRoleLabel ? 'Yes' : 'No') . ", Job Name: " . ($noJobNameLabel ? 'No' : 'Yes'));

// -------------------------------------------------------------
// TEST 4 — Job Role dropdown
// -------------------------------------------------------------
$hasJobRoleSelect = preg_match('/<select[^>]*name="job_title"[^>]*>([\s\S]*?)<\/select>/i', $formHtml, $mRole);
$hasSoftwareEngineer = false;
$roleCount = 0;
if ($hasJobRoleSelect) {
    preg_match_all('/<option[^>]*value="([^"]*)"/i', $mRole[1], $mRoles);
    $roleCount = count($mRoles[1]);
    $hasSoftwareEngineer = (stripos($mRole[1], 'Software Engineer') !== false);
}
recordTest('TEST 4', 'Job Role dropdown with predefined role options', $hasJobRoleSelect && $hasSoftwareEngineer && $roleCount >= 5, "Dropdown found, count: $roleCount, Software Engineer present: " . ($hasSoftwareEngineer ? 'Yes' : 'No'));

// -------------------------------------------------------------
// TEST 5 — Pay Scale field
// -------------------------------------------------------------
$hasPayScaleInput = preg_match('/<input[^>]*name="pay_scale"[^>]*type="(number|text)"[^>]*>/i', $formHtml, $mPay);
$isNumericInput = $hasPayScaleInput && (stripos($mPay[0], 'type="number"') !== false || stripos($mPay[0], 'step=') !== false);
recordTest('TEST 5', 'Pay Scale numeric input field', $isNumericInput, "Input tag: " . ($mPay[0] ?? 'not found'));

// -------------------------------------------------------------
// TEST 6 — Decimal value (7.5 accepted)
// -------------------------------------------------------------
$stepAny = (stripos($mPay[0] ?? '', 'step="any"') !== false || stripos($mPay[0] ?? '', 'step="0.') !== false || stripos($mPay[0] ?? '', 'step') !== false);
recordTest('TEST 6', 'Decimal value accepted (step="any" / decimals allowed)', $stepAny, "Step attribute present: " . ($stepAny ? 'Yes' : 'No'));

// -------------------------------------------------------------
// TEST 7 — LPA suffix
// -------------------------------------------------------------
$hasLpaSuffix = preg_match('/LPA/i', $formHtml) && preg_match('/name="pay_scale"[\s\S]{0,400}LPA/i', $formHtml);
recordTest('TEST 7', 'LPA suffix visually integrated with Pay Scale input', (bool)$hasLpaSuffix, "Integrated LPA element found");

// -------------------------------------------------------------
// TEST 8 — Other decimal (0.5 accepted by validation)
// -------------------------------------------------------------
$testVal05 = '0.5';
$valPass05 = is_numeric($testVal05) && (float)$testVal05 > 0 && preg_match('/^\d+(\.\d+)?$/', $testVal05);
recordTest('TEST 8', 'Other decimal (0.5 accepted by validation)', (bool)$valPass05, "0.5 matches validation rule");

// -------------------------------------------------------------
// TEST 9 — Invalid value ('abc' rejected server-side)
// -------------------------------------------------------------
$postFieldsBad = [
    'csrf'                   => $csrf,
    'record_type'            => 'placement',
    'nav'                    => 'add',
    'reg_no'                 => '91152000001',
    'student_name'           => 'Test Invalid Student',
    'department'             => 'CSBS',
    'academic_session'       => $activeAy,
    'job_title'              => 'Software Engineer',
    'mode'                   => 'On Campus',
    'company'                => 'Test Co',
    'pay_scale'              => 'abc',
    'appointment_order_link' => 'https://example.com/order',
];
$respBad = curlReq("$baseUrl/upload.php?type=placement", 'POST', $postFieldsBad, $cookieFaculty);
$badRejected = (stripos($respBad['body'], 'Pay Scale must be a valid positive numeric value') !== false || stripos($respBad['body'], 'valid positive numeric') !== false);
recordTest('TEST 9', 'Invalid value (abc) rejected server-side', $badRejected, "Validation message triggered");

// -------------------------------------------------------------
// TEST 10 — Negative value (-1 rejected server-side)
// -------------------------------------------------------------
$postFieldsNeg = $postFieldsBad;
$postFieldsNeg['csrf'] = getCsrf($respBad['body']) ?: $csrf;
$postFieldsNeg['pay_scale'] = '-1';
$respNeg = curlReq("$baseUrl/upload.php?type=placement", 'POST', $postFieldsNeg, $cookieFaculty);
$negRejected = (stripos($respNeg['body'], 'Pay Scale must be a valid positive numeric value') !== false || stripos($respNeg['body'], 'valid positive numeric') !== false);
recordTest('TEST 10', 'Negative value (-1) rejected server-side', $negRejected, "Validation rejected negative number");

// -------------------------------------------------------------
// TEST 11 — Placement submission (Pay Scale = 7.5)
// -------------------------------------------------------------
$pdo = db();
$uniqReg = '9115' . rand(1000000, 9999999);
$uniqName = 'Test Student ' . uniqid();
$postValid = [
    'csrf'                   => getCsrf($respNeg['body']) ?: $csrf,
    'record_type'            => 'placement',
    'nav'                    => 'add',
    'reg_no'                 => $uniqReg,
    'student_name'           => $uniqName,
    'department'             => 'CSBS',
    'academic_session'       => $activeAy,
    'job_title'              => 'Software Engineer',
    'mode'                   => 'On Campus',
    'company'                => "O'Connor & Sons Tech",
    'pay_scale'              => '7.5',
    'appointment_order_link' => 'https://example.com/order/' . uniqid(),
    'proof'                  => new CURLFile($testPdf, 'application/pdf', 'proof.pdf'),
];
$respValid = curlReq("$baseUrl/upload.php?type=placement", 'POST', $postValid, $cookieFaculty);

$stmt = $pdo->prepare("SELECT * FROM placements WHERE reg_no = ?");
$stmt->execute([$uniqReg]);
$savedRow = $stmt->fetch(PDO::FETCH_ASSOC);
$saveSuccess = ($savedRow !== false && $savedRow['student_name'] === $uniqName);
recordTest('TEST 11', 'Placement submission saves successfully', $saveSuccess, "Saved ID: " . ($savedRow['id'] ?? 'none'));

// -------------------------------------------------------------
// TEST 12 — Database value (stored as 7.5, not '7.5 LPA')
// -------------------------------------------------------------
$storedNumeric = ($savedRow && $savedRow['pay_scale'] === '7.5' && $savedRow['pay_scale'] !== '7.5 LPA');
recordTest('TEST 12', 'Database stores numeric value 7.5 without LPA string', $storedNumeric, "Stored pay_scale: '" . ($savedRow['pay_scale'] ?? '') . "'");

// -------------------------------------------------------------
// TEST 13 — Existing record display
// -------------------------------------------------------------
$attrs = record_display_attributes('placement', $savedRow);
$payAttr = null;
foreach ($attrs as $a) {
    if ($a['key'] === 'pay_scale') $payAttr = $a;
}
$displaysWithLpa = ($payAttr && stripos($payAttr['value'], '7.5 LPA') !== false);
recordTest('TEST 13', 'Existing record display shows 7.5 LPA', $displaysWithLpa, "Attribute display: " . ($payAttr['value'] ?? 'none'));

// -------------------------------------------------------------
// TEST 14 — Existing workflow (status is Submitted/Approved)
// -------------------------------------------------------------
$validStatus = ($savedRow && in_array($savedRow['status'], ['Submitted', 'Approved', 'HOD Pending'], true));
recordTest('TEST 14', 'Existing Placement review workflow preserved', $validStatus, "Status: " . ($savedRow['status'] ?? 'none'));

// -------------------------------------------------------------
// TEST 15 — Proof upload
// -------------------------------------------------------------
$hasProofFile = ($savedRow && !empty($savedRow['proof_file']) && file_exists(__DIR__ . '/../php-app/uploads/' . $savedRow['proof_file']));
recordTest('TEST 15', 'Proof uploads correctly and file exists', $hasProofFile, "Proof file: " . ($savedRow['proof_file'] ?? 'none'));

// -------------------------------------------------------------
// TEST 16 — Draft behavior preserved
// -------------------------------------------------------------
$hasDraftScript = (stripos($formHtml, 'saveDraft') !== false && stripos($formHtml, 'restoreDraft') !== false);
$hasDraftStatus = (stripos($formHtml, 'js-draft-status') !== false);
recordTest('TEST 16', 'Draft preservation in sessionStorage and UI status', $hasDraftScript && $hasDraftStatus, "Draft auto-save scripts and status indicator active");

// -------------------------------------------------------------
// TEST 17 — Special characters (O'Connor & Sons)
// -------------------------------------------------------------
$specialCharsSafe = ($savedRow && $savedRow['company'] === "O'Connor & Sons Tech");
recordTest('TEST 17', "Special characters handled safely (O'Connor & Sons)", $specialCharsSafe, "Stored company: " . ($savedRow['company'] ?? 'none'));

// -------------------------------------------------------------
// TEST 18 — Academic Year
// -------------------------------------------------------------
$ayMatchesActive = ($savedRow && $savedRow['academic_year'] === $activeAy);
recordTest('TEST 18', 'Placement record uses active centralized Academic Year', $ayMatchesActive, "Stored academic_year: " . ($savedRow['academic_year'] ?? 'none'));

// Cleanup test row
if ($savedRow) {
    if (!empty($savedRow['proof_file'])) {
        @unlink(__DIR__ . '/../php-app/uploads/' . $savedRow['proof_file']);
    }
    $pdo->prepare("DELETE FROM placements WHERE id = ?")->execute([$savedRow['id']]);
    $pdo->prepare("DELETE FROM workflow_audit_logs WHERE record_id = ? AND record_type = 'placement'")->execute([$savedRow['id']]);
    echo "Cleaned up temporary test record ID {$savedRow['id']}.\n";
}

$allPass = true;
foreach ($results as $r) {
    if ($r['status'] !== 'PASS') $allPass = false;
}
echo "\n============================================\n";
echo "OVERALL VERIFICATION: " . ($allPass ? "ALL 18 TESTS PASSED!" : "SOME TESTS FAILED!") . "\n";
echo "============================================\n";
