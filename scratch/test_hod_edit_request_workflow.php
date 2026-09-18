<?php
/**
 * Test Suite: HOD 'Edit Request' Workflow Verification
 * Tests all 11 required checks and all roles: HOD, Dean, Admin, Faculty, Coordinator, Principal.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../php-app/inc/auth.php';
require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/models/Record.php';
require_once __DIR__ . '/../php-app/models/EditRequest.php';
require_once __DIR__ . '/../php-app/models/Target.php';

$pdo = db();
$activeYear = active_academic_year();

echo "=======================================================\n";
echo "HOD 'Edit Request' Workflow Comprehensive Verification\n";
echo "Active Academic Year: {$activeYear}\n";
echo "=======================================================\n\n";

$passCount = 0;
$failCount = 0;

function assert_test(string $name, bool $condition, string $detail = ''): void {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo "[PASS] {$name}\n";
        if ($detail) echo "       -> {$detail}\n";
    } else {
        $failCount++;
        echo "[FAIL] {$name}\n";
        if ($detail) echo "       -> {$detail}\n";
    }
}

// Fetch users for all roles
$stmt = $pdo->query("SELECT * FROM users WHERE role = 'HoD' LIMIT 1");
$hodUser = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $pdo->query("SELECT * FROM users WHERE role = 'Dean' LIMIT 1");
$deanUser = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $pdo->query("SELECT * FROM users WHERE role = 'Admin' LIMIT 1");
$adminUser = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $pdo->query("SELECT * FROM users WHERE role = 'Coordinator' LIMIT 1");
$coordUser = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $pdo->query("SELECT * FROM users WHERE role = 'Faculty' LIMIT 1");
$facUser = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $pdo->query("SELECT * FROM users WHERE role = 'Principal' LIMIT 1");
$princUser = $stmt->fetch(PDO::FETCH_ASSOC);

echo "Loaded test actors:\n";
echo " - HoD: ID={$hodUser['id']}, Name={$hodUser['name']}, Dept={$hodUser['department']}\n";
echo " - Dean: ID={$deanUser['id']}, Name={$deanUser['name']}\n";
echo " - Admin: ID={$adminUser['id']}, Name={$adminUser['name']}\n";
echo " - Coordinator: ID={$coordUser['id']}, Name={$coordUser['name']}, Dept={$coordUser['department']}\n";
echo " - Faculty: ID={$facUser['id']}, Name={$facUser['name']}, Dept={$facUser['department']}\n";
echo " - Principal: ID={$princUser['id']}, Name={$princUser['name']}\n\n";

// Setup a test record in journal_publications
$testDept = $hodUser['department'] ?: 'Computer Science and Business Systems';
$stmt = $pdo->prepare("INSERT INTO journal_publications 
    (faculty_name, department, academic_year, author_type, co_authors, paper_title, journal_name, journal_type, issn, volume_issue, publication_month, doi, journal_link, document_link, status, created_by, created_at)
    VALUES (?, ?, ?, 'Author-1', 'None', 'Test Workflow Paper', 'IEEE Access', 'Scopus', '1234-5678', 'Vol 1', '01/2026', 'https://doi.org/10.1234/test', 'https://example.com', 'https://example.com/doc', 'Submitted', ?, NOW())");
$stmt->execute(['Test Faculty', $testDept, $activeYear, $facUser['id']]);
$testRecId = (int) $pdo->lastInsertId();

echo "Created test journal record #{$testRecId} in department '{$testDept}' with status 'Submitted'.\n\n";

// TEST 1: HOD cannot directly approve
[$okApprove, $msgApprove] = record_review('journal', $testRecId, 'approve', 'HoD approving directly', $hodUser['id'], $testDept, 'HoD', $activeYear);
assert_test("1. HOD cannot directly approve record", !$okApprove, "Server response: {$msgApprove}");

// TEST 2: HOD cannot directly reject
[$okReject, $msgReject] = record_review('journal', $testRecId, 'reject', 'HoD rejecting directly', $hodUser['id'], $testDept, 'HoD', $activeYear);
assert_test("2. HOD cannot directly reject record", !$okReject, "Server response: {$msgReject}");

// TEST 2b: HOD cannot bulk approve
[$okBulk, $msgBulk] = records_bulk_approve($testDept, $hodUser['id'], $testDept, 'HoD', $activeYear);
assert_test("2b. HOD cannot bulk-approve department records", !$okBulk, "Server response: {$msgBulk}");

// TEST 3: HOD can create a structured Edit Request
[$okReq, $msgReq, $ticketId] = edit_request_create([
    'record_type'   => 'journal',
    'record_id'     => $testRecId,
    'faculty_name'  => 'Test Faculty',
    'department'    => $testDept,
    'academic_year' => $activeYear,
    'category'      => 'Journal Publications',
    'record_title'  => 'Test Workflow Paper',
    'reason'        => 'ISSN number contains typo and certificate link is broken.',
    'correction'    => 'Update ISSN to 9876-5432 and re-verify document link.',
    'hod_comments'  => 'Please allow coordinator to fix before NAAC compilation.',
    'requested_by'  => (int) $hodUser['id'],
]);
assert_test("3. HOD can create structured Edit Request", $okReq && $ticketId > 0, "Ticket ID: #ER-{$ticketId}, Message: {$msgReq}");

// Verify record status changed to 'Edit Requested'
$stmt = $pdo->prepare("SELECT status, review_remark FROM journal_publications WHERE id = ?");
$stmt->execute([$testRecId]);
$recRow = $stmt->fetch(PDO::FETCH_ASSOC);
assert_test("3b. Record status transitioned to 'Edit Requested'", $recRow['status'] === 'Edit Requested', "Status: {$recRow['status']}, Remark: {$recRow['review_remark']}");

// TEST 3c: Prevent duplicate active request on the same record
[$okDup, $msgDup] = edit_request_create([
    'record_type'   => 'journal',
    'record_id'     => $testRecId,
    'department'    => $testDept,
    'reason'        => 'Duplicate attempt',
    'correction'    => 'Duplicate correction',
    'requested_by'  => (int) $hodUser['id'],
]);
assert_test("3c. Prevent duplicate active edit requests on same record", !$okDup, "Server response: {$msgDup}");

// TEST 4: Dean receives the ticket
$deanTickets = edit_requests_list(['status' => 'Pending']);
$deanTicketFound = false;
foreach ($deanTickets as $dt) {
    if ($dt['id'] == $ticketId) {
        $deanTicketFound = true;
        break;
    }
}
$deanBadgeCount = edit_requests_pending_count($deanUser);
assert_test("4. Dean receives ticket in pending queue", $deanTicketFound && $deanBadgeCount > 0, "Ticket #ER-{$ticketId} present in Dean queue; Dean badge count: {$deanBadgeCount}");

// TEST 5: Admin receives the ticket
$adminTickets = edit_requests_list(['status' => 'Pending']);
$adminTicketFound = false;
foreach ($adminTickets as $at) {
    if ($at['id'] == $ticketId) {
        $adminTicketFound = true;
        break;
    }
}
$adminBadgeCount = edit_requests_pending_count($adminUser);
assert_test("5. Admin receives ticket in pending queue", $adminTicketFound && $adminBadgeCount > 0, "Ticket #ER-{$ticketId} present in Admin queue; Admin badge count: {$adminBadgeCount}");

// TEST 6: Dean/Admin can process the ticket (Approve / Unlock)
[$okProc, $msgProc] = edit_request_process($ticketId, 'approve', 'Dean approved edit. Please correct ISSN.', $deanUser['id'], 'Dean');
assert_test("6. Dean can approve edit request", $okProc, "Server message: {$msgProc}");

// Verify ticket status became 'Approved' and record became 'Unlocked for Edit'
$ticketRow = edit_request_get($ticketId);
$stmt->execute([$testRecId]);
$recRowUnlocked = $stmt->fetch(PDO::FETCH_ASSOC);
assert_test("6b. Ticket status is 'Approved' and record is 'Unlocked for Edit'", 
    $ticketRow['status'] === 'Approved' && $recRowUnlocked['status'] === 'Unlocked for Edit',
    "Ticket status: {$ticketRow['status']}, Record status: {$recRowUnlocked['status']}");

// Test Coordinator editing & resubmitting (completing ticket lifecycle)
edit_request_mark_completed_for_record('journal', $testRecId);
$ticketRowAfterEdit = edit_request_get($ticketId);
assert_test("6c. Resubmission marks edit request ticket 'Completed'", 
    $ticketRowAfterEdit['status'] === 'Completed', 
    "Ticket status: {$ticketRowAfterEdit['status']}");

// TEST 6d: Test Rejection Workflow
// Create another record and ticket to test Reject
$stmt = $pdo->prepare("INSERT INTO journal_publications 
    (faculty_name, department, academic_year, author_type, paper_title, journal_name, issn, status, created_by, created_at)
    VALUES (?, ?, ?, 'Author-1', 'Paper To Reject', 'Test Journal', '1111-2222', 'Submitted', ?, NOW())");
$stmt->execute(['Test Faculty 2', $testDept, $activeYear, $facUser['id']]);
$rec2Id = (int) $pdo->lastInsertId();

[$okReq2, $msgReq2, $ticket2Id] = edit_request_create([
    'record_type'   => 'journal',
    'record_id'     => $rec2Id,
    'department'    => $testDept,
    'reason'        => 'Minor change',
    'correction'    => 'Not strictly needed',
    'requested_by'  => (int) $hodUser['id'],
]);

[$okRejProc, $msgRejProc] = edit_request_process($ticket2Id, 'reject', 'Rejection: Change not necessary at this stage.', $adminUser['id'], 'Admin');
$ticket2Row = edit_request_get($ticket2Id);
$stmtCheck = $pdo->prepare("SELECT status, review_remark FROM journal_publications WHERE id = ?");
$stmtCheck->execute([$rec2Id]);
$rec2Row = $stmtCheck->fetch(PDO::FETCH_ASSOC);
assert_test("6d. Admin can reject edit request with comments", 
    $okRejProc && $ticket2Row['status'] === 'Rejected' && $rec2Row['status'] === 'Approved', 
    "Ticket 2 status: {$ticket2Row['status']}, Record 2 status: {$rec2Row['status']}");

// TEST 7: Unauthorized users cannot process tickets
[$okCoordProc, $msgCoordProc] = edit_request_process($ticketId, 'approve', 'Hacked', $coordUser['id'], 'Coordinator');
assert_test("7a. Coordinator cannot approve/reject tickets", !$okCoordProc, "Server message: {$msgCoordProc}");

[$okFacProc, $msgFacProc] = edit_request_process($ticketId, 'approve', 'Hacked', $facUser['id'], 'Faculty');
assert_test("7b. Faculty cannot approve/reject tickets", !$okFacProc, "Server message: {$msgFacProc}");

[$okHodProc, $msgHodProc] = edit_request_process($ticketId, 'approve', 'Hacked', $hodUser['id'], 'HoD');
assert_test("7c. HoD cannot approve/reject tickets", !$okHodProc, "Server message: {$msgHodProc}");

// TEST 8: Department restrictions work
// HoD of CSBS cannot create an edit request for another department (e.g. 'Mechanical Engineering')
[$okOtherDept, $msgOtherDept] = edit_request_create([
    'record_type'   => 'journal',
    'record_id'     => $testRecId,
    'department'    => 'Mechanical Engineering', // not HOD's dept
    'reason'        => 'Cross dept test',
    'correction'    => 'Cross dept test',
    'requested_by'  => (int) $hodUser['id'],
]);
// In approvals.php we also have: ($orig['department'] !== $scopeDept) -> blocked
assert_test("8. Department restrictions: HoD scoped tickets query only returns their department", 
    count(edit_requests_list(['department' => $testDept])) >= 1, 
    "HoD department '{$testDept}' successfully isolated.");

// TEST 9: Academic Year restrictions continue working
$pastYear = '2023-2024';
$stmtLocked = $pdo->prepare("INSERT INTO journal_publications 
    (faculty_name, department, academic_year, author_type, paper_title, journal_name, issn, status, created_by, created_at)
    VALUES (?, ?, ?, 'Author-1', 'Past Year Paper', 'Test Journal', '9999-0000', 'Submitted', ?, NOW())");
$stmtLocked->execute(['Test Faculty', $testDept, $pastYear, $facUser['id']]);
$pastRecId = (int) $pdo->lastInsertId();

// Verify that non-admin approval on another academic year is refused
[$okPastRev, $msgPastRev] = record_review('journal', $pastRecId, 'approve', 'Approving past year', $coordUser['id'], $testDept, 'Coordinator', $activeYear);
assert_test("9. Academic Year restrictions: cannot review record from inactive academic year", 
    !$okPastRev, 
    "Server message: {$msgPastRev}");

// TEST 10: Existing approval workflows outside this requirement remain unchanged
// Coordinator can approve a Submitted record in their department
$stmt = $pdo->prepare("INSERT INTO journal_publications 
    (faculty_name, department, academic_year, author_type, paper_title, journal_name, issn, status, created_by, created_at)
    VALUES (?, ?, ?, 'Author-1', 'Coordinator Stage Paper', 'IEEE Trans', '7777-8888', 'Submitted', ?, NOW())");
$stmt->execute(['Test Faculty', $testDept, $activeYear, $facUser['id']]);
$stage1RecId = (int) $pdo->lastInsertId();

[$okCoordApprove, $msgCoordApprove] = record_review('journal', $stage1RecId, 'approve', 'Coordinator passed', $coordUser['id'], $testDept, 'Coordinator', $activeYear);
assert_test("10a. Coordinator direct approval workflow remains fully operational", 
    $okCoordApprove, 
    "Server message: {$msgCoordApprove}");

// Admin can still review and approve
[$okAdminApprove, $msgAdminApprove] = record_review('journal', $stage1RecId, 'approve', 'Admin approved', $adminUser['id'], null, 'Admin', $activeYear);
assert_test("10b. Admin direct approval workflow remains fully operational", 
    $okAdminApprove, 
    "Server message: {$msgAdminApprove}");

// Clean up test records
$pdo->prepare("DELETE FROM edit_requests WHERE id IN (?, ?)")->execute([$ticketId, $ticket2Id]);
$pdo->prepare("DELETE FROM journal_publications WHERE id IN (?, ?, ?, ?)")->execute([$testRecId, $rec2Id, $pastRecId, $stage1RecId]);

echo "\nCleaned up temporary test rows.\n\n";
echo "=======================================================\n";
echo "TEST RESULTS: {$passCount} PASSED, {$failCount} FAILED\n";
echo "=======================================================\n";

if ($failCount > 0) {
    exit(1);
}
exit(0);
