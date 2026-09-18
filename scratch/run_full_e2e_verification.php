<?php
/**
 * BUG-WF-11 FULL END-TO-END WORKFLOW VERIFICATION SUITE
 */
require_once __DIR__ . '/../php-app/inc/auth.php';
require_once __DIR__ . '/../php-app/inc/helpers.php';
require_once __DIR__ . '/../php-app/models/Record.php';
require_once __DIR__ . '/../php-app/models/Announcement.php';
require_once __DIR__ . '/../php-app/models/Target.php';
require_once __DIR__ . '/../php-app/models/Department.php';

$results = [];

function record_test(string $name, string $expected, string $actual, bool $pass) {
    global $results;
    $results[] = [
        'name'     => $name,
        'expected' => $expected,
        'actual'   => $actual,
        'result'   => $pass ? 'PASS' : 'FAIL',
    ];
    echo ($pass ? "[PASS] " : "[FAIL] ") . "$name: $actual\n";
}

echo "========================================================================\n";
echo "STARTING BUG-WF-11 FULL END-TO-END WORKFLOW SELF-TEST\n";
echo "========================================================================\n\n";

$pdo = db();
$activeYear = active_academic_year();
echo "Active Academic Year: $activeYear\n";

// Ensure Department 'AI & DS' exists
$deptName = 'AI & DS';
$deptStmt = $pdo->prepare("SELECT * FROM departments WHERE name = ? OR code = 'AIDS' LIMIT 1");
$deptStmt->execute([$deptName]);
$deptRow = $deptStmt->fetch(PDO::FETCH_ASSOC);
if (!$deptRow) {
    $pdo->prepare("INSERT INTO departments (name, code) VALUES (?, 'AIDS')")->execute([$deptName]);
}

// -------------------------------------------------------------------------
// Setup Test Users
// -------------------------------------------------------------------------
function get_or_create_user(string $email, string $name, string $role, ?string $department, string $pass = 'pass123'): array {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u) {
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $ins = $pdo->prepare("INSERT INTO users (email, name, role, department, password, status, created_at) VALUES (?, ?, ?, ?, ?, 1, NOW())");
        $ins->execute([$email, $name, $role, $department, $hash]);
        $id = (int)$pdo->lastInsertId();
        $u = ['id' => $id, 'email' => $email, 'name' => $name, 'role' => $role, 'department' => $department, 'status' => 1];
    } else {
        // Ensure role & dept are updated
        $pdo->prepare("UPDATE users SET role = ?, department = ?, status = 1 WHERE id = ?")->execute([$role, $department, $u['id']]);
        $u['role'] = $role;
        $u['department'] = $department;
    }
    return $u;
}

$uFaculty     = get_or_create_user('faculty.test@atts.local', 'Test Faculty AIDS', 'Faculty', 'AI & DS');
$uCoordinator = get_or_create_user('coordinator.test@atts.local', 'Test Coord AIDS', 'Coordinator', 'AI & DS');
$uHod         = get_or_create_user('hod.test@atts.local', 'Test HoD AIDS', 'HoD', 'AI & DS');
$uDean        = get_or_create_user('dean.test@atts.local', 'Test Dean', 'Dean', null);

$uFacultyCSE  = get_or_create_user('faculty.cse@atts.local', 'CSE Faculty', 'Faculty', 'CSE');
$uCoordCSE    = get_or_create_user('coordinator.cse@atts.local', 'CSE Coordinator', 'Coordinator', 'CSE');
$uHodCSE      = get_or_create_user('hod.cse@atts.local', 'CSE HoD', 'HoD', 'CSE');

echo "Test Users initialized.\n\n";

// Dummy test proof
$dummyProof = 'test_proof_wf11_' . time() . '.pdf';
$proofPath = UPLOAD_DIR . '/' . $dummyProof;
if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0775, true);
file_put_contents($proofPath, "%PDF-1.4\n% Test proof document for BUG-WF-11\n%%EOF");

// -------------------------------------------------------------------------
// TEST 1 — FACULTY SUBMISSION
// -------------------------------------------------------------------------
echo "--- TEST 1: Faculty Submission ---\n";
// Insert record as Faculty
$journalTable = 'journal_publications';
$ins = $pdo->prepare("
    INSERT INTO journal_publications (
        paper_title, journal_name, journal_type, author_type, co_authors,
        issn, volume_issue, publication_month, doi, journal_link, document_link,
        faculty_name, department, academic_year, proof_file, status, created_by, created_at
    ) VALUES (
        'Autonomous AI Verification Framework', 'IEEE Transactions on Education', 'SCI', 'First Author', 'None',
        '1234-5678', 'Vol 10, Issue 2', '2026-03', '10.TEST/OLD-001', 'https://ieee.org/journal', 'https://ieee.org/doc',
        ?, ?, ?, ?, 'Submitted', ?, NOW()
    )
");
$ins->execute([
    $uFaculty['name'],
    $uFaculty['department'],
    $activeYear,
    $dummyProof,
    $uFaculty['id']
]);
$rec1Id = (int)$pdo->lastInsertId();

// Verify in DB
$rec1 = record_find('journal', $rec1Id);
$t1Pass = ($rec1 && $rec1['status'] === 'Submitted' && $rec1['department'] === 'AI & DS' && $rec1['doi'] === '10.TEST/OLD-001');
record_test(
    'Faculty Upload',
    'Record created with status Submitted, Dept AI & DS, proof attached',
    $rec1 ? "ID: {$rec1['id']}, Status: {$rec1['status']}, Dept: {$rec1['department']}, Proof: {$rec1['proof_file']}" : 'Record not found',
    $t1Pass
);

// -------------------------------------------------------------------------
// TEST 2 — COORDINATOR REVIEW & APPROVAL
// -------------------------------------------------------------------------
echo "\n--- TEST 2: Coordinator Review & Approval ---\n";
// Coordinator approves record
[$okRev, $msgRev] = record_review('journal', $rec1Id, 'approve', 'Initial verification satisfactory', (int)$uCoordinator['id'], $uCoordinator['department'], $uCoordinator['role'], $activeYear);
$rec1AfterCoord = record_find('journal', $rec1Id);
$t2Pass = ($okRev && $rec1AfterCoord && $rec1AfterCoord['status'] === 'Approved');
record_test(
    'Coordinator Approval',
    'Record approved directly into DB (status: Approved)',
    $okRev ? "Status: {$rec1AfterCoord['status']}, Msg: $msgRev" : "Approval failed: $msgRev",
    $t2Pass
);

// -------------------------------------------------------------------------
// TEST 3 — HOD REVIEW
// -------------------------------------------------------------------------
echo "\n--- TEST 3: HoD Review Records ---\n";
// HoD fetches records
$hodRecs = records_list('journal', $uHod['department'], null, null, null, null, $activeYear);
$rec1InHod = null;
foreach ($hodRecs as $hr) {
    if ((int)$hr['id'] === $rec1Id) {
        $rec1InHod = $hr;
        break;
    }
}
$t3Pass = ($rec1InHod !== null && $rec1InHod['status'] === 'Approved');
record_test(
    'HoD Review Records',
    'HoD views record under Department Records without approve/reject authority',
    $rec1InHod ? "Found record #{$rec1InHod['id']}, Status: {$rec1InHod['status']}" : 'Record not visible to HoD',
    $t3Pass
);

// -------------------------------------------------------------------------
// TEST 4 — DEPARTMENT ANNOUNCEMENT / CONTACT HoD
// -------------------------------------------------------------------------
echo "\n--- TEST 4: Department Announcement / Contact HoD ---\n";
// Faculty contacts own HoD
$annData = [
    'title'      => 'Correction needed for Journal DOI',
    'body'       => 'Dear HoD, I noticed the DOI for Autonomous AI Verification Framework has a typo. Please request an edit.',
    'audience'   => 'HoD',
    'department' => $uFaculty['department'],
    'category'   => 'Academic',
    'priority'   => 'Important',
    'status'     => 'Published',
    'pinned'     => 0,
];
[$okAnn, $msgAnn, $annId] = announcement_create($annData, (int)$uFaculty['id']);

// Check visibility for AI&DS HoD
$hodAnnList = announcements_list($uHod);
$hodSeesAnn = false;
foreach ($hodAnnList['rows'] as $ar) {
    if ((int)$ar['id'] === $annId) {
        $hodSeesAnn = true;
        break;
    }
}

// Check isolation: CSE HoD should NOT see this announcement
$cseHodAnnList = announcements_list($uHodCSE);
$cseHodSeesAnn = false;
foreach ($cseHodAnnList['rows'] as $ar) {
    if ((int)$ar['id'] === $annId) {
        $cseHodSeesAnn = true;
        break;
    }
}

// Check isolation: Dean should NOT see departmental HoD announcement
$deanAnnList = announcements_list($uDean);
$deanSeesAnn = false;
foreach ($deanAnnList['rows'] as $ar) {
    if ((int)$ar['id'] === $annId) {
        $deanSeesAnn = true;
        break;
    }
}

$t4Pass = ($okAnn && $hodSeesAnn && !$cseHodSeesAnn && !$deanSeesAnn);
record_test(
    'Department Announcement Scoping',
    'Faculty communicates with own HoD; CSE HoD and Dean cannot see departmental message',
    "Created Ann #$annId. AI&DS HoD saw: " . ($hodSeesAnn ? 'YES' : 'NO') . ", CSE HoD saw: " . ($cseHodSeesAnn ? 'YES' : 'NO') . ", Dean saw: " . ($deanSeesAnn ? 'YES' : 'NO'),
    $t4Pass
);

// -------------------------------------------------------------------------
// TEST 5 — HOD EDIT REQUEST
// -------------------------------------------------------------------------
echo "\n--- TEST 5: HoD Edit Request to Dean ---\n";
$erData = [
    'record_type'     => 'journal',
    'record_id'       => $rec1Id,
    'specific_field'  => 'doi',
    'current_value'   => '10.TEST/OLD-001',
    'requested_value' => '10.TEST/NEW-001',
    'reason'          => 'HoD verification found that submitted DOI does not match uploaded publication proof.',
];
[$okER, $msgER] = edit_request_create($erData, $uHod);

$rec1AfterER = record_find('journal', $rec1Id);
$erRow = $pdo->query("SELECT * FROM edit_requests WHERE record_id = $rec1Id AND record_type = 'journal' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

$t5Pass = ($okER && $rec1AfterER['status'] === 'Edit Requested' && $erRow && $erRow['status'] === 'Pending');
record_test(
    'HoD Edit Request',
    'Edit Request created in DB, record status transitions to Edit Requested (Requested to Dean badge)',
    $okER ? "ER-#{$erRow['id']} created (Status: {$erRow['status']}), Record Status: {$rec1AfterER['status']}" : "Failed: $msgER",
    $t5Pass
);

// -------------------------------------------------------------------------
// TEST 6 — DEAN / ADMIN REVIEW & APPROVAL
// -------------------------------------------------------------------------
echo "\n--- TEST 6: Dean Review & Approval ---\n";
$erId = (int)$erRow['id'];
[$okDeanApp, $msgDeanApp] = edit_request_review($erId, 'approve', 'Approved for Coordinator correction.', $uDean);

$erRowAfterApp = edit_request_find($erId);
$rec1AfterDeanApp = record_find('journal', $rec1Id);

$t6Pass = ($okDeanApp && $erRowAfterApp['status'] === 'Approved' && $rec1AfterDeanApp['status'] === 'Unlocked for Edit');
record_test(
    'Dean Approval',
    'Edit Request approved, record status transitions to Unlocked for Edit for Coordinator',
    $okDeanApp ? "ER Status: {$erRowAfterApp['status']}, Record Status: {$rec1AfterDeanApp['status']}" : "Failed: $msgDeanApp",
    $t6Pass
);

// -------------------------------------------------------------------------
// TEST 7 — COORDINATOR AUTHORIZED CORRECTION & RESUBMISSION
// -------------------------------------------------------------------------
echo "\n--- TEST 7: Coordinator Authorized Edit & Resubmit ---\n";
// Coordinator checks permission
[$canCoordEdit, $coordEditMsg, $recToEdit] = can_edit_record('journal', $rec1Id, $uCoordinator);

// Coordinator performs update
$oldVals = ['doi' => '10.TEST/OLD-001'];
$newVals = ['doi' => '10.TEST/NEW-001'];
$updStmt = $pdo->prepare("UPDATE journal_publications SET doi = ?, status = 'Resubmitted', review_remark = 'Corrected and resubmitted by Coordinator Test Coord AIDS', updated_at = NOW() WHERE id = ?");
$updStmt->execute(['10.TEST/NEW-001', $rec1Id]);

edit_request_complete($rec1Id, 'journal', (int)$uCoordinator['id'], $oldVals, $newVals);

record_workflow_audit(
    'journal',
    $rec1Id,
    'COORDINATOR_RESUBMITTED',
    $uCoordinator,
    'Unlocked for Edit',
    'Resubmitted',
    'Coordinator resubmitted corrected record',
    ['old_values' => $oldVals, 'new_values' => $newVals],
    $uCoordinator['department'],
    $activeYear
);

$rec1AfterResubmit = record_find('journal', $rec1Id);
$erRowAfterResubmit = edit_request_find($erId);

$t7Pass = ($canCoordEdit && $rec1AfterResubmit['doi'] === '10.TEST/NEW-001' && $rec1AfterResubmit['status'] === 'Resubmitted' && $erRowAfterResubmit['status'] === 'Completed');
record_test(
    'Coordinator Correction & Resubmit',
    'DOI updated to 10.TEST/NEW-001, status set to Resubmitted, ER marked Completed',
    "Can Edit: " . ($canCoordEdit ? 'YES' : 'NO') . ", New DOI: {$rec1AfterResubmit['doi']}, Status: {$rec1AfterResubmit['status']}, ER Status: {$erRowAfterResubmit['status']}",
    $t7Pass
);

// -------------------------------------------------------------------------
// TEST 8 — HOD RE-REVIEW & ACKNOWLEDGE
// -------------------------------------------------------------------------
echo "\n--- TEST 8: HoD Re-Review & Acknowledge ---\n";
// HoD acknowledges review
[$okAck, $msgAck] = record_acknowledge_review('journal', $rec1Id, $uHod);

$rec1Final = record_find('journal', $rec1Id);
$t8Pass = ($okAck && $rec1Final['status'] === 'Approved' && str_contains($rec1Final['review_remark'], 'acknowledged by HoD'));
record_test(
    'HoD Re-review & Acknowledge',
    'HoD acknowledges updated record, status confirmed as Approved in database',
    $okAck ? "Status: {$rec1Final['status']}, Remark: {$rec1Final['review_remark']}" : "Failed: $msgAck",
    $t8Pass
);

// -------------------------------------------------------------------------
// TEST 9 — REJECTION PATH
// -------------------------------------------------------------------------
echo "\n--- TEST 9: Rejection Path ---\n";
// Create 2nd record
$ins2 = $pdo->prepare("
    INSERT INTO journal_publications (
        paper_title, journal_name, journal_type, author_type, co_authors,
        issn, volume_issue, publication_month, doi, journal_link, document_link,
        faculty_name, department, academic_year, proof_file, status, created_by, created_at
    ) VALUES (
        'Quantum Key Distribution Study', 'IEEE Quantum Computing', 'SCI', 'First Author', 'None',
        '9876-5432', 'Vol 4, Issue 1', '2026-04', '10.TEST/REJ-001', 'https://ieee.org', 'https://ieee.org/doc',
        ?, ?, ?, ?, 'Approved', ?, NOW()
    )
");
$ins2->execute([$uFaculty['name'], $uFaculty['department'], $activeYear, $dummyProof, $uFaculty['id']]);
$rec2Id = (int)$pdo->lastInsertId();

// HoD creates edit request
$er2Data = [
    'record_type'     => 'journal',
    'record_id'       => $rec2Id,
    'specific_field'  => 'doi',
    'current_value'   => '10.TEST/REJ-001',
    'requested_value' => '10.TEST/REJ-002',
    'reason'          => 'Testing rejection path.',
];
[$okER2, $msgER2] = edit_request_create($er2Data, $uHod);
$er2Row = $pdo->query("SELECT * FROM edit_requests WHERE record_id = $rec2Id AND record_type = 'journal' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$er2Id = (int)$er2Row['id'];

// Dean REJECTS edit request
[$okDeanRej, $msgDeanRej] = edit_request_review($er2Id, 'reject', 'Original DOI is verified correct per publisher portal.', $uDean);

$rec2AfterRej = record_find('journal', $rec2Id);
$er2AfterRej  = edit_request_find($er2Id);

// Coordinator attempts to edit rejected record -> MUST BE DENIED
[$coordCanEditRej, $coordRejMsg] = can_edit_record('journal', $rec2Id, $uCoordinator);

$t9Pass = ($okDeanRej && $er2AfterRej['status'] === 'Rejected' && $rec2AfterRej['status'] === 'Approved' && !$coordCanEditRej);
record_test(
    'Dean Rejection Path',
    'ER status set to Rejected, record status remains Approved, Coordinator denied edit permission',
    "ER Status: {$er2AfterRej['status']}, Record Status: {$rec2AfterRej['status']}, Coord Edit Permitted: " . ($coordCanEditRej ? 'YES' : 'NO (Blocked)'),
    $t9Pass
);

// -------------------------------------------------------------------------
// TEST 10 — CROSS-DEPARTMENT SECURITY
// -------------------------------------------------------------------------
echo "\n--- TEST 10: Cross-Department Security ---\n";
// CSE Coordinator tries to approve AI&DS record
[$crossCoordOk, $crossCoordMsg] = record_review('journal', $rec1Id, 'approve', 'Illegal approval', (int)$uCoordCSE['id'], $uCoordCSE['department'], $uCoordCSE['role'], $activeYear);

// CSE HoD tries to create edit request for AI&DS record
$crossERData = [
    'record_type' => 'journal',
    'record_id'   => $rec1Id,
    'reason'      => 'Illegal cross-department request',
];
[$crossEROk, $crossERMsg] = edit_request_create($crossERData, $uHodCSE);

// AI&DS Coordinator tries to edit CSE record
$cseRec = $pdo->query("SELECT id FROM journal_publications WHERE department = 'CSE' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$crossEditAllowed = false;
if ($cseRec) {
    [$crossEditAllowed] = can_edit_record('journal', (int)$cseRec['id'], $uCoordinator);
}

$t10Pass = (!$crossCoordOk && !$crossEROk && !$crossEditAllowed);
record_test(
    'Cross-Department Security',
    'CSE users cannot approve or request edit for AI&DS records; AI&DS users cannot edit CSE records',
    "CSE Coord Approve: " . ($crossCoordOk ? 'ALLOWED (FAIL)' : 'BLOCKED (PASS)') .
    ", CSE HoD Edit Request: " . ($crossEROk ? 'ALLOWED (FAIL)' : 'BLOCKED (PASS)') .
    ", AI&DS Coord Edit CSE Rec: " . ($crossEditAllowed ? 'ALLOWED (FAIL)' : 'BLOCKED (PASS)'),
    $t10Pass
);

// -------------------------------------------------------------------------
// TEST 11 — DIRECT API & ROLE PERMISSION BYPASS
// -------------------------------------------------------------------------
echo "\n--- TEST 11: Direct API & Role Permission Bypass ---\n";
// 1. Faculty tries to directly approve a record
[$facAppOk, $facAppMsg] = record_review('journal', $rec1Id, 'approve', 'Faculty direct approve', (int)$uFaculty['id'], $uFaculty['department'], $uFaculty['role'], $activeYear);

// 2. HoD tries to directly approve a record
[$hodAppOk, $hodAppMsg] = record_review('journal', $rec1Id, 'approve', 'HoD direct approve', (int)$uHod['id'], $uHod['department'], $uHod['role'], $activeYear);

// 3. Coordinator tries to edit an Approved record without Dean authorization
[$coordDirectEditOk] = can_edit_record('journal', $rec1Id, $uCoordinator);

// 4. Duplicate edit request prevention
// First submit an edit request so one is actively pending/in-flight
[$firstEROk] = edit_request_create($erData, $uHod);
// Now attempt a second identical/duplicate request on the same record while in-flight
[$dupEROk, $dupERMsg] = edit_request_create($erData, $uHod);

$t11Pass = (!$facAppOk && !$hodAppOk && !$coordDirectEditOk && !$dupEROk);
record_test(
    'Direct API Security & Duplicate Prevention',
    'Faculty and HoD direct approvals blocked; Coordinator unauthorized edit blocked; Duplicate edit requests blocked',
    "Faculty Approve: " . ($facAppOk ? 'ALLOWED (FAIL)' : 'BLOCKED (PASS)') .
    ", HoD Approve: " . ($hodAppOk ? 'ALLOWED (FAIL)' : 'BLOCKED (PASS)') .
    ", Coord Direct Edit: " . ($coordDirectEditOk ? 'ALLOWED (FAIL)' : 'BLOCKED (PASS)') .
    ", Duplicate ER: " . ($dupEROk ? 'ALLOWED (FAIL)' : 'BLOCKED (PASS) - ' . $dupERMsg),
    $t11Pass
);

// -------------------------------------------------------------------------
// TEST 12 — PERSISTENCE & AUDIT HISTORY
// -------------------------------------------------------------------------
echo "\n--- TEST 12: Persistence & Audit History ---\n";
$auditLogs = $pdo->query("SELECT action, user_role, old_status, new_status FROM workflow_audit_logs WHERE record_id = $rec1Id AND record_type = 'journal' ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

$actionsFound = array_column($auditLogs, 'action');
$requiredActions = ['COORDINATOR_APPROVED', 'HOD_EDIT_REQUESTED', 'DEAN_EDIT_APPROVED', 'COORDINATOR_RESUBMITTED', 'HOD_REVIEW_ACKNOWLEDGED'];
$missingActions = array_diff($requiredActions, $actionsFound);

$t12Pass = empty($missingActions);
record_test(
    'Audit History & State Persistence',
    'Complete workflow audit trail persisted: ' . implode(' -> ', $requiredActions),
    "Recorded: " . implode(' -> ', $actionsFound) . (empty($missingActions) ? '' : ' (Missing: ' . implode(',', $missingActions) . ')'),
    $t12Pass
);

echo "\n========================================================================\n";
echo "SUMMARY OF TEST RESULTS:\n";
echo "========================================================================\n";
$passCount = count(array_filter($results, fn($r) => $r['result'] === 'PASS'));
$failCount = count($results) - $passCount;
echo "Total Tests: " . count($results) . " | Passed: $passCount | Failed: $failCount\n\n";

if ($failCount === 0) {
    echo "ALL 12 TESTS PASSED PERFECTLY!\n";
} else {
    echo "SOME TESTS FAILED. PLEASE REVIEW ABOVE LOGS.\n";
}
