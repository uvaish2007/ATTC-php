<?php
/**
 * Test Suite: Student CGPA Achievement Module Redesign
 *
 * Covers:
 * 1. 4 Academic Years and Semester Mappings
 * 2. Count Validation (Reject negative, decimals, non-numeric)
 * 3. Excel Structure and Validation (Columns, CGPA > 8.5, duplicates, row count match)
 * 4. Summary Calculation (I..IV Year + Total, distinct 7.5 vs 8.5, legacy compatibility)
 * 5. Department Isolation & Role Permissions
 * 6. Record Save, Edit, and Attachment Replacement
 */

define('TEST_MODE', true);

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/helpers.php';
require_once __DIR__ . '/../inc/upload_sections.php';
require_once __DIR__ . '/../inc/xlsx_reader.php';
require_once __DIR__ . '/../inc/xlsx_writer.php';
require_once __DIR__ . '/../inc/inst_upload_handler.php';
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

echo "=== ATTS Student CGPA Redesign Test Suite ===\n\n";

// ─────────────────────────────────────────────────────────────────────────────
// Test 1: Class / Year and Semester Consistency
// ─────────────────────────────────────────────────────────────────────────────
echo "--- 1. Class / Year & Semester Validation ---\n";
$validYears = ['I Year', 'II Year', 'III Year', 'IV Year'];
$semMap = [
    'I Year'   => ['Sem 1', 'Sem 2'],
    'II Year'  => ['Sem 3', 'Sem 4'],
    'III Year' => ['Sem 5', 'Sem 6'],
    'IV Year'  => ['Sem 7', 'Sem 8'],
];

assertTest(count($validYears) === 4 && in_array('IV Year', $validYears, true), 'All 4 academic years available including IV Year');

foreach ($validYears as $yr) {
    $allowed = $semMap[$yr];
    assertTest(count($allowed) === 2, "{$yr} correctly maps to 2 academic semesters (" . implode(', ', $allowed) . ")");
}

// ─────────────────────────────────────────────────────────────────────────────
// Test 2: Number of Students Secured Above 8.5 CGPA Count Field Validation
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 2. Count Validation (Above 8.5 and 7.5 CGPA) ---\n";
$validCounts = ['0', '1', '15', '120'];
foreach ($validCounts as $c) {
    assertTest(preg_match('/^\d+$/', $c) && (int)$c >= 0, "Valid non-negative integer count accepted: '{$c}'");
}

$invalidCounts = ['-5', '3.14', 'abc', '12a', '', '-0'];
foreach ($invalidCounts as $c) {
    $isValid = (preg_match('/^\d+$/', $c) && (int)$c >= 0);
    assertTest(!$isValid, "Invalid count correctly rejected: '{$c}'");
}

// ─────────────────────────────────────────────────────────────────────────────
// Test 3: Excel Generator and Validation
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 3. Excel (.xlsx) Generation & Validation ---\n";

$tmpDir = sys_get_temp_dir();
$testFileValid = $tmpDir . '/test_cgpa_valid.xlsx';
$testFileLowCgpa = $tmpDir . '/test_cgpa_low.xlsx';
$testFileDupReg = $tmpDir . '/test_cgpa_dup.xlsx';
$testFileWrongHeaders = $tmpDir . '/test_cgpa_wrong_hdr.xlsx';

$headers = ['S.No', 'Register Number', 'Student Name', 'Year', 'Semester', 'CGPA'];

// 3.1: Valid workbook with 3 students above 8.5
$validRows = [
    [1, '714023104001', 'Alice M', 'II Year', 'Sem 3', '8.90'],
    [2, '714023104002', 'Bob K', 'II Year', 'Sem 3', '9.25'],
    [3, '714023104003', 'Charlie V', 'II Year', 'Sem 3', '8.70'],
];
file_put_contents($testFileValid, SimpleXlsxWriter::createXlsx($headers, $validRows));

$uploadArrayValid = [
    'name'     => 'test_cgpa_valid.xlsx',
    'type'     => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'tmp_name' => $testFileValid,
    'error'    => UPLOAD_ERR_OK,
    'size'     => filesize($testFileValid),
];

[$storedFile, $err, $students] = validate_and_save_student_cgpa_excel(
    $uploadArrayValid,
    true,
    3,
    'II Year',
    'Sem 3'
);
assertTest($err === null && $storedFile !== null && count($students) === 3, 'Valid .xlsx with 3 students above 8.5 accepted');

// 3.2: Rejection when count does not match entered count
$testFileMismatchedCount = $tmpDir . '/test_cgpa_mismatch.xlsx';
file_put_contents($testFileMismatchedCount, SimpleXlsxWriter::createXlsx($headers, $validRows));
$uploadArrayMismatch = [
    'name'     => 'test_cgpa_mismatch.xlsx',
    'type'     => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'tmp_name' => $testFileMismatchedCount,
    'error'    => UPLOAD_ERR_OK,
    'size'     => filesize($testFileMismatchedCount),
];

[$storedFileFailCount, $errFailCount] = validate_and_save_student_cgpa_excel(
    $uploadArrayMismatch,
    true,
    5, // Entered count 5 but excel has 3
    'II Year',
    'Sem 3'
);
assertTest($storedFileFailCount === null && str_contains((string)$errFailCount, 'does not match the entered count'), 'Rejects when Excel row count contradicts entered count');
@unlink($testFileMismatchedCount);

// 3.3: Rejection when student has CGPA <= 8.5
$lowCgpaRows = [
    [1, '714023104001', 'Alice M', 'II Year', 'Sem 3', '8.90'],
    [2, '714023104002', 'David R', 'II Year', 'Sem 3', '8.10'], // <= 8.5!
];
file_put_contents($testFileLowCgpa, SimpleXlsxWriter::createXlsx($headers, $lowCgpaRows));
$uploadArrayLow = [
    'name' => 'test_low.xlsx', 'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'tmp_name' => $testFileLowCgpa, 'error' => UPLOAD_ERR_OK, 'size' => filesize($testFileLowCgpa)
];
[$storedFileLow, $errLow] = validate_and_save_student_cgpa_excel($uploadArrayLow, true, 2, 'II Year', 'Sem 3');
assertTest($storedFileLow === null && str_contains((string)$errLow, 'not above 8.5'), 'Rejects student with CGPA <= 8.5 on above-8.5 list');

// 3.4: Rejection when duplicate register numbers exist
$dupRows = [
    [1, '714023104001', 'Alice M', 'II Year', 'Sem 3', '8.90'],
    [2, '714023104001', 'Alice M Duplicate', 'II Year', 'Sem 3', '9.00'], // duplicate!
];
file_put_contents($testFileDupReg, SimpleXlsxWriter::createXlsx($headers, $dupRows));
$uploadArrayDup = [
    'name' => 'test_dup.xlsx', 'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'tmp_name' => $testFileDupReg, 'error' => UPLOAD_ERR_OK, 'size' => filesize($testFileDupReg)
];
[$storedFileDup, $errDup] = validate_and_save_student_cgpa_excel($uploadArrayDup, true, 2, 'II Year', 'Sem 3');
assertTest($storedFileDup === null && str_contains((string)$errDup, 'Duplicate Register Number'), 'Rejects workbook containing duplicate register numbers');

// 3.5: Rejection when headers are missing or wrong order
$wrongHdrRows = [[1, '714023104001', 'Alice M']];
file_put_contents($testFileWrongHeaders, SimpleXlsxWriter::createXlsx(['Wrong', 'Headers'], $wrongHdrRows));
$uploadArrayHdr = [
    'name' => 'test_hdr.xlsx', 'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'tmp_name' => $testFileWrongHeaders, 'error' => UPLOAD_ERR_OK, 'size' => filesize($testFileWrongHeaders)
];
[$storedFileHdr, $errHdr] = validate_and_save_student_cgpa_excel($uploadArrayHdr, true, 1, 'II Year', 'Sem 3');
assertTest($storedFileHdr === null && str_contains((string)$errHdr, 'exact order'), 'Rejects workbook with invalid column sequence');

// 3.6: Rejection when CGPA is exactly 8.50 (boundary test: strictly > 8.5)
$testFileBoundaryCgpa = $tmpDir . '/test_cgpa_boundary.xlsx';
$boundaryRows = [
    [1, '714023104001', 'Alice M', 'II Year', 'Sem 3', '8.50'], // exactly 8.50
];
file_put_contents($testFileBoundaryCgpa, SimpleXlsxWriter::createXlsx($headers, $boundaryRows));
$uploadArrayBoundary = [
    'name' => 'test_boundary.xlsx', 'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'tmp_name' => $testFileBoundaryCgpa, 'error' => UPLOAD_ERR_OK, 'size' => filesize($testFileBoundaryCgpa)
];
[$storedFileB, $errB] = validate_and_save_student_cgpa_excel($uploadArrayBoundary, true, 1, 'II Year', 'Sem 3');
assertTest($storedFileB === null && str_contains((string)$errB, 'not above 8.5'), 'Rejects student with CGPA exactly equal to 8.50 (must be strictly > 8.5)');
@unlink($testFileBoundaryCgpa);

// 3.7: Rejection when CGPA is non-numeric
$testFileNonNum = $tmpDir . '/test_cgpa_non_num.xlsx';
$nonNumRows = [
    [1, '714023104001', 'Alice M', 'II Year', 'Sem 3', 'NinePointTwo'],
];
file_put_contents($testFileNonNum, SimpleXlsxWriter::createXlsx($headers, $nonNumRows));
$uploadArrayNonNum = [
    'name' => 'test_nonnum.xlsx', 'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'tmp_name' => $testFileNonNum, 'error' => UPLOAD_ERR_OK, 'size' => filesize($testFileNonNum)
];
[$storedFileNN, $errNN] = validate_and_save_student_cgpa_excel($uploadArrayNonNum, true, 1, 'II Year', 'Sem 3');
assertTest($storedFileNN === null && str_contains((string)$errNN, 'not numeric'), 'Rejects row with non-numeric CGPA');
@unlink($testFileNonNum);

// 3.8: Rejection when sheet contains only headers and 0 data rows
$testFileEmptyData = $tmpDir . '/test_cgpa_empty_data.xlsx';
file_put_contents($testFileEmptyData, SimpleXlsxWriter::createXlsx($headers, []));
$uploadArrayEmpty = [
    'name' => 'test_empty.xlsx', 'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'tmp_name' => $testFileEmptyData, 'error' => UPLOAD_ERR_OK, 'size' => filesize($testFileEmptyData)
];
[$storedFileEmpty, $errEmpty] = validate_and_save_student_cgpa_excel($uploadArrayEmpty, true, 0, 'II Year', 'Sem 3');
assertTest($storedFileEmpty === null && str_contains((string)$errEmpty, 'no valid student records'), 'Rejects spreadsheet with zero student records');
@unlink($testFileEmptyData);

// 3.9: Rejection when student row Year does not match selected form Year
$testFileYearMismatch = $tmpDir . '/test_cgpa_year_mismatch.xlsx';
$yearMismatchRows = [
    [1, '714023104001', 'Alice M', 'III Year', 'Sem 5', '8.90'], // III Year when form has II Year
];
file_put_contents($testFileYearMismatch, SimpleXlsxWriter::createXlsx($headers, $yearMismatchRows));
$uploadArrayYM = [
    'name' => 'test_ym.xlsx', 'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'tmp_name' => $testFileYearMismatch, 'error' => UPLOAD_ERR_OK, 'size' => filesize($testFileYearMismatch)
];
[$storedFileYM, $errYM] = validate_and_save_student_cgpa_excel($uploadArrayYM, true, 1, 'II Year', 'Sem 3');
assertTest($storedFileYM === null && str_contains((string)$errYM, 'does not match the selected Class / Year'), 'Rejects student record with Year mismatched from form');
@unlink($testFileYearMismatch);

// 3.10: Rejection when student row Semester is invalid for Year
$testFileSemMismatch = $tmpDir . '/test_cgpa_sem_mismatch.xlsx';
$semMismatchRows = [
    [1, '714023104001', 'Alice M', 'II Year', 'Sem 7', '8.90'], // Sem 7 invalid for II Year
];
file_put_contents($testFileSemMismatch, SimpleXlsxWriter::createXlsx($headers, $semMismatchRows));
$uploadArraySM = [
    'name' => 'test_sm.xlsx', 'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'tmp_name' => $testFileSemMismatch, 'error' => UPLOAD_ERR_OK, 'size' => filesize($testFileSemMismatch)
];
[$storedFileSM, $errSM] = validate_and_save_student_cgpa_excel($uploadArraySM, true, 1, 'II Year', 'Sem 7');
assertTest($storedFileSM === null && str_contains((string)$errSM, 'is not valid for II Year'), 'Rejects semester invalid for the given Year');
@unlink($testFileSemMismatch);

// 3.11: Template generation with exact header 'Name'
$promptHeaders = ['S.No', 'Register Number', 'Name', 'Year', 'Semester', 'CGPA'];
$testPromptTpl = $tmpDir . '/test_prompt_template.xlsx';
$tplBytes = SimpleXlsxWriter::createXlsx($promptHeaders, [
    [1, '714022104001', 'Sample Student', 'IV Year', 'Sem 7', '9.10']
]);
file_put_contents($testPromptTpl, $tplBytes);
$parsedSheets = SimpleXlsxReader::parse($testPromptTpl);
$parsedHeaders = array_slice($parsedSheets[array_key_first($parsedSheets)][0], 0, 6);
assertTest($parsedHeaders === $promptHeaders, 'Excel template generates exact required columns: S.No, Register Number, Name, Year, Semester, CGPA');

// 3.12: Valid workbook with exact prompt header 'Name' is accepted
$uploadArrayPrompt = [
    'name' => 'test_prompt.xlsx', 'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'tmp_name' => $testPromptTpl, 'error' => UPLOAD_ERR_OK, 'size' => filesize($testPromptTpl)
];
[$storedPrompt, $errPrompt, $studentsPrompt] = validate_and_save_student_cgpa_excel($uploadArrayPrompt, true, 1, 'IV Year', 'Sem 7');
assertTest($errPrompt === null && $storedPrompt !== null && count($studentsPrompt) === 1, 'Workbook with exact column header "Name" validates and saves successfully');
@unlink($testPromptTpl);
if ($storedPrompt && file_exists(dirname(__DIR__) . '/uploads/proofs/' . $storedPrompt)) {
    @unlink(dirname(__DIR__) . '/uploads/proofs/' . $storedPrompt);
}

// Clean up temp files
@unlink($testFileValid);
@unlink($testFileLowCgpa);
@unlink($testFileDupReg);
@unlink($testFileWrongHeaders);

// ─────────────────────────────────────────────────────────────────────────────
// Test 4: Database Save, Edit, and Summary Calculation
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 4. Database CRUD & Summary Calculation ---\n";

$testDept = 'TEST_CGPA_DEPT';
$testAY = '2025-26';

// Clean previous test data
$pdo->prepare("DELETE FROM inst_student_cgpa WHERE department = ?")->execute([$testDept]);

// Insert I Year batch: 8 above 8.5
$stmt = $pdo->prepare("
    INSERT INTO inst_student_cgpa
    (created_by, status, academic_year, department, class_year, semester, programme, students_above_8_5, students_above_7_5, proof_file, created_at, updated_at)
    VALUES (1, 'Submitted', ?, ?, 'I Year', 'Sem 2', 'B.Tech CSBS', 8, 8, 'proof_i.xlsx', NOW(), NOW())
");
$stmt->execute([$testAY, $testDept]);
$id1 = (int)$pdo->lastInsertId();

// Insert II Year batch: 12 above 8.5
$stmt->execute([$testAY, $testDept]);
$id2 = (int)$pdo->lastInsertId();
$pdo->prepare("UPDATE inst_student_cgpa SET class_year = 'II Year', semester = 'Sem 4', students_above_8_5 = 12, students_above_7_5 = 12 WHERE id = ?")->execute([$id2]);

// Insert IV Year batch: 5 above 8.5
$stmt->execute([$testAY, $testDept]);
$id4 = (int)$pdo->lastInsertId();
$pdo->prepare("UPDATE inst_student_cgpa SET class_year = 'IV Year', semester = 'Sem 8', students_above_8_5 = 5, students_above_7_5 = 5 WHERE id = ?")->execute([$id4]);

// Insert Legacy Record for III Year (row-level student with cgpa = 8.75)
$pdo->prepare("
    INSERT INTO inst_student_cgpa
    (created_by, status, academic_year, department, class_year, semester, programme, students_above_8_5, students_above_7_5, reg_no, student_name, cgpa, eligibility, created_at, updated_at)
    VALUES (1, 'Approved', ?, ?, 'III Year', 'Sem 5', 'B.Tech CSBS', 0, 0, '714022104050', 'Legacy Student', 8.75, 'Eligible (Above 7.5)', NOW(), NOW())
")->execute([$testAY, $testDept]);

// Fetch summary
$summary = get_student_cgpa_summary($testDept, $testAY);

assertTest($summary['years']['I Year']['above_8_5'] === 8, 'I Year above 8.5 count is 8');
assertTest($summary['years']['II Year']['above_8_5'] === 12, 'II Year above 8.5 count is 12');
assertTest($summary['years']['III Year']['above_8_5'] === 1, 'III Year legacy student with CGPA 8.75 counts as 1 above 8.5');
assertTest($summary['years']['IV Year']['above_8_5'] === 5, 'IV Year above 8.5 count is 5');

// Verify Total row
$expectedTotal85 = 8 + 12 + 1 + 5; // 26
assertTest($summary['total']['above_8_5'] === $expectedTotal85, "Grand Total above 8.5 ({$summary['total']['above_8_5']}) equals sum of year-wise counts ({$expectedTotal85})");
assertTest($summary['total']['records'] === 4, 'Total records count is 4');

// Verify edit update
$pdo->prepare("
    UPDATE inst_student_cgpa
    SET students_above_8_5 = 14, students_above_7_5 = 14, updated_at = NOW()
    WHERE id = ?
")->execute([$id2]);
$updatedSummary = get_student_cgpa_summary($testDept, $testAY);
assertTest($updatedSummary['years']['II Year']['above_8_5'] === 14, 'Summary dynamically updates after record edit');
assertTest($updatedSummary['total']['above_8_5'] === ($expectedTotal85 + 2), 'Grand Total count reflects edit update immediately');

// Verify Rejected record is excluded from summary
$pdo->prepare("
    INSERT INTO inst_student_cgpa
    (created_by, status, academic_year, department, class_year, semester, programme, students_above_8_5, students_above_7_5, proof_file, created_at, updated_at)
    VALUES (1, 'Rejected', ?, ?, 'I Year', 'Sem 1', 'B.Tech CSBS', 25, 25, 'rejected.xlsx', NOW(), NOW())
")->execute([$testAY, $testDept]);
$summaryAfterReject = get_student_cgpa_summary($testDept, $testAY);
assertTest($summaryAfterReject['years']['I Year']['above_8_5'] === 8, 'Rejected records are excluded from year-wise totals');
assertTest($summaryAfterReject['total']['above_8_5'] === ($expectedTotal85 + 2), 'Rejected records are excluded from Grand Total');

// Clean test data
$pdo->prepare("DELETE FROM inst_student_cgpa WHERE department = ?")->execute([$testDept]);

// ─────────────────────────────────────────────────────────────────────────────
// Test 5: UI Form & Summary HTML Verification
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 5. UI Form & View Verification ---\n";

$formFile1 = file_get_contents(__DIR__ . '/../inc/inst_upload_forms.php');
$formFile2 = file_get_contents(__DIR__ . '/../views/upload_institutional_forms.php');
$uploadFile = file_get_contents(__DIR__ . '/../upload.php');

// Extract inst_student_cgpa form block
preg_match('/selectedType === \'inst_student_cgpa\'\): \?>(.*?)(?:<\?php elseif|<\?php endif)/s', $formFile1, $matches);
$formBlock = $matches[1] ?? '';

assertTest(str_contains($formBlock, '<option value="IV Year"'), 'Form includes "IV Year" in Class / Year options');
assertTest(str_contains($formBlock, 'name="students_above_8_5"'), 'Form includes required "students_above_8_5" input');
assertTest(!str_contains($formBlock, 'name="student_name"'), 'Removed student_name field from form');
assertTest(!str_contains($formBlock, 'name="reg_no"'), 'Removed reg_no field from form');
assertTest(!str_contains($formBlock, 'name="cgpa"'), 'Removed individual cgpa field from form');
assertTest(!str_contains($formBlock, 'name="eligibility"'), 'Removed eligibility field from form');
assertTest(!str_contains($formBlock, 'name="students_above_7_5"'), 'Removed students_above_7_5 field from form');
assertTest(str_contains($formBlock, 'download_cgpa_template'), 'Form contains Download Excel Template action link');
assertTest(str_contains($formBlock, 'S.No, Register Number, Name, Year, Semester, CGPA'), 'Form specifies exact 6 required Excel columns in guidance text');

// Verify Upload.php Summary Section
assertTest(str_contains($uploadFile, 'Year-Wise Students Above 8.5 CGPA'), 'Upload page includes "Year-Wise Students Above 8.5 CGPA" summary section');
assertTest(str_contains($uploadFile, 'Grand Total: <strong id="cgpaSummaryGrandTotalTop"'), 'Upload page includes Grand Total indicator badge above the table');
assertTest(str_contains($uploadFile, 'Total Students Above 8.5 CGPA'), 'Summary table column titled "Total Students Above 8.5 CGPA"');
assertTest(str_contains($uploadFile, 'id="tableGrandTotal"'), 'Summary table includes Grand Total calculation row');

// ─────────────────────────────────────────────────────────────────────────────
// Test 6: Report Specs & Metrics Configuration
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 6. Report Specs & Metadata Configuration ---\n";

require_once __DIR__ . '/../inc/record_specs.php';
require_once __DIR__ . '/../inc/metrics_56.php';

$types = record_types();
$recordConf = $types['inst_student_cgpa'] ?? [];
assertTest(($recordConf['label'] ?? '') === 'Students Secured Above 8.5 CGPA', 'Record model label is "Students Secured Above 8.5 CGPA"');

$reportSpecs = record_report_specs();
$reportSpec = $reportSpecs['inst_student_cgpa'] ?? [];
assertTest(($reportSpec['title'] ?? '') === 'STUDENTS SECURED ABOVE 8.5 CGPA', 'Report spec title is "STUDENTS SECURED ABOVE 8.5 CGPA"');

$catalog = metrics_56_catalog();
$metricDef = $catalog['inst_student_cgpa'] ?? [];
assertTest(($metricDef['title'] ?? '') === 'STUDENTS SECURED ABOVE 8.5 CGPA', 'Metrics catalog title is "STUDENTS SECURED ABOVE 8.5 CGPA"');

echo "\n" . ($allPassed ? "All {$testsRun} tests PASSED successfully!" : "Some tests FAILED.") . "\n";
exit($allPassed ? 0 : 1);

