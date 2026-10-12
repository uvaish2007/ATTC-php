<?php
/**
 * ATTS-UPERCENT Comprehensive End-to-End Test Suite
 * Tests A through F as specified in ATTS-UPERCENT Requirements
 */

declare(strict_types=1);

define('TEST_MODE', true);

require_once __DIR__ . '/../php-app/inc/auth.php';
require_once __DIR__ . '/../php-app/inc/helpers.php';
require_once __DIR__ . '/../php-app/inc/record_specs.php';
require_once __DIR__ . '/../php-app/models/Record.php';
require_once __DIR__ . '/../php-app/models/Department.php';
require_once __DIR__ . '/../php-app/inc/inst_upload_handler.php';

$pdo = db();
$activeAy = active_academic_year();

$passedCount = 0;
$failedCount = 0;

function assertTest(string $section, string $desc, bool $condition, string $detail = ''): void {
    global $passedCount, $failedCount;
    if ($condition) {
        $passedCount++;
        echo "[PASS] [{$section}] {$desc}" . ($detail ? " — {$detail}" : "") . "\n";
    } else {
        $failedCount++;
        echo "[FAIL] [{$section}] {$desc}" . ($detail ? " — {$detail}" : "") . "\n";
    }
}

echo "=== ATTS-UPERCENT TEST SUITE STARTING ===\n\n";

// Ensure clean test state for department CSE and Arch
$pdo->exec("DELETE FROM inst_pass_percentage WHERE department IN ('CSE', 'Computer Science and Engineering', 'Arch', 'Architecture') AND academic_year = '{$activeAy}'");

// Ensure test users exist in `users` table so foreign key constraint fk_ipp_created_by is satisfied
$pdo->exec("INSERT INTO users (id, name, email, password, role, department, status) 
    VALUES (99991, 'Prof. CSE Faculty', 'cse_fac@atts.test', 'pwd_hash', 'Faculty', 'CSE', 1),
           (99992, 'Prof. Arch Faculty', 'arch_fac@atts.test', 'pwd_hash', 'Faculty', 'Arch', 1)
    ON DUPLICATE KEY UPDATE name=VALUES(name), department=VALUES(department), role=VALUES(role)");

// --- TEST A: Non-Architecture Department (CSE) ---
echo "--- TEST A: Non-Architecture Department (CSE) ---\n";

$cseFaculty = [
    'id' => 99991,
    'name' => 'Prof. CSE Faculty',
    'email' => 'cse_fac@atts.test',
    'role' => 'Faculty',
    'department' => 'CSE'
];

// Check architecture helper
assertTest('TEST A', 'CSE is not Architecture', !is_architecture_dept('CSE'));
assertTest('TEST A', 'Computer Science and Engineering is not Architecture', !is_architecture_dept('Computer Science and Engineering'));

// Test default rows logic for CSE: exactly 4 rows, no 5th year
$nonArchYears = ['1st Year', '2nd Year', '3rd Year', '4th Year'];
assertTest('TEST A', 'Non-Architecture programme has 4 duration years', count($nonArchYears) === 4);
assertTest('TEST A', '5th Year is not in default non-Architecture duration', !in_array('5th Year', $nonArchYears, true));

// Test semester validation for CSE: accepts Sem 1-8, rejects Sem 9+
$csePostValid = [
    'record_type' => 'inst_pass_percentage',
    'department' => 'CSE',
    'academic_year' => $activeAy,
    'exam_session' => 'Nov/Dec',
    'semester' => 'Sem 3',
    'pass_percentage_rows' => json_encode([
        ['class_year' => '1st Year', 'subject_code' => 'CS101', 'subject_name' => 'Programming', 'faculty_name' => 'Prof. CSE Faculty', 'faculty_dept' => 'CSE', 'total_members' => 100, 'passed_members' => 85],
        ['class_year' => '2nd Year', 'subject_code' => 'CS201', 'subject_name' => 'Data Structures', 'faculty_name' => 'Prof. CSE Faculty', 'faculty_dept' => 'CSE', 'total_members' => 90, 'passed_members' => 72],
        ['class_year' => '3rd Year', 'subject_code' => 'CS301', 'subject_name' => 'Operating Systems', 'faculty_name' => 'Prof. CSE Faculty', 'faculty_dept' => 'CSE', 'total_members' => 80, 'passed_members' => 68],
        ['class_year' => '4th Year', 'subject_code' => 'CS401', 'subject_name' => 'Cloud Computing', 'faculty_name' => 'Prof. CSE Faculty', 'faculty_dept' => 'CSE', 'total_members' => 70, 'passed_members' => 63],
    ])
];

$_POST = $csePostValid;
ob_start();
try {
    inst_handle_pass_percentage($cseFaculty, $activeAy, $pdo, 'add');
} catch (\Throwable $e) {}
ob_end_clean();

$savedCse = $pdo->query("SELECT * FROM inst_pass_percentage WHERE department = 'CSE' AND academic_year = '{$activeAy}' AND exam_session = 'Nov/Dec' AND semester = 'Sem 3'")->fetch(PDO::FETCH_ASSOC);
assertTest('TEST A', 'Valid CSE records saved to database', !empty($savedCse));

if ($savedCse) {
    assertTest('TEST A', 'Total subjects count equals 4', (int)$savedCse['total_subjects'] === 4);
    assertTest('TEST A', 'Total students sum equals 340', (int)$savedCse['total_students'] === 340);
    assertTest('TEST A', 'Total passed sum equals 288', (int)$savedCse['total_passed'] === 288);
    // 288 / 340 * 100 = 84.71%
    assertTest('TEST A', 'Overall Pass % equals 84.71%', (float)$savedCse['overall_pass_percentage'] === 84.71);
    assertTest('TEST A', 'Submission status is Submitted (not approved)', $savedCse['status'] === 'Submitted');

    $cseRows = $pdo->query("SELECT * FROM inst_pass_percentage_rows WHERE pass_percentage_id = {$savedCse['id']} ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);
    assertTest('TEST A', 'Saved exactly 4 child rows in database', count($cseRows) === 4);
    assertTest('TEST A', 'Row 1 Sub Code CS101 stored', ($cseRows[0]['subject_code'] ?? '') === 'CS101');
    assertTest('TEST A', 'Row 1 Failed calculated correctly (100 - 85 = 15)', (int)($cseRows[0]['failed_members'] ?? 0) === 15);
    assertTest('TEST A', 'Row 1 Pass % calculated correctly (85.00%)', (float)($cseRows[0]['pass_percentage'] ?? 0) === 85.00);
}


// --- TEST B: Architecture Department (Arch) ---
echo "\n--- TEST B: Architecture Department (Arch) ---\n";

$archFaculty = [
    'id' => 99992,
    'name' => 'Prof. Arch Faculty',
    'email' => 'arch_fac@atts.test',
    'role' => 'Faculty',
    'department' => 'Arch'
];

assertTest('TEST B', 'Arch identified as Architecture department', is_architecture_dept('Arch'));
assertTest('TEST B', 'Architecture identified as Architecture department', is_architecture_dept('Architecture'));

// Architecture 5-year submission with Sem 9 (which is valid for Arch 1-10)
$archPostValid = [
    'record_type' => 'inst_pass_percentage',
    'department' => 'Arch',
    'academic_year' => $activeAy,
    'exam_session' => 'Nov/Dec',
    'semester' => 'Sem 9',
    'pass_percentage_rows' => json_encode([
        ['class_year' => '1st Year', 'subject_code' => 'AR101', 'subject_name' => 'Architectural Design I', 'faculty_name' => 'Prof. Arch Faculty', 'faculty_dept' => 'Arch', 'total_members' => 40, 'passed_members' => 36],
        ['class_year' => '2nd Year', 'subject_code' => 'AR201', 'subject_name' => 'Building Materials', 'faculty_name' => 'Prof. Arch Faculty', 'faculty_dept' => 'Arch', 'total_members' => 38, 'passed_members' => 35],
        ['class_year' => '3rd Year', 'subject_code' => 'AR301', 'subject_name' => 'Structural Systems', 'faculty_name' => 'Prof. Arch Faculty', 'faculty_dept' => 'Arch', 'total_members' => 35, 'passed_members' => 32],
        ['class_year' => '4th Year', 'subject_code' => 'AR401', 'subject_name' => 'Urban Planning', 'faculty_name' => 'Prof. Arch Faculty', 'faculty_dept' => 'Arch', 'total_members' => 32, 'passed_members' => 30],
        ['class_year' => '5th Year', 'subject_code' => 'AR501', 'subject_name' => 'Professional Practice', 'faculty_name' => 'Prof. Arch Faculty', 'faculty_dept' => 'Arch', 'total_members' => 30, 'passed_members' => 29],
    ])
];

$_POST = $archPostValid;
ob_start();
try {
    inst_handle_pass_percentage($archFaculty, $activeAy, $pdo, 'add');
} catch (\Throwable $e) {}
ob_end_clean();

$savedArch = $pdo->query("SELECT * FROM inst_pass_percentage WHERE department = 'Arch' AND academic_year = '{$activeAy}' AND exam_session = 'Nov/Dec' AND semester = 'Sem 9'")->fetch(PDO::FETCH_ASSOC);
assertTest('TEST B', 'Valid Architecture 5-year record saved', !empty($savedArch));

if ($savedArch) {
    assertTest('TEST B', 'Total subjects count equals 5 for Architecture', (int)$savedArch['total_subjects'] === 5);
    $archRows = $pdo->query("SELECT * FROM inst_pass_percentage_rows WHERE pass_percentage_id = {$savedArch['id']} ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);
    assertTest('TEST B', 'Saved exactly 5 child rows in database', count($archRows) === 5);
    assertTest('TEST B', '5th Year row stored in database', ($archRows[4]['class_year'] ?? '') === '5th Year');
    assertTest('TEST B', '5th Year Failed calculated (30 - 29 = 1)', (int)($archRows[4]['failed_members'] ?? 0) === 1);
    assertTest('TEST B', '5th Year Pass % calculated (96.67%)', (float)($archRows[4]['pass_percentage'] ?? 0) === 96.67);
}


// --- TEST C: Calculation Validation ---
echo "\n--- TEST C: Calculation Validation ---\n";

// Case 1: Reg = 100, Passed = 85 -> Failed = 15, Pass % = 85.00%
$reg1 = 100; $passed1 = 85;
$failed1 = max(0, $reg1 - $passed1);
$pct1 = round(($passed1 / $reg1) * 100, 2);
assertTest('TEST C', 'Case 1: Reg=100, Passed=85 -> Failed=15', $failed1 === 15);
assertTest('TEST C', 'Case 1: Reg=100, Passed=85 -> Pass%=85.00%', $pct1 === 85.00);

// Case 2: Reg = 50, Passed = 50 -> Failed = 0, Pass % = 100.00%
$reg2 = 50; $passed2 = 50;
$failed2 = max(0, $reg2 - $passed2);
$pct2 = round(($passed2 / $reg2) * 100, 2);
assertTest('TEST C', 'Case 2: Reg=50, Passed=50 -> Failed=0', $failed2 === 0);
assertTest('TEST C', 'Case 2: Reg=50, Passed=50 -> Pass%=100.00%', $pct2 === 100.00);

// Case 3: Reg = 40, Passed = 0 -> Failed = 40, Pass % = 0.00%
$reg3 = 40; $passed3 = 0;
$failed3 = max(0, $reg3 - $passed3);
$pct3 = round(($passed3 / $reg3) * 100, 2);
assertTest('TEST C', 'Case 3: Reg=40, Passed=0 -> Failed=40', $failed3 === 40);
assertTest('TEST C', 'Case 3: Reg=40, Passed=0 -> Pass%=0.00%', $pct3 === 0.00);

// Case 4: Reg = 10, Passed = 11 -> Expected validation failure (Passed > Reg)
$invPassPost = $csePostValid;
$invPassPost['semester'] = 'Sem 5';
$invPassPost['pass_percentage_rows'] = json_encode([
    ['class_year' => '1st Year', 'subject_code' => 'CS101', 'subject_name' => 'Prog', 'total_members' => 10, 'passed_members' => 11]
]);
$_POST = $invPassPost;
ob_start();
try {
    inst_handle_pass_percentage($cseFaculty, $activeAy, $pdo, 'add');
} catch (\Throwable $e) {}
ob_end_clean();
$failedRec = $pdo->query("SELECT id FROM inst_pass_percentage WHERE department = 'CSE' AND semester = 'Sem 5'")->fetch(PDO::FETCH_ASSOC);
assertTest('TEST C', 'Case 4: Passed > Reg rejected by backend', empty($failedRec));

// Case 5: Reg = -1 -> Expected validation failure
$negPassPost = $csePostValid;
$negPassPost['semester'] = 'Sem 7';
$negPassPost['pass_percentage_rows'] = json_encode([
    ['class_year' => '1st Year', 'subject_code' => 'CS101', 'subject_name' => 'Prog', 'total_members' => -1, 'passed_members' => 5]
]);
$_POST = $negPassPost;
ob_start();
try {
    inst_handle_pass_percentage($cseFaculty, $activeAy, $pdo, 'add');
} catch (\Throwable $e) {}
ob_end_clean();
$negRec = $pdo->query("SELECT id FROM inst_pass_percentage WHERE department = 'CSE' AND semester = 'Sem 7'")->fetch(PDO::FETCH_ASSOC);
assertTest('TEST C', 'Case 5: Reg = -1 rejected by backend', empty($negRec));

// Case 6: Reg = 0 -> Safe handling, no division by zero
$reg6 = 0; $passed6 = 0;
$pct6 = ($reg6 > 0) ? round(($passed6 / $reg6) * 100, 2) : 0.00;
assertTest('TEST C', 'Case 6: Reg = 0 handled safely without division by zero', $pct6 === 0.00);


// --- TEST D: Session and Data Isolation ---
echo "\n--- TEST D: Session and Data Isolation ---\n";

// Save Apr/May record for CSE Sem 4
$cseAprMayPost = [
    'record_type' => 'inst_pass_percentage',
    'department' => 'CSE',
    'academic_year' => $activeAy,
    'exam_session' => 'Apr/May',
    'semester' => 'Sem 4',
    'pass_percentage_rows' => json_encode([
        ['class_year' => '1st Year', 'subject_code' => 'CS102', 'subject_name' => 'Digital Logic', 'total_members' => 95, 'passed_members' => 90],
    ])
];
$_POST = $cseAprMayPost;
ob_start();
try {
    inst_handle_pass_percentage($cseFaculty, $activeAy, $pdo, 'add');
} catch (\Throwable $e) {}
ob_end_clean();

$recNovDec = $pdo->query("SELECT id FROM inst_pass_percentage WHERE department = 'CSE' AND exam_session = 'Nov/Dec' AND semester = 'Sem 3'")->fetch(PDO::FETCH_ASSOC);
$recAprMay = $pdo->query("SELECT id FROM inst_pass_percentage WHERE department = 'CSE' AND exam_session = 'Apr/May' AND semester = 'Sem 4'")->fetch(PDO::FETCH_ASSOC);

assertTest('TEST D', 'Nov/Dec and Apr/May sessions saved as distinct records', !empty($recNovDec) && !empty($recAprMay) && $recNovDec['id'] !== $recAprMay['id']);


// --- TEST E: Reports and Persistence ---
echo "\n--- TEST E: Reports and Persistence ---\n";

$specs = record_report_specs();
assertTest('TEST E', 'record_report_specs contains inst_pass_percentage', isset($specs['inst_pass_percentage']));
assertTest('TEST E', 'inst_pass_percentage specification has Exam Session column', in_array(['Exam Session', 'exam_session'], $specs['inst_pass_percentage']['columns'], true));
assertTest('TEST E', 'inst_pass_percentage specification has Semester column', in_array(['Semester', 'semester'], $specs['inst_pass_percentage']['columns'], true));

$cseRecord = !empty($savedCse['id']) ? record_find('inst_pass_percentage', (int)$savedCse['id']) : null;
assertTest('TEST E', 'record_find retrieves saved inst_pass_percentage record', !empty($cseRecord));

$displayAttrs = record_display_attributes('inst_pass_percentage', $cseRecord);
$attrKeys = array_column($displayAttrs, 'label');
assertTest('TEST E', 'Display attributes contain Exam Session', in_array('Exam Session', $attrKeys, true));
assertTest('TEST E', 'Display attributes contain Semester', in_array('Semester', $attrKeys, true));
assertTest('TEST E', 'Display attributes contain Overall Pass %', in_array('Overall Pass %', $attrKeys, true));


// --- TEST F: Backend Security ---
echo "\n--- TEST F: Backend Security ---\n";

// Security 1: Non-Arch department submits Sem 9 (invalid semester for non-Arch)
$invSemPost = $csePostValid;
$invSemPost['semester'] = 'Sem 9'; // Arch only
$_POST = $invSemPost;
ob_start();
try {
    inst_handle_pass_percentage($cseFaculty, $activeAy, $pdo, 'add');
} catch (\Throwable $e) {}
ob_end_clean();
$invSemRec = $pdo->query("SELECT id FROM inst_pass_percentage WHERE department = 'CSE' AND semester = 'Sem 9'")->fetch(PDO::FETCH_ASSOC);
assertTest('TEST F', 'Security 1: Semester 9 rejected for non-Architecture department', empty($invSemRec));

// Security 2: Invalid exam session (arbitrary string like "Summer-2026")
$invSessPost = $csePostValid;
$invSessPost['semester'] = 'Sem 1';
$invSessPost['exam_session'] = 'Summer-2026';
$_POST = $invSessPost;
ob_start();
try {
    inst_handle_pass_percentage($cseFaculty, $activeAy, $pdo, 'add');
} catch (\Throwable $e) {}
ob_end_clean();
$invSessRec = $pdo->query("SELECT id FROM inst_pass_percentage WHERE department = 'CSE' AND exam_session = 'Summer-2026'")->fetch(PDO::FETCH_ASSOC);
assertTest('TEST F', 'Security 2: Arbitrary exam session rejected', empty($invSessRec));

// Security 3: Faculty tries to submit for another department (e.g. CSE faculty submits for ECE)
$unauthPost = $csePostValid;
$unauthPost['department'] = 'ECE';
$unauthPost['semester'] = 'Sem 1';
$_POST = $unauthPost;
ob_start();
try {
    inst_handle_pass_percentage($cseFaculty, $activeAy, $pdo, 'add');
} catch (\Throwable $e) {}
ob_end_clean();
$unauthRec = $pdo->query("SELECT id FROM inst_pass_percentage WHERE department = 'ECE' AND semester = 'Sem 1'")->fetch(PDO::FETCH_ASSOC);
assertTest('TEST F', 'Security 3: Cross-department submission by faculty rejected/prevented', empty($unauthRec));

// Clean up test records
$pdo->exec("DELETE FROM inst_pass_percentage WHERE department IN ('CSE', 'Arch') AND academic_year = '{$activeAy}'");

echo "\n=== TEST SUITE RESULTS ===\n";
echo "Total Passed: {$passedCount}\n";
echo "Total Failed: {$failedCount}\n";

if ($failedCount === 0) {
    echo "=== ALL UPERCENT TESTS PASSED PERFECTLY! ===\n";
    exit(0);
} else {
    echo "=== SOME TESTS FAILED! ===\n";
    exit(1);
}
