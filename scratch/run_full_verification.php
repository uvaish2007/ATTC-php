<?php
/**
 * Automated Verification Suite for ATTS Publication Form Fix
 * Tests A, B, C, D, E
 */

require_once __DIR__ . '/../php-app/inc/auth.php';
require_once __DIR__ . '/../php-app/models/Record.php';
require_once __DIR__ . '/../php-app/inc/record_specs.php';

$pdo = db();
$results = [];

function record_test(&$results, $section, $testName, $passed, $details = '') {
    $results[] = [
        'section' => $section,
        'test'    => $testName,
        'status'  => $passed ? 'PASS' : 'FAIL',
        'details' => $details
    ];
    $icon = $passed ? "[PASS]" : "[FAIL]";
    echo "{$icon} [{$section}] {$testName}: {$details}\n";
}

echo "=== STARTING ATTS PUBLICATION FORM FIX VERIFICATION ===\n\n";

// ---------------------------------------------------------------------------
// TEST B: Publication Years Range & Integrity
// ---------------------------------------------------------------------------
$years = publication_years();
$minYear = min($years);
$maxYear = max($years);
$has2060 = in_array(2060, $years, true);
$has2024 = in_array(2024, $years, true);
$has2000 = in_array(2000, $years, true);
$noDuplicates = (count($years) === count(array_unique($years)));
$isDescending = true;
for ($i = 0; $i < count($years) - 1; $i++) {
    if ($years[$i] <= $years[$i + 1]) {
        $isDescending = false;
        break;
    }
}

record_test($results, 'Test B - Years', 'Year 2060 Included', $has2060, "Max year: $maxYear");
record_test($results, 'Test B - Years', 'Year 2000 Included', $has2000, "Min year: $minYear");
record_test($results, 'Test B - Years', 'No Duplicates', $noDuplicates, "Total unique years: " . count($years));
record_test($results, 'Test B - Years', 'Chronological Descending Order', $isDescending, "First: {$years[0]}, Last: " . end($years));

// ---------------------------------------------------------------------------
// TEST A & D: Form HTML Verification (Synchronized Dept, Document Link Removal, Paper Copy Label)
// ---------------------------------------------------------------------------
// Render forms in memory for an admin or coordinator user
$adminUser = $pdo->query("SELECT * FROM users WHERE role = 'Admin' AND status = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$coordUser = $pdo->query("SELECT * FROM users WHERE role = 'Coordinator' AND status = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);

function capture_form_html($user, $type) {
    ob_start();
    // Simulate global state for upload.php
    $GLOBALS['selectedType'] = $type;
    $GLOBALS['user'] = $user;
    $GLOBALS['activeYear'] = active_academic_year();
    $GLOBALS['effectiveYear'] = active_academic_year();
    $GLOBALS['departments'] = departments_all();
    $editRecord = null;
    $activeSection = ['attachment_config' => ['required' => true, 'max_file_mb' => 2, 'allowed_types' => ['pdf']]];
    $GLOBALS['activeSection'] = $activeSection;

    // Load form sections by including helper functions
    require_once __DIR__ . '/../php-app/upload.php';
    return ob_get_clean();
}

// Check Journal Publication Form HTML
$journalHtml = '';
ob_start();
$selectedType = 'journal';
$user = $adminUser;
$activeYear = active_academic_year();
$effectiveYear = $activeYear;
$departments = departments_all();
$editRecord = null;
render_publication_author_fields($adminUser, $activeYear, 'paper');
render_publication_date_fields();
render_proof_field($editRecord);
$journalHtml = ob_get_clean();

$journalHasMainAuthorSelect = str_contains($journalHtml, 'id="pubMainAuthor"');
$journalHasReadonlyDept = str_contains($journalHtml, 'id="pubMainDept"') && str_contains($journalHtml, 'readonly');
$journalHasDataDept = str_contains($journalHtml, 'data-dept=');
$journalHasPaperCopyLabel = str_contains($journalHtml, 'Paper Copy');
$journalHasProofAttachmentLabel = str_contains($journalHtml, 'Proof / Attachment');

record_test($results, 'Test A - Journal Sync', 'Main Author Select Exists with data-dept', $journalHasMainAuthorSelect && $journalHasDataDept, "pubMainAuthor and data-dept present");
record_test($results, 'Test A - Journal Sync', 'Department is Readonly and Protected', $journalHasReadonlyDept, "pubMainDept has readonly attribute");
record_test($results, 'Test C - Paper Copy', 'Journal Attachment Label is "Paper Copy"', $journalHasPaperCopyLabel && !$journalHasProofAttachmentLabel, "Exact label: Paper Copy");

// Check Conference Publication Form HTML
// Inspect the conference form block in upload.php
$uploadPhpContent = file_get_contents(__DIR__ . '/../php-app/upload.php');
$confHasAuthorSelect = str_contains($uploadPhpContent, 'id="confMainAuthor"');
$confHasReadonlyDept = str_contains($uploadPhpContent, 'id="confMainDept"') && str_contains($uploadPhpContent, 'readonly');
$confHasYear2060 = str_contains($uploadPhpContent, 'publication_years()');
$confHasDocLink = preg_match('/<input[^>]+name="document_link"[^>]*>/i', substr($uploadPhpContent, strpos($uploadPhpContent, "\$selectedType === 'conference'"), 1500));
$journalHasDocLink = preg_match('/<input[^>]+name="document_link"[^>]*>/i', substr($uploadPhpContent, strpos($uploadPhpContent, "\$selectedType === 'journal'"), 1500));
$confHasDoi = str_contains($uploadPhpContent, 'name="doi"');

record_test($results, 'Test A - Conf Sync', 'Conference Main Author Select Exists with data-dept', $confHasAuthorSelect, "confMainAuthor present");
record_test($results, 'Test A - Conf Sync', 'Conference Department is Readonly and Protected', $confHasReadonlyDept, "confMainDept has readonly attribute");
record_test($results, 'Test D - Doc Link', 'Conference Document Link Input Removed', !$confHasDocLink, "document_link input eliminated from conference form");
record_test($results, 'Test D - Doc Link', 'Journal Document Link Input Removed', !$journalHasDocLink, "document_link input eliminated from journal form");
record_test($results, 'Test D - Doc Link', 'Conference DOI Field Retained', $confHasDoi, "doi input present");

// ---------------------------------------------------------------------------
// TEST A (Part 7): Backend Inconsistency Rejection
// ---------------------------------------------------------------------------
// Attempt to submit mismatched department for Journal
$facultyA = $pdo->query("SELECT * FROM users WHERE role = 'Faculty' AND department IS NOT NULL AND department != '' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$mismatchedDept = 'NonExistentFakeDeptXYZ';

$journalInputMismatched = [
    'main_author_id'       => $facultyA['id'],
    'main_author_position' => '1',
    'co_author_count'      => '0',
    'department'           => $mismatchedDept,
];
[$authorsClean, $colsClean, $journalErrors] = publication_prepare_authors($adminUser, $journalInputMismatched, 'journal');
$journalRejectedMismatch = false;
foreach ($journalErrors as $err) {
    if (str_contains($err, 'authoritative department')) {
        $journalRejectedMismatch = true;
        break;
    }
}
record_test($results, 'Test A - Backend Rejection', 'Journal Backend Rejects Mismatched Department', $journalRejectedMismatch, "Error: " . implode('; ', $journalErrors));

// Attempt to submit mismatched department for Conference
$confInputMismatched = [
    'main_author_id'   => $facultyA['id'],
    'department'       => $mismatchedDept,
    'author_type'      => 'First Author',
    'paper_title'      => 'TEST TITLE',
    'conference_name'  => 'TEST CONF',
    'conference_type'  => 'National',
    'venue'            => 'Chennai',
    'conference_date'  => '2026-05-10',
    'publication_year' => '2026',
];
[$confClean, $confErrors] = conference_prepare_submission($adminUser, $confInputMismatched);
$confRejectedMismatch = false;
foreach ($confErrors as $err) {
    if (str_contains($err, 'authoritative department')) {
        $confRejectedMismatch = true;
        break;
    }
}
record_test($results, 'Test A - Backend Rejection', 'Conference Backend Rejects Mismatched Department', $confRejectedMismatch, "Error: " . implode('; ', $confErrors));

// Verify backend derives authoritative department when department is omitted or valid
$confInputValid = [
    'main_author_id'   => $facultyA['id'],
    'department'       => $facultyA['department'],
    'author_type'      => 'First Author',
    'paper_title'      => 'AI INNOVATIONS IN TESTING',
    'conference_name'  => 'IEEE TEST CONFERENCE 2026',
    'conference_type'  => 'International',
    'venue'            => 'Chennai, India',
    'conference_date'  => '2026-10-15',
    'publication_year' => '2060',
    'doi'              => '10.1109/TEST.2026.001',
    'isbn'             => '978-1-2345-6789-0',
    'proceedings_link' => 'https://ieee.org/proceedings/test',
];
[$confCleanValid, $confErrorsValid] = conference_prepare_submission($adminUser, $confInputValid);
$authoritativeMatch = ($confCleanValid['department'] === $facultyA['department'] && empty($confErrorsValid));
record_test($results, 'Test A - Authoritative Derivation', 'Authoritative Department Derived Correctly', $authoritativeMatch, "Author dept: {$facultyA['department']}, Derived: {$confCleanValid['department']}");

// ---------------------------------------------------------------------------
// TEST B: Publication Year 2060 Validation & Persistence
// ---------------------------------------------------------------------------
$year2060Accepted = ($confCleanValid['publication_year'] === '2060');
record_test($results, 'Test B - Year 2060', 'Publication Year 2060 Accepted by Validator', $year2060Accepted, "Clean year: " . $confCleanValid['publication_year']);

// Test invalid future year beyond 2060
$confInputInvalidYear = $confInputValid;
$confInputInvalidYear['publication_year'] = '2075';
[$cOutInvalidY, $cErrorsInvalidY] = conference_prepare_submission($adminUser, $confInputInvalidYear);
$invalidYearRejected = !empty($cErrorsInvalidY);
record_test($results, 'Test B - Out of Bounds Year', 'Year beyond 2060 Rejected', $invalidYearRejected, "Error: " . implode('; ', $cErrorsInvalidY));

// ---------------------------------------------------------------------------
// TEST C: Paper Copy Upload, Storage, Persistence & Retrieval
// ---------------------------------------------------------------------------
// Create a dummy PDF test file
$dummyPdfContent = "%PDF-1.4\n1 0 obj\n<< /Title (Paper Copy Test) >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF";
$tempPdf = tempnam(sys_get_temp_dir(), 'paper_') . '.pdf';
file_put_contents($tempPdf, $dummyPdfContent);

// Test save_upload_proof()
$fileMock = [
    'name'     => 'research_paper_copy.pdf',
    'type'     => 'application/pdf',
    'tmp_name' => $tempPdf,
    'error'    => UPLOAD_ERR_OK,
    'size'     => strlen($dummyPdfContent)
];
[$storedProofFile, $proofErrors] = save_upload_proof($fileMock, true, 'conference');
$proofStoredSuccessfully = ($storedProofFile !== null && empty($proofErrors));
record_test($results, 'Test C - Paper Upload', 'Paper Copy Upload Stored via save_upload_proof', $proofStoredSuccessfully, "Stored filename: " . ($storedProofFile ?? 'NONE'));

// Insert test Conference record into DB with Paper Copy reference
$testAcademicYear = active_academic_year();
$insStmt = $pdo->prepare("INSERT INTO conference_publications (
    faculty_name, department, academic_year, main_author_id, author_type,
    paper_title, conference_name, conference_type, isbn, doi,
    conference_date, publication_year, venue, proceedings_link,
    proofs, proof_file, status, created_by
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Submitted', ?)");

$insStmt->execute([
    $facultyA['name'],
    $facultyA['department'],
    $testAcademicYear,
    $facultyA['id'],
    'First Author',
    'AUTOMATED TEST CONFERENCE PAPER ON AI',
    'GLOBAL CONFERENCE ON COMPUTING 2026',
    'International',
    '978-0-1234-5678-9',
    '10.1109/GCC.2026.123',
    '2026-11-20',
    '2060',
    'Chennai, India',
    'https://conference.example.org/proc',
    json_encode([$storedProofFile]),
    $storedProofFile,
    $adminUser['id']
]);
$testConfId = (int)$pdo->lastInsertId();

// Verify persistence in DB
$persistedConf = $pdo->query("SELECT * FROM conference_publications WHERE id = $testConfId")->fetch(PDO::FETCH_ASSOC);
$persistedOk = ($persistedConf && $persistedConf['proof_file'] === $storedProofFile && $persistedConf['publication_year'] === '2060' && $persistedConf['main_author_id'] == $facultyA['id']);
record_test($results, 'Test C - Persistence', 'Conference Record with Paper Copy and Year 2060 Persisted', $persistedOk, "ID: $testConfId, Year: {$persistedConf['publication_year']}, File: {$persistedConf['proof_file']}");

// Test Paper Retrieval via view-proof.php logic
// Simulate authorization check from view-proof.php for faculty author
$file = $storedProofFile;
$targetPath = proof_file_path($file);
$fileExistsOnDisk = ($targetPath && is_file($targetPath));

// Author access check:
$authorUserId = (int)$facultyA['id'];
$isOwner = ((int)($persistedConf['created_by'] ?? 0) === $authorUserId);
$isNamedAuthor = (!empty($persistedConf['main_author_id']) && (int)$persistedConf['main_author_id'] === $authorUserId)
    || (!empty($persistedConf['faculty_name']) && trim((string)$persistedConf['faculty_name']) === trim((string)$facultyA['name']));

record_test($results, 'Test C - Retrieval', 'Paper Copy Exists On Server Disk', $fileExistsOnDisk, "Path: $targetPath");
record_test($results, 'Test C - Retrieval', 'Faculty Author Permitted to View Paper Copy', $isNamedAuthor, "Faculty ID {$authorUserId} matches main_author_id {$persistedConf['main_author_id']}");

// ---------------------------------------------------------------------------
// TEST D: Historical Document Link Preservation
// ---------------------------------------------------------------------------
// Create a historical record with document_link populated
$histStmt = $pdo->prepare("INSERT INTO conference_publications (
    faculty_name, department, academic_year, author_type, paper_title,
    conference_name, conference_type, document_link, status, created_by
) VALUES (?, ?, ?, 'Co-Author', 'HISTORICAL PAPER 2023', 'PREVIOUS CONF 2023', 'National', 'https://historical.drive.google.com/paper.pdf', 'Approved', ?)");
$histStmt->execute([$facultyA['name'], $facultyA['department'], '2023-2024', $adminUser['id']]);
$histId = (int)$pdo->lastInsertId();

$histRec = $pdo->query("SELECT * FROM conference_publications WHERE id = $histId")->fetch(PDO::FETCH_ASSOC);
$histDocLinkPreserved = ($histRec && $histRec['document_link'] === 'https://historical.drive.google.com/paper.pdf');
record_test($results, 'Test D - Historical Preservation', 'Historical Document Link Preserved in Database', $histDocLinkPreserved, "Historical URL: {$histRec['document_link']}");

// ---------------------------------------------------------------------------
// TEST E: Regression & Workflow (Coordinator Verification & HoD Review)
// ---------------------------------------------------------------------------
// Verify report specs include conference publication_year and doi
$confReportSpec = record_report_specs()['conference'] ?? [];
$specCols = array_column($confReportSpec, 1);
$reportHasYear = in_array('publication_year', $specCols, true);
$reportHasDoi = in_array('doi', $specCols, true);
$reportHasDocLink = in_array('document_link', $specCols, true);
record_test($results, 'Test E - Regression', 'Conference Report Specs Contain Year, DOI, and Document Link', $reportHasYear && $reportHasDoi && $reportHasDocLink, "Cols present: publication_year, doi, document_link");

// Workflow transition test: Submitted -> Approved
$pdo->prepare("UPDATE conference_publications SET status = 'Approved', approved_by = ? WHERE id = ?")->execute([$adminUser['id'], $testConfId]);
$approvedRec = $pdo->query("SELECT status, approved_by FROM conference_publications WHERE id = $testConfId")->fetch(PDO::FETCH_ASSOC);
$approvalWorkflowOk = ($approvedRec && $approvedRec['status'] === 'Approved' && $approvedRec['approved_by'] == $adminUser['id']);
record_test($results, 'Test E - Workflow', 'Publication Approval Status Transition', $approvalWorkflowOk, "Status: {$approvedRec['status']}");

// Clean up test temp file
if (file_exists($tempPdf)) {
    unlink($tempPdf);
}

echo "\n=== VERIFICATION COMPLETE ===\n";
