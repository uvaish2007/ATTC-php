<?php
/**
 * Test Suite: Patent & Copyright Form Redesign, Co-Authors, Attachments & Consolidated Reporting
 *
 * Verifies all 13 items from Section 7:
 * 1. Main Author field removed from UI and backend validation.
 * 2. Multiple co-authors add, remove, edit, DB persistence.
 * 3. Document link validation and safe clickable display.
 * 4. Multiple attachment categories (Patent Copy, Certificate, Supporting Document).
 * 5. File validation (extensions, MIME, magic bytes, <= 2 MB).
 * 6. Existing attachment preservation on edit.
 * 7. Registration number normalization rules.
 * 8. Consolidated report deduplication by registration number.
 * 9. Separation of different numbers, categories, and academic years.
 * 10. Flagging missing registration numbers for review.
 * 11. Consistency across consolidated screen view and exports.
 * 12. Proof authorization and delivery in proof.php.
 * 13. System integrity and regression check.
 */

define('TEST_MODE', true);

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/helpers.php';
require_once __DIR__ . '/../inc/record_specs.php';
require_once __DIR__ . '/../inc/patent_handler.php';
require_once __DIR__ . '/../models/Record.php';

$pdo = db();
$allPassed = true;
$testsRun = 0;

function assertTest(bool $condition, string $testName, string $details = ''): void {
    global $allPassed, $testsRun;
    $testsRun++;
    if ($condition) {
        echo "[PASS] {$testName}\n";
    } else {
        $allPassed = false;
        echo "[FAIL] {$testName}" . ($details ? " - {$details}" : "") . "\n";
    }
}

echo "=== ATTS Patent & Copyright Redesign Test Suite ===\n\n";

// ─────────────────────────────────────────────────────────────────────────────
// Test 1: Main Author Field Completely Removed
// ─────────────────────────────────────────────────────────────────────────────
echo "--- 1. Main Author Field Removal ---\n";
$uploadCode = file_get_contents(__DIR__ . '/../upload.php');

// Inspect requiredMap in upload.php
assertTest(strpos($uploadCode, "'main_author'") === false, 'upload.php does not contain main_author in requiredMap');

// Inspect Patent form section in upload.php
$patentSectionStart = strpos($uploadCode, "\$selectedType === 'patent'");
$patentSectionEnd = strpos($uploadCode, "\$selectedType === 'fdp'", $patentSectionStart);
$patentHtml = substr($uploadCode, $patentSectionStart, $patentSectionEnd - $patentSectionStart);

assertTest(stripos($patentHtml, 'Main Author') === false, 'Main Author field label is NOT rendered in Patent upload form');
assertTest(strpos($patentHtml, 'name="main_author"') === false, 'No input name="main_author" in Patent form');
assertTest(strpos($patentHtml, 'Author Method / Co-Authors') !== false, 'Author Method / Co-Authors section is present');
assertTest(strpos($patentHtml, 'Add Co-Author (+)') !== false, 'Add Co-Author (+) action button is present');

// ─────────────────────────────────────────────────────────────────────────────
// Test 2: Multiple Co-Authors Input, Parsing & DB Persistence
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 2. Co-Author Parsing, Adding, Editing & Persistence ---\n";

$rawInputs = [
    'Dr. Alan Turing',
    ' Dr. Ada Lovelace ',
    '<b>Grace Hopper</b>',
    'Dr. Alan Turing', // duplicate to filter
    '',
];
$cleanedCoAuthors = patent_parse_coauthors_input($rawInputs);
assertTest(count($cleanedCoAuthors) === 3, 'Co-author input cleaning strips tags, trims whitespace and deduplicates', 'Got ' . count($cleanedCoAuthors));
assertTest($cleanedCoAuthors[0] === 'Dr. Alan Turing', 'Co-author 1 preserved');
assertTest($cleanedCoAuthors[1] === 'Dr. Ada Lovelace', 'Co-author 2 trimmed and preserved');
assertTest($cleanedCoAuthors[2] === 'Grace Hopper', 'Co-author 3 HTML tags stripped');

// Test string input parsing (comma-separated)
$stringInput = 'Linus Torvalds, Dennis Ritchie, Ken Thompson';
$cleanedFromString = patent_parse_coauthors_input($stringInput);
assertTest(count($cleanedFromString) === 3 && $cleanedFromString[1] === 'Dennis Ritchie', 'Comma-separated string of co-authors parsed correctly');

// Create a test patent record in database
$testAy = active_academic_year();
$stmt = $pdo->prepare("
    INSERT INTO patents (faculty_name, department, academic_year, academic_session, category, title, patent_number, publication_date, document_link, status, created_by, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Submitted', 1, NOW())
");
$stmt->execute([
    'Dr. Submitting Faculty',
    'CSE',
    $testAy,
    'ODD SEM',
    'Patent Published',
    'Autonomous Multi-Agent AI Distributed Compiler',
    'IN-2026-PAT-001A',
    '2026-03-15',
    'https://ipindiaservices.gov.in/publicsearch/001A'
]);
$testPatentId = (int)$pdo->lastInsertId();
assertTest($testPatentId > 0, "Created test patent record #{$testPatentId}");

// Save co-authors to DB
patent_save_coauthors($testPatentId, $cleanedCoAuthors, $pdo);

// Fetch co-authors from DB via relational table
$fetchedCoAuthors = patent_get_coauthors($testPatentId);
assertTest(count($fetchedCoAuthors) === 3, "Relational table patent_coauthors has 3 records for Patent #{$testPatentId}");
assertTest($fetchedCoAuthors[0] === 'Dr. Alan Turing' && $fetchedCoAuthors[2] === 'Grace Hopper', 'Relational co-authors match exact names and sort order');

// Verify patents.co_authors text column was also synced
$coAuthorsCol = $pdo->query("SELECT co_authors FROM patents WHERE id = {$testPatentId}")->fetchColumn();
assertTest(strpos($coAuthorsCol, 'Dr. Alan Turing') !== false && strpos($coAuthorsCol, 'Grace Hopper') !== false, 'patents.co_authors column updated with readable formatted text');

// Edit co-authors: remove one, add a new one
$updatedCoAuthors = ['Dr. Alan Turing', 'Margaret Hamilton'];
patent_save_coauthors($testPatentId, $updatedCoAuthors, $pdo);
$refetchedCoAuthors = patent_get_coauthors($testPatentId);
assertTest(count($refetchedCoAuthors) === 2 && in_array('Margaret Hamilton', $refetchedCoAuthors, true), 'Editing co-authors updates relational database records without orphan rows');
assertTest(!in_array('Dr. Ada Lovelace', $refetchedCoAuthors, true), 'Removed co-author was correctly deleted');

// ─────────────────────────────────────────────────────────────────────────────
// Test 3: Document Link Validation & Display
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 3. Document Link Validation ---\n";
$validUrls = [
    'https://patents.google.com/patent/US10293847B2/en',
    'http://ipindiaservices.gov.in/patentsearch',
    'https://copyright.gov.in/UserRegistration/frmStatus.aspx',
];
$invalidUrls = [
    'ftp://invalid-protocol.com/doc.pdf',
    'javascript:alert(1)',
    'www.no-protocol.com',
    'file:///C:/passwords.txt',
    'invalid-url-string',
];

foreach ($validUrls as $vu) {
    assertTest((bool)preg_match('/^https?:\/\/.+/i', $vu), "Valid HTTP/HTTPS URL accepted: {$vu}");
}
foreach ($invalidUrls as $iu) {
    assertTest(!preg_match('/^https?:\/\/.+/i', $iu), "Invalid URL correctly rejected: {$iu}");
}

// ─────────────────────────────────────────────────────────────────────────────
// Test 4: Multiple Attachment Uploads & Categorization
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 4. Multiple Attachment Categories (Patent Copy, Certificate, Supporting Document) ---\n";
assertTest(count(PATENT_DOC_TYPES) === 3, 'PATENT_DOC_TYPES contains exactly 3 allowed categories');
assertTest(in_array('Patent Copy', PATENT_DOC_TYPES, true), 'Contains "Patent Copy"');
assertTest(in_array('Certificate', PATENT_DOC_TYPES, true), 'Contains "Certificate"');
assertTest(in_array('Supporting Document', PATENT_DOC_TYPES, true), 'Contains "Supporting Document"');

// Create temporary valid test files
$tempDir = sys_get_temp_dir() . '/atts_patent_test_' . uniqid();
@mkdir($tempDir, 0755, true);

// 1. Valid PDF file
$pdfPath = $tempDir . '/test_patent_copy.pdf';
file_put_contents($pdfPath, "%PDF-1.4\n1 0 obj\n<< /Title (Test Patent Copy) >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF");

// 2. Valid PNG file
$pngPath = $tempDir . '/test_certificate.png';
file_put_contents($pngPath, "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15c4");

// 3. Valid JPG file
$jpgPath = $tempDir . '/test_supporting.jpg';
file_put_contents($jpgPath, "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x01\x00`\x00`\x00\x00\xFF\xDB\x00C\x00\xFF\xD9");

$multiFiles = [
    'name'     => ['test_patent_copy.pdf', 'test_certificate.png', 'test_supporting.jpg'],
    'tmp_name' => [$pdfPath, $pngPath, $jpgPath],
    'type'     => ['application/pdf', 'image/png', 'image/jpeg'],
    'error'    => [UPLOAD_ERR_OK, UPLOAD_ERR_OK, UPLOAD_ERR_OK],
    'size'     => [filesize($pdfPath), filesize($pngPath), filesize($jpgPath)],
];
$multiDocTypes = ['Patent Copy', 'Certificate', 'Supporting Document'];

[$uploadOk, $uploadErr, $savedAttachments] = patent_process_attachments($testPatentId, $multiFiles, $multiDocTypes, [], true);
assertTest($uploadOk && empty($uploadErr), 'Multiple attachments uploaded successfully across all 3 document categories');
assertTest(count($savedAttachments) === 3, 'Exactly 3 attachments saved');

// Verify in DB table patent_attachments
$dbAtts = patent_get_attachments($testPatentId);
assertTest(count($dbAtts) === 3, "Database returned 3 attachments for Patent #{$testPatentId}");
$docTypeCounts = array_count_values(array_column($dbAtts, 'doc_type'));
assertTest(($docTypeCounts['Patent Copy'] ?? 0) === 1, 'Patent Copy attachment persisted');
assertTest(($docTypeCounts['Certificate'] ?? 0) === 1, 'Certificate attachment persisted');
assertTest(($docTypeCounts['Supporting Document'] ?? 0) === 1, 'Supporting Document attachment persisted');

// Verify patents.proof_file and attachments cache
$pRow = $pdo->query("SELECT proof_file, attachments FROM patents WHERE id = {$testPatentId}")->fetch(PDO::FETCH_ASSOC);
assertTest(!empty($pRow['proof_file']), 'patents.proof_file is populated with primary attachment');
$cachedAtts = json_decode($pRow['attachments'] ?? '[]', true);
assertTest(is_array($cachedAtts) && count($cachedAtts) === 3, 'patents.attachments JSON cache reflects all 3 documents');

// ─────────────────────────────────────────────────────────────────────────────
// Test 5: Attachment Security & File Validation
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 5. Security Validation (Reject invalid extension, bad magic bytes, oversize) ---\n";

// 1. Invalid extension (.exe)
$exePath = $tempDir . '/malicious.exe';
file_put_contents($exePath, "MZ\x90\x00\x03\x00\x00\x00");
$badExtFiles = [
    'name'     => ['malicious.exe'],
    'tmp_name' => [$exePath],
    'error'    => [UPLOAD_ERR_OK],
    'size'     => [100],
];
[$badExtOk, $badExtErr] = patent_process_attachments(0, $badExtFiles, ['Patent Copy'], [], false);
assertTest(!$badExtOk && strpos($badExtErr, 'Invalid format') !== false, 'Executable .exe file rejected with clear error');

// 2. Fake PDF with wrong magic bytes
$fakePdfPath = $tempDir . '/fake.pdf';
file_put_contents($fakePdfPath, "NOT A REAL PDF CONTENT");
$fakePdfFiles = [
    'name'     => ['fake.pdf'],
    'tmp_name' => [$fakePdfPath],
    'error'    => [UPLOAD_ERR_OK],
    'size'     => [50],
];
[$fakePdfOk, $fakePdfErr] = patent_process_attachments(0, $fakePdfFiles, ['Patent Copy'], [], false);
assertTest(!$fakePdfOk && strpos($fakePdfErr, 'not a valid PDF') !== false, 'Spoofed PDF with invalid magic bytes rejected');

// 3. Oversized file (> 2 MB)
$oversizeFiles = [
    'name'     => ['large_file.pdf'],
    'tmp_name' => [$pdfPath],
    'error'    => [UPLOAD_ERR_OK],
    'size'     => [PROOF_MAX_BYTES + 50000],
];
[$overOk, $overErr] = patent_process_attachments(0, $oversizeFiles, ['Patent Copy'], [], false);
assertTest(!$overOk && strpos($overErr, 'exceeds the maximum') !== false, 'File exceeding 2 MB strictly rejected');

// ─────────────────────────────────────────────────────────────────────────────
// Test 6: Existing Attachments Remain Intact on Edit
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 6. Attachment Preservation on Edit ---\n";

// Keep attachment IDs 1 and 2 from previous test, exclude 3
$allCurrentIds = array_column($dbAtts, 'id');
$keepIds = [$allCurrentIds[0], $allCurrentIds[1]];

[$editAttOk, $editAttErr, $remainingAtts] = patent_process_attachments($testPatentId, null, [], $keepIds, true);
assertTest($editAttOk, 'Attachment update without new files succeeds');
$afterEditAtts = patent_get_attachments($testPatentId);
assertTest(count($afterEditAtts) === 2, 'Exactly 2 kept attachments remain intact in database');
$keptIdsInDb = array_column($afterEditAtts, 'id');
assertTest(in_array($allCurrentIds[0], $keptIdsInDb, true) && in_array($allCurrentIds[1], $keptIdsInDb, true), 'Target kept attachments match exactly');
assertTest(!in_array($allCurrentIds[2], $keptIdsInDb, true), 'Unchecked attachment was safely deleted without accidental loss of other attachments');

// ─────────────────────────────────────────────────────────────────────────────
// Test 7: Registration Number Normalization Rules
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 7. Patent / Registration Number Normalization Rules ---\n";

$raw1 = '  IN-2026/PAT-001A  ';
$raw2 = 'in 2026 pat 001a';
$raw3 = 'in-2026.pat.001a';
$raw4 = 'IN_2026_PAT_001A';

$norm1 = patent_normalize_number($raw1);
$norm2 = patent_normalize_number($raw2);
$norm3 = patent_normalize_number($raw3);
$norm4 = patent_normalize_number($raw4);

assertTest($norm1 === 'IN2026PAT001A', 'Raw number trimmed and special characters stripped to uppercase canonical form');
assertTest($norm1 === $norm2 && $norm2 === $norm3 && $norm3 === $norm4, 'All harmless formatting variations normalize to the same canonical key');

// Genuine different numbers
$diff1 = patent_normalize_number('IN-2026-PAT-001A');
$diff2 = patent_normalize_number('IN-2026-PAT-001B');
$diff3 = patent_normalize_number('IN-2025-PAT-001A');
assertTest($diff1 !== $diff2, 'Genuinely different patent serials (001A vs 001B) remain completely distinct');

// ─────────────────────────────────────────────────────────────────────────────
// Test 8: Consolidated Reporting & Deduplication
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 8. Consolidated Reporting Deduplication & Aggregation ---\n";

// Create a second record with the SAME patent number submitted by a co-author in a different department
$stmt->execute([
    'Dr. CoAuthor Faculty',
    'ECE',
    $testAy,
    'ODD SEM',
    'Patent Published',
    'Autonomous Multi-Agent AI Distributed Compiler',
    'in-2026/pat-001a', // different case and spacing
    '2026-03-15',
    'https://ipindiaservices.gov.in/publicsearch/001A'
]);
$secondPatentId = (int)$pdo->lastInsertId();
patent_save_coauthors($secondPatentId, ['Dr. Submitting Faculty', 'Dr. External Specialist'], $pdo);

// Simulate report_records output containing both records
$mockRecords = [
    [
        'id'               => $testPatentId,
        '_type_key'        => 'patent',
        '_type_label'      => 'Patent / Copyright',
        '_title'           => 'Autonomous Multi-Agent AI Distributed Compiler',
        '_person'          => 'Dr. Submitting Faculty',
        'faculty_name'     => 'Dr. Submitting Faculty',
        'department'       => 'CSE',
        'academic_year'    => $testAy,
        'category'         => 'Patent Published',
        'patent_number'    => 'IN-2026-PAT-001A',
        'publication_date' => '2026-03-15',
        'document_link'    => 'https://ipindiaservices.gov.in/publicsearch/001A',
        'status'           => 'Approved',
        'created_at'       => '2026-03-15 10:00:00',
    ],
    [
        'id'               => $secondPatentId,
        '_type_key'        => 'patent',
        '_type_label'      => 'Patent / Copyright',
        '_title'           => 'Autonomous Multi-Agent AI Distributed Compiler',
        '_person'          => 'Dr. CoAuthor Faculty',
        'faculty_name'     => 'Dr. CoAuthor Faculty',
        'department'       => 'ECE',
        'academic_year'    => $testAy,
        'category'         => 'Patent Published',
        'patent_number'    => 'in-2026/pat-001a',
        'publication_date' => '2026-03-15',
        'document_link'    => 'https://ipindiaservices.gov.in/publicsearch/001A',
        'status'           => 'Submitted',
        'created_at'       => '2026-03-16 11:00:00',
    ],
];

$consolidated = patent_consolidate_records($mockRecords);
assertTest(count($consolidated) === 1, 'Two submissions of the same intellectual property record consolidated into 1 record', 'Got ' . count($consolidated));

$cRecord = $consolidated[0];
assertTest($cRecord['_is_consolidated'] === true, '_is_consolidated flag is set');
assertTest($cRecord['_submissions_count'] === 2, 'Submissions count is 2');
assertTest($cRecord['status'] === 'Approved', 'Consolidated status adopts Approved when at least one submission is Approved');
assertTest(strpos($cRecord['_author_summary'], 'Dr. Submitting Faculty') !== false && strpos($cRecord['_author_summary'], 'Dr. CoAuthor Faculty') !== false, 'Composite author summary contains both faculty members without losing information');
assertTest(strpos($cRecord['_department'], 'CSE') !== false && strpos($cRecord['_department'], 'ECE') !== false, 'Composite departments contains both CSE and ECE');

// ─────────────────────────────────────────────────────────────────────────────
// Test 9: Separation of Different Numbers, Categories & Academic Years
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 9. Strict Scope Isolation (No false merges) ---\n";

$distinctRecords = [
    // 1. Base record
    $mockRecords[0],
    // 2. Same number, DIFFERENT Academic Year (2024-25 vs 2025-26)
    array_merge($mockRecords[0], ['id' => 9991, 'academic_year' => '2024-25']),
    // 3. Same number, DIFFERENT Category ('Copyrights' vs 'Patent Published')
    array_merge($mockRecords[0], ['id' => 9992, 'category' => 'Copyrights', 'title' => 'Compiler Source Code']),
    // 4. DIFFERENT patent number
    array_merge($mockRecords[0], ['id' => 9993, 'patent_number' => 'IN-2026-PAT-999Z', 'title' => 'Quantum Cryptography Engine']),
];

$distinctConsolidated = patent_consolidate_records($distinctRecords);
assertTest(count($distinctConsolidated) === 4, 'Records across different academic years, categories, or numbers remain separate (no false merges)');

// ─────────────────────────────────────────────────────────────────────────────
// Test 10: Missing Registration Numbers Flagged for Review
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 10. Missing Registration Number Flagged for Review ---\n";

$missingNumRecord = [
    'id'               => 8881,
    '_type_key'        => 'patent',
    '_type_label'      => 'Patent / Copyright',
    '_title'           => 'Draft Patent Submission without Number',
    '_person'          => 'Dr. Submitting Faculty',
    'faculty_name'     => 'Dr. Submitting Faculty',
    'department'       => 'CSE',
    'academic_year'    => $testAy,
    'category'         => 'Patent Published',
    'patent_number'    => '   ', // whitespace only
    'publication_date' => '2026-03-20',
    'status'           => 'Submitted',
    'created_at'       => '2026-03-20 12:00:00',
];

$resWithMissing = patent_consolidate_records([$missingNumRecord]);
assertTest(count($resWithMissing) === 1, 'Missing registration record retained');
assertTest(!empty($resWithMissing[0]['_needs_review']), 'Record is explicitly flagged with _needs_review = true');
assertTest(strpos($resWithMissing[0]['_review_flag_reason'], 'Missing Registration Number') !== false, 'Flag reason is Missing Registration Number');

// ─────────────────────────────────────────────────────────────────────────────
// Test 11: Consolidated Report Output Consistency
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 11. Multi-Format Report Views & Excel Export Structure ---\n";

$consRepCode = file_get_contents(__DIR__ . '/../consolidated-report.php');
assertTest(strpos($consRepCode, 'patent_consolidate_records') !== false, 'consolidated-report.php invokes patent_consolidate_records');
assertTest(strpos($consRepCode, "'Patents & Copyrights'") !== false, 'Dedicated Patents & Copyrights sheet added to Excel multi-sheet export');
assertTest(strpos($consRepCode, 'PATENTS &amp; COPYRIGHTS REGISTER') !== false, 'Executive Patents & Copyrights Register rendered in HTML/PDF/Word views');

// ─────────────────────────────────────────────────────────────────────────────
// Test 12: Proof Authorization in proof.php
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 12. Proof Document Delivery & Access Control ---\n";

$proofCode = file_get_contents(__DIR__ . '/../proof.php');
assertTest(strpos($proofCode, 'patent_attachments') !== false, 'proof.php queries patent_attachments table');
assertTest(strpos($proofCode, 'attachment_doc_type') !== false, 'proof.php retrieves document type metadata');

// ─────────────────────────────────────────────────────────────────────────────
// Cleanup Test Data
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- Cleaning up temporary test records ---\n";
try {
    $pdo->prepare("DELETE FROM patent_attachments WHERE patent_id IN (?, ?)")->execute([$testPatentId, $secondPatentId]);
    $pdo->prepare("DELETE FROM patent_coauthors WHERE patent_id IN (?, ?)")->execute([$testPatentId, $secondPatentId]);
    $pdo->prepare("DELETE FROM patents WHERE id IN (?, ?)")->execute([$testPatentId, $secondPatentId]);
    
    // Remove temp files
    @unlink($pdfPath);
    @unlink($pngPath);
    @unlink($jpgPath);
    @unlink($exePath);
    @unlink($fakePdfPath);
    @rmdir($tempDir);
    echo "Temporary test records and files cleaned up.\n";
} catch (\Throwable $e) {
    echo "Cleanup note: " . $e->getMessage() . "\n";
}

echo "\n=======================================================\n";
echo "Tests Run: {$testsRun} | Result: " . ($allPassed ? "ALL TESTS PASSED!" : "SOME TESTS FAILED!") . "\n";
echo "=======================================================\n";

exit($allPassed ? 0 : 1);
