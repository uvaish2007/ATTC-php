<?php
/**
 * scratch/verify_coord14.php
 * Comprehensive automated verification script for Bug COORD-14:
 * "Book / Chapter records are incorrectly being routed into an approval queue,
 *  even though approval is NOT required for this record type."
 */

error_reporting(E_ALL & ~E_DEPRECATED);

require_once 'C:/Users/Lenovo/Downloads/iqac/ATTC-php-main/php-app/inc/db.php';
require_once 'C:/Users/Lenovo/Downloads/iqac/ATTC-php-main/php-app/inc/helpers.php';
require_once 'C:/Users/Lenovo/Downloads/iqac/ATTC-php-main/php-app/models/Record.php';
require_once 'C:/Users/Lenovo/Downloads/iqac/ATTC-php-main/php-app/models/Target.php';
require_once 'C:/Users/Lenovo/Downloads/iqac/ATTC-php-main/php-app/inc/nav.php';
require_once 'C:/Users/Lenovo/Downloads/iqac/ATTC-php-main/php-app/inc/notifications.php';

$baseUrl = 'http://localhost:8000';
$pdo = db();
$activeYear = active_academic_year();

echo "===============================================================\n";
echo " COORD-14 AUTOMATED VERIFICATION: Book / Chapter Approval Bypass\n";
echo " Base URL: $baseUrl | Active Academic Year: $activeYear\n";
echo "===============================================================\n\n";

$testResults = [];
function recordResult($testId, $name, $pass, $details = '') {
    global $testResults;
    $status = $pass ? "PASS" : "FAIL";
    $testResults[] = ['id' => $testId, 'name' => $name, 'pass' => $pass, 'details' => $details];
    echo "[$status] $testId: $name\n";
    if ($details) {
        echo "       Details: $details\n";
    }
}

// cURL helper
function curlReq($url, $method = 'GET', $fields = [], $cookieFile = null, $isMultipart = false) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    if ($cookieFile) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($isMultipart) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($fields) ? http_build_query($fields) : $fields);
        }
    }
    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    return ['code' => $httpCode, 'headers' => $headers, 'body' => $body];
}

function getCsrf($html) {
    if (preg_match('/name="csrf"\s+value="([^"]+)"/', $html, $m)) {
        return $m[1];
    }
    return '';
}

// Logins
$cookieFaculty = __DIR__ . '/cookie_faculty_c14.txt';
$cookieCoord   = __DIR__ . '/cookie_coord_c14.txt';
$cookieHod     = __DIR__ . '/cookie_hod_c14.txt';
$cookieDean    = __DIR__ . '/cookie_dean_c14.txt';
$cookieAdmin   = __DIR__ . '/cookie_admin_c14.txt';

@unlink($cookieFaculty); @unlink($cookieCoord); @unlink($cookieHod); @unlink($cookieDean); @unlink($cookieAdmin);

function loginUser($email, $password, $role, $cookieFile) {
    global $baseUrl;
    $res = curlReq("$baseUrl/login.php", 'GET', [], $cookieFile);
    $csrf = getCsrf($res['body']);
    $res = curlReq("$baseUrl/login.php", 'POST', [
        'csrf' => $csrf,
        'email' => $email,
        'password' => $password,
        'role' => $role
    ], $cookieFile);
    return $res['code'] === 302;
}

echo "Authenticating test users...\n";
$fOk = loginUser('faculty@atts.edu', 'faculty123', 'Faculty', $cookieFaculty);
$cOk = loginUser('coordinator@atts.edu', 'coordinator123', 'Coordinator', $cookieCoord);
$hOk = loginUser('hod@atts.edu', 'hod123', 'HoD', $cookieHod);
$dOk = loginUser('dean@atts.edu', 'dean123', 'Dean', $cookieDean);
$aOk = loginUser('mohameduvaish132@gmail.com', 'admin123', 'Admin', $cookieAdmin);

if (!$fOk || !$cOk || !$hOk || !$dOk || !$aOk) {
    die("ERROR: Failed to authenticate one or more test users.\n");
}
echo "All users authenticated successfully.\n\n";

// Sample PDF for proof
$testPdfPath = __DIR__ . '/test_proof_c14.pdf';
$pdfContent = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/MediaBox[0 0 612 792]/Parent 2 0 R/Resources<<>>>>endobj\nxref\n0 4\n0000000000 65535 f \n0000000009 00000 n \n0000000052 00000 n \n0000000101 00000 n \ntrailer<</Size 4/Root 1 0 R>>\nstartxref\n178\n%%EOF\n";
file_put_contents($testPdfPath, $pdfContent);

$cleanupBookIds = [];
$cleanupJournalIds = [];

// ---------------------------------------------------------------------
// TEST 1 — Open Book / Chapter
// ---------------------------------------------------------------------
$cntBefore = (int) $pdo->query("SELECT COUNT(*) FROM book_publications")->fetchColumn();
$res = curlReq("$baseUrl/upload.php?type=book", 'GET', [], $cookieFaculty);
$cntAfter = (int) $pdo->query("SELECT COUNT(*) FROM book_publications")->fetchColumn();
$t1Pass = ($res['code'] === 200 && $cntBefore === $cntAfter);
recordResult("TEST 1", "Open Book / Chapter form creates no Submitted record (COORD-06)", $t1Pass, "HTTP {$res['code']}, DB count before: $cntBefore, after: $cntAfter");

// ---------------------------------------------------------------------
// TEST 2 — Draft
// ---------------------------------------------------------------------
$cntDraft = (int) $pdo->query("SELECT COUNT(*) FROM book_publications WHERE status = 'Draft'")->fetchColumn();
$hasDraftBadge = strpos($res['body'], 'js-form-status-badge') !== false || strpos($res['body'], 'Draft') !== false;
$t2Pass = ($cntDraft === 0 && $hasDraftBadge);
recordResult("TEST 2", "Draft behavior preserved without premature persistent DB records", $t2Pass, "Draft rows in DB: $cntDraft");

// ---------------------------------------------------------------------
// TEST 3 — Valid Book / Chapter submission
// ---------------------------------------------------------------------
$csrf = getCsrf($res['body']);
$uniqueTitle = "COORD14 Quantum Cryptography Handbook " . uniqid();
$postFields = [
    'csrf'                 => $csrf,
    'record_type'          => 'book',
    'nav'                  => 'submit',
    'faculty_name'         => 'Faculty User',
    'department'           => 'CSBS',
    'publication_category' => 'Book',
    'title'                => $uniqueTitle,
    'publisher_name'       => 'Cambridge University Press',
    'isbn'                 => '978-3-16-148410-0',
    'publication_month'    => '06/2026',
    'document_link'        => 'https://example.com/books/quantum',
    'proof'                => new CURLFile($testPdfPath, 'application/pdf', 'quantum_proof.pdf')
];

$res = curlReq("$baseUrl/upload.php?type=book", 'POST', $postFields, $cookieFaculty, true);
$t3Redirect = ($res['code'] === 302 && strpos($res['headers'], 'upload.php?type=book') !== false);

// Check DB for new row
$stmt = $pdo->prepare("SELECT * FROM book_publications WHERE title = ?");
$stmt->execute([$uniqueTitle]);
$newBook = $stmt->fetch(PDO::FETCH_ASSOC);

$newBookId = $newBook ? (int)$newBook['id'] : 0;
if ($newBookId > 0) {
    $cleanupBookIds[] = $newBookId;
}

$t3DbPass = ($newBook && $newBook['status'] === 'Submitted');
$t3Pass = ($t3Redirect && $t3DbPass);
recordResult("TEST 3", "Valid Book / Chapter submission succeeds with status = Submitted", $t3Pass, "Book ID: $newBookId, Status: " . ($newBook['status'] ?? 'NONE'));

// ---------------------------------------------------------------------
// TEST 4 — Coordinator queue
// ---------------------------------------------------------------------
// Open Coordinator Approvals page and inspect pending_records for CSBS Coordinator
$coordRecords = pending_records('CSBS', null, 'Coordinator', $activeYear);
$inCoordQueue = false;
foreach ($coordRecords as $cr) {
    if (($cr['_type_key'] ?? '') === 'book' || (int)($cr['id'] ?? 0) === $newBookId) {
        $inCoordQueue = true;
        break;
    }
}
$resCoordPage = curlReq("$baseUrl/approvals.php", 'GET', [], $cookieCoord);
$coordHtmlContainsBook = strpos($resCoordPage['body'], $uniqueTitle) !== false;
$coordUser = ['id' => 4, 'role' => 'Coordinator', 'department' => 'CSBS'];
$coordBadgeCount = pending_approvals_count($coordUser);

$t4Pass = (!$inCoordQueue && !$coordHtmlContainsBook);
recordResult("TEST 4", "Book / Chapter NOT in Coordinator Pending Approvals queue", $t4Pass, "In queue array: " . ($inCoordQueue ? 'YES' : 'NO') . ", in page HTML: " . ($coordHtmlContainsBook ? 'YES' : 'NO') . ", badge count: $coordBadgeCount");

// ---------------------------------------------------------------------
// TEST 5 — HOD queue
// ---------------------------------------------------------------------
$hodRecords = pending_records('CSBS', null, 'HoD', $activeYear);
$inHodQueue = false;
foreach ($hodRecords as $hr) {
    if (($hr['_type_key'] ?? '') === 'book' || (int)($hr['id'] ?? 0) === $newBookId) {
        $inHodQueue = true;
        break;
    }
}
$resHodPage = curlReq("$baseUrl/approvals.php", 'GET', [], $cookieHod);
$hodHtmlContainsBook = strpos($resHodPage['body'], $uniqueTitle) !== false;
$t5Pass = (!$inHodQueue && !$hodHtmlContainsBook);
recordResult("TEST 5", "Book / Chapter NOT routed into HOD Pending Approvals queue", $t5Pass, "In queue array: " . ($inHodQueue ? 'YES' : 'NO') . ", in page HTML: " . ($hodHtmlContainsBook ? 'YES' : 'NO'));

// ---------------------------------------------------------------------
// TEST 6 — Dean queue
// ---------------------------------------------------------------------
$deanRecords = pending_records(null, null, 'Dean', $activeYear);
$inDeanQueue = false;
foreach ($deanRecords as $dr) {
    if (($dr['_type_key'] ?? '') === 'book' || (int)($dr['id'] ?? 0) === $newBookId) {
        $inDeanQueue = true;
        break;
    }
}
$resDeanPage = curlReq("$baseUrl/approvals.php", 'GET', [], $cookieDean);
$deanHtmlContainsBook = strpos($resDeanPage['body'], $uniqueTitle) !== false;

// Also verify Admin queue
$adminRecords = pending_records(null, null, 'Admin', $activeYear);
$inAdminQueue = false;
foreach ($adminRecords as $ar) {
    if (($ar['_type_key'] ?? '') === 'book' || (int)($ar['id'] ?? 0) === $newBookId) {
        $inAdminQueue = true;
        break;
    }
}

$t6Pass = (!$inDeanQueue && !$deanHtmlContainsBook && !$inAdminQueue);
recordResult("TEST 6", "Book / Chapter NOT routed into Dean or Admin approval queue", $t6Pass, "In Dean queue: " . ($inDeanQueue ? 'YES' : 'NO') . ", In Admin queue: " . ($inAdminQueue ? 'YES' : 'NO'));

// ---------------------------------------------------------------------
// TEST 7 — Status verification
// ---------------------------------------------------------------------
$stmt = $pdo->prepare("SELECT status FROM book_publications WHERE id = ?");
$stmt->execute([$newBookId]);
$dbStatus = $stmt->fetchColumn();
$forbiddenStatuses = ['Draft', 'Pending Review', 'HOD Pending', 'Dean Pending', 'Approved'];
$t7Pass = ($dbStatus === 'Submitted' && !in_array($dbStatus, $forbiddenStatuses, true));
recordResult("TEST 7", "Database record status is exactly 'Submitted'", $t7Pass, "Status: '$dbStatus'");

// ---------------------------------------------------------------------
// TEST 8 — Other record types regression (Journal Publication)
// ---------------------------------------------------------------------
$resJ = curlReq("$baseUrl/upload.php?type=journal", 'GET', [], $cookieFaculty);
$csrfJ = getCsrf($resJ['body']);
$journalTitle = "COORD14 Regression Journal Test " . uniqid();
$postJournal = [
    'csrf'              => $csrfJ,
    'record_type'       => 'journal',
    'nav'               => 'submit',
    'faculty_name'      => 'Faculty User',
    'department'        => 'CSBS',
    'author_type'       => 'Author-1',
    'co_authors'        => 'None',
    'paper_title'       => $journalTitle,
    'journal_name'      => 'IEEE Transactions on Software Engineering',
    'journal_type'      => 'International',
    'issn'              => '0098-5589',
    'volume_issue'      => 'Vol 50 Issue 2',
    'publication_month' => '04/2026',
    'doi'               => 'https://doi.org/10.1109/TSE.2026.123456',
    'journal_link'      => 'https://ieeexplore.ieee.org',
    'document_link'     => 'https://example.com/paper.pdf'
];
$resJPost = curlReq("$baseUrl/upload.php?type=journal", 'POST', $postJournal, $cookieFaculty);
$stmtJ = $pdo->prepare("SELECT * FROM journal_publications WHERE paper_title = ?");
$stmtJ->execute([$journalTitle]);
$newJournal = $stmtJ->fetch(PDO::FETCH_ASSOC);
$journalId = $newJournal ? (int)$newJournal['id'] : 0;
if ($journalId > 0) {
    $cleanupJournalIds[] = $journalId;
}

// Coordinator should see this journal in pending approvals!
$coordRecordsAfter = pending_records('CSBS', null, 'Coordinator', $activeYear);
$journalInCoordQueue = false;
foreach ($coordRecordsAfter as $cr) {
    if (($cr['_type_key'] ?? '') === 'journal' && (int)($cr['id'] ?? 0) === $journalId) {
        $journalInCoordQueue = true;
        break;
    }
}
$t8Pass = ($newJournal && $newJournal['status'] === 'Submitted' && $journalInCoordQueue);
recordResult("TEST 8", "Approval-required record types (Journal) preserve existing approval workflow", $t8Pass, "Journal ID: $journalId, Status: " . ($newJournal['status'] ?? 'NONE') . ", Found in Coordinator queue: " . ($journalInCoordQueue ? 'YES' : 'NO'));

// ---------------------------------------------------------------------
// TEST 9 — Save and add other (Validation)
// ---------------------------------------------------------------------
// 9A: Incomplete required fields must be blocked
$resIncomp = curlReq("$baseUrl/upload.php?type=book", 'GET', [], $cookieFaculty);
$csrfIncomp = getCsrf($resIncomp['body']);
$postIncomplete = [
    'csrf'                 => $csrfIncomp,
    'record_type'          => 'book',
    'nav'                  => 'add',
    'faculty_name'         => 'Faculty User',
    'department'           => 'CSBS',
    'publication_category' => 'Book',
    'title'                => '', // MISSING TITLE
    'publisher_name'       => '', // MISSING PUBLISHER
    'isbn'                 => '978-0-00-000000-0',
    'publication_month'    => '01/2026',
    'document_link'        => 'https://example.com'
];
$cntBeforeInc = (int) $pdo->query("SELECT COUNT(*) FROM book_publications")->fetchColumn();
$resIncPost = curlReq("$baseUrl/upload.php?type=book", 'POST', $postIncomplete, $cookieFaculty);
$cntAfterInc = (int) $pdo->query("SELECT COUNT(*) FROM book_publications")->fetchColumn();
$blockedPass = ($cntBeforeInc === $cntAfterInc);

// 9B: Complete fields with nav=add succeeds
$completeAddTitle = "COORD14 Save & Add Another Test " . uniqid();
$postComplete = [
    'csrf'                 => $csrfIncomp,
    'record_type'          => 'book',
    'nav'                  => 'add',
    'faculty_name'         => 'Faculty User',
    'department'           => 'CSBS',
    'publication_category' => 'Book Chapter',
    'title'                => $completeAddTitle,
    'publisher_name'       => 'Springer Nature',
    'isbn'                 => '978-3-030-12345-6',
    'publication_month'    => '02/2026',
    'document_link'        => 'https://example.com/chapter'
];
$resCompPost = curlReq("$baseUrl/upload.php?type=book", 'POST', $postComplete, $cookieFaculty);
$stmtComp = $pdo->prepare("SELECT * FROM book_publications WHERE title = ?");
$stmtComp->execute([$completeAddTitle]);
$newCompBook = $stmtComp->fetch(PDO::FETCH_ASSOC);
$compBookId = $newCompBook ? (int)$newCompBook['id'] : 0;
if ($compBookId > 0) {
    $cleanupBookIds[] = $compBookId;
}
$compPass = ($newCompBook && $newCompBook['status'] === 'Submitted');
$t9Pass = ($blockedPass && $compPass);
recordResult("TEST 9", "'Save and add other' validation: incomplete blocked, complete saved as Submitted", $t9Pass, "Incomplete blocked: " . ($blockedPass ? 'YES' : 'NO') . ", Complete saved: " . ($compPass ? 'YES' : 'NO'));

// ---------------------------------------------------------------------
// TEST 10 — Special characters (O'Connor & Sons)
// ---------------------------------------------------------------------
$resSpec = curlReq("$baseUrl/upload.php?type=book", 'GET', [], $cookieFaculty);
$csrfSpec = getCsrf($resSpec['body']);
$specTitle = "O'Connor & Sons: Advanced Algorithms & Data " . uniqid();
$postSpec = [
    'csrf'                 => $csrfSpec,
    'record_type'          => 'book',
    'nav'                  => 'submit',
    'faculty_name'         => "Dr. Sean O'Connor",
    'department'           => 'CSBS',
    'publication_category' => 'Book',
    'title'                => $specTitle,
    'publisher_name'       => "O'Connor & Sons Publishing Ltd.",
    'isbn'                 => "ISBN-978-0-O'Connor",
    'publication_month'    => '03/2026',
    'document_link'        => 'https://example.com/oconnor'
];
$resSpecPost = curlReq("$baseUrl/upload.php?type=book", 'POST', $postSpec, $cookieFaculty);
$stmtSpec = $pdo->prepare("SELECT * FROM book_publications WHERE title = ?");
$stmtSpec->execute([$specTitle]);
$specBook = $stmtSpec->fetch(PDO::FETCH_ASSOC);
$specBookId = $specBook ? (int)$specBook['id'] : 0;
if ($specBookId > 0) {
    $cleanupBookIds[] = $specBookId;
}
$t10Pass = ($specBook && $specBook['publisher_name'] === "O'Connor & Sons Publishing Ltd." && $specBook['status'] === 'Submitted');
recordResult("TEST 10", "Special characters (O'Connor & Sons) handled safely via prepared statements", $t10Pass, "Saved publisher: " . ($specBook['publisher_name'] ?? 'NONE') . ", Status: " . ($specBook['status'] ?? 'NONE'));

// ---------------------------------------------------------------------
// TEST 11 — Proof attachment
// ---------------------------------------------------------------------
$proofFileName = $newBook['proof_file'] ?? null;
$proofExists = false;
if ($proofFileName) {
    $proofFullPath = 'C:/Users/Lenovo/Downloads/iqac/ATTC-php-main/php-app/uploads/proofs/' . $proofFileName;
    $proofExists = file_exists($proofFullPath);
}
// Test view in upload list
$resViewList = curlReq("$baseUrl/upload.php?type=book", 'GET', [], $cookieFaculty);
$hasViewProof = (strpos($resViewList['body'], 'View Proof') !== false || strpos($resViewList['body'], 'view-proof.php') !== false || strpos($resViewList['body'], $proofFileName) !== false);

$t11Pass = ($proofFileName && $proofExists && $hasViewProof);
recordResult("TEST 11", "Proof attachment preserved: file saved, View Proof accessible, no approval routing", $t11Pass, "Proof file: $proofFileName, on disk: " . ($proofExists ? 'YES' : 'NO') . ", in UI: " . ($hasViewProof ? 'YES' : 'NO'));

// ---------------------------------------------------------------------
// TEST 12 — Academic year
// ---------------------------------------------------------------------
$t12Pass = ($newBook && $newBook['academic_year'] === $activeYear);
recordResult("TEST 12", "Book / Chapter record stamped with active academic year ($activeYear)", $t12Pass, "Record year: " . ($newBook['academic_year'] ?? 'NONE') . ", Active year: $activeYear");

// ---------------------------------------------------------------------
// Notification Verification
// ---------------------------------------------------------------------
$coordNotifs = fetch_header_notifications($coordUser);
$coordApprovalsNotif = null;
foreach ($coordNotifs as $cn) {
    if (($cn['id'] ?? '') === 'approval_pending') {
        $coordApprovalsNotif = $cn;
        break;
    }
}
$notifDesc = $coordApprovalsNotif ? $coordApprovalsNotif['description'] : 'None';
echo "\nNotification check:\n";
echo "Coordinator pending approval notification: $notifDesc\n";

// ---------------------------------------------------------------------
// CLEANUP TEST DATA
// ---------------------------------------------------------------------
echo "\nCleaning up test records...\n";
if (!empty($cleanupBookIds)) {
    $in = implode(',', array_map('intval', $cleanupBookIds));
    $proofs = $pdo->query("SELECT proof_file FROM book_publications WHERE id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($proofs as $pf) {
        if ($pf) {
            @unlink('C:/Users/Lenovo/Downloads/iqac/ATTC-php-main/php-app/uploads/proofs/' . $pf);
            @unlink('C:/Users/Lenovo/Downloads/iqac/ATTC-php-main/php-app/uploads/' . $pf);
        }
    }
    $delB = $pdo->exec("DELETE FROM book_publications WHERE id IN ($in)");
    echo "Cleaned up $delB temporary test book publication(s).\n";
}
if (!empty($cleanupJournalIds)) {
    $in = implode(',', array_map('intval', $cleanupJournalIds));
    $delJ = $pdo->exec("DELETE FROM journal_publications WHERE id IN ($in)");
    echo "Cleaned up $delJ temporary test journal publication(s).\n";
}
@unlink($testPdfPath);
@unlink($cookieFaculty); @unlink($cookieCoord); @unlink($cookieHod); @unlink($cookieDean); @unlink($cookieAdmin);

// Summary
echo "\n===============================================================\n";
echo " VERIFICATION SUMMARY\n";
echo "===============================================================\n";
$allPassed = true;
foreach ($testResults as $r) {
    if (!$r['pass']) {
        $allPassed = false;
    }
    echo ($r['pass'] ? "[PASS]" : "[FAIL]") . " {$r['id']} - {$r['name']}\n";
}
echo "===============================================================\n";
if ($allPassed) {
    echo "COORD-14 — PASS (All 12 tests passed successfully)\n";
} else {
    echo "COORD-14 — FAIL (One or more tests failed)\n";
}
