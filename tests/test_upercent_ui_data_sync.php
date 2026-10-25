<?php
/**
 * Test Suite: University Pass Percentage Matrix UI and Data Synchronization
 * Covers all 17 verification items specified in the requirements.
 */

declare(strict_types=1);

define('TEST_MODE', true);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../php-app/inc/auth.php';
require_once __DIR__ . '/../php-app/inc/helpers.php';
require_once __DIR__ . '/../php-app/inc/record_specs.php';
require_once __DIR__ . '/../php-app/models/Record.php';
require_once __DIR__ . '/../php-app/models/Department.php';
require_once __DIR__ . '/../php-app/inc/inst_upload_handler.php';

$pdo = db();
$activeAy = active_academic_year();

$passCount = 0;
$failCount = 0;

function assertTest(bool $condition, string $title, string $details = ''): void {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo "[\033[32mPASS\033[0m] {$title}\n";
    } else {
        $failCount++;
        echo "[\033[31mFAIL\033[0m] {$title}" . ($details ? ": {$details}" : '') . "\n";
    }
}

function getLastFlashError(): string {
    $flashes = take_flashes();
    foreach (array_reverse($flashes) as $f) {
        if (($f['type'] ?? '') === 'error') {
            return (string)($f['message'] ?? '');
        }
    }
    return '';
}

echo "=== STARTING UPERCENT UI & DATA SYNCHRONIZATION TESTS ===\n\n";

// --------------------------------------------------------------------------
// 1. Column Order Verification (Requirement 3 & 8.5)
// --------------------------------------------------------------------------
echo "--- 1. Column Order Verification ---\n";

$formContent = file_get_contents(__DIR__ . '/../php-app/inc/inst_upload_forms.php');
$viewsContent = file_get_contents(__DIR__ . '/../php-app/views/upload_institutional_forms.php');
$detailsContent = file_get_contents(__DIR__ . '/../php-app/entry-details.php');

$deptPos = strpos($formContent, 'Faculty Department');
$facPos = strpos($formContent, 'Faculty Name');
assertTest($deptPos !== false && $facPos !== false && $deptPos < $facPos,
    'inst_upload_forms.php: Faculty Department column appears before Faculty Name column');

$viewsDeptPos = strpos($viewsContent, 'Faculty Department');
$viewsFacPos = strpos($viewsContent, 'Faculty Name');
assertTest($viewsDeptPos !== false && $viewsFacPos !== false && $viewsDeptPos < $viewsFacPos,
    'upload_institutional_forms.php: Faculty Department column appears before Faculty Name column');

$detailsDeptPos = strpos($detailsContent, 'Faculty Dept');
$detailsFacPos = strpos($detailsContent, 'Faculty Name');
assertTest($detailsDeptPos !== false && $detailsFacPos !== false && $detailsDeptPos < $detailsFacPos,
    'entry-details.php: Faculty Dept appears before Faculty Name');

// --------------------------------------------------------------------------
// 2. Result Summary Position (Requirement 1 & 8.10)
// --------------------------------------------------------------------------
echo "\n--- 2. Result Summary Position ---\n";

$tablePos = strpos($formContent, 'id="upercentTable"');
$summaryPos = strpos($formContent, 'University Result Summary');
$attachmentPos = strpos($formContent, 'id="upercentResultCopyInput"');

assertTest($tablePos !== false && $summaryPos !== false && $tablePos < $summaryPos,
    'Result Summary appears below the subject results table');
assertTest($attachmentPos !== false && $summaryPos < $attachmentPos,
    'Result Summary appears before the Result Copy attachment area');

assertTest(strpos($formContent, 'Total Subjects') !== false,
    'Result Summary includes Total Subjects card');
assertTest(strpos($formContent, 'Registered Students') !== false,
    'Result Summary includes Registered Students card');
assertTest(strpos($formContent, 'Passed Students') !== false,
    'Result Summary includes Passed Students card');
assertTest(strpos($formContent, 'Failed Students') !== false,
    'Result Summary includes Failed Students card');
assertTest(strpos($formContent, 'Overall Pass Percentage') !== false,
    'Result Summary includes Overall Pass Percentage card');

// --------------------------------------------------------------------------
// 3. Text Inputs for Registered and Passed (Requirement 4 & 8.6)
// --------------------------------------------------------------------------
echo "\n--- 3. Registered and Passed Input Types ---\n";

assertTest(preg_match('/<input[^>]*class="[^"]*upercent-reg-input[^"]*"[^>]*type="text"[^>]*inputmode="numeric"/i', $formContent) === 1,
    'Registered input is type="text" with inputmode="numeric" in inst_upload_forms.php');

assertTest(preg_match('/<input[^>]*class="[^"]*upercent-passed-input[^"]*"[^>]*type="text"[^>]*inputmode="numeric"/i', $formContent) === 1,
    'Passed input is type="text" with inputmode="numeric" in inst_upload_forms.php');

assertTest(strpos($formContent, 'Must be a non-negative whole number') !== false,
    'Inline validation message for non-numeric input is present in JS');

// --------------------------------------------------------------------------
// 4. Result Copy Attachment Label and Instructions (Requirement 5 & 8.11)
// --------------------------------------------------------------------------
echo "\n--- 4. Result Copy Label & Instructions ---\n";

assertTest(strpos($formContent, 'Result Copy') !== false,
    'Attachment label displays "Result Copy"');
assertTest(strpos($formContent, 'Upload the university result copy or approved result document (PDF/PNG, maximum 4 MB).') !== false,
    'Upload instructions specify PDF/PNG and maximum 4 MB');
assertTest(strpos($formContent, 'accept="application/pdf,image/png,.pdf,.png"') !== false,
    'File input accept attribute restricts to PDF and PNG');
assertTest(strpos($formContent, 'Certified Event Completion Verification') === false,
    'Old label "Certified Event Completion Verification" is completely removed');

// --------------------------------------------------------------------------
// 5. Academic Year, Session & Semester Synchronization (Requirement 2 & 8.1, 8.2)
// --------------------------------------------------------------------------
echo "\n--- 5. Session & Semester Rules ---\n";

$testUser = [
    'id' => 1,
    'role' => 'Admin',
    'department' => 'CSE',
    'name' => 'System Admin'
];

// Test: Nov/Dec rejects even semester (Sem 2)
$_POST = [
    'academic_year' => $activeAy,
    'department' => 'CSE',
    'exam_session' => 'Nov/Dec',
    'semester' => 'Sem 2',
    'pass_percentage_rows' => json_encode([
        ['class_year' => '1st Year', 'subject_code' => 'CS102', 'subject_name' => 'Prog in C', 'faculty_dept' => 'CSE', 'faculty_name' => 'Dr. A', 'total_members' => '60', 'passed_members' => '55']
    ])
];
ob_start();
$res = inst_handle_pass_percentage($testUser, $activeAy, $pdo, 'add');
ob_end_clean();
$flashMsg = getLastFlashError();
assertTest($res === false && str_contains($flashMsg, 'odd semesters'), 'Nov/Dec rejects even semester Sem 2: ' . $flashMsg);

// Test: Apr/May rejects odd semester (Sem 3)
$_POST = [
    'academic_year' => $activeAy,
    'department' => 'CSE',
    'exam_session' => 'Apr/May',
    'semester' => 'Sem 3',
    'pass_percentage_rows' => json_encode([
        ['class_year' => '2nd Year', 'subject_code' => 'CS201', 'subject_name' => 'Data Structures', 'faculty_dept' => 'CSE', 'faculty_name' => 'Dr. B', 'total_members' => '60', 'passed_members' => '55']
    ])
];
ob_start();
$res = inst_handle_pass_percentage($testUser, $activeAy, $pdo, 'add');
ob_end_clean();
$flashMsg = getLastFlashError();
assertTest($res === false && str_contains($flashMsg, 'even semesters'), 'Apr/May rejects odd semester Sem 3: ' . $flashMsg);

// Test: Non-Arch department rejects Sem 9
$_POST = [
    'academic_year' => $activeAy,
    'department' => 'CSE',
    'exam_session' => 'Nov/Dec',
    'semester' => 'Sem 9',
    'pass_percentage_rows' => json_encode([
        ['class_year' => '5th Year', 'subject_code' => 'CS501', 'subject_name' => 'Advanced AI', 'faculty_dept' => 'CSE', 'faculty_name' => 'Dr. C', 'total_members' => '60', 'passed_members' => '55']
    ])
];
ob_start();
$res = inst_handle_pass_percentage($testUser, $activeAy, $pdo, 'add');
ob_end_clean();
$flashMsg = getLastFlashError();
assertTest($res === false && str_contains($flashMsg, 'semesters 1–8'), 'Non-Arch rejects Sem 9: ' . $flashMsg);

// --------------------------------------------------------------------------
// 6. Registered and Passed Numeric Validation (Requirement 4 & 8.7, 8.8)
// --------------------------------------------------------------------------
echo "\n--- 6. Numeric Validation & Passed <= Registered ---\n";

// Test non-digit string for Registered
$_POST = [
    'academic_year' => $activeAy,
    'department' => 'CSE',
    'exam_session' => 'Nov/Dec',
    'semester' => 'Sem 1',
    'pass_percentage_rows' => json_encode([
        ['class_year' => '1st Year', 'subject_code' => 'CS101', 'subject_name' => 'Python', 'faculty_dept' => 'CSE', 'faculty_name' => 'Dr. A', 'total_members' => 'abc', 'passed_members' => '50']
    ])
];
ob_start();
$res = inst_handle_pass_percentage($testUser, $activeAy, $pdo, 'add');
ob_end_clean();
$flashMsg = getLastFlashError();
assertTest($res === false && str_contains($flashMsg, 'non-negative integer'), 'Reject non-digit string "abc" for Registered');

// Test negative value for Passed
$_POST = [
    'academic_year' => $activeAy,
    'department' => 'CSE',
    'exam_session' => 'Nov/Dec',
    'semester' => 'Sem 1',
    'pass_percentage_rows' => json_encode([
        ['class_year' => '1st Year', 'subject_code' => 'CS101', 'subject_name' => 'Python', 'faculty_dept' => 'CSE', 'faculty_name' => 'Dr. A', 'total_members' => '60', 'passed_members' => '-5']
    ])
];
ob_start();
$res = inst_handle_pass_percentage($testUser, $activeAy, $pdo, 'add');
ob_end_clean();
$flashMsg = getLastFlashError();
assertTest($res === false && str_contains($flashMsg, 'non-negative integer'), 'Reject negative value "-5" for Passed');

// Test Passed > Registered
$_POST = [
    'academic_year' => $activeAy,
    'department' => 'CSE',
    'exam_session' => 'Nov/Dec',
    'semester' => 'Sem 1',
    'pass_percentage_rows' => json_encode([
        ['class_year' => '1st Year', 'subject_code' => 'CS101', 'subject_name' => 'Python', 'faculty_dept' => 'CSE', 'faculty_name' => 'Dr. A', 'total_members' => '50', 'passed_members' => '60']
    ])
];
ob_start();
$res = inst_handle_pass_percentage($testUser, $activeAy, $pdo, 'add');
ob_end_clean();
$flashMsg = getLastFlashError();
assertTest($res === false && str_contains($flashMsg, 'cannot exceed registered count'), 'Passed (60) > Registered (50) rejected: ' . $flashMsg);

// --------------------------------------------------------------------------
// 7. Result Copy Attachment Upload & Validation (Requirement 5 & 8.11, 8.12, 8.13)
// --------------------------------------------------------------------------
echo "\n--- 7. Result Copy File Handling & 4 MB Limit ---\n";

$tmpDir = sys_get_temp_dir();

// A. Test invalid file extension (.txt)
$txtFile = $tmpDir . '/test_result.txt';
file_put_contents($txtFile, "Not a PDF or PNG");
$fakeFileTxt = [
    'name' => 'test_result.txt',
    'type' => 'text/plain',
    'tmp_name' => $txtFile,
    'error' => UPLOAD_ERR_OK,
    'size' => filesize($txtFile)
];
[$pStored, $pErr] = save_result_copy_proof($fakeFileTxt, false);
assertTest($pStored === null && str_contains((string)$pErr, 'PDF') && str_contains((string)$pErr, 'PNG'), 'Reject .txt file for Result Copy: ' . $pErr);
@unlink($txtFile);

// B. Test file > 4 MB (e.g. 4.5 MB)
$oversizedFile = $tmpDir . '/oversized_result.pdf';
$fp = fopen($oversizedFile, 'w');
fwrite($fp, "%PDF-1.4\n");
fseek($fp, (int)(4.5 * 1024 * 1024), SEEK_SET);
fwrite($fp, "%%EOF");
fclose($fp);
$fakeFileOver = [
    'name' => 'oversized_result.pdf',
    'type' => 'application/pdf',
    'tmp_name' => $oversizedFile,
    'error' => UPLOAD_ERR_OK,
    'size' => filesize($oversizedFile)
];
[$pStored, $pErr] = save_result_copy_proof($fakeFileOver, false);
assertTest($pStored === null && str_contains((string)$pErr, '4 MB'), 'Reject >4MB file for Result Copy: ' . $pErr);
@unlink($oversizedFile);

// C. Test spoofed extension (.pdf with non-PDF content)
$spoofedFile = $tmpDir . '/spoofed_result.pdf';
file_put_contents($spoofedFile, "This is plain text disguised as PDF");
$fakeFileSpoof = [
    'name' => 'spoofed_result.pdf',
    'type' => 'application/pdf',
    'tmp_name' => $spoofedFile,
    'error' => UPLOAD_ERR_OK,
    'size' => filesize($spoofedFile)
];
[$pStored, $pErr] = save_result_copy_proof($fakeFileSpoof, false);
assertTest($pStored === null && str_contains((string)$pErr, 'valid PDF'), 'Reject spoofed file headers: ' . $pErr);
@unlink($spoofedFile);

// D. Test valid PDF under 4 MB
$validPdfFile = $tmpDir . '/valid_result.pdf';
file_put_contents($validPdfFile, "%PDF-1.5\n%Valid test PDF stream\n%%EOF");
$fakeFilePdf = [
    'name' => 'university_result_nov_dec.pdf',
    'type' => 'application/pdf',
    'tmp_name' => $validPdfFile,
    'error' => UPLOAD_ERR_OK,
    'size' => filesize($validPdfFile)
];
[$pStored, $pErr] = save_result_copy_proof($fakeFilePdf, false);
assertTest(!empty($pStored) && file_exists(__DIR__ . '/../php-app/uploads/proofs/' . $pStored),
    'Valid PDF saved successfully to uploads/proofs/');
@unlink($validPdfFile);
if ($pStored) @unlink(__DIR__ . '/../php-app/uploads/proofs/' . $pStored);

// E. Test valid PNG under 4 MB
$validPngFile = $tmpDir . '/valid_result.png';
file_put_contents($validPngFile, "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89");
$fakeFilePng = [
    'name' => 'university_result_screenshot.png',
    'type' => 'image/png',
    'tmp_name' => $validPngFile,
    'error' => UPLOAD_ERR_OK,
    'size' => filesize($validPngFile)
];
[$pStored, $pErr] = save_result_copy_proof($fakeFilePng, false);
assertTest(!empty($pStored) && file_exists(__DIR__ . '/../php-app/uploads/proofs/' . $pStored),
    'Valid PNG saved successfully to uploads/proofs/');
@unlink($validPngFile);
if ($pStored) @unlink(__DIR__ . '/../php-app/uploads/proofs/' . $pStored);

// --------------------------------------------------------------------------
// 8. End-to-End Save, Calculations & Preservation (Requirement 6, 7 & 8.8, 8.9, 8.14)
// --------------------------------------------------------------------------
echo "\n--- 8. End-to-End Record Save & Percentage Calculation ---\n";

$rowsData = [
    [
        'class_year' => '2nd Year',
        'subject_code' => 'CS301',
        'subject_name' => 'Data Structures and Algorithms',
        'faculty_dept' => 'CSE',
        'faculty_name' => 'Prof. CSE Faculty',
        'total_members' => '60',
        'passed_members' => '54',
        'sort_order' => 1
    ],
    [
        'class_year' => '2nd Year',
        'subject_code' => 'MA301',
        'subject_name' => 'Discrete Mathematics',
        'faculty_dept' => 'Mathematics',
        'faculty_name' => '',
        'total_members' => '60',
        'passed_members' => '48',
        'sort_order' => 2
    ],
    [
        'class_year' => '2nd Year',
        'subject_code' => 'CS302',
        'subject_name' => 'Digital Principles',
        'faculty_dept' => 'ECE',
        'faculty_name' => '',
        'total_members' => '60',
        'passed_members' => '60',
        'sort_order' => 3
    ]
];

// Clean up any existing record for this combination before testing
$pdo->prepare("DELETE FROM inst_pass_percentage WHERE department = 'CSE' AND academic_year = ? AND exam_session = 'Nov/Dec' AND semester = 'Sem 3'")->execute([$activeAy]);

$_POST = [
    'academic_year' => $activeAy,
    'department' => 'CSE',
    'exam_session' => 'Nov/Dec',
    'semester' => 'Sem 3',
    'pass_percentage_rows' => json_encode($rowsData)
];
$_FILES = [];

// Create a valid proof file for this record
$recProof = $tmpDir . '/rec_proof.pdf';
file_put_contents($recProof, "%PDF-1.4\n%Record proof\n%%EOF");
$_FILES['proof'] = [
    'name' => 'cs_sem3_results.pdf',
    'type' => 'application/pdf',
    'tmp_name' => $recProof,
    'error' => UPLOAD_ERR_OK,
    'size' => filesize($recProof)
];

ob_start();
$res = inst_handle_pass_percentage($testUser, $activeAy, $pdo, 'add');
ob_end_clean();
assertTest($res === true, "University Pass Percentage record saved successfully");
@unlink($recProof);

// Verify parent record
$parentStmt = $pdo->prepare("SELECT * FROM inst_pass_percentage WHERE department = 'CSE' AND academic_year = ? AND exam_session = 'Nov/Dec' AND semester = 'Sem 3'");
$parentStmt->execute([$activeAy]);
$parent = $parentStmt->fetch(PDO::FETCH_ASSOC);

assertTest($parent !== false, 'Parent record exists in database');
$recordId = (int)($parent['id'] ?? 0);
assertTest((int)$parent['total_subjects'] === 3, 'Total subjects equals 3');
assertTest((int)$parent['total_students'] === 180, 'Total students equals 180 (60 + 60 + 60)');
assertTest((int)$parent['total_passed'] === 162, 'Total passed equals 162 (54 + 48 + 60)');

// Overall pass percentage calculation: (162 / 180) * 100 = 90.00%
$expectedPct = '90.00';
assertTest((string)$parent['overall_pass_percentage'] === $expectedPct,
    "Overall Pass % is calculated correctly: {$parent['overall_pass_percentage']}% (Expected: {$expectedPct}%)");

assertTest(!empty($parent['proof_file']), 'Proof file is saved and attached to the record');

// Verify child rows
$childStmt = $pdo->prepare("SELECT * FROM inst_pass_percentage_rows WHERE pass_percentage_id = ? ORDER BY sort_order ASC");
$childStmt->execute([$recordId]);
$savedRows = $childStmt->fetchAll(PDO::FETCH_ASSOC);

assertTest(count($savedRows) === 3, 'Exactly 3 subject rows saved in database');

// Check Row 1: CS301 (54/60 = 90.00%, failed = 6)
$r1 = $savedRows[0];
assertTest($r1['subject_code'] === 'CS301', 'Row 1 subject code is CS301');
assertTest($r1['faculty_dept'] === 'CSE', 'Row 1 faculty dept is CSE');
assertTest((int)$r1['failed_members'] === 6, 'Row 1 failed students = 60 - 54 = 6');
assertTest((string)$r1['pass_percentage'] === '90.00', 'Row 1 pass % = 90.00%');

// Check Row 2: MA301 (48/60 = 80.00%, failed = 12)
$r2 = $savedRows[1];
assertTest($r2['subject_code'] === 'MA301', 'Row 2 subject code is MA301');
assertTest($r2['faculty_dept'] === 'Mathematics', 'Row 2 cross-department faculty dept (Mathematics) preserved');
assertTest((int)$r2['failed_members'] === 12, 'Row 2 failed students = 60 - 48 = 12');
assertTest((string)$r2['pass_percentage'] === '80.00', 'Row 2 pass % = 80.00%');

// Check Row 3: CS302 (60/60 = 100.00%, failed = 0)
$r3 = $savedRows[2];
assertTest((int)$r3['failed_members'] === 0, 'Row 3 failed students = 0');
assertTest((string)$r3['pass_percentage'] === '100.00', 'Row 3 pass % = 100.00%');

// Clean up proof file & test record
if (!empty($parent['proof_file'])) {
    @unlink(__DIR__ . '/../php-app/uploads/proofs/' . $parent['proof_file']);
}
$pdo->prepare("DELETE FROM inst_pass_percentage WHERE id = ?")->execute([$recordId]);

// --------------------------------------------------------------------------
// 9. Department and Faculty Restrictions (Requirement 3 & 8.15)
// --------------------------------------------------------------------------
echo "\n--- 9. Role Permissions & Department Restrictions ---\n";

$facultyUser = [
    'id' => 99,
    'role' => 'Faculty',
    'department' => 'Mechanical',
    'name' => 'Prof. Mech'
];

$_POST = [
    'academic_year' => $activeAy,
    'department' => 'CSE', // Attempt to submit for CSE while being Mechanical faculty
    'exam_session' => 'Nov/Dec',
    'semester' => 'Sem 1',
    'pass_percentage_rows' => json_encode([
        ['class_year' => '1st Year', 'subject_code' => 'CS101', 'subject_name' => 'Test', 'faculty_dept' => 'CSE', 'faculty_name' => 'Dr. A', 'total_members' => '60', 'passed_members' => '50']
    ])
];
ob_start();
$res = inst_handle_pass_percentage($facultyUser, $activeAy, $pdo, 'add');
ob_end_clean();
$flashMsg = getLastFlashError();
assertTest($res === false && str_contains($flashMsg, 'not authorized to submit data for this department'),
    'Faculty attempting cross-department submission blocked: ' . $flashMsg);

echo "\n==================================================\n";
echo "SUMMARY OF TESTS:\n";
echo "Passed: {$passCount}\n";
echo "Failed: {$failCount}\n";
echo "==================================================\n";

if ($failCount > 0) {
    exit(1);
}
echo "ALL UPERCENT UI & DATA SYNCHRONIZATION TESTS PASSED!\n";
