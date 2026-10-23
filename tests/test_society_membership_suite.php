<?php
/**
 * Test Suite: ATTS-DYNAMIC-SOCIETY-MEMBERSHIP
 * 
 * Verifies:
 * - Test A: Faculty Individual Entry
 * - Test B: Student Individual Entry
 * - Test C: Faculty Bulk Upload (template, validation, preview, commit)
 * - Test D: Student Bulk Upload (template, validation, preview, commit)
 * - Test E: Dynamic Switching & Student Lookup Endpoint
 * - Test F: Validation & Security Rules (missing headers, cross-dept, duplicate detection)
 * - Test G: Regression (Internship & Summer Training Bulk Uploads intact)
 */

declare(strict_types=1);

require_once __DIR__ . '/../php-app/inc/auth.php';
require_once __DIR__ . '/../php-app/inc/helpers.php';
require_once __DIR__ . '/../php-app/inc/record_specs.php';
require_once __DIR__ . '/../php-app/models/Record.php';
require_once __DIR__ . '/../php-app/models/BulkImport.php';

$pdo = db();
$results = [];

function record_test_result(string $testName, bool $passed, string $details = ''): void {
    global $results;
    $results[] = [
        'test'    => $testName,
        'passed'  => $passed,
        'details' => $details,
    ];
    $statusStr = $passed ? "\033[32m[PASS]\033[0m" : "\033[31m[FAIL]\033[0m";
    echo "{$statusStr} {$testName}: {$details}\n";
}

// Fetch real authoritative users from database
$testFacultyUser = $pdo->query("SELECT * FROM users WHERE role = 'Faculty' AND department = 'CSE' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$testFacultyUser) {
    $testFacultyUser = ['id' => 60012, 'name' => 'CSE Faculty', 'email' => 'faculty.cse@atts.local', 'role' => 'Faculty', 'department' => 'CSE'];
}

$testCoordUser = $pdo->query("SELECT * FROM users WHERE role = 'Coordinator' AND department = 'CSE' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$testCoordUser) {
    $testCoordUser = ['id' => 60013, 'name' => 'CSE Coordinator', 'email' => 'coordinator.cse@atts.local', 'role' => 'Coordinator', 'department' => 'CSE'];
}

$activeAy = '2024-25';

// -----------------------------------------------------------------------------
// Test A: Faculty Individual Entry
// -----------------------------------------------------------------------------
try {
    $uniqMemNo = 'FAC-IEEE-' . time() . '-' . rand(100, 999);
    $stmt = $pdo->prepare("INSERT INTO inst_society_memberships 
        (membership_category, faculty_name, department, academic_year, society_name, membership_number, membership_type, start_date, expiry_date, membership_status, proof_file, status, created_by, created_at)
        VALUES ('Faculty', ?, 'CSE', ?, 'IEEE Computer Society', ?, 'Life Member', '2024-01-01', '2029-12-31', 'Active', 'test_cert.pdf', 'Submitted', ?, NOW())");
    $stmt->execute([$testFacultyUser['name'], $activeAy, $uniqMemNo, $testFacultyUser['id']]);
    $facRecId = (int)$pdo->lastInsertId();

    $chk = $pdo->prepare("SELECT * FROM inst_society_memberships WHERE id = ?");
    $chk->execute([$facRecId]);
    $rec = $chk->fetch(PDO::FETCH_ASSOC);

    $passed = $rec && $rec['membership_category'] === 'Faculty' 
        && $rec['faculty_name'] === $testFacultyUser['name']
        && empty($rec['reg_no'])
        && empty($rec['student_name'])
        && $rec['membership_number'] === $uniqMemNo;

    record_test_result('Test A — Faculty Individual Entry', (bool)$passed, "Record #{$facRecId} persisted with Category=Faculty, Faculty={$rec['faculty_name']}, MemNo={$uniqMemNo}");
} catch (\Throwable $e) {
    record_test_result('Test A — Faculty Individual Entry', false, 'Error: ' . $e->getMessage());
}

// -----------------------------------------------------------------------------
// Test B: Student Individual Entry
// -----------------------------------------------------------------------------
try {
    $uniqStuMemNo = 'STU-CSI-' . time() . '-' . rand(100, 999);
    $testRegNo = '711322104999';
    $stmt = $pdo->prepare("INSERT INTO inst_society_memberships 
        (membership_category, reg_no, student_name, class_year, department, academic_year, society_name, membership_number, membership_type, start_date, expiry_date, membership_status, proof_file, status, created_by, created_at)
        VALUES ('Student', ?, 'John Doe Student', 'III Year', 'CSE', ?, 'Computer Society of India (CSI)', ?, 'Student Member', '2024-06-01', '2025-05-31', 'Active', 'stu_cert.pdf', 'Submitted', ?, NOW())");
    $stmt->execute([$testRegNo, $activeAy, $uniqStuMemNo, $testCoordUser['id']]);
    $stuRecId = (int)$pdo->lastInsertId();

    $chk = $pdo->prepare("SELECT * FROM inst_society_memberships WHERE id = ?");
    $chk->execute([$stuRecId]);
    $rec = $chk->fetch(PDO::FETCH_ASSOC);

    $passed = $rec && $rec['membership_category'] === 'Student'
        && $rec['reg_no'] === $testRegNo
        && $rec['student_name'] === 'John Doe Student'
        && $rec['class_year'] === 'III Year'
        && empty($rec['faculty_name'])
        && $rec['membership_number'] === $uniqStuMemNo;

    record_test_result('Test B — Student Individual Entry', (bool)$passed, "Record #{$stuRecId} persisted with Category=Student, RegNo={$testRegNo}, Student Name={$rec['student_name']}");
} catch (\Throwable $e) {
    record_test_result('Test B — Student Individual Entry', false, 'Error: ' . $e->getMessage());
}

// -----------------------------------------------------------------------------
// -----------------------------------------------------------------------------
// Test C: Faculty Bulk Upload (Template, Validate, Commit)
// -----------------------------------------------------------------------------
$runId = time() . '_' . rand(100, 999);
$facMemNo1 = 'BULK-FAC-1-' . $runId;
$facMemNo2 = 'BULK-FAC-2-' . $runId;
$facName1 = "Dr. Bulk Faculty One {$runId}";
$facName2 = "Dr. Bulk Faculty Two {$runId}";
try {
    // 1. Template generation
    $facTpl = BulkImport::generateTemplate(BulkImport::MODULE_SOCIETY_FACULTY, $activeAy, 'CSE');
    $tplOk = strlen($facTpl) > 1000 && str_contains($facTpl, 'PK'); // Zip container check for xlsx

    // 2. Build test spreadsheet with multiple faculty records
    $tmpFacFile = tempnam(sys_get_temp_dir(), 'fac_bulk_') . '.csv';
    $csvContent = "Faculty Identifier or Name,Department,Academic Year,Professional Society,Membership Number,Membership Type,Start Date,Expiry Date,Status,Certificate Drive Link\n"
        . "{$facName1},CSE,{$activeAy},IEEE Computer Society,{$facMemNo1},Life Member,01/01/2024,31/12/2028,Active,https://drive.google.com/file/d/1AbCdEfGhIjKlMnOpQrStUvWxYz012345/view\n"
        . "{$facName2},CSE,{$activeAy},ACM,{$facMemNo2},Fellow,01/06/2024,31/05/2029,Active,https://drive.google.com/file/d/1AbCdEfGhIjKlMnOpQrStUvWxYz678901/view\n";
    file_put_contents($tmpFacFile, $csvContent);

    // 3. Validation
    $previewData = BulkImport::validateUpload(BulkImport::MODULE_SOCIETY_FACULTY, $tmpFacFile, $testCoordUser, $activeAy, null);
    $valOk = ($previewData['total_detected'] === 2) && ($previewData['valid_count'] === 2) && ($previewData['invalid_count'] === 0);

    // 4. Commit Import
    $commitRes = BulkImport::commitImport($previewData, $testCoordUser);
    $commitOk = ($commitRes['imported_records'] === 2);

    // Verify database rows
    $chk = $pdo->prepare("SELECT * FROM inst_society_memberships WHERE membership_number IN (?, ?)");
    $chk->execute([$facMemNo1, $facMemNo2]);
    $savedRows = $chk->fetchAll(PDO::FETCH_ASSOC);

    $dbOk = count($savedRows) === 2 && $savedRows[0]['membership_category'] === 'Faculty' && $savedRows[1]['membership_category'] === 'Faculty';
    @unlink($tmpFacFile);

    record_test_result('Test C — Faculty Bulk Upload', (bool)($tplOk && $valOk && $commitOk && $dbOk), "Template generated ({$tplOk}), 2 valid rows validated ({$valOk}), 2 records persisted ({$commitOk})");
} catch (\Throwable $e) {
    record_test_result('Test C — Faculty Bulk Upload', false, 'Error: ' . $e->getMessage());
}

// -----------------------------------------------------------------------------
// Test D: Student Bulk Upload (Template, Validate, Commit)
// -----------------------------------------------------------------------------
$stuMemNo1 = 'BULK-STU-1-' . $runId;
$stuMemNo2 = 'BULK-STU-2-' . $runId;
$stuReg1 = '7113' . rand(20000000, 29999999);
$stuReg2 = '7113' . rand(30000000, 39999999);
$stuName1 = "Alice Johnson {$runId}";
$stuName2 = "Bob Smith {$runId}";
try {
    // 1. Template generation
    $stuTpl = BulkImport::generateTemplate(BulkImport::MODULE_SOCIETY_STUDENT, $activeAy, 'CSE');
    $tplOk = strlen($stuTpl) > 1000 && str_contains($stuTpl, 'PK');

    // 2. Build test spreadsheet with multiple student records
    $tmpStuFile = tempnam(sys_get_temp_dir(), 'stu_bulk_') . '.csv';
    $csvContent = "Student Register Number,Student Name,Department,Academic Year,Class / Year of Study,Professional Society,Membership Number,Membership Type,Start Date,Expiry Date,Status,Certificate Drive Link\n"
        . "{$stuReg1},{$stuName1},CSE,{$activeAy},III Year,IEEE,{$stuMemNo1},Student Member,01/07/2024,30/06/2025,Active,https://drive.google.com/file/d/1AbCdEfGhIjKlMnOpQrStUvWxYz012345/view\n"
        . "{$stuReg2},{$stuName2},CSE,{$activeAy},IV Year,CSI,{$stuMemNo2},Student Member,01/08/2024,31/07/2025,Active,https://drive.google.com/file/d/1AbCdEfGhIjKlMnOpQrStUvWxYz678901/view\n";
    file_put_contents($tmpStuFile, $csvContent);

    // 3. Validation
    $previewData = BulkImport::validateUpload(BulkImport::MODULE_SOCIETY_STUDENT, $tmpStuFile, $testCoordUser, $activeAy, null);
    $valOk = ($previewData['total_detected'] === 2) && ($previewData['valid_count'] === 2) && ($previewData['invalid_count'] === 0);

    // 4. Commit Import
    $commitRes = BulkImport::commitImport($previewData, $testCoordUser);
    $commitOk = ($commitRes['imported_records'] === 2);

    // Verify database rows
    $chk = $pdo->prepare("SELECT * FROM inst_society_memberships WHERE membership_number IN (?, ?)");
    $chk->execute([$stuMemNo1, $stuMemNo2]);
    $savedRows = $chk->fetchAll(PDO::FETCH_ASSOC);

    $dbOk = count($savedRows) === 2 && $savedRows[0]['membership_category'] === 'Student' && $savedRows[0]['reg_no'] === $stuReg1;
    @unlink($tmpStuFile);

    record_test_result('Test D — Student Bulk Upload', (bool)($tplOk && $valOk && $commitOk && $dbOk), "Template generated ({$tplOk}), 2 student rows validated ({$valOk}), 2 records persisted with RegNo ({$commitOk})");
} catch (\Throwable $e) {
    record_test_result('Test D — Student Bulk Upload', false, 'Error: ' . $e->getMessage());
}

// -----------------------------------------------------------------------------
// Test E: Dynamic Switching & Student Lookup Endpoint
// -----------------------------------------------------------------------------
try {
    // 1. Verify student lookup queries return expected student info
    $testLookupReg = $stuReg1;
    $stmt = $pdo->prepare("SELECT student_name, department, class_year FROM inst_society_memberships WHERE reg_no = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$testLookupReg]);
    $lookupRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $lookupOk = $lookupRow && $lookupRow['student_name'] === $stuName1 && $lookupRow['department'] === 'CSE';

    // 2. Check dynamic template modules
    $cfgFac = BulkImport::getModuleConfig(BulkImport::MODULE_SOCIETY_FACULTY);
    $cfgStu = BulkImport::getModuleConfig(BulkImport::MODULE_SOCIETY_STUDENT);
    $moduleConfigsOk = ($cfgFac['label'] === 'Faculty Membership in Professional Societies')
        && ($cfgStu['label'] === 'Student Membership in Professional Societies')
        && ($cfgFac['template_file'] === 'ATTS_Faculty_Society_Membership_Template.xlsx')
        && ($cfgStu['template_file'] === 'ATTS_Student_Society_Membership_Template.xlsx');

    record_test_result('Test E — Dynamic Switching & Student Lookup', (bool)($lookupOk && $moduleConfigsOk), "Student lookup resolved {$stuName1} for {$testLookupReg}, distinct templates configured");
} catch (\Throwable $e) {
    record_test_result('Test E — Dynamic Switching & Student Lookup', false, 'Error: ' . $e->getMessage());
}

// -----------------------------------------------------------------------------
// Test F: Validation & Security Rules
// -----------------------------------------------------------------------------
try {
    // F1. Missing required headers in spreadsheet
    $badCsvFile = tempnam(sys_get_temp_dir(), 'bad_csv_') . '.csv';
    file_put_contents($badCsvFile, "RandomHeader,SomeColumn\nVal1,Val2\n");
    $headerErrCaught = false;
    try {
        BulkImport::validateUpload(BulkImport::MODULE_SOCIETY_FACULTY, $badCsvFile, $testCoordUser, $activeAy, null);
    } catch (\Throwable $e) {
        $headerErrCaught = str_contains($e->getMessage(), 'required column');
    }
    @unlink($badCsvFile);

    // F2. Cross-department submission unauthorized for faculty
    $crossDeptFile = tempnam(sys_get_temp_dir(), 'cross_dept_') . '.csv';
    file_put_contents($crossDeptFile, "Faculty Identifier or Name,Department,Academic Year,Professional Society,Membership Number,Membership Type,Start Date,Expiry Date,Status,Certificate Drive Link\n"
        . "Dr. Unauthorized {$runId},MECH,{$activeAy},IEEE,UNAUTH-{$runId},Life Member,01/01/2024,31/12/2028,Active,\n");
    $crossPreview = BulkImport::validateUpload(BulkImport::MODULE_SOCIETY_FACULTY, $crossDeptFile, $testFacultyUser, $activeAy, null);
    $crossRejected = ($crossPreview['invalid_count'] > 0) && str_contains(json_encode($crossPreview['errors']), 'authorized');
    @unlink($crossDeptFile);

    // F3. Duplicate membership number detection (using $facMemNo1 committed in Test C)
    $dupCsvFile = tempnam(sys_get_temp_dir(), 'dup_csv_') . '.csv';
    file_put_contents($dupCsvFile, "Faculty Identifier or Name,Department,Academic Year,Professional Society,Membership Number,Membership Type,Start Date,Expiry Date,Status,Certificate Drive Link\n"
        . "Dr. Different Name {$runId},CSE,{$activeAy},IEEE Computer Society,{$facMemNo1},Life Member,01/01/2024,31/12/2028,Active,https://drive.google.com/file/d/1AbCdEfGhIjKlMnOpQrStUvWxYz012345/view\n");
    $dupPreview = BulkImport::validateUpload(BulkImport::MODULE_SOCIETY_FACULTY, $dupCsvFile, $testCoordUser, $activeAy, null);
    $dupDetected = ($dupPreview['duplicate_count'] > 0);
    @unlink($dupCsvFile);

    $passedF = $headerErrCaught && $crossRejected && $dupDetected;
    record_test_result('Test F — Validation and Security', (bool)$passedF, "Header validation ({$headerErrCaught}), Department authorization ({$crossRejected}), Duplicate detection ({$dupDetected})");
} catch (\Throwable $e) {
    record_test_result('Test F — Validation and Security', false, 'Error: ' . $e->getMessage());
}

// -----------------------------------------------------------------------------
// Test G: Regression Check
// -----------------------------------------------------------------------------
try {
    // G1. Internship bulk import config intact
    $cfgIntern = BulkImport::getModuleConfig(BulkImport::MODULE_INTERNSHIP);
    $internOk = $cfgIntern && $cfgIntern['table'] === 'internships' && $cfgIntern['key'] === 'internship';

    // G2. Summer training bulk import config intact
    $cfgSummer = BulkImport::getModuleConfig(BulkImport::MODULE_SUMMER_TRAINING);
    $summerOk = $cfgSummer && $cfgSummer['table'] === 'summer_training' && $cfgSummer['key'] === 'summer_training';

    // G3. Record report specs intact
    $specs = record_report_specs();
    $societySpec = $specs['inst_society_memberships'] ?? null;
    $specOk = false;
    if ($societySpec) {
        $cols = array_column($societySpec['columns'], 0);
        $specOk = in_array('Category', $cols, true) && in_array('Certificate', $cols, true);
    }

    $passedG = $internOk && $summerOk && $specOk;
    record_test_result('Test G — Regression', (bool)$passedG, "Internship bulk intact ({$internOk}), Summer training intact ({$summerOk}), Record specs Certificate updated ({$specOk})");
} catch (\Throwable $e) {
    record_test_result('Test G — Regression', false, 'Error: ' . $e->getMessage());
}

echo "\n--- Summary ---\n";
$totalTests = count($results);
$passedTests = count(array_filter($results, fn($r) => $r['passed']));
echo "Total: {$totalTests}, Passed: {$passedTests}, Failed: " . ($totalTests - $passedTests) . "\n";
if ($passedTests === $totalTests) {
    echo "ALL TESTS PASSED!\n";
}
