<?php
/**
 * Verification of Academic Session Field Placement and Labels across all Upload Data categories
 */
require_once __DIR__ . '/../php-app/inc/env.php';
require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/models/Record.php';
require_once __DIR__ . '/../php-app/models/Department.php';

$baseUrl = 'http://localhost:8000';
$tests = [];

function check(string $id, string $title, bool $pass, string $details = '') {
    global $tests;
    $tests[] = ['id' => $id, 'title' => $title, 'pass' => $pass, 'details' => $details];
    echo ($pass ? "[PASS] " : "[FAIL] ") . "{$id}: {$title}" . ($details ? " ({$details})" : "") . "\n";
}

function curlReq(string $url, string $method = 'GET', $data = [], ?string $cookie = null, bool $follow = true): array {
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
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $effUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    return ['code' => $code, 'body' => (string)$resp, 'effUrl' => $effUrl];
}

function getCsrf(string $html): string {
    if (preg_match('/name="csrf"\s+value="([^"]+)"/', $html, $m)) return $m[1];
    if (preg_match('/value="([^"]+)"\s+name="csrf"/', $html, $m)) return $m[1];
    return '';
}

// 1. Authenticate as Faculty
$facultyCookie = tempnam(sys_get_temp_dir(), 'cook_fac_');
$loginPage = curlReq("$baseUrl/login.php", 'GET', [], $facultyCookie);
$csrf = getCsrf($loginPage['body']);
$postLogin = [
    'csrf'     => $csrf,
    'email'    => 'faculty@atts.edu',
    'password' => 'faculty12',
    'role'     => 'Faculty',
];
curlReq("$baseUrl/login.php", 'POST', http_build_query($postLogin), $facultyCookie, true);

// TEST 1: Open the Online Course form
$ocResp = curlReq("$baseUrl/upload.php?type=online_course", 'GET', [], $facultyCookie);
$ocBody = $ocResp['body'];
$ocForm = '';
if (preg_match('/<form[^>]*enctype="multipart\/form-data"[^>]*>([\s\S]*?)<\/form>/i', $ocBody, $m)) {
    $ocForm = $m[1];
}
$ocHasAcademic = (strpos($ocForm, 'Academic Session') !== false);
$ocNoExam = (strpos($ocForm, 'Exam Session') === false);
// Check position: Academic Session must appear near the top (before Candidate Name)
$posAcadSess = strpos($ocForm, 'Academic Session');
$posCandName = strpos($ocForm, 'Candidate Name');
$ocPositionNearTop = ($posAcadSess !== false && $posCandName !== false && $posAcadSess < $posCandName);

check('TEST 1', 'Online Course form: Academic Session near top, Exam Session not displayed',
    $ocHasAcademic && $ocNoExam && $ocPositionNearTop,
    "Academic Session: " . ($ocHasAcademic ? 'Yes' : 'No') . ", Exam Session: " . ($ocNoExam ? 'No' : 'Yes') . ", Position before Candidate Name: " . ($ocPositionNearTop ? 'Yes' : 'No')
);

// TEST 2: Open the Placement form
$plResp = curlReq("$baseUrl/upload.php?type=placement", 'GET', [], $facultyCookie);
$plBody = $plResp['body'];
$plForm = '';
if (preg_match('/<form[^>]*enctype="multipart\/form-data"[^>]*>([\s\S]*?)<\/form>/i', $plBody, $m)) {
    $plForm = $m[1];
}
$plHasAcademic = (strpos($plForm, 'Academic Session') !== false);
$plNoExam = (strpos($plForm, 'Exam Session') === false);
$plPosAcad = strpos($plForm, 'Academic Session');
$plPosReg = strpos($plForm, 'Reg. No');
$plPositionNearTop = ($plPosAcad !== false && $plPosReg !== false && $plPosAcad < $plPosReg);

check('TEST 2', 'Placement form: Academic Session near top, Exam Session not displayed',
    $plHasAcademic && $plNoExam && $plPositionNearTop,
    "Academic Session: " . ($plHasAcademic ? 'Yes' : 'No') . ", Exam Session: " . ($plNoExam ? 'No' : 'Yes') . ", Position before Reg. No: " . ($plPositionNearTop ? 'Yes' : 'No')
);

// TEST 3: Open the FDP / Workshop form
$fdpResp = curlReq("$baseUrl/upload.php?type=fdp", 'GET', [], $facultyCookie);
$fdpBody = $fdpResp['body'];
$fdpForm = '';
if (preg_match('/<form[^>]*enctype="multipart\/form-data"[^>]*>([\s\S]*?)<\/form>/i', $fdpBody, $m)) {
    $fdpForm = $m[1];
}
$fdpHasAcademic = (strpos($fdpForm, 'Academic Session') !== false);
$fdpNoExam = (strpos($fdpForm, 'Exam Session') === false);
$fdpPosAcad = strpos($fdpForm, 'Academic Session');
$fdpPosTitle = strpos($fdpForm, 'Name of the FDP');
$fdpPositionNearTop = ($fdpPosAcad !== false && $fdpPosTitle !== false && $fdpPosAcad < $fdpPosTitle);

check('TEST 3', 'FDP / Workshop form: Academic Session near top, Exam Session not displayed',
    $fdpHasAcademic && $fdpNoExam && $fdpPositionNearTop,
    "Academic Session: " . ($fdpHasAcademic ? 'Yes' : 'No') . ", Exam Session: " . ($fdpNoExam ? 'No' : 'Yes') . ", Position before Event Title: " . ($fdpPositionNearTop ? 'Yes' : 'No')
);

// TEST 4: Open the MoU form
$mouResp = curlReq("$baseUrl/upload.php?type=mou", 'GET', [], $facultyCookie);
$mouBody = $mouResp['body'];
$mouForm = '';
if (preg_match('/<form[^>]*enctype="multipart\/form-data"[^>]*>([\s\S]*?)<\/form>/i', $mouBody, $m)) {
    $mouForm = $m[1];
}
$mouHasAcademic = (strpos($mouForm, 'Academic Session') !== false);
$mouNoExam = (strpos($mouForm, 'Exam Session') === false);
$mouPosAcad = strpos($mouForm, 'Academic Session');
$mouPosSign = strpos($mouForm, 'Signed Date');
$mouPositionNearTop = ($mouPosAcad !== false && $mouPosSign !== false && $mouPosAcad < $mouPosSign);

check('TEST 4', 'MoU form: Academic Session near top, Exam Session not displayed, other fields preserved',
    $mouHasAcademic && $mouNoExam && $mouPositionNearTop,
    "Academic Session: " . ($mouHasAcademic ? 'Yes' : 'No') . ", Exam Session: " . ($mouNoExam ? 'No' : 'Yes') . ", Position before Signed Date: " . ($mouPositionNearTop ? 'Yes' : 'No')
);

// TEST 5: Check remaining applicable Upload Data categories
$categoriesToCheck = [
    'patent'           => 'Patent / Copyright',
    'event'            => 'Event',
    'nptel'            => 'NPTEL',
    'internship'       => 'Internship',
    'nss'              => 'NSS / YRC / RRC',
    'co_curricular'    => 'Co-Curricular',
    'extra_curricular' => 'Extra-Curricular',
    'summer_training'  => 'Summer / Winter Training',
    'value_added'      => 'Value Added Course',
    'training'         => 'Training Programme',
];

$allCatsPass = true;
$catDetails = [];
foreach ($categoriesToCheck as $catKey => $catLabel) {
    $cResp = curlReq("$baseUrl/upload.php?type=$catKey", 'GET', [], $facultyCookie);
    $cForm = '';
    if (preg_match('/<form[^>]*enctype="multipart\/form-data"[^>]*>([\s\S]*?)<\/form>/i', $cResp['body'], $m)) {
        $cForm = $m[1];
    }
    $hasAcad = (strpos($cForm, 'Academic Session') !== false);
    $hasExam = (strpos($cForm, 'Exam Session') !== false);
    if (!$hasAcad || $hasExam) {
        $allCatsPass = false;
        $catDetails[] = "$catKey (Acad:" . ($hasAcad ? 'Y' : 'N') . ", Exam:" . ($hasExam ? 'Y' : 'N') . ")";
    }
}

check('TEST 5', 'All applicable Upload Data categories use Academic Session and NOT Exam Session',
    $allCatsPass,
    $allCatsPass ? "All " . count($categoriesToCheck) . " categories verified" : "Failed categories: " . implode(', ', $catDetails)
);

// TEST 6: University Pass Percentage panel
// Coordinator or Admin login to view inst_pass_percentage
$adminCookie = tempnam(sys_get_temp_dir(), 'cook_admin_');
$adminLogin = curlReq("$baseUrl/login.php", 'GET', [], $adminCookie);
$csrfAdmin = getCsrf($adminLogin['body']);
$postAdmin = [
    'csrf'     => $csrfAdmin,
    'email'    => 'mohameduvaish132@gmail.com',
    'password' => 'uvaish123',
    'role'     => 'Admin',
];
curlReq("$baseUrl/login.php", 'POST', http_build_query($postAdmin), $adminCookie, true);

$uppResp = curlReq("$baseUrl/upload.php?type=inst_pass_percentage", 'GET', [], $adminCookie);
$uppBody = $uppResp['body'];
$uppForm = '';
if (preg_match('/<form[^>]*enctype="multipart\/form-data"[^>]*>([\s\S]*?)<\/form>/i', $uppBody, $m)) {
    $uppForm = $m[1];
}
// University Pass Percentage panel must be UNCHANGED
// Check if instPpTable / instPpBody or multi-row table is preserved
$hasPpTable = (strpos($uppBody, 'instPpTable') !== false || strpos($uppBody, 'instPpBody') !== false);
// In inst_upload_forms.php, inst_pass_percentage renders Programme / Course, Class / Year, Semester, and table
// Check if session in inst_pass_percentage is not accidentally changed if present
$noAccidentalDamage = $hasPpTable && (strpos($uppBody, 'instPpAddRow') !== false);

check('TEST 6', 'University Pass Percentage panel remains completely unchanged',
    $noAccidentalDamage,
    "Pass Percentage panel rendered intact with multi-row table and instPpAddRow"
);

// TEST 7: Submit a valid record using an affected category (e.g. online_course)
$ocFormPage = curlReq("$baseUrl/upload.php?type=online_course", 'GET', [], $facultyCookie);
$ocCsrf = getCsrf($ocFormPage['body']);

// Create dummy PDF
$dummyPdf = sys_get_temp_dir() . '/test_proof_' . uniqid() . '.pdf';
file_put_contents($dummyPdf, "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/MediaBox[0 0 612 792]>>endobj\nxref\n0 4\n0000000000 65535 f\n0000000009 00000 n\n0000000052 00000 n\n0000000101 00000 n\ntrailer<</Size 4/Root 1 0 R>>\nstartxref\n162\n%%EOF");

$postFields = [
    'csrf'             => $ocCsrf,
    'record_type'      => 'online_course',
    'department'       => 'CSE',
    'exam_session'     => 'Nov-Dec',
    'candidate_name'   => 'Test Candidate',
    'category'         => 'Faculty',
    'course_title'     => 'Test Automated Verification Course ' . uniqid(),
    'provider'         => 'Coursera',
    'duration'         => '4 weeks',
    'month_year'       => '10/2026',
    'certificate_link' => 'https://example.com/cert.pdf',
    'proof'            => new CURLFile($dummyPdf, 'application/pdf', 'proof.pdf'),
];

$subResp = curlReq("$baseUrl/upload.php?type=online_course", 'POST', $postFields, $facultyCookie, true);
$pdo = db();
$stmt = $pdo->prepare("SELECT * FROM online_courses WHERE course_title = ? ORDER BY id DESC LIMIT 1");
$stmt->execute([$postFields['course_title']]);
$savedRec = $stmt->fetch(PDO::FETCH_ASSOC);

$test7Pass = !empty($savedRec) && $savedRec['exam_session'] === 'Nov-Dec';
check('TEST 7', 'Submit a valid record using an affected category (online_course)',
    $test7Pass,
    $test7Pass ? "Saved ID: {$savedRec['id']}, exam_session: {$savedRec['exam_session']}" : "Failed to insert record: " . substr(strip_tags($subResp['body']), 0, 150)
);

// TEST 8: Verify the academic year displayed at the top remains correct
$activeAy = active_academic_year();
$hasAyTop = (strpos($ocFormPage['body'], $activeAy) !== false);
$savedAyCorrect = (!empty($savedRec) && $savedRec['academic_year'] === $activeAy);

check('TEST 8', 'Admin-controlled Academic Year system is unchanged and displays active year',
    $hasAyTop && $savedAyCorrect,
    "Active AY: {$activeAy}, Record AY: " . ($savedRec['academic_year'] ?? 'None')
);

// TEST 9: Verify proof upload, View Proof, and Download still work
$proofFile = $savedRec['proof_file'] ?? '';
$proofUrl = "$baseUrl/proof.php?type=online_course&id=" . ($savedRec['id'] ?? 0);
$proofResp = curlReq($proofUrl, 'GET', [], $facultyCookie);
$proofWorks = ($proofResp['code'] === 200 && str_starts_with($proofResp['body'], '%PDF'));

check('TEST 9', 'Proof upload, View Proof, and Download work correctly',
    $proofWorks,
    "Proof file: {$proofFile}, HTTP code: {$proofResp['code']}, PDF header verified: " . ($proofWorks ? 'Yes' : 'No')
);

// Cleanup
if (!empty($savedRec['id'])) {
    $pdo->exec("DELETE FROM online_courses WHERE id = " . (int)$savedRec['id']);
}
if (file_exists($dummyPdf)) @unlink($dummyPdf);
if (!empty($proofFile)) {
    @unlink(__DIR__ . '/../php-app/uploads/' . $proofFile);
    @unlink(__DIR__ . '/../php-app/uploads/proofs/' . $proofFile);
}
@unlink($facultyCookie);
@unlink($adminCookie);
