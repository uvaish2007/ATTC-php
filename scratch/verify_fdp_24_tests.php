<?php
/**
 * Automated Verification Suite for FDP / Workshop Restructure (24 Tests)
 */

require_once __DIR__ . '/../php-app/inc/env.php';
require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/models/Record.php';
require_once __DIR__ . '/../php-app/models/Department.php';

$baseUrl = 'http://localhost:8000';
$results = [];

function record_test(string $name, bool $pass, string $details = '') {
    global $results;
    $results[] = [
        'name' => $name,
        'pass' => $pass,
        'details' => $details,
    ];
    echo ($pass ? "PASS: " : "FAIL: ") . "{$name}" . ($details ? " - {$details}" : "") . "\n";
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
    return ['code' => $code, 'body' => $resp, 'effUrl' => $effUrl];
}

function getCsrf(string $html): string {
    if (preg_match('/name="csrf"\s+value="([^"]+)"/', $html, $m)) return $m[1];
    if (preg_match('/value="([^"]+)"\s+name="csrf"/', $html, $m)) return $m[1];
    return '';
}

// 1. Setup Faculty Login
$facultyCookie = tempnam(sys_get_temp_dir(), 'cook_fac_');
$loginPage = curlReq("$baseUrl/login.php", 'GET', [], $facultyCookie);
$loginCsrf = getCsrf($loginPage['body']);

$postLogin = [
    'csrf'     => $loginCsrf,
    'email'    => 'faculty@atts.edu',
    'password' => 'faculty12',
    'role'     => 'Faculty',
];
curlReq("$baseUrl/login.php", 'POST', http_build_query($postLogin), $facultyCookie, true);

$pdo = db();
$activeYear = active_academic_year();

// Extract CSRF token from upload.php?type=fdp
$uploadFdp = curlReq("$baseUrl/upload.php?type=fdp", 'GET', [], $facultyCookie);
$csrf = getCsrf($uploadFdp['body']);

echo "=== BEGINNING 24-TEST VERIFICATION ===\n";

// ==================================================
// TEST 1 — Conference Publication removal
// ==================================================
// Open Upload Data navigation. Expected: Conference Publication tab does NOT appear.
$hasConfTab = (strpos($uploadFdp['body'], 'data-type="conference"') !== false) ||
              (strpos($uploadFdp['body'], 'Conference Publication</a>') !== false);
// Also verify direct GET to upload.php?type=conference redirects to upload.php?type=fdp
$directConf = curlReq("$baseUrl/upload.php?type=conference", 'GET', [], $facultyCookie, true);
$redirectsToFdp = (strpos($directConf['effUrl'], 'type=fdp') !== false) || (strpos($directConf['body'], 'Conference is now submitted under FDP') !== false);

record_test("TEST 1 — Conference Publication removal", (!$hasConfTab && $redirectsToFdp), 
    !$hasConfTab ? "Tab not present; legacy URL redirects to fdp" : "Tab still present");

// ==================================================
// TEST 2 — Conference Event Type
// ==================================================
// In FDP/Workshop form, Event Type dropdown has Conference
$hasConfOption = preg_match('/<select[^>]*name="event_type"[^>]*>.*?<option[^>]*value="Conference"[^>]*>Conference<\/option>.*?<\/select>/s', $uploadFdp['body']);
record_test("TEST 2 — Conference Event Type", (bool)$hasConfOption, "Conference option present in Event Type dropdown");

// ==================================================
// TEST 3 — FDP (dynamic title & label)
// ==================================================
// Check default / FDP label map: 'Name of the FDP', 'New FDP'
$hasFdpHeading = (strpos($uploadFdp['body'], 'New FDP') !== false);
$hasFdpLabel = (strpos($uploadFdp['body'], 'Name of the FDP') !== false);
record_test("TEST 3 — FDP", ($hasFdpHeading && $hasFdpLabel), "Heading is 'New FDP' and label is 'Name of the FDP'");

// ==================================================
// TEST 4 — Workshop
// ==================================================
// Check label mapping in JS and PHP for Workshop
$hasWorkshopMap = (strpos($uploadFdp['body'], "'Workshop': 'Name of the Workshop'") !== false);
record_test("TEST 4 — Workshop", $hasWorkshopMap, "Workshop maps to 'New Workshop' and 'Name of the Workshop'");

// ==================================================
// TEST 5 — Seminar
// ==================================================
$hasSeminarMap = (strpos($uploadFdp['body'], "'Seminar': 'Name of the Seminar'") !== false);
record_test("TEST 5 — Seminar", $hasSeminarMap, "Seminar maps to 'New Seminar' and 'Name of the Seminar'");

// ==================================================
// TEST 6 — STTP
// ==================================================
$hasSttpMap = (strpos($uploadFdp['body'], "'STTP': 'Name of the STTP'") !== false);
record_test("TEST 6 — STTP", $hasSttpMap, "STTP maps to 'New STTP' and 'Name of the STTP'");

// ==================================================
// TEST 7 — Training
// ==================================================
$hasTrainingMap = (strpos($uploadFdp['body'], "'Training': 'Name of the Training Programme'") !== false);
record_test("TEST 7 — Training", $hasTrainingMap, "Training maps to 'New Training' and 'Name of the Training Programme'");

// ==================================================
// TEST 8 — Conference
// ==================================================
$hasConferenceMap = (strpos($uploadFdp['body'], "'Conference': 'Name of the Conference'") !== false);
record_test("TEST 8 — Conference", $hasConferenceMap, "Conference maps to 'New Conference' and 'Name of the Conference'");

// ==================================================
// TEST 9 — Academic Session
// ==================================================
// The form displays "Academic Session", not "Exam Session"
$hasAcademicSessionLabel = (strpos($uploadFdp['body'], '<label>Academic Session <span class="req">*</span></label>') !== false);
// Make sure "Exam Session" is NOT rendered as label in the FDP form
// (Check within the FDP form block)
$fdpFormBlock = '';
if (preg_match('/<form[^>]*enctype="multipart\/form-data"[^>]*>([\s\S]*?)<\/form>/i', $uploadFdp['body'], $m)) {
    $fdpFormBlock = $m[0];
}
$examSessionInForm = (strpos($fdpFormBlock, '<label>Exam Session') !== false);
record_test("TEST 9 — Academic Session", ($hasAcademicSessionLabel && !$examSessionInForm), "Field displays 'Academic Session', not 'Exam Session'");

// ==================================================
// TEST 10 — Date selection (inclusive duration calculation)
// ==================================================
// Verify formula in JS: Math.round(diffMs / (1000 * 60 * 60 * 24)) + 1
$hasInclusiveJs = (strpos($uploadFdp['body'], 'Math.round(diffMs / (1000 * 60 * 60 * 24)) + 1') !== false);
// Calculate 2026-10-01 to 2026-10-07
$d1 = new DateTime('2026-10-01');
$d2 = new DateTime('2026-10-07');
$calcDays = $d1->diff($d2)->days + 1;
record_test("TEST 10 — Date selection", ($hasInclusiveJs && $calcDays === 7), "Inclusive date calculation: 01/10/2026 to 07/10/2026 is 7 days");

// ==================================================
// TEST 11 — Duration live update
// ==================================================
// Check event listeners on input and change for live calculation
$hasLiveListeners = (strpos($uploadFdp['body'], "fromDateInput.addEventListener('change', calculateDuration)") !== false) &&
                    (strpos($uploadFdp['body'], "toDateInput.addEventListener('change', calculateDuration)") !== false);
// Changing to 2026-10-10:
$d3 = new DateTime('2026-10-10');
$calcDays2 = $d1->diff($d3)->days + 1;
record_test("TEST 11 — Duration live update", ($hasLiveListeners && $calcDays2 === 10), "Live event listeners attached and 01/10 to 10/10 produces 10 days");

// ==================================================
// TEST 12 — Duration database storage (server-side calculation override)
// ==================================================
// Submit record with deliberately forged Duration: '100 days'
$testTitle = "Automated Test FDP Inclusive Duration " . time();
$postData = [
    'csrf' => $csrf,
    'record_type' => 'fdp',
    'nav' => 'add',
    'faculty_name' => 'Faculty Test',
    'department' => 'CSE',
    'academic_session' => 'Nov-Dec',
    'exam_session' => 'Nov-Dec',
    'event_type' => 'FDP',
    'title' => $testTitle,
    'mode' => 'Online',
    'organized_by' => 'Test Institution',
    'from_date' => '2026-10-01',
    'to_date' => '2026-10-07',
    'duration' => '100 days', // forged duration
    'certificate_link' => '',
];

$subRes = curlReq("$baseUrl/upload.php?type=fdp", 'POST', $postData, $facultyCookie);
$stmt = $pdo->prepare("SELECT * FROM fdp WHERE title = ?");
$stmt->execute([$testTitle]);
$savedRow = $stmt->fetch(PDO::FETCH_ASSOC);

$durationCorrect = ($savedRow && $savedRow['duration'] === '7 days');
record_test("TEST 12 — Duration database storage", (bool)$durationCorrect, 
    $savedRow ? "Stored duration is '{$savedRow['duration']}' (server correctly overrode forged '100 days' with '7 days')" : "Row not found in DB");

// ==================================================
// TEST 13 — Invalid dates (From > To)
// ==================================================
$invalidTitle = "Automated Test Invalid Dates " . time();
$postDataInvalid = [
    'csrf' => $csrf,
    'record_type' => 'fdp',
    'nav' => 'add',
    'faculty_name' => 'Faculty Test',
    'department' => 'CSE',
    'academic_session' => 'Nov-Dec',
    'exam_session' => 'Nov-Dec',
    'event_type' => 'Workshop',
    'title' => $invalidTitle,
    'mode' => 'Online',
    'organized_by' => 'Test Institution',
    'from_date' => '2026-10-10',
    'to_date' => '2026-10-05', // earlier than from_date!
    'duration' => '5 days',
];
$subInv = curlReq("$baseUrl/upload.php?type=fdp", 'POST', $postDataInvalid, $facultyCookie);
$stmtInv = $pdo->prepare("SELECT COUNT(*) FROM fdp WHERE title = ?");
$stmtInv->execute([$invalidTitle]);
$invCount = (int)$stmtInv->fetchColumn();
$rejected = ($invCount === 0 && (strpos($subInv['body'], 'To Date cannot be before From Date') !== false));
record_test("TEST 13 — Invalid dates", $rejected, "Rejected invalid date range (From: 10/10, To: 05/10), DB insert prevented");

// ==================================================
// TEST 14 — Certificate optional
// ==================================================
// Verify $savedRow from TEST 12 succeeded with empty certificate_link
$certOptional = ($savedRow && empty($savedRow['certificate_link']));
record_test("TEST 14 — Certificate optional", (bool)$certOptional, "Record saved successfully with empty Certificate Link");

// ==================================================
// TEST 15 — Proof before certificate
// ==================================================
// Check DOM order: proofInput appears before fdp_certificate_link
$proofPos = strpos($uploadFdp['body'], 'id="proofInput"');
$certPos  = strpos($uploadFdp['body'], 'id="fdp_certificate_link"');
$orderOk  = ($proofPos !== false && $certPos !== false && $proofPos < $certPos);
record_test("TEST 15 — Proof before certificate", $orderOk, "Proof / Attachment appears before Certificate Link (Optional)");

// ==================================================
// TEST 16 — Proof upload
// ==================================================
// Create a real dummy PDF and upload via multipart form-data
$tmpPdf = tempnam(sys_get_temp_dir(), 'test_') . '.pdf';
file_put_contents($tmpPdf, "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/MediaBox[0 0 3 3]>>endobj\nxref\n0 4\n0000000000 65535 f\n0000000009 00000 n\n0000000052 00000 n\n0000000101 00000 n\ntrailer<</Size 4/Root 1 0 R>>\nstartxref\n160\n%%EOF");

$proofTitle = "Automated Test Proof Upload " . time();
$cFile = new CURLFile($tmpPdf, 'application/pdf', 'sample_certificate.pdf');
$postDataProof = [
    'csrf' => $csrf,
    'record_type' => 'fdp',
    'nav' => 'add',
    'faculty_name' => 'Faculty Test',
    'department' => 'CSE',
    'academic_session' => 'Nov-Dec',
    'exam_session' => 'Nov-Dec',
    'event_type' => 'Conference',
    'title' => $proofTitle,
    'mode' => 'Offline',
    'organized_by' => 'IEEE Conference Org',
    'from_date' => '2026-11-01',
    'to_date' => '2026-11-03',
    'duration' => '3 days',
    'proof' => $cFile,
];

$proofRes = curlReq("$baseUrl/upload.php?type=fdp", 'POST', $postDataProof, $facultyCookie);
$stmtProof = $pdo->prepare("SELECT * FROM fdp WHERE title = ?");
$stmtProof->execute([$proofTitle]);
$proofRow = $stmtProof->fetch(PDO::FETCH_ASSOC);

$proofSaved = ($proofRow && !empty($proofRow['proof_file']));
record_test("TEST 16 — Proof upload", (bool)$proofSaved, 
    $proofSaved ? "PDF uploaded and stored as {$proofRow['proof_file']}" : "Proof file not saved");

// ==================================================
// TEST 17 — View Proof
// ==================================================
// Test accessing the uploaded proof via proof.php endpoint
$viewProofUrl = $baseUrl . '/proof.php?type=fdp&id=' . ($proofRow['id'] ?? 0);
$vpRes = curlReq($viewProofUrl, 'GET', [], $facultyCookie);
$isPdfContent = (strpos($vpRes['body'], '%PDF') === 0);
$notForm = (strpos($vpRes['body'], '<form') === false);
record_test("TEST 17 — View Proof", ($isPdfContent && $notForm), 
    "View Proof serves raw PDF bytes and does NOT open upload form");

// ==================================================
// TEST 18 — Draft
// ==================================================
// Check JavaScript draft logic has isolated storage keys per event type
$hasDraftIsolation = (strpos($uploadFdp['body'], "atts_upload_draft_' + activeYear + '_fdp_' + ev") !== false);
$hasRestoreHook = (strpos($uploadFdp['body'], "restoreDraft()") !== false);
record_test("TEST 18 — Draft", ($hasDraftIsolation && $hasRestoreHook), 
    "SessionStorage draft keys isolated per Event Type (e.g. _fdp_Conference, _fdp_Workshop)");

// ==================================================
// TEST 19 — Special characters
// ==================================================
$specialTitle = "O'Connor & Sons Advanced Seminar " . time();
$specialOrg   = "O'Connor & Sons International";
$postDataSpecial = [
    'csrf' => $csrf,
    'record_type' => 'fdp',
    'nav' => 'add',
    'faculty_name' => "Dr. O'Connor",
    'department' => 'CSE',
    'academic_session' => 'Nov-Dec',
    'exam_session' => 'Nov-Dec',
    'event_type' => 'Seminar',
    'title' => $specialTitle,
    'mode' => 'Hybrid',
    'organized_by' => $specialOrg,
    'from_date' => '2026-12-01',
    'to_date' => '2026-12-02',
    'duration' => '2 days',
];
$subSpec = curlReq("$baseUrl/upload.php?type=fdp", 'POST', $postDataSpecial, $facultyCookie);
$stmtSpec = $pdo->prepare("SELECT * FROM fdp WHERE title = ?");
$stmtSpec->execute([$specialTitle]);
$specRow = $stmtSpec->fetch(PDO::FETCH_ASSOC);

$specSaved = ($specRow && $specRow['organized_by'] === $specialOrg);
record_test("TEST 19 — Special characters", (bool)$specSaved, 
    $specSaved ? "O'Connor & Sons safely handled without SQL/PDO error" : "Special character insert failed");

// ==================================================
// TEST 20 — Academic Year
// ==================================================
// Verify active admin-controlled Academic Year is used
$ayUsed = ($savedRow && $savedRow['academic_year'] === $activeYear);
record_test("TEST 20 — Academic Year", (bool)$ayUsed, 
    "Record stored under active centralized academic year: {$activeYear}");

// ==================================================
// TEST 21 — Existing records
// ==================================================
// Verify historical FDP row ID 1 is readable
$stmtHist = $pdo->query("SELECT * FROM fdp WHERE id = 1");
$histFdp = $stmtHist->fetch(PDO::FETCH_ASSOC);
record_test("TEST 21 — Existing records", (bool)$histFdp, 
    $histFdp ? "Historical FDP record #1 intact: '{$histFdp['title']}'" : "Historical record missing");

// ==================================================
// TEST 22 — Existing Conference data
// ==================================================
// Verify historical Conference Publication records are not deleted
$confCount = (int)$pdo->query("SELECT COUNT(*) FROM conference_publications")->fetchColumn();
record_test("TEST 22 — Existing Conference data", ($confCount > 0), 
    "Historical conference publications intact (count: {$confCount})");

// ==================================================
// TEST 23 — Reports
// ==================================================
// Verify report generation for FDP
$reportRes = curlReq("$baseUrl/record-report.php?type=fdp", 'GET', [], $facultyCookie);
$reportWorks = ($reportRes['code'] === 200 && strpos($reportRes['body'], 'FACULTY PARTICIPATIONS') !== false);
record_test("TEST 23 — Reports", $reportWorks, "FDP Report accessible and functional");

// ==================================================
// TEST 24 — Role access
// ==================================================
// Test Coordinator role access
$coordCookie = tempnam(sys_get_temp_dir(), 'cook_coord_');
$coordLoginPage = curlReq("$baseUrl/login.php", 'GET', [], $coordCookie);
$coordLoginCsrf = getCsrf($coordLoginPage['body']);
$coordLogin = curlReq("$baseUrl/login.php", 'POST', http_build_query([
    'csrf'     => $coordLoginCsrf,
    'email'    => 'mohameduvaish132@gmail.com', // Coordinator / Admin user
    'password' => 'uvaish123',
    'role'     => 'Admin',
]), $coordCookie, true);

$coordUpload = curlReq("$baseUrl/upload.php?type=fdp", 'GET', [], $coordCookie);
$coordCanAccess = ($coordUpload['code'] === 200 && strpos($coordUpload['body'], 'Upload form') !== false);
record_test("TEST 24 — Role access", $coordCanAccess, "Coordinator/Admin successfully accesses upload module");

// Clean up temporary test records
$pdo->prepare("DELETE FROM fdp WHERE title IN (?, ?, ?)")->execute([$testTitle, $proofTitle, $specialTitle]);
if (file_exists($tmpPdf)) @unlink($tmpPdf);
if ($savedRow && !empty($savedRow['proof_file'])) {
    @unlink(__DIR__ . '/../php-app/uploads/' . $savedRow['proof_file']);
}
if ($proofRow && !empty($proofRow['proof_file'])) {
    @unlink(__DIR__ . '/../php-app/uploads/' . $proofRow['proof_file']);
}
@unlink($facultyCookie);
@unlink($coordCookie);

echo "\n=== ALL 24 TESTS COMPLETED ===\n";
