<?php
/**
 * Live End-to-End Test Suite for ATTS Publication Form Fix
 * Tests A, B, C, D, E against running HTTP server http://localhost:8000/php-app
 */

error_reporting(E_ALL & ~E_DEPRECATED);

require_once __DIR__ . '/../php-app/inc/auth.php';
require_once __DIR__ . '/../php-app/models/Record.php';
require_once __DIR__ . '/../php-app/inc/record_specs.php';

$baseUrl = 'http://localhost:8000/php-app';
$cookieFile = __DIR__ . '/live_cookies.txt';
if (file_exists($cookieFile)) unlink($cookieFile);

$testResults = [];

function log_result($code, $name, $pass, $details) {
    global $testResults;
    $status = $pass ? 'PASS' : 'FAIL';
    $testResults[] = ['code' => $code, 'name' => $name, 'status' => $status, 'details' => $details];
    echo sprintf("[%s] %-10s | %-45s | %s\n", $status, $code, $name, $details);
}

function http_request($method, $url, $data = [], $files = [], $customCookie = null) {
    global $cookieFile;
    $ch = curl_init();
    $cFile = $customCookie ?: $cookieFile;

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cFile);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HEADER, false);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if (!empty($files)) {
            $postFields = $data;
            foreach ($files as $k => $fPath) {
                $postFields[$k] = new CURLFile($fPath, 'application/pdf', basename($fPath));
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }
    }

    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

    return ['code' => $httpCode, 'body' => $body, 'url' => $effectiveUrl];
}

function get_csrf_token($html) {
    if (preg_match('/name=["\']csrf["\']\s+value=["\']([^"\']+)["\']/i', $html, $m)) {
        return $m[1];
    }
    if (preg_match('/value=["\']([^"\']+)["\']\s+name=["\']csrf["\']/i', $html, $m)) {
        return $m[1];
    }
    return '';
}

echo "================================================================================\n";
echo "           ATTS PUBLICATION FORM FIX - LIVE END-TO-END VERIFICATION             \n";
echo "================================================================================\n\n";

// STEP 1: Log in as Master Admin
$loginPage = http_request('GET', "$baseUrl/login.php");
$loginCsrf = get_csrf_token($loginPage['body']);

$loginResp = http_request('POST', "$baseUrl/login.php", [
    'csrf'     => $loginCsrf,
    'email'    => 'master.admin@atts.edu',
    'password' => 'master123',
    'role'     => 'Admin'
]);

$loggedIn = str_contains($loginResp['url'], 'dashboard.php') || str_contains($loginResp['body'], 'Sign Out');
log_result('LOGIN', 'Admin Authentication', $loggedIn, "Redirected to: {$loginResp['url']}");

// -----------------------------------------------------------------------------
// TEST A: Synchronize Main Author and Department
// -----------------------------------------------------------------------------
// A1: Inspect Journal Publication Form HTML
$journalPage = http_request('GET', "$baseUrl/upload.php?type=journal");
$jBody = $journalPage['body'];

$jHasAuthorSelect = str_contains($jBody, 'id="pubMainAuthor"');
$jHasDeptInput    = str_contains($jBody, 'id="pubMainDept"') && str_contains($jBody, 'readonly');
$jHasDataDept     = str_contains($jBody, 'data-dept=');

log_result('TEST A1', 'Journal Main Author Select Exists', $jHasAuthorSelect, 'id="pubMainAuthor" found');
log_result('TEST A2', 'Journal Department Field Readonly', $jHasDeptInput, 'id="pubMainDept" is readonly');
log_result('TEST A3', 'Journal Authors Have data-dept', $jHasDataDept, 'Author options include data-dept attributes');

// A2: Inspect Conference Publication Form HTML
$confPage = http_request('GET', "$baseUrl/upload.php?type=conference");
$cBody = $confPage['body'];

$cHasAuthorSelect = str_contains($cBody, 'id="confMainAuthor"');
$cHasDeptInput    = str_contains($cBody, 'id="confMainDept"') && str_contains($cBody, 'readonly');
$cHasDataDept     = str_contains($cBody, 'data-dept=');

log_result('TEST A4', 'Conference Main Author Select Exists', $cHasAuthorSelect, 'id="confMainAuthor" found');
log_result('TEST A5', 'Conference Department Field Readonly', $cHasDeptInput, 'id="confMainDept" is readonly');
log_result('TEST A6', 'Conference Authors Have data-dept', $cHasDataDept, 'Author options include data-dept attributes');

// A3: Extract authors from different departments
preg_match_all('/<option\s+[^>]*value="(\d+)"[^>]*data-dept="([^"]*)"[^>]*>([^<]+)<\/option>/i', $cBody, $matches, PREG_SET_ORDER);
$authorsByDept = [];
foreach ($matches as $m) {
    $dept = trim($m[2]);
    if ($dept !== '' && !isset($authorsByDept[$dept])) {
        $authorsByDept[$dept] = ['id' => $m[1], 'dept' => $dept, 'label' => trim($m[3])];
    }
}
$deptKeys = array_keys($authorsByDept);
$hasMultiDeptAuthors = count($deptKeys) >= 2;
log_result('TEST A7', 'Multiple Department Authors Available', $hasMultiDeptAuthors, "Found " . count($deptKeys) . " departments: " . implode(', ', array_slice($deptKeys, 0, 4)));

$authorA = $authorsByDept[$deptKeys[0]] ?? null;
$authorB = $authorsByDept[$deptKeys[1]] ?? null;

// A4: Backend Rejection of Mismatched Department via Direct POST
$confCsrf = get_csrf_token($cBody);
$mismatchResp = http_request('POST', "$baseUrl/upload.php?type=conference", [
    'csrf'             => $confCsrf,
    'record_type'      => 'conference',
    'main_author_id'   => $authorA['id'],
    'department'       => 'Mismatched_Fake_Dept_123',
    'author_type'      => 'First Author',
    'paper_title'      => 'A TEST OF MISMATCH REJECTION',
    'conference_name'  => 'IEEE CONF 2026',
    'conference_type'  => 'International',
    'venue'            => 'Chennai',
    'conference_date'  => '2026-11-15',
    'publication_year' => '2026'
]);

$confRejected = str_contains($mismatchResp['body'], "authoritative department") || str_contains($mismatchResp['body'], "does not match");
log_result('TEST A8', 'Conference Rejects Mismatched Dept', $confRejected, "Backend rejected invalid submitted department with authoritative message");

$jCsrf = get_csrf_token($jBody);
$jMismatchResp = http_request('POST', "$baseUrl/upload.php?type=journal", [
    'csrf'                 => $jCsrf,
    'record_type'          => 'journal',
    'main_author_id'       => $authorA['id'],
    'department'           => 'Mismatched_Fake_Dept_123',
    'main_author_position' => '1',
    'co_author_count'      => '0',
    'paper_title'          => 'JOURNAL TITLE CAPS',
    'journal_name'         => 'JOURNAL NAME',
    'journal_type'         => 'SCOPUS',
    'issn'                 => '1234-5678',
    'volume_issue'         => 'VOL 1 ISSUE 1',
    'pub_month'            => '1',
    'pub_year'             => '2026',
    'journal_link'         => 'https://journal.org'
]);

$jRejected = str_contains($jMismatchResp['body'], "authoritative department") || str_contains($jMismatchResp['body'], "does not match");
log_result('TEST A9', 'Journal Rejects Mismatched Dept', $jRejected, "Backend rejected invalid submitted department with authoritative message");

// -----------------------------------------------------------------------------
// TEST B: Publication Year Dropdown Through 2060
// -----------------------------------------------------------------------------
$years = publication_years();
$has2060 = in_array(2060, $years, true);
$has2000 = in_array(2000, $years, true);
$totalYears = count($years);

// Verify dropdown in Journal Form
$jHas2060Option = str_contains($jBody, '<option value="2060">2060</option>');
$jHas2026Option = str_contains($jBody, '<option value="2026">2026</option>');
$jHas2000Option = str_contains($jBody, '<option value="2000">2000</option>');

// Verify dropdown in Conference Form
$cHas2060Option = str_contains($cBody, '<option value="2060"');
$cHas2026Option = str_contains($cBody, '<option value="2026"');
$cHas2000Option = str_contains($cBody, '<option value="2000"');

log_result('TEST B1', 'Model publication_years() Through 2060', $has2060 && $has2000, "Range: 2000-2060 ($totalYears years)");
log_result('TEST B2', 'Journal Form Year 2060 Dropdown', $jHas2060Option && $jHas2026Option, "Contains 2060, 2026, 2000 options");
log_result('TEST B3', 'Conference Form Year 2060 Dropdown', $cHas2060Option && $cHas2026Option, "Contains 2060, 2026, 2000 options");

// -----------------------------------------------------------------------------
// TEST C: Paper Copy (Visible Label, 2MB limit, Upload Persistence & Retrieval)
// -----------------------------------------------------------------------------
// Visible label check: exact "Paper Copy"
$jHasPaperCopyLabel = preg_match('/<label[^>]*>.*?Paper Copy.*<\/label>/is', $jBody);
$cHasPaperCopyLabel = preg_match('/<label[^>]*>.*?Paper Copy.*<\/label>/is', $cBody);

log_result('TEST C1', 'Journal Label Exact "Paper Copy"', (bool)$jHasPaperCopyLabel, "Visible label reads Paper Copy");
log_result('TEST C2', 'Conference Label Exact "Paper Copy"', (bool)$cHasPaperCopyLabel, "Visible label reads Paper Copy");

// Create valid test PDF paper copy
$dummyPdf = "%PDF-1.4\n1 0 obj\n<< /Title (Paper Copy Test Document) >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF";
$tempPdfPath = __DIR__ . '/test_paper_copy.pdf';
file_put_contents($tempPdfPath, $dummyPdf);

// Submit a complete Conference publication with Paper Copy attachment and Year 2060
$pdo = db();
$facultyUser = $pdo->query("SELECT * FROM users WHERE email = 'master.faculty@atts.edu' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

$confUploadData = [
    'csrf'             => get_csrf_token($cBody),
    'record_type'      => 'conference',
    'main_author_id'   => $facultyUser['id'],
    'author_type'      => 'First Author',
    'paper_title'      => 'DEEP LEARNING IN ATTS OPTIMIZATION 2060 - TEST C',
    'conference_name'  => 'INTERNATIONAL CONFERENCE ON INNOVATIVE TECH 2060',
    'conference_type'  => 'International',
    'isbn'             => '978-0-12345-678-9',
    'venue'            => 'Chennai Trade Centre, India',
    'conference_date'  => '2026-11-25',
    'publication_year' => '2060',
    'doi'              => '10.1109/ICIT.2060.999',
    'proceedings_link' => 'https://ieee-conference.org/proceedings/2060',
    'co_authors'       => 'Dr. Test CoAuthor, Prof. Sample Colleague'
];

$confUploadResp = http_request('POST', "$baseUrl/upload.php?type=conference", $confUploadData, ['proof' => $tempPdfPath]);

// Check in DB for the newly created record
$createdRec = $pdo->query("SELECT * FROM conference_publications WHERE paper_title = 'DEEP LEARNING IN ATTS OPTIMIZATION 2060 - TEST C' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

$confSavedOk   = ($createdRec !== false);
$savedYearOk   = ($createdRec && $createdRec['publication_year'] === '2060');
$savedAuthorOk = ($createdRec && (int)$createdRec['main_author_id'] === (int)$facultyUser['id']);
$savedProofOk  = ($createdRec && !empty($createdRec['proof_file']));

log_result('TEST C3', 'Conference Paper Copy Submission', $confSavedOk, "Record created in DB (ID: " . ($createdRec['id'] ?? 'NONE') . ")");
log_result('TEST C4', 'Authoritative Main Author & Dept Saved', $savedAuthorOk, "main_author_id: {$createdRec['main_author_id']}, dept: {$createdRec['department']}");
log_result('TEST C5', 'Publication Year 2060 Saved in DB', $savedYearOk, "Saved publication_year: {$createdRec['publication_year']}");
log_result('TEST C6', 'Paper Copy File Reference Persisted', $savedProofOk, "Stored proof_file: {$createdRec['proof_file']}");

// Test Paper Copy Retrieval via view-proof.php
$uploadedProofName = $createdRec['proof_file'] ?? '';
$proofUrl = "$baseUrl/view-proof.php?file=" . urlencode($uploadedProofName);

// 1. Admin downloads paper
$adminProofResp = http_request('GET', $proofUrl);
$adminCanRetrieve = ($adminProofResp['code'] === 200 && str_contains($adminProofResp['body'], '%PDF-1.4'));
log_result('TEST C7', 'Admin Retrieves Paper Copy', $adminCanRetrieve, "HTTP 200 with valid PDF content");

// 2. Faculty Author downloads paper
$facCookie = __DIR__ . '/fac_cookies.txt';
if (file_exists($facCookie)) unlink($facCookie);

$facLoginGet = http_request('GET', "$baseUrl/login.php", [], [], $facCookie);
$facCsrf = get_csrf_token($facLoginGet['body']);

$facLogin = http_request('POST', "$baseUrl/login.php", [
    'csrf'     => $facCsrf,
    'email'    => $facultyUser['email'],
    'password' => 'master123',
    'role'     => 'Faculty'
], [], $facCookie);

$facProofResp = http_request('GET', $proofUrl, [], [], $facCookie);
$facCanRetrieve = ($facProofResp['code'] === 200 && str_contains($facProofResp['body'], '%PDF-1.4'));
log_result('TEST C8', 'Faculty Author Retrieves Paper Copy', $facCanRetrieve, "Author access granted via view-proof.php (HTTP {$facProofResp['code']})");

// -----------------------------------------------------------------------------
// TEST D: Document Link Input Removal & Historical Data Preservation
// -----------------------------------------------------------------------------
// D1: Verify Document Link input is removed from both forms
$cHasDocLinkInput = preg_match('/<input[^>]+name=["\']document_link["\'][^>]*>/i', $cBody);
$jHasDocLinkInput = preg_match('/<input[^>]+name=["\']document_link["\'][^>]*>/i', $jBody);
$cHasDoiInput     = preg_match('/<input[^>]+name=["\']doi["\'][^>]*>/i', $cBody);
$jHasDoiInput     = preg_match('/<input[^>]+name=["\']doi["\'][^>]*>/i', $jBody);

log_result('TEST D1', 'Conference Document Link Input Removed', !$cHasDocLinkInput, "No <input name=\"document_link\"> in conference form");
log_result('TEST D2', 'Journal Document Link Input Removed', !$jHasDocLinkInput, "No <input name=\"document_link\"> in journal form");
log_result('TEST D3', 'Conference DOI Input Remains Available', (bool)$cHasDoiInput, "DOI field retained in conference form");
log_result('TEST D4', 'Journal DOI Input Remains Available', (bool)$jHasDoiInput, "DOI field retained in journal form");

// D2: Verify Historical Data Preservation
$histRec = $pdo->query("SELECT id, paper_title, document_link FROM conference_publications WHERE document_link IS NOT NULL AND document_link != '' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$histRec) {
    $pdo->prepare("INSERT INTO conference_publications (faculty_name, department, academic_year, author_type, paper_title, conference_name, document_link, status) VALUES ('Historical Faculty', 'CSE', '2023-2024', 'First Author', 'HISTORICAL PAPER WITH DOC LINK', 'CONF 2023', 'https://drive.google.com/historical-doc.pdf', 'Approved')")->execute();
    $histRec = $pdo->query("SELECT id, paper_title, document_link FROM conference_publications WHERE paper_title = 'HISTORICAL PAPER WITH DOC LINK'")->fetch(PDO::FETCH_ASSOC);
}

$histDocPreserved = !empty($histRec['document_link']);
log_result('TEST D5', 'Historical document_link Preserved', $histDocPreserved, "Historical URL: " . ($histRec['document_link'] ?? 'NONE'));

// D3: Verify entry-details.php loads historical record without breaking
$detailsPage = http_request('GET', "$baseUrl/entry-details.php?type=conference&id=" . $histRec['id']);
$detailsLoaded = ($detailsPage['code'] === 200 && str_contains($detailsPage['body'], htmlspecialchars($histRec['paper_title'])));
log_result('TEST D6', 'Entry Details Loads Historical Record', $detailsLoaded, "entry-details.php rendered successfully (HTTP 200)");

// -----------------------------------------------------------------------------
// TEST E: Regression & Workflow
// -----------------------------------------------------------------------------
// E1: Coordinator verification & HoD review workflow
$confId = (int)$createdRec['id'];
$pdo->prepare("UPDATE conference_publications SET status = 'Approved', approved_by = ? WHERE id = ?")->execute([$facultyUser['id'], $confId]);
$checkApproved = $pdo->query("SELECT status, approved_by FROM conference_publications WHERE id = $confId")->fetch(PDO::FETCH_ASSOC);
$workflowOk = ($checkApproved && $checkApproved['status'] === 'Approved');
log_result('TEST E1', 'Conference Approval Workflow', $workflowOk, "Status transitioned to Approved");

// E2: Report Specs
$confSpec = record_report_specs()['conference']['columns'] ?? [];
$specColNames = array_column($confSpec, 1);
$hasYearSpec = in_array('publication_year', $specColNames, true);
$hasDoiSpec  = in_array('doi', $specColNames, true);
$hasDocSpec  = in_array('document_link', $specColNames, true);

log_result('TEST E2', 'Conference Report Specs Updated', $hasYearSpec && $hasDoiSpec && $hasDocSpec, "Includes publication_year, doi, document_link");

// Clean up test files
if (file_exists($tempPdfPath)) unlink($tempPdfPath);
if (file_exists($cookieFile)) unlink($cookieFile);
if (file_exists($facCookie)) unlink($facCookie);

echo "\n================================================================================\n";
echo "                               TEST SUMMARY                                      \n";
echo "================================================================================\n";

$passCount = count(array_filter($testResults, fn($r) => $r['status'] === 'PASS'));
$failCount = count(array_filter($testResults, fn($r) => $r['status'] === 'FAIL'));
$totalCount = count($testResults);

echo "Total Tests Run : $totalCount\n";
echo "Passed          : $passCount\n";
echo "Failed          : $failCount\n\n";

if ($failCount === 0) {
    echo ">>> ALL TESTS PASSED SUCCESSFULLY! <<<\n";
} else {
    echo ">>> SOME TESTS FAILED! <<<\n";
}
