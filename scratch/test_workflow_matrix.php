<?php
/**
 * Automated Verification Script for BUG-WF-11 Governance Workflow & Test Matrix
 */
require_once __DIR__ . '/../php-app/inc/auth.php';
require_once __DIR__ . '/../php-app/models/Record.php';
require_once __DIR__ . '/../php-app/models/User.php';
require_once __DIR__ . '/../php-app/models/Department.php';

echo "=== STARTING BUG-WF-11 GOVERNANCE WORKFLOW TEST MATRIX ===\n\n";

$pdo = db();
$activeYear = active_academic_year();

// Helper: load test users
function getUser(string $role, string $dept = 'CSBS') {
    $stmt = db()->prepare("SELECT * FROM users WHERE role = ? AND (department = ? OR department IS NULL OR department = '') LIMIT 1");
    $stmt->execute([$role, $dept]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u) {
        throw new Exception("Test user for role {$role} not found");
    }
    return $u;
}

$admin       = getUser('Admin');
$dean        = getUser('Dean');
$director    = getUser('Director');
$hodCsbs     = getUser('HoD', 'CSBS');
$coordCsbs   = getUser('Coordinator', 'CSBS');
$facultyCsbs = getUser('Faculty', 'CSBS');

// Ensure a user from another department (e.g. CSE) exists for cross-dept security test
$stmt = db()->prepare("SELECT * FROM users WHERE role = 'HoD' AND department = 'CSE' LIMIT 1");
$stmt->execute();
$hodCse = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$hodCse) {
    db()->prepare("INSERT INTO users (name, email, password, role, department, status, created_at) VALUES ('HoD CSE', 'hod_cse@atts.edu', 'pass', 'HoD', 'CSE', 1, NOW())")->execute();
    $hodCse = getUser('HoD', 'CSE');
}

$stmt = db()->prepare("SELECT * FROM users WHERE role = 'Faculty' AND email = 'faculty2@atts.edu' LIMIT 1");
$stmt->execute();
$faculty2 = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$faculty2) {
    db()->prepare("INSERT INTO users (name, email, password, role, department, status, created_at) VALUES ('Faculty 2', 'faculty2@atts.edu', 'pass', 'Faculty', 'CSBS', 1, NOW())")->execute();
    $faculty2 = db()->query("SELECT * FROM users WHERE email = 'faculty2@atts.edu'")->fetch(PDO::FETCH_ASSOC);
}

$passCount = 0;
$failCount = 0;

function assertTest(bool $condition, string $testName, string $details = '') {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo "  [PASS] {$testName}\n";
        if ($details) echo "         {$details}\n";
    } else {
        $failCount++;
        echo "  [FAIL] {$testName}\n";
        if ($details) echo "         ERROR: {$details}\n";
    }
}

// --------------------------------------------------------------------------
// TEST 1: Faculty submits record -> Coordinator sees it
// --------------------------------------------------------------------------
echo "TEST 1: Faculty submits record -> Coordinator sees it in Pending Verification\n";
$stmt = $pdo->prepare("INSERT INTO journal_publications (created_by, faculty_name, department, academic_year, status, paper_title, journal_name, created_at)
                       VALUES (?, ?, ?, ?, 'Submitted', 'Test Paper By Faculty Kumar', 'IEEE Transactions', NOW())");
$stmt->execute([$facultyCsbs['id'], $facultyCsbs['name'], $facultyCsbs['department'], $activeYear]);
$recId1 = (int)$pdo->lastInsertId();

// Audit log
record_workflow_audit('journal', $recId1, 'FACULTY_SUBMITTED', $facultyCsbs, null, 'Submitted', 'Initial submission', null, $facultyCsbs['department'], $activeYear);

$coordPending = pending_records($coordCsbs['department'], null, 'Coordinator', $activeYear);
$foundInCoord = false;
foreach ($coordPending as $r) {
    if ($r['_type_key'] === 'journal' && (int)$r['id'] === $recId1 && $r['status'] === 'Submitted') {
        $foundInCoord = true;
        break;
    }
}
assertTest($foundInCoord, "TEST 1", "Coordinator sees record #{$recId1} with status 'Submitted'");

// --------------------------------------------------------------------------
// TEST 2: Faculty submits record -> HoD does NOT get an approval button
// --------------------------------------------------------------------------
echo "\nTEST 2: HoD direct approval is blocked by RBAC\n";
[$hodApproveOk, $hodApproveMsg] = record_review('journal', $recId1, 'approve', 'HoD trying to approve', (int)$hodCsbs['id'], $hodCsbs['department'], 'HoD', $activeYear);
assertTest(!$hodApproveOk && strpos($hodApproveMsg, 'reviewer only') !== false, "TEST 2", "HoD direct approve blocked: '{$hodApproveMsg}'");

// --------------------------------------------------------------------------
// TEST 3: HoD direct reject is blocked by RBAC
// --------------------------------------------------------------------------
echo "\nTEST 3: HoD direct reject is blocked by RBAC\n";
[$hodRejectOk, $hodRejectMsg] = record_review('journal', $recId1, 'reject', 'HoD trying to reject', (int)$hodCsbs['id'], $hodCsbs['department'], 'HoD', $activeYear);
assertTest(!$hodRejectOk && strpos($hodRejectMsg, 'reviewer only') !== false, "TEST 3", "HoD direct reject blocked: '{$hodRejectMsg}'");

// Now Coordinator approves record #recId1 -> becomes Approved in DB
echo "\nCOORDINATOR VERIFIES & APPROVES RECORD #{$recId1} INTO DATABASE\n";
[$coordApproveOk, $coordApproveMsg] = record_review('journal', $recId1, 'approve', 'Coordinator verified proof', (int)$coordCsbs['id'], $coordCsbs['department'], 'Coordinator', $activeYear);
$checkRec1 = record_find('journal', $recId1);
assertTest($coordApproveOk && $checkRec1['status'] === 'Approved', "Coordinator approval", "Record status is now 'Approved' in database");

// --------------------------------------------------------------------------
// TEST 4: HoD requests edit -> Edit Request created, dept auto-populated
// --------------------------------------------------------------------------
echo "\nTEST 4: HoD requests edit for record #{$recId1} -> Edit Request created, dept auto-populated\n";
$editReqData = [
    'record_type'     => 'journal',
    'record_id'       => $recId1,
    'reason'          => 'Publication date is incorrect. Please verify against uploaded certificate.',
    'specific_field'  => 'publication_date',
    'current_value'   => '12/03/2026',
    'requested_value' => '10/03/2026',
];
[$reqOk, $reqMsg] = edit_request_create($editReqData, $hodCsbs);
$checkRec1AfterReq = record_find('journal', $recId1);

$stmt = $pdo->prepare("SELECT * FROM edit_requests WHERE record_id = ? AND record_type = 'journal' ORDER BY id DESC LIMIT 1");
$stmt->execute([$recId1]);
$savedReq = $stmt->fetch(PDO::FETCH_ASSOC);

assertTest($reqOk && $savedReq && $savedReq['department'] === 'CSBS' && $savedReq['status'] === 'Pending' && $checkRec1AfterReq['status'] === 'Edit Requested',
    "TEST 4",
    "Edit Request ER-{$savedReq['id']} created with department auto-populated as '{$savedReq['department']}', record status set to 'Edit Requested'");

// --------------------------------------------------------------------------
// TEST 5: HoD tries to directly edit record -> 403 Access Denied
// --------------------------------------------------------------------------
echo "\nTEST 5: HoD tries to directly edit record -> Access Denied\n";
[$canHodEdit, $hodEditErr] = can_edit_record('journal', $recId1, $hodCsbs);
assertTest(!$canHodEdit, "TEST 5", "HoD direct edit denied: '{$hodEditErr}'");

// --------------------------------------------------------------------------
// TEST 6 & 7: Dean opens edit request -> Dean rejects request -> record unchanged
// --------------------------------------------------------------------------
echo "\nTEST 6 & 7: Dean reviews edit request -> Rejects -> record remains unchanged\n";
// Create another record for rejection test
$stmt = $pdo->prepare("INSERT INTO journal_publications (created_by, faculty_name, department, academic_year, status, paper_title, journal_name, created_at)
                       VALUES (?, ?, ?, ?, 'Approved', 'Paper to Reject Edit', 'Nature', NOW())");
$stmt->execute([$facultyCsbs['id'], $facultyCsbs['name'], $facultyCsbs['department'], $activeYear]);
$recId2 = (int)$pdo->lastInsertId();

$editReqData2 = [
    'record_type'     => 'journal',
    'record_id'       => $recId2,
    'reason'          => 'Typo in author name',
];
edit_request_create($editReqData2, $hodCsbs);
$stmt = $pdo->prepare("SELECT id FROM edit_requests WHERE record_id = ? ORDER BY id DESC LIMIT 1");
$stmt->execute([$recId2]);
$reqId2 = (int)$stmt->fetchColumn();

// Dean rejects request 2
[$rejOk, $rejMsg] = edit_request_review($reqId2, 'reject', 'Insufficient proof provided', $dean);
$rec2AfterRej = record_find('journal', $recId2);
$req2AfterRej = edit_request_find($reqId2);

assertTest($rejOk && $req2AfterRej['status'] === 'Rejected' && $rec2AfterRej['status'] === 'Approved',
    "TEST 6 & 7",
    "Dean rejected ER-{$reqId2}; request status is 'Rejected', record reverted to 'Approved' without changes");

// --------------------------------------------------------------------------
// TEST 8: Dean approves request -> Coordinator receives authorized correction
// --------------------------------------------------------------------------
echo "\nTEST 8: Dean approves request ER-{$savedReq['id']} -> Coordinator receives authorized correction\n";
[$apprOk, $apprMsg] = edit_request_review((int)$savedReq['id'], 'approve', 'Proceed with correction', $dean);
$rec1AfterAppr = record_find('journal', $recId1);
$req1AfterAppr = edit_request_find((int)$savedReq['id']);

assertTest($apprOk && $req1AfterAppr['status'] === 'Approved' && $rec1AfterAppr['status'] === 'Unlocked for Edit',
    "TEST 8",
    "Dean approved ER-{$savedReq['id']}; record is now 'Unlocked for Edit' for Coordinator");

// --------------------------------------------------------------------------
// TEST 9: Coordinator edits authorized record -> Edit succeeds
// --------------------------------------------------------------------------
echo "\nTEST 9: Coordinator edits authorized record -> Edit succeeds\n";
[$canCoordEdit, $coordEditErr, $recData] = can_edit_record('journal', $recId1, $coordCsbs);
assertTest($canCoordEdit, "TEST 9 (Auth check)", "Coordinator is authorized to edit unlocked record #{$recId1}");

// Simulate Coordinator resubmitting corrected values
$pdo->prepare("UPDATE journal_publications SET paper_title = 'Corrected Title By Coordinator', status = 'Approved', review_remark = 'Corrected and resubmitted' WHERE id = ?")
    ->execute([$recId1]);
edit_request_complete($recId1, 'journal', (int)$coordCsbs['id'], ['paper_title' => 'Test Paper By Faculty Kumar'], ['paper_title' => 'Corrected Title By Coordinator']);
record_workflow_audit('journal', $recId1, 'COORDINATOR_RESUBMITTED', $coordCsbs, 'Unlocked for Edit', 'Approved', 'Resubmitted', null, 'CSBS', $activeYear);

$rec1Updated = record_find('journal', $recId1);
$req1Updated = edit_request_find((int)$savedReq['id']);

assertTest($rec1Updated['paper_title'] === 'Corrected Title By Coordinator' && $rec1Updated['status'] === 'Approved' && $req1Updated['status'] === 'Completed',
    "TEST 9 (Resubmit)",
    "Coordinator resubmitted: record updated in DB, edit request marked 'Completed'");

// --------------------------------------------------------------------------
// TEST 10: Coordinator attempts to edit a different (locked) record -> 403 / unauthorized
// --------------------------------------------------------------------------
echo "\nTEST 10: Coordinator attempts to edit an unauthorized/locked record -> 403 / unauthorized\n";
// Record #recId2 is Approved (not Unlocked for Edit)
[$canEditLocked, $lockedErr] = can_edit_record('journal', $recId2, $coordCsbs);
assertTest(!$canEditLocked, "TEST 10", "Coordinator cannot edit locked record: '{$lockedErr}'");

// --------------------------------------------------------------------------
// TEST 11: Coordinator resubmits -> HoD can review updated record
// --------------------------------------------------------------------------
echo "\nTEST 11: HoD can review updated record\n";
$hodRecords = pending_records($hodCsbs['department'], null, 'HoD', $activeYear);
$foundInHod = false;
foreach ($hodRecords as $hr) {
    if ($hr['_type_key'] === 'journal' && (int)$hr['id'] === $recId1 && $hr['paper_title'] === 'Corrected Title By Coordinator') {
        $foundInHod = true;
        break;
    }
}
assertTest($foundInHod, "TEST 11", "HoD sees updated record in Review Records list");

// --------------------------------------------------------------------------
// TEST 12: CSE HoD attempts to access / request edit for CSBS record -> Blocked
// --------------------------------------------------------------------------
echo "\nTEST 12: Cross-department access: CSE HoD attempts to request edit for CSBS record -> Blocked\n";
$crossDeptReq = [
    'record_type' => 'journal',
    'record_id'   => $recId1, // CSBS record
    'reason'      => 'CSE HoD trying to modify CSBS record',
];
[$crossOk, $crossMsg] = edit_request_create($crossDeptReq, $hodCse);
assertTest(!$crossOk && strpos($crossMsg, 'Access Denied') !== false, "TEST 12", "Cross-dept edit request blocked: '{$crossMsg}'");

// --------------------------------------------------------------------------
// TEST 13: Faculty attempts to directly edit record -> Blocked
// --------------------------------------------------------------------------
echo "\nTEST 13: Faculty attempts to directly edit record -> Blocked\n";
[$canFacultyEdit, $facEditErr] = can_edit_record('journal', $recId1, $facultyCsbs);
assertTest(!$canFacultyEdit, "TEST 13", "Faculty direct edit blocked: '{$facEditErr}'");

// --------------------------------------------------------------------------
// TEST 14: Admin views workflow & audit logs
// --------------------------------------------------------------------------
echo "\nTEST 14: Admin monitors workflow & audit logs\n";
$stmt = $pdo->prepare("SELECT * FROM workflow_audit_logs WHERE record_id = ? AND record_type = 'journal' ORDER BY id ASC");
$stmt->execute([$recId1]);
$auditLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$actionsLogged = array_column($auditLogs, 'action');
$hasFacultySubmitted = in_array('FACULTY_SUBMITTED', $actionsLogged, true);
$hasHodEditRequested = in_array('HOD_EDIT_REQUESTED', $actionsLogged, true);
$hasDeanEditApproved = in_array('DEAN_EDIT_APPROVED', $actionsLogged, true);
$hasCoordResubmitted = in_array('COORDINATOR_RESUBMITTED', $actionsLogged, true);

assertTest($hasFacultySubmitted && $hasHodEditRequested && $hasDeanEditApproved && $hasCoordResubmitted,
    "TEST 14",
    "Complete audit trail recorded: " . implode(' -> ', $actionsLogged));

echo "\n============================================================\n";
echo "TEST MATRIX RESULTS: {$passCount} PASSED, {$failCount} FAILED\n";
echo "============================================================\n";

if ($failCount > 0) {
    exit(1);
}
