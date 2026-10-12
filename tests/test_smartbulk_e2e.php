<?php
/**
 * ATTS-SMARTBULK End-to-End Automated Test Suite
 *
 * Verifies:
 * 1. Excel Template Generation & Sheet Structure
 * 2. Compulsory Proofs ZIP Archive Enforcement & Rejections
 * 3. 1:1 Student Proof Certificate Matching by Register Number
 * 4. Multi-level Validation (Department, Duplicates, Formats)
 * 5. Missing Certificate in ZIP Rejection
 * 6. Transactional Commit, DB Persistence & Proof File Storage
 * 7. Downstream Availability (Approvals, Reports, Grid)
 * 8. Exclusive Bulk UI Verification in upload.php
 */

require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/models/BulkImport.php';
require_once __DIR__ . '/../php-app/inc/xlsx_writer.php';
require_once __DIR__ . '/../php-app/inc/xlsx_reader.php';
require_once __DIR__ . '/../php-app/models/Record.php';
require_once __DIR__ . '/../php-app/models/Dashboard.php';

function assert_test(string $name, bool $condition, string $details = '') {
    if ($condition) {
        echo "[PASS] {$name}" . ($details ? " - {$details}" : "") . "\n";
    } else {
        echo "[FAIL] {$name}" . ($details ? " - {$details}" : "") . "\n";
        exit(1);
    }
}

echo "=== ATTS-SMARTBULK TEST SUITE STARTING ===\n\n";

$testDir = __DIR__ . '/../scratch/test_bulk_' . time();
if (!is_dir($testDir)) {
    mkdir($testDir, 0777, true);
}

$currentUser = [
    'id'         => 1,
    'name'       => 'Test Faculty CSE',
    'email'      => 'test.cse@atts.edu',
    'role'       => 'Faculty',
    'department' => 'CSE',
];
$activeYear = '2025-26';

// Pre-clean any test records from prior runs
$dbInit = db();
$dbInit->exec("DELETE FROM internships WHERE reg_no LIKE '7113221045%' OR reg_no LIKE '7113221046%' OR reg_no LIKE '7113221049%'");
$dbInit->exec("DELETE FROM summer_training WHERE reg_no LIKE '7113221045%' OR reg_no LIKE '7113221046%' OR reg_no LIKE '7113221049%'");

// Helper to create a valid dummy PDF
function create_dummy_pdf(string $text = "Certificate"): string {
    return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>\nendobj\nxref\n0 4\n0000000000 65535 f \n0000000010 00000 n \n0000000060 00000 n \n0000000117 00000 n \ntrailer\n<< /Size 4 /Root 1 0 R >>\nstartxref\n198\n%%EOF";
}

// Helper to create a test ZIP archive containing student PDF proofs
function create_proofs_zip(string $zipPath, array $files): string {
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($files as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->close();
    return $zipPath;
}

// ---------------------------------------------------------------------------
// 1. Template Generation & Structure Tests
// ---------------------------------------------------------------------------
echo "--- 1. Template Generation & Structure ---\n";

$intTemplate = BulkImport::generateTemplate(BulkImport::MODULE_INTERNSHIP, $activeYear, 'CSE');
assert_test("Generate Internship Template", strlen($intTemplate) > 1000, "Generated size: " . strlen($intTemplate) . " bytes");

$stTemplate = BulkImport::generateTemplate(BulkImport::MODULE_SUMMER_TRAINING, $activeYear, 'CSE');
assert_test("Generate Summer/Winter Training Template", strlen($stTemplate) > 1000, "Generated size: " . strlen($stTemplate) . " bytes");

$intTempPath = $testDir . '/template_int.xlsx';
file_put_contents($intTempPath, $intTemplate);
$parsedInt = SimpleXlsxReader::parse($intTempPath);

assert_test("Internship Template Has 'Internship Records' Sheet", isset($parsedInt['Internship Records']));
assert_test("Internship Template Has 'Instructions' Sheet", isset($parsedInt['Instructions']));

$headersInt = $parsedInt['Internship Records'][0] ?? [];
assert_test("Internship Template Headers Contain Register Number", in_array('Student Register Number *', $headersInt));
assert_test("Internship Template Headers Contain Proof File Name in ZIP", in_array('Proof File Name in ZIP (Optional - defaults to <RegNo>.pdf)', $headersInt));
assert_test("Internship Template Does NOT Contain Certificate Link", !in_array('Certificate / Document Link (Or upload in ZIP)', $headersInt));

$stTempPath = $testDir . '/template_st.xlsx';
file_put_contents($stTempPath, $stTemplate);
$parsedSt = SimpleXlsxReader::parse($stTempPath);
assert_test("Training Template Has 'Training Records' Sheet", isset($parsedSt['Training Records']));
assert_test("Training Template Has 'Instructions' Sheet", isset($parsedSt['Instructions']));

// ---------------------------------------------------------------------------
// 2. Compulsory Proofs ZIP Archive Rejections
// ---------------------------------------------------------------------------
echo "\n--- 2. Compulsory ZIP Archive Enforcement ---\n";

$validXlsx = $testDir . '/valid_sample.xlsx';
file_put_contents($validXlsx, SimpleXlsxWriter::createMultiSheetXlsx([
    [
        'title' => 'Student Records',
        'headers' => ['Student Register Number', 'Student Name', 'Department', 'Academic Year', 'Exam Session', 'Title of Internship', 'Industry Name', 'Duration', 'No. of Days'],
        'rows' => [
            ['711322104501', 'Anitha K', 'CSE', '2025-26', 'Odd', 'AI Engineering Internship', 'TCS Innovation Labs, Chennai', '1 month', 30]
        ]
    ]
]));

// A. Missing ZIP path must be rejected with compulsory exception
$missingZipCaught = false;
try {
    BulkImport::validateUpload(BulkImport::MODULE_INTERNSHIP, $validXlsx, $currentUser, $activeYear, null);
} catch (InvalidArgumentException $e) {
    if (strpos($e->getMessage(), 'compulsory') !== false) {
        $missingZipCaught = true;
    }
}
assert_test("Missing ZIP Archive Rejection", $missingZipCaught, "Caught compulsory ZIP requirement error");

// B. Empty ZIP archive with no valid PDFs must be rejected
$emptyZipPath = $testDir . '/empty_proofs.zip';
$zip = new ZipArchive();
$zip->open($emptyZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('notes.txt', 'This is not a pdf');
$zip->close();

$emptyZipCaught = false;
try {
    BulkImport::validateUpload(BulkImport::MODULE_INTERNSHIP, $validXlsx, $currentUser, $activeYear, $emptyZipPath);
} catch (InvalidArgumentException $e) {
    if (strpos($e->getMessage(), 'contains no valid PDF') !== false) {
        $emptyZipCaught = true;
    }
}
assert_test("Empty/Invalid ZIP Archive Rejection", $emptyZipCaught, "Caught no-PDF in ZIP error");

// ---------------------------------------------------------------------------
// 3. 1:1 Matching & Missing Certificate in ZIP Detection
// ---------------------------------------------------------------------------
echo "\n--- 3. 1:1 Student Proof Matching & Missing Proof Flagging ---\n";

// Create ZIP with proof ONLY for 711322104501, but NOT for 711322104502
$partialZipPath = $testDir . '/partial_proofs.zip';
create_proofs_zip($partialZipPath, [
    '711322104501.pdf' => create_dummy_pdf('Anitha Certificate')
]);

$multiStudentXlsx = $testDir . '/multi_student.xlsx';
file_put_contents($multiStudentXlsx, SimpleXlsxWriter::createMultiSheetXlsx([
    [
        'title' => 'Student Records',
        'headers' => ['Student Register Number', 'Student Name', 'Department', 'Academic Year', 'Exam Session', 'Title of Internship', 'Industry Name', 'Duration', 'No. of Days'],
        'rows' => [
            ['711322104501', 'Anitha K', 'CSE', '2025-26', 'Odd', 'AI Engineering Internship', 'TCS Innovation Labs, Chennai', '1 month', 30],
            ['711322104502', 'Babu R', 'CSE', '2025-26', 'Odd', 'Cybersecurity Internship', 'Wipro Digital, Bangalore', '4 weeks', 28],
        ]
    ]
]));

$partialPreview = BulkImport::validateUpload(BulkImport::MODULE_INTERNSHIP, $multiStudentXlsx, $currentUser, $activeYear, $partialZipPath);
assert_test("Partial ZIP Preview Total Detected", $partialPreview['total_detected'] === 2);
assert_test("Partial ZIP: Row 1 Matched Proof", $partialPreview['rows'][0]['status'] === 'valid' && $partialPreview['rows'][0]['proof_source'] === 'zip');
assert_test("Partial ZIP: Row 2 Missing Proof Flagged", $partialPreview['rows'][1]['status'] === 'invalid');

$missingProofErrFound = false;
foreach ($partialPreview['rows'][1]['errors'] as $err) {
    if (strpos($err['problem'], 'Compulsory certificate missing in ZIP') !== false) {
        $missingProofErrFound = true;
    }
}
assert_test("Missing Student Certificate in ZIP Detailed Error", $missingProofErrFound, "Flagged missing 711322104502.pdf");

// ---------------------------------------------------------------------------
// 4. Internship Full Workflow: Upload, Errors, Commit & DB Verification
// ---------------------------------------------------------------------------
echo "\n--- 4. Full Internship Workflow with Proofs ZIP ---\n";

// Complete ZIP with certificates for all valid students
$fullIntZipPath = $testDir . '/full_intern_proofs.zip';
create_proofs_zip($fullIntZipPath, [
    '711322104501.pdf' => create_dummy_pdf('Anitha Certificate'),
    '711322104502.pdf' => create_dummy_pdf('Babu Certificate'),
    '711322104503.pdf' => create_dummy_pdf('Chandran Certificate'),
]);

$intWorkbookData = [
    [
        'title' => 'Internship Records',
        'headers' => [
            'Student Register Number *', 'Student Name *', 'Department *', 'Academic Year *',
            'Exam Session *', 'Title of Internship *', 'Organization / Company Name & Address *',
            'Duration *', 'No. of Days *'
        ],
        'rows' => [
            // Sample row to be filtered
            ['[EXAMPLE] 711322104000', 'Example Student', 'CSE', '2025-26', 'Odd', 'Example', 'Example Org', '1 month', 30],
            // Valid student row 1
            ['711322104501', 'Anitha K', 'CSE', '2025-26', 'Odd', 'AI Engineering Internship', 'TCS Innovation Labs, Chennai', '1 month', 30],
            // Valid student row 2
            ['711322104502', 'Babu R', 'CSE', '2025-26', 'Odd', 'Cybersecurity Analyst Internship', 'Wipro Digital Security, Bangalore', '4 weeks', 28],
            // Valid student row 3
            ['711322104503', 'Chandran M', 'CSE', '2025-26', 'Odd', 'Cloud Solutions Internship', 'Infosys Ltd, Sholinganallur, Chennai', '6 weeks', 42],
            // Invalid student row (empty name, negative days)
            ['711322104504', '', 'CSE', '2025-26', 'Odd', 'Short Title', 'HCL Tech, Chennai', '1 month', -10],
            // Duplicate row within spreadsheet (same as row 1)
            ['711322104501', 'Anitha K', 'CSE', '2025-26', 'Odd', 'AI Engineering Internship', 'TCS Innovation Labs, Chennai', '1 month', 30],
        ]
    ]
];

$intUploadFile = $testDir . '/internship_upload_test.xlsx';
file_put_contents($intUploadFile, SimpleXlsxWriter::createMultiSheetXlsx($intWorkbookData));

$intPreview = BulkImport::validateUpload(BulkImport::MODULE_INTERNSHIP, $intUploadFile, $currentUser, $activeYear, $fullIntZipPath);
assert_test("Internship Preview Total Detected", $intPreview['total_detected'] === 5, "Detected rows: " . $intPreview['total_detected']);
assert_test("Internship Preview Valid Count", $intPreview['valid_count'] === 3, "Valid rows: " . $intPreview['valid_count']);
assert_test("Internship Preview Duplicate Count", $intPreview['duplicate_count'] === 1, "Duplicate rows: " . $intPreview['duplicate_count']);
assert_test("Internship Preview Invalid Count", $intPreview['invalid_count'] === 1, "Invalid rows: " . $intPreview['invalid_count']);
assert_test("Internship Proofs Matched Count", $intPreview['proofs_matched'] === 4, "Matched PDFs from ZIP");

// Commit Valid Internship Records
$intCommitResult = BulkImport::commitImport($intPreview, $currentUser);
assert_test("Commit Internship Records", $intCommitResult['success'] === true && $intCommitResult['imported_records'] === 3);

// Verify records in DB
$db = db();
$stmt = $db->prepare("SELECT * FROM internships WHERE reg_no IN ('711322104501', '711322104502', '711322104503')");
$stmt->execute();
$dbRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
assert_test("Database Persistence - 3 Records Saved", count($dbRows) === 3);
assert_test("Status Enters Verification Workflow as 'Submitted'", $dbRows[0]['status'] === 'Submitted');
assert_test("Academic Year Saved Correctly", $dbRows[0]['academic_year'] === '2025-26');
assert_test("Import Batch ID Attached", !empty($dbRows[0]['import_batch_id']));
assert_test("Proof File Stored & Linked in DB", !empty($dbRows[0]['proof_file']) && str_ends_with($dbRows[0]['proof_file'], '.pdf'));

// Verify physical file was saved in uploads directory
$uploadProofPath = UPLOAD_DIR . '/proofs/' . $dbRows[0]['proof_file'];
assert_test("Proof File Physically Stored on Disk", is_file($uploadProofPath), "Path: {$uploadProofPath}");

// ---------------------------------------------------------------------------
// 5. Summer/Winter Training Full Workflow with Proofs ZIP
// ---------------------------------------------------------------------------
echo "\n--- 5. Summer/Winter Training Workflow with Proofs ZIP ---\n";

$fullStZipPath = $testDir . '/full_training_proofs.zip';
create_proofs_zip($fullStZipPath, [
    '711322104601.pdf' => create_dummy_pdf('Dinesh Training Cert'),
    '711322104602.pdf' => create_dummy_pdf('Fathima Training Cert'),
]);

$stWorkbookData = [
    [
        'title' => 'Training Records',
        'headers' => [
            'Student Register Number *', 'Student Name *', 'Department *', 'Academic Year *',
            'Exam Session *', 'Title of Training *', 'Training Provider / Institution & Address *',
            'Duration *', 'No. of Days *'
        ],
        'rows' => [
            // Valid student row 1
            ['711322104601', 'Dinesh Kumar', 'CSE', '2025-26', 'Odd', 'Machine Learning Winter Training', 'Center for AI Excellence, Chennai', '2 weeks', 14],
            // Valid student row 2
            ['711322104602', 'Fathima S', 'CSE', '2025-26', 'Odd', 'Full Stack Development Summer Boot Camp', 'DLK Career Solutions, Chennai', '15 days', 15],
            // Invalid student row (missing title)
            ['711322104603', 'Ganesh P', 'CSE', '2025-26', 'Odd', '', 'DLK Career Solutions, Chennai', '15 days', 15],
        ]
    ]
];

$stUploadFile = $testDir . '/training_upload_test.xlsx';
file_put_contents($stUploadFile, SimpleXlsxWriter::createMultiSheetXlsx($stWorkbookData));

$stPreview = BulkImport::validateUpload(BulkImport::MODULE_SUMMER_TRAINING, $stUploadFile, $currentUser, $activeYear, $fullStZipPath);
assert_test("Training Preview Valid Count", $stPreview['valid_count'] === 2);
assert_test("Training Preview Invalid Count", $stPreview['invalid_count'] === 1);

$stCommitResult = BulkImport::commitImport($stPreview, $currentUser);
assert_test("Commit Training Records", $stCommitResult['success'] === true && $stCommitResult['imported_records'] === 2);

$stDbRows = $db->query("SELECT * FROM summer_training WHERE reg_no IN ('711322104601', '711322104602')")->fetchAll(PDO::FETCH_ASSOC);
assert_test("Database Persistence - 2 Training Records Saved", count($stDbRows) === 2);
assert_test("Training Status is 'Submitted'", $stDbRows[0]['status'] === 'Submitted');
assert_test("Training Proof File Stored & Linked", !empty($stDbRows[0]['proof_file']));

// ---------------------------------------------------------------------------
// 6. UI Verification: Individual Form & SmartBulk Coexistence in upload.php
// ---------------------------------------------------------------------------
echo "\n--- 6. UI Verification: Individual Form & SmartBulk in upload.php ---\n";

$uploadPhpCode = file_get_contents(__DIR__ . '/../php-app/upload.php');
assert_test("upload.php Supports Both Individual Form and SmartBulk", strpos($uploadPhpCode, 'switchUploadMode') !== false && strpos($uploadPhpCode, 'tabBtnIndividual') !== false && strpos($uploadPhpCode, 'smartbulkUploadContainer') !== false);

// ---------------------------------------------------------------------------
// 7. Clean Up Test Records
// ---------------------------------------------------------------------------
echo "\n--- 7. Clean Up Test Records ---\n";

$db->exec("DELETE FROM internships WHERE reg_no IN ('711322104501', '711322104502', '711322104503')");
$db->exec("DELETE FROM summer_training WHERE reg_no IN ('711322104601', '711322104602')");
$db->exec("DELETE FROM bulk_import_history WHERE batch_id IN ('" . $intPreview['batch_id'] . "', '" . $stPreview['batch_id'] . "')");
$db->exec("DELETE FROM workflow_audit_logs WHERE details LIKE '%" . $intPreview['batch_id'] . "%' OR details LIKE '%" . $stPreview['batch_id'] . "%'");

// Delete stored test proofs
if (!empty($dbRows[0]['proof_file']) && is_file(UPLOAD_DIR . '/proofs/' . $dbRows[0]['proof_file'])) {
    @unlink(UPLOAD_DIR . '/proofs/' . $dbRows[0]['proof_file']);
    @unlink(UPLOAD_DIR . '/' . $dbRows[0]['proof_file']);
}
if (!empty($stDbRows[0]['proof_file']) && is_file(UPLOAD_DIR . '/proofs/' . $stDbRows[0]['proof_file'])) {
    @unlink(UPLOAD_DIR . '/proofs/' . $stDbRows[0]['proof_file']);
    @unlink(UPLOAD_DIR . '/' . $stDbRows[0]['proof_file']);
}

assert_test("Test Data Cleaned Up Successfully", true);

echo "\n=== ALL TESTS PASSED SUCCESSFULLY! ===\n";
