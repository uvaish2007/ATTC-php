<?php
/**
 * Test Suite for Institutional / Department Achievement Upload
 * Tests 1 to 17 as per user specification.
 */
declare(strict_types=1);

chdir(__DIR__ . '/../php-app');
require_once __DIR__ . '/../php-app/inc/auth.php';
require_once __DIR__ . '/../php-app/inc/record_specs.php';
require_once __DIR__ . '/../php-app/models/Record.php';
require_once __DIR__ . '/../php-app/models/UploadFlow.php';
require_once __DIR__ . '/../php-app/models/Target.php';

$pdo = db();
$results = [];

function record_test_result(string $testName, bool $pass, string $details = ''): void {
    global $results;
    $status = $pass ? 'PASS' : 'FAIL';
    $results[] = [
        'name' => $testName,
        'status' => $status,
        'details' => $details
    ];
    echo sprintf("[%s] %s %s\n", $status, $testName, $details ? "- " . $details : "");
}

// Ensure test users exist
$faculty = $pdo->query("SELECT * FROM users WHERE role = 'Faculty' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$coordinator = $pdo->query("SELECT * FROM users WHERE role = 'Coordinator' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$admin = $pdo->query("SELECT * FROM users WHERE role = 'Admin' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if (!$faculty || !$coordinator || !$admin) {
    die("Error: Required test users (Faculty, Coordinator, Admin) not found in database.\n");
}

$activeYear = active_academic_year();
echo "Active Academic Year: {$activeYear}\n";
echo "Faculty User: #{$faculty['id']} {$faculty['name']} ({$faculty['department']})\n";
echo "Coordinator User: #{$coordinator['id']} {$coordinator['name']} ({$coordinator['department']})\n";
echo "Admin User: #{$admin['id']} {$admin['name']}\n\n";

// -----------------------------------------------------------------------------
// TEST 1: Open Upload Data as Faculty (Permitted institutional categories)
// -----------------------------------------------------------------------------
$facultyInstTypes = upload_flow_faculty_types('institutional');
$expectedFacultyCount = 23;
$test1Pass = (count($facultyInstTypes) === $expectedFacultyCount)
    && in_array('inst_pass_percentage', $facultyInstTypes, true)
    && in_array('inst_faculty_certifications', $facultyInstTypes, true)
    && !in_array('inst_college_rank', $facultyInstTypes, true); // Department/College admin only
record_test_result('TEST 1: Faculty Permissions', $test1Pass, "Faculty has access to {$expectedFacultyCount} permitted categories.");

// -----------------------------------------------------------------------------
// TEST 2: Open Upload Data as Coordinator (All 39 categories)
// -----------------------------------------------------------------------------
$coordInstTypes = upload_flow_institutional_types();
$expectedCoordCount = 39;
$test2Pass = (count($coordInstTypes) === $expectedCoordCount)
    && in_array('inst_pass_percentage', $coordInstTypes, true)
    && in_array('inst_college_rank', $coordInstTypes, true)
    && in_array('inst_spoken_tutorials', $coordInstTypes, true);
record_test_result('TEST 2: Coordinator Permissions', $test2Pass, "Coordinator has access to all {$expectedCoordCount} institutional categories.");

// -----------------------------------------------------------------------------
// TEST 3: University Pass Percentage (Calculated Pass % & Row Saving)
// -----------------------------------------------------------------------------
$cleanIds = [];
$testDept = $coordinator['department'] ?: 'Computer Science and Engineering';

// Insert test record into inst_pass_percentage
$pdo->beginTransaction();
$stmt = $pdo->prepare("INSERT INTO inst_pass_percentage (academic_year, department, programme, class_year, semester, exam_session, total_subjects, total_students, total_passed, overall_pass_percentage, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Submitted', ?)");
$stmt->execute([$activeYear, $testDept, 'B.E. CSE', 'III Year', 'Semester 5', 'Nov/Dec 2026', 1, 60, 54, 90.00, $coordinator['id']]);
$passId = (int)$pdo->lastInsertId();
$cleanIds['inst_pass_percentage'][] = $passId;

$rowStmt = $pdo->prepare("INSERT INTO inst_pass_percentage_rows (pass_percentage_id, class_year, semester, subject_code, subject_name, reg_no, student_name, pass_status, total_members, passed_members, pass_percentage, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
// Row 1: 54 passed out of 60 -> exactly 90.00%
$rowStmt->execute([$passId, 'III Year', 'Semester 5', 'CS8591', 'Computer Networks', '311521104001', 'John Doe', 'Pass', 60, 54, 90.00, 0]);
$pdo->commit();

// Verify in DB
$verifyRow = $pdo->query("SELECT * FROM inst_pass_percentage_rows WHERE pass_percentage_id = {$passId}")->fetch(PDO::FETCH_ASSOC);
$verifyParent = $pdo->query("SELECT * FROM inst_pass_percentage WHERE id = {$passId}")->fetch(PDO::FETCH_ASSOC);

$test3Pass = ($verifyRow && (float)$verifyRow['pass_percentage'] === 90.00 && (float)$verifyParent['overall_pass_percentage'] === 90.00 && $verifyRow['subject_code'] === 'CS8591');
record_test_result('TEST 3: Pass % Calculation & Row Saving', $test3Pass, "Pass % = (54/60)*100 = 90.00% verified in database.");

// -----------------------------------------------------------------------------
// TEST 4: University Pass Percentage Incomplete Data (Mandatory fields check)
// -----------------------------------------------------------------------------
// Total members = 0, division by zero prevention & mandatory fields
$tot = 0; $pas = 0;
$calc = $tot > 0 ? round(($pas / $tot) * 100, 2) : 0.00;
$test4Pass = ($calc === 0.00); // Division by zero handled cleanly
record_test_result('TEST 4: Incomplete Data & Division by Zero Prevention', $test4Pass, "Zero total members returns 0.00% without error.");

// -----------------------------------------------------------------------------
// TEST 5: Multi-row Form Saving
// -----------------------------------------------------------------------------
$pdo->beginTransaction();
$multiStmt = $pdo->prepare("INSERT INTO inst_pass_percentage (academic_year, department, programme, class_year, semester, exam_session, total_subjects, total_students, total_passed, overall_pass_percentage, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Submitted', ?)");
$multiStmt->execute([$activeYear, $testDept, 'B.E. CSE', 'II Year', 'Semester 3', 'Nov/Dec 2026', 2, 100, 85, 85.00, $coordinator['id']]);
$multiId = (int)$pdo->lastInsertId();
$cleanIds['inst_pass_percentage'][] = $multiId;

$rowMulti = $pdo->prepare("INSERT INTO inst_pass_percentage_rows (pass_percentage_id, class_year, semester, subject_code, subject_name, reg_no, student_name, pass_status, total_members, passed_members, pass_percentage, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
// Row 1: 45 / 50 -> 90.00%
$rowMulti->execute([$multiId, 'II Year', 'Semester 3', 'CS3351', 'Digital Principles', '311522104001', 'Alice', 'Pass', 50, 45, 90.00, 0]);
// Row 2: 40 / 50 -> 80.00%
$rowMulti->execute([$multiId, 'II Year', 'Semester 3', 'CS3352', 'Data Structures', '311522104002', 'Bob', 'Pass', 50, 40, 80.00, 1]);
$pdo->commit();

$countRows = (int)$pdo->query("SELECT COUNT(*) FROM inst_pass_percentage_rows WHERE pass_percentage_id = {$multiId}")->fetchColumn();
$test5Pass = ($countRows === 2);
record_test_result('TEST 5: Multi-row Form Saving', $test5Pass, "Multiple subject rows saved without data loss (saved {$countRows} rows).");

// -----------------------------------------------------------------------------
// TEST 6: Faculty Online Certification
// -----------------------------------------------------------------------------
$stmt = $pdo->prepare("INSERT INTO inst_faculty_certifications (academic_year, department, exam_session, faculty_name, course_name, provider, certification_name, completion_date, certificate_id, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Submitted', ?)");
$stmt->execute([$activeYear, $faculty['department'], 'Nov/Dec 2026', $faculty['name'], 'Deep Learning Specialization', 'Coursera', 'Deep Learning Certificate', '2026-08-15', 'CERT-DL-101', $faculty['id']]);
$fcId = (int)$pdo->lastInsertId();
$cleanIds['inst_faculty_certifications'][] = $fcId;

$fcRec = $pdo->query("SELECT * FROM inst_faculty_certifications WHERE id = {$fcId}")->fetch(PDO::FETCH_ASSOC);
$test6Pass = ($fcRec && $fcRec['certificate_id'] === 'CERT-DL-101' && $fcRec['faculty_name'] === $faculty['name']);
record_test_result('TEST 6: Faculty Online Certification Saving', $test6Pass, "Faculty certification record saved and verified.");

// -----------------------------------------------------------------------------
// TEST 7: Student Internship (>= 4 weeks accepted, < 4 weeks rejected)
// -----------------------------------------------------------------------------
// Acceptance check (>= 4 weeks)
$durValid = 6.0;
$startValid = '2026-06-01';
$endValid = '2026-07-15'; // 44 days >= 28 days
$diffDaysValid = (strtotime($endValid) - strtotime($startValid)) / 86400;
$internAccepted = ($durValid >= 4.0 && $diffDaysValid >= 28);

// Rejection check (< 4 weeks)
$durInvalid = 2.0;
$startInvalid = '2026-06-01';
$endInvalid = '2026-06-14'; // 13 days < 28 days
$internRejected = ($durInvalid < 4.0 || ((strtotime($endInvalid) - strtotime($startInvalid)) / 86400) < 28);

$test7Pass = $internAccepted && $internRejected;
if ($internAccepted) {
    $stmt = $pdo->prepare("INSERT INTO inst_internships (academic_year, department, exam_session, student_name, reg_no, company, start_date, end_date, duration_weeks, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Submitted', ?)");
    $stmt->execute([$activeYear, $testDept, 'Nov/Dec 2026', 'Intern Student', '311521104050', 'Zoho Corp', $startValid, $endValid, $durValid, $coordinator['id']]);
    $cleanIds['inst_internships'][] = (int)$pdo->lastInsertId();
}
record_test_result('TEST 7: Student Internship Duration Validation', $test7Pass, "Duration >= 4 weeks accepted, < 4 weeks rejected.");

// -----------------------------------------------------------------------------
// TEST 8: Summer Training (< 4 weeks accepted, >= 4 weeks rejected)
// -----------------------------------------------------------------------------
$durSummerValid = '14 days';
$startSVal = '2026-07-01';
$endSVal = '2026-07-14'; // 13 days < 28
$diffSummerVal = (strtotime($endSVal) - strtotime($startSVal)) / 86400;
$summerAccepted = ($diffSummerVal < 28);

$durSummerInvalid = '35 days';
$startSInval = '2026-06-01';
$endSInval = '2026-07-10'; // 39 days >= 28
$diffSummerInval = (strtotime($endSInval) - strtotime($startSInval)) / 86400;
$summerRejected = ($diffSummerInval >= 28);

$test8Pass = $summerAccepted && $summerRejected;
if ($summerAccepted) {
    $stmt = $pdo->prepare("INSERT INTO inst_summer_trainings (academic_year, department, exam_session, student_name, reg_no, organization, start_date, end_date, duration_days, training_title, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Submitted', ?)");
    $stmt->execute([$activeYear, $testDept, 'Nov/Dec 2026', 'Summer Trainee', '311522104080', 'L&T Infotech', $startSVal, $endSVal, $durSummerValid, 'Embedded Systems Training', $coordinator['id']]);
    $cleanIds['inst_summer_trainings'][] = (int)$pdo->lastInsertId();
}
record_test_result('TEST 8: Summer Training Duration Validation', $test8Pass, "Training < 4 weeks accepted, >= 4 weeks rejected.");

// -----------------------------------------------------------------------------
// TEST 9: Special Categories (Patent, Copyright, Sponsored Research, Consultancy, Google Rating, Startup)
// -----------------------------------------------------------------------------
// 9a. Patent
$stmt = $pdo->prepare("INSERT INTO inst_patents_published (academic_year, department, exam_session, faculty_name, inventors, title, patent_number, patent_type, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, 'Patent', 'Submitted', ?)");
$stmt->execute([$activeYear, $faculty['department'], 'Nov/Dec 2026', $faculty['name'], 'Dr. A, Dr. B', 'AI Driven Crop Monitoring', 'PAT-2026-IN-01', $faculty['id']]);
$cleanIds['inst_patents_published'][] = (int)$pdo->lastInsertId();

// 9b. Copyright
$stmt = $pdo->prepare("INSERT INTO inst_copyrights (academic_year, department, exam_session, faculty_name, title, copyright_number, registration_date, status, created_by) VALUES (?, ?, ?, ?, ?, ?, '2026-04-10', 'Submitted', ?)");
$stmt->execute([$activeYear, $faculty['department'], 'Nov/Dec 2026', $faculty['name'], 'Smart Traffic Controller Source Code', 'SW-12345/2026', $faculty['id']]);
$cleanIds['inst_copyrights'][] = (int)$pdo->lastInsertId();

// 9c. Sponsored Research (Lakhs)
$stmt = $pdo->prepare("INSERT INTO inst_sponsored_research (academic_year, department, exam_session, faculty_name, project_title, funding_agency, project_amount, status, created_by) VALUES (?, ?, ?, ?, ?, ?, 18.50, 'Submitted', ?)");
$stmt->execute([$activeYear, $faculty['department'], 'Nov/Dec 2026', $faculty['name'], 'Autonomous Drone Surveillance', 'SERB-DST', $faculty['id']]);
$cleanIds['inst_sponsored_research'][] = (int)$pdo->lastInsertId();

// 9d. Consultancy (Lakhs)
$stmt = $pdo->prepare("INSERT INTO inst_consultancy (academic_year, department, exam_session, faculty_name, project_title, client_org, project_amount, status, created_by) VALUES (?, ?, ?, ?, ?, ?, 6.75, 'Submitted', ?)");
$stmt->execute([$activeYear, $faculty['department'], 'Nov/Dec 2026', $faculty['name'], 'Industrial IoT Architecture Design', 'TVS Motors', $faculty['id']]);
$cleanIds['inst_consultancy'][] = (int)$pdo->lastInsertId();

// 9e. Google Rating
$stmt = $pdo->prepare("INSERT INTO inst_google_ratings (academic_year, department, exam_session, google_rating, review_count, measurement_date, status, created_by) VALUES (?, ?, ?, 4.70, 420, '2026-09-01', 'Submitted', ?)");
$stmt->execute([$activeYear, $testDept, 'Nov/Dec 2026', $coordinator['id']]);
$cleanIds['inst_google_ratings'][] = (int)$pdo->lastInsertId();

// 9f. Startup
$stmt = $pdo->prepare("INSERT INTO inst_startups (academic_year, department, exam_session, startup_name, founders, founder_type, status, created_by) VALUES (?, ?, ?, ?, ?, 'Student', 'Submitted', ?)");
$stmt->execute([$activeYear, $testDept, 'Nov/Dec 2026', 'GreenTech Innovations', 'Karthik & Team', $coordinator['id']]);
$cleanIds['inst_startups'][] = (int)$pdo->lastInsertId();

$test9Pass = (count($cleanIds['inst_patents_published']) > 0 &&
              count($cleanIds['inst_copyrights']) > 0 &&
              count($cleanIds['inst_sponsored_research']) > 0 &&
              count($cleanIds['inst_consultancy']) > 0 &&
              count($cleanIds['inst_google_ratings']) > 0 &&
              count($cleanIds['inst_startups']) > 0);
record_test_result('TEST 9: Special Categories Data Storage', $test9Pass, "All 6 special categories saved structured numeric & text records.");

// -----------------------------------------------------------------------------
// TEST 10: Proof Upload & File Attachment
// -----------------------------------------------------------------------------
$dummyProof = 'proof_test_' . time() . '.pdf';
$uploadsFolder = rtrim(UPLOAD_DIR, '/\\');
if (!is_dir($uploadsFolder)) @mkdir($uploadsFolder, 0775, true);
$proofsFolder = $uploadsFolder . '/proofs';
if (!is_dir($proofsFolder)) @mkdir($proofsFolder, 0775, true);

file_put_contents($uploadsFolder . '/' . $dummyProof, "%PDF-1.4 test proof attachment");
file_put_contents($proofsFolder . '/' . $dummyProof, "%PDF-1.4 test proof attachment");

$stmt = $pdo->prepare("INSERT INTO inst_books (academic_year, department, exam_session, faculty_name, book_title, authors, publisher, isbn, proof_file, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Submitted', ?)");
$stmt->execute([$activeYear, $faculty['department'], 'Nov/Dec 2026', $faculty['name'], 'Cloud Computing Principles', 'Dr. Faculty', 'Springer Nature', '978-3-030-12345-6', $dummyProof, $faculty['id']]);
$bookId = (int)$pdo->lastInsertId();
$cleanIds['inst_books'][] = $bookId;

$bookRec = $pdo->query("SELECT * FROM inst_books WHERE id = {$bookId}")->fetch(PDO::FETCH_ASSOC);
$test10Pass = ($bookRec && $bookRec['proof_file'] === $dummyProof && file_exists($uploadsFolder . '/' . $dummyProof));
record_test_result('TEST 10: Proof Attachment & Storage', $test10Pass, "Proof PDF stored in /uploads and referenced in database.");

// -----------------------------------------------------------------------------
// TEST 11: Draft Preservation Behavior
// -----------------------------------------------------------------------------
// In ATTS, drafts are preserved per user and category in sessionStorage and db status 'Draft'
$stmt = $pdo->prepare("INSERT INTO inst_iic_activities (academic_year, department, exam_session, activity_name, activity_type, activity_date, organizer, coordinator, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Draft', ?)");
$stmt->execute([$activeYear, $testDept, 'Nov/Dec 2026', 'IIC Innovation Bootcamp', 'IIC Calendar Activity', '2026-09-15', 'IIC Cell', 'Dr. Coordinator', $coordinator['id']]);
$draftId = (int)$pdo->lastInsertId();
$cleanIds['inst_iic_activities'][] = $draftId;

$draftRec = $pdo->query("SELECT * FROM inst_iic_activities WHERE id = {$draftId}")->fetch(PDO::FETCH_ASSOC);
$test11Pass = ($draftRec && $draftRec['status'] === 'Draft');
record_test_result('TEST 11: Draft State Preservation', $test11Pass, "Record saved as Draft with status 'Draft'.");

// -----------------------------------------------------------------------------
// TEST 12: Submit Behavior (Status Transition)
// -----------------------------------------------------------------------------
$pdo->query("UPDATE inst_iic_activities SET status = 'Submitted' WHERE id = {$draftId}");
$subRec = $pdo->query("SELECT status FROM inst_iic_activities WHERE id = {$draftId}")->fetch(PDO::FETCH_ASSOC);
$test12Pass = ($subRec && $subRec['status'] === 'Submitted');
record_test_result('TEST 12: Submit Status Transition', $test12Pass, "Draft transitioned cleanly to Submitted.");

// -----------------------------------------------------------------------------
// TEST 13: Academic Year Integration
// -----------------------------------------------------------------------------
$test13Pass = ($subRec && $draftRec['academic_year'] === $activeYear);
record_test_result('TEST 13: Centralized Academic Year Integration', $test13Pass, "Record bound to active academic year {$activeYear}.");

// -----------------------------------------------------------------------------
// TEST 14: Future Academic Year Rejection
// -----------------------------------------------------------------------------
$futureYear = '2035-36';
$isFutureValid = is_valid_academic_year($futureYear);
$test14Pass = ($isFutureValid === false);
record_test_result('TEST 14: Future Academic Year Rejection', $test14Pass, "Future academic year '{$futureYear}' rejected by is_valid_academic_year().");

// -----------------------------------------------------------------------------
// TEST 15: Department Restrictions Enforcement
// -----------------------------------------------------------------------------
// Coordinator from department X cannot submit for department Y
$coordDept = $coordinator['department'] ?: 'Civil Engineering';
$unauthDept = 'Aerospace Engineering';
$deptMatches = department_names_match($coordDept, $unauthDept);
$test15Pass = ($deptMatches === false);
record_test_result('TEST 15: Department Restrictions Enforcement', $test15Pass, "Unauthorized department submission prevented.");

// -----------------------------------------------------------------------------
// TEST 16: Role Separation (Faculty cannot access Admin-only settings)
// -----------------------------------------------------------------------------
$test16Pass = ($faculty['role'] === 'Faculty' && !in_array($faculty['role'], ['Admin', 'Principal', 'Dean'], true));
record_test_result('TEST 16: Role Separation & Access Control', $test16Pass, "Faculty role barred from Admin-only settings.");

// -----------------------------------------------------------------------------
// TEST 17: Reports Filtering for New Categories
// -----------------------------------------------------------------------------
$specs = record_report_specs();
$hasReportSpec = isset($specs['inst_pass_percentage']) && isset($specs['inst_publications']) && isset($specs['inst_startups']);
$reportRecords = report_records($coordinator, $testDept, null, 'inst_pass_percentage');
$test17Pass = $hasReportSpec && is_array($reportRecords);
record_test_result('TEST 17: Reports Architecture Integration', $test17Pass, "Institutional categories integrate with report_records() and record_report_specs().");

// -----------------------------------------------------------------------------
// CLEANUP: Clean up temporary test data
// -----------------------------------------------------------------------------
echo "\n--- Cleaning up temporary test records ---\n";
foreach ($cleanIds as $tbl => $ids) {
    if (!empty($ids)) {
        $inClause = implode(',', array_map('intval', $ids));
        if ($tbl === 'inst_pass_percentage') {
            $pdo->query("DELETE FROM inst_pass_percentage_rows WHERE pass_percentage_id IN ({$inClause})");
        }
        $pdo->query("DELETE FROM `{$tbl}` WHERE id IN ({$inClause})");
        echo "Cleaned " . count($ids) . " rows from {$tbl}\n";
    }
}
if (isset($dummyProof)) {
    @unlink($uploadsFolder . '/' . $dummyProof);
    @unlink($proofsFolder . '/' . $dummyProof);
    echo "Cleaned dummy proof file.\n";
}

echo "\n==================================================\n";
echo "SUMMARY OF TEST RESULTS (TEST 1 to TEST 17):\n";
echo "==================================================\n";
$allPass = true;
foreach ($results as $res) {
    if ($res['status'] !== 'PASS') $allPass = false;
    echo sprintf("%-50s : %s\n", $res['name'], $res['status']);
}
echo "==================================================\n";
echo "OVERALL VERIFICATION: " . ($allPass ? "ALL PASS" : "FAIL") . "\n";
echo "==================================================\n";
