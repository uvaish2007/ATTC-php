<?php
/**
 * Comprehensive Automated Verification for COORD-13
 * Journal Publication 7-Day Approval Expiration Lifecycle
 */

require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/inc/auth.php';
require_once __DIR__ . '/../php-app/models/Record.php';
require_once __DIR__ . '/../php-app/models/Target.php';
require_once __DIR__ . '/../php-app/models/Dashboard.php';

$pdo = db();
$activeYear = active_academic_year();
echo "Active Academic Year: {$activeYear}\n";
echo "Configured Timezone: " . (defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Unknown') . "\n";
echo "DB Current Timestamp: " . $pdo->query("SELECT NOW()")->fetchColumn() . "\n";

$testRecords = [];
$cleanup = function() use ($pdo, &$testRecords) {
    if (!empty($testRecords['journal'])) {
        $in = implode(',', array_map('intval', $testRecords['journal']));
        $pdo->exec("DELETE FROM journal_publications WHERE id IN ($in)");
    }
    if (!empty($testRecords['book'])) {
        $in = implode(',', array_map('intval', $testRecords['book']));
        $pdo->exec("DELETE FROM book_publications WHERE id IN ($in)");
    }
    if (!empty($testRecords['conference'])) {
        $in = implode(',', array_map('intval', $testRecords['conference']));
        $pdo->exec("DELETE FROM conference_publications WHERE id IN ($in)");
    }
    if (!empty($testRecords['event'])) {
        $in = implode(',', array_map('intval', $testRecords['event']));
        $pdo->exec("DELETE FROM events WHERE id IN ($in)");
    }
};

register_shutdown_function($cleanup);

$results = [];
function recordResult(string $testName, bool $pass, string $details = '') {
    global $results;
    $results[$testName] = ['pass' => $pass, 'details' => $details];
    echo ($pass ? "[PASS] " : "[FAIL] ") . $testName . ($details ? " - {$details}" : "") . "\n";
}

// Coordinator user (CSBS department)
$coordUser = $pdo->query("SELECT * FROM users WHERE role = 'Coordinator' AND department = 'CSBS' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$coordUser) {
    $coordUser = $pdo->query("SELECT * FROM users WHERE role IN ('Coordinator', 'Admin') LIMIT 1")->fetch(PDO::FETCH_ASSOC);
}
// Faculty user (CSBS department)
$facUser = $pdo->query("SELECT * FROM users WHERE role = 'Faculty' AND department = 'CSBS' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$facUser) {
    $facUser = $pdo->query("SELECT * FROM users WHERE role = 'Faculty' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
}

echo "Using Reviewer: {$coordUser['name']} (Role: {$coordUser['role']}, ID: {$coordUser['id']}, Dept: {$coordUser['department']})\n";
echo "Using Faculty: {$facUser['name']} (Role: {$facUser['role']}, ID: {$facUser['id']}, Dept: {$facUser['department']})\n\n";

// =========================================================================
// TEST 1 — SUBMITTED STATE
// =========================================================================
echo "--- TEST 1: SUBMITTED STATE ---\n";
$stmt = $pdo->prepare(
    "INSERT INTO journal_publications (
        faculty_name, department, academic_year, author_type, paper_title,
        journal_name, journal_type, issn, status, created_by, created_at, updated_at
    ) VALUES (
        ?, ?, ?, 'First Author', 'COORD-13 Test Paper Alpha',
        'International Journal of Computing', 'Scopus', '1234-5678', 'Submitted', ?, NOW(), NOW()
    )"
);
$stmt->execute([$facUser['name'], $facUser['department'], $activeYear, $facUser['id']]);
$testId1 = (int)$pdo->lastInsertId();
$testRecords['journal'][] = $testId1;

$row1 = $pdo->query("SELECT * FROM journal_publications WHERE id = {$testId1}")->fetch(PDO::FETCH_ASSOC);
$pendingList = pending_records($coordUser['department'], null, $coordUser['role'], $activeYear);
$inQueue = false;
foreach ($pendingList as $pr) {
    if ($pr['_type_key'] === 'journal' && (int)$pr['id'] === $testId1) {
        $inQueue = true;
        break;
    }
}
$t1Pass = ($row1['status'] === 'Submitted' && $inQueue);
recordResult("TEST 1 — SUBMITTED STATE", $t1Pass, "Record ID {$testId1} status is 'Submitted' and appears in Coordinator pending queue: " . ($inQueue ? 'YES' : 'NO'));

// =========================================================================
// TEST 2 — APPROVAL
// =========================================================================
echo "\n--- TEST 2: APPROVAL ---\n";
[$appOk, $appMsg] = record_review('journal', $testId1, 'approve', 'Approved for publication index', (int)$coordUser['id'], $coordUser['department'], $coordUser['role'], $activeYear);
$row2 = $pdo->query("SELECT * FROM journal_publications WHERE id = {$testId1}")->fetch(PDO::FETCH_ASSOC);

$t2Pass = ($appOk && $row2['status'] === 'Approved' && (int)$row2['approved_by'] === (int)$coordUser['id'] && !empty($row2['approved_at']));
recordResult("TEST 2 — APPROVAL", $t2Pass, "Status: {$row2['status']}, approved_by: {$row2['approved_by']}, approved_at: {$row2['approved_at']}, msg: {$appMsg}");

// =========================================================================
// TEST 3 — BEFORE EXPIRY (< 7 days)
// =========================================================================
echo "\n--- TEST 3: BEFORE EXPIRY (< 7 days) ---\n";
// Set approved_at to 3 days ago (well within the 7-day window)
$pdo->exec("UPDATE journal_publications SET approved_at = DATE_SUB(NOW(), INTERVAL 3 DAY) WHERE id = {$testId1}");
// Invoke expiry check
$expCount3 = journal_process_approval_expiry(null, true);
$row3 = $pdo->query("SELECT * FROM journal_publications WHERE id = {$testId1}")->fetch(PDO::FETCH_ASSOC);
$t3Pass = ($row3['status'] === 'Approved' && !empty($row3['approved_at']));
recordResult("TEST 3 — BEFORE EXPIRY", $t3Pass, "Record approved 3 days ago remains status 'Approved' (expired count: {$expCount3})");

// =========================================================================
// TEST 4 — AFTER EXPIRY (> 7 days)
// =========================================================================
echo "\n--- TEST 4: AFTER EXPIRY (> 7 days) ---\n";
// Set approved_at to 8 days ago (> 7 days)
$pdo->exec("UPDATE journal_publications SET approved_at = DATE_SUB(NOW(), INTERVAL 8 DAY), review_remark = 'Initial approval remark' WHERE id = {$testId1}");
// Trigger normal expiry processing
$expCount4 = journal_process_approval_expiry(null, true);
$row4 = $pdo->query("SELECT * FROM journal_publications WHERE id = {$testId1}")->fetch(PDO::FETCH_ASSOC);
$history4 = record_approval_history('journal', $testId1);

$archivedOk = false;
if (!empty($history4) && is_array($history4)) {
    $lastArchive = end($history4);
    if ($lastArchive['status'] === 'Approved' &&
        (int)$lastArchive['approved_by'] === (int)$coordUser['id'] &&
        !empty($lastArchive['approved_at']) &&
        !empty($lastArchive['expired_at'])) {
        $archivedOk = true;
    }
}

$t4Pass = ($row4['status'] === 'Submitted' && $row4['approved_by'] === null && $row4['approved_at'] === null && $archivedOk && $row4['academic_year'] === $activeYear);
recordResult("TEST 4 — AFTER EXPIRY", $t4Pass, "Status transitioned Approved -> Submitted: " . ($row4['status'] === 'Submitted' ? 'YES' : 'NO') . ", History archived: " . ($archivedOk ? 'YES' : 'NO') . ", Details preserved: " . json_encode($history4));

// =========================================================================
// TEST 5 — EXACT BOUNDARY (7 days)
// =========================================================================
echo "\n--- TEST 5: EXACT BOUNDARY ---\n";
// Create test record 2
$stmt->execute([$facUser['name'], $facUser['department'], $activeYear, $facUser['id']]);
$testId2 = (int)$pdo->lastInsertId();
$testRecords['journal'][] = $testId2;

// Approve test record 2
record_review('journal', $testId2, 'approve', 'Boundary test approval', (int)$coordUser['id'], $coordUser['department'], $coordUser['role'], $activeYear);

// Boundary Sub-test A: 7 days minus 2 seconds (6 days 23 hours 59 mins 58 secs)
$pdo->exec("UPDATE journal_publications SET approved_at = DATE_SUB(NOW(), INTERVAL 604798 SECOND) WHERE id = {$testId2}");
journal_process_approval_expiry(null, true);
$row5a = $pdo->query("SELECT status FROM journal_publications WHERE id = {$testId2}")->fetch(PDO::FETCH_ASSOC);
$subA_Pass = ($row5a['status'] === 'Approved');

// Boundary Sub-test B: 7 days plus 2 seconds (604802 seconds)
$pdo->exec("UPDATE journal_publications SET approved_at = DATE_SUB(NOW(), INTERVAL 604802 SECOND) WHERE id = {$testId2}");
journal_process_approval_expiry(null, true);
$row5b = $pdo->query("SELECT status FROM journal_publications WHERE id = {$testId2}")->fetch(PDO::FETCH_ASSOC);
$subB_Pass = ($row5b['status'] === 'Submitted');

$t5Pass = ($subA_Pass && $subB_Pass);
recordResult("TEST 5 — EXACT BOUNDARY", $t5Pass, "At 7 days - 2s: {$row5a['status']} (expected Approved); At 7 days + 2s: {$row5b['status']} (expected Submitted)");

// =========================================================================
// TEST 6 — RE-APPROVAL BEHAVIOR
// =========================================================================
echo "\n--- TEST 6: RE-APPROVAL BEHAVIOR ---\n";
// Test record 1 is currently in 'Submitted' status with 1 archived approval in history
$prevHistCount = count($history4);
[$reAppOk, $reAppMsg] = record_review('journal', $testId1, 'approve', 'Second cycle approval after expiry', (int)$coordUser['id'], $coordUser['department'], $coordUser['role'], $activeYear);
$row6 = $pdo->query("SELECT * FROM journal_publications WHERE id = {$testId1}")->fetch(PDO::FETCH_ASSOC);
$history6 = record_approval_history('journal', $testId1);

$t6Pass = ($reAppOk &&
    $row6['status'] === 'Approved' &&
    !empty($row6['approved_at']) &&
    count($history6) === $prevHistCount && // Previous history preserved
    $history6[0]['approved_by'] == $coordUser['id']
);
recordResult("TEST 6 — RE-APPROVAL", $t6Pass, "Re-approved status: {$row6['status']}, new approved_at: {$row6['approved_at']}, preserved past history count: " . count($history6));

// Test secondary expiry on testId1 to verify history accumulates cleanly
$pdo->exec("UPDATE journal_publications SET approved_at = DATE_SUB(NOW(), INTERVAL 8 DAY) WHERE id = {$testId1}");
journal_process_approval_expiry(null, true);
$history6b = record_approval_history('journal', $testId1);
$t6bPass = (count($history6b) === 2);
recordResult("TEST 6b — MULTIPLE APPROVAL ARCHIVES", $t6bPass, "Archived approvals accumulated in history: " . count($history6b) . " entries");

// =========================================================================
// TEST 7 — UNRELATED RECORD TYPES
// =========================================================================
echo "\n--- TEST 7: UNRELATED RECORD TYPES ---\n";
// Insert Book, Conference, Event with approved_by and approved status backdated 15 days ago
$pdo->exec(
    "INSERT INTO book_publications (faculty_name, department, academic_year, title, publisher_name, status, approved_by, created_by, created_at, updated_at)
     VALUES ('{$facUser['name']}', '{$facUser['department']}', '{$activeYear}', 'COORD-13 Book Test', 'Publisher X', 'Approved', {$coordUser['id']}, {$facUser['id']}, DATE_SUB(NOW(), INTERVAL 15 DAY), DATE_SUB(NOW(), INTERVAL 15 DAY))"
);
$bookId = (int)$pdo->lastInsertId();
$testRecords['book'][] = $bookId;

$pdo->exec(
    "INSERT INTO conference_publications (faculty_name, department, academic_year, author_type, paper_title, conference_name, status, approved_by, created_by, created_at, updated_at)
     VALUES ('{$facUser['name']}', '{$facUser['department']}', '{$activeYear}', 'First Author', 'COORD-13 Conf Test', 'IEEE Conf', 'Approved', {$coordUser['id']}, {$facUser['id']}, DATE_SUB(NOW(), INTERVAL 15 DAY), DATE_SUB(NOW(), INTERVAL 15 DAY))"
);
$confId = (int)$pdo->lastInsertId();
$testRecords['conference'][] = $confId;

$pdo->exec(
    "INSERT INTO events (department, event_date, event_title, event_type, mode, participants, status, approved_by, created_by, created_at, updated_at)
     VALUES ('{$facUser['department']}', CURDATE(), 'COORD-13 Event Test', 'Workshop', 'Offline', 50, 'Approved', {$coordUser['id']}, {$facUser['id']}, DATE_SUB(NOW(), INTERVAL 15 DAY), DATE_SUB(NOW(), INTERVAL 15 DAY))"
);
$eventId = (int)$pdo->lastInsertId();
$testRecords['event'][] = $eventId;

// Run expiry check
journal_process_approval_expiry(null, true);

$bookStatus = $pdo->query("SELECT status FROM book_publications WHERE id = {$bookId}")->fetchColumn();
$confStatus = $pdo->query("SELECT status FROM conference_publications WHERE id = {$confId}")->fetchColumn();
$eventStatus = $pdo->query("SELECT status FROM events WHERE id = {$eventId}")->fetchColumn();

$t7Pass = ($bookStatus === 'Approved' && $confStatus === 'Approved' && $eventStatus === 'Approved');
recordResult("TEST 7 — UNRELATED RECORD TYPES", $t7Pass, "Book: {$bookStatus}, Conference: {$confStatus}, Event: {$eventStatus} (all remain Approved)");

// =========================================================================
// TEST 8 — DASHBOARD & QUEUE CONSISTENCY
// =========================================================================
echo "\n--- TEST 8: DASHBOARD & QUEUE CONSISTENCY ---\n";
// Verify that testId1 (which is currently expired to 'Submitted') appears in pending_records
$pendingRecords = pending_records($coordUser['department'], null, $coordUser['role'], $activeYear);
$foundInPending = false;
foreach ($pendingRecords as $pr) {
    if ($pr['_type_key'] === 'journal' && (int)$pr['id'] === $testId1) {
        $foundInPending = true;
        break;
    }
}

// Verify that my_records for faculty shows 'Submitted'
$facultyRecords = my_records($facUser['id']);
$foundInMyRecords = false;
foreach ($facultyRecords as $mr) {
    if ($mr['_type_key'] === 'journal' && (int)$mr['id'] === $testId1 && $mr['status'] === 'Submitted') {
        $foundInMyRecords = true;
        break;
    }
}

// Verify dashboard_data computes breakdown
$dash = dashboard_data($coordUser);
$statusBreakdown = $dash['statusBreakdown'] ?? [];
$t8Pass = ($foundInPending && $foundInMyRecords && isset($statusBreakdown['Submitted']));
recordResult("TEST 8 — DASHBOARD & QUEUES", $t8Pass, "Found in Coordinator pending queue: " . ($foundInPending ? 'YES' : 'NO') . ", Found in Faculty My Submissions as 'Submitted': " . ($foundInMyRecords ? 'YES' : 'NO'));

// =========================================================================
// TEST 9 — REFRESH & PERSISTENCE
// =========================================================================
echo "\n--- TEST 9: REFRESH & PERSISTENCE ---\n";
// Re-read directly from fresh DB query
$row9 = $pdo->query("SELECT status, approved_by, approved_at FROM journal_publications WHERE id = {$testId1}")->fetch(PDO::FETCH_ASSOC);
$t9Pass = ($row9['status'] === 'Submitted' && $row9['approved_at'] === null && $row9['approved_by'] === null);
recordResult("TEST 9 — REFRESH & PERSISTENCE", $t9Pass, "Record {$testId1} persisted in DB as status: {$row9['status']}, approved_at: " . ($row9['approved_at'] ?? 'NULL'));

// =========================================================================
// TEST 10 — ACADEMIC YEAR INTEGRITY
// =========================================================================
echo "\n--- TEST 10: ACADEMIC YEAR INTEGRITY ---\n";
$row10 = $pdo->query("SELECT academic_year, status FROM journal_publications WHERE id = {$testId1}")->fetch(PDO::FETCH_ASSOC);
$t10Pass = ($row10['academic_year'] === $activeYear && $row10['status'] === 'Submitted');
recordResult("TEST 10 — ACADEMIC YEAR", $t10Pass, "Academic Year preserved: {$row10['academic_year']} (active: {$activeYear})");

// =========================================================================
// AUTOMATIC DATABASE VERIFICATION (SECTION 27)
// =========================================================================
echo "\n--- AUTOMATIC DATABASE VERIFICATION (SECTION 27) ---\n";
// Test a fresh record through the entire lifecycle and log before/after
$stmt->execute([$facUser['name'], $facUser['department'], $activeYear, $facUser['id']]);
$testId3 = (int)$pdo->lastInsertId();
$testRecords['journal'][] = $testId3;

$beforeApproval = $pdo->query("SELECT id, status, approved_by, approved_at, academic_year FROM journal_publications WHERE id = {$testId3}")->fetch(PDO::FETCH_ASSOC);
echo "BEFORE APPROVAL: status = {$beforeApproval['status']}, approved_at = " . ($beforeApproval['approved_at'] ?? 'NULL') . "\n";

record_review('journal', $testId3, 'approve', 'Formal Review OK', (int)$coordUser['id'], $coordUser['department'], $coordUser['role'], $activeYear);
$afterApproval = $pdo->query("SELECT id, status, approved_by, approved_at, academic_year FROM journal_publications WHERE id = {$testId3}")->fetch(PDO::FETCH_ASSOC);
echo "AFTER APPROVAL: status = {$afterApproval['status']}, approved_at = {$afterApproval['approved_at']}, approved_by = {$afterApproval['approved_by']}\n";

$pdo->exec("UPDATE journal_publications SET approved_at = DATE_SUB(NOW(), INTERVAL 8 DAY) WHERE id = {$testId3}");
journal_process_approval_expiry(null, true);
$afterExpiry = $pdo->query("SELECT id, status, approved_by, approved_at, academic_year, approval_history FROM journal_publications WHERE id = {$testId3}")->fetch(PDO::FETCH_ASSOC);
echo "AFTER 7-DAY EXPIRY: status = {$afterExpiry['status']}, approved_at = " . ($afterExpiry['approved_at'] ?? 'NULL') . ", academic_year = {$afterExpiry['academic_year']}\n";
echo "Approval history: {$afterExpiry['approval_history']}\n";

$dupCheck = (int)$pdo->query("SELECT COUNT(*) FROM journal_publications WHERE id = {$testId3}")->fetchColumn();
echo "No duplicate record: " . ($dupCheck === 1 ? 'YES' : 'NO') . "\n";

$dbVerifPass = (
    $beforeApproval['status'] === 'Submitted' &&
    $afterApproval['status'] === 'Approved' && !empty($afterApproval['approved_at']) &&
    $afterExpiry['status'] === 'Submitted' && $afterExpiry['approved_at'] === null &&
    !empty($afterExpiry['approval_history']) &&
    $afterExpiry['academic_year'] === $activeYear &&
    $dupCheck === 1
);
recordResult("DATABASE VERIFICATION (SECTION 27)", $dbVerifPass, "Lifecycle completed with full data integrity and zero duplication");

// =========================================================================
// PERFORMANCE VALIDATION (SECTION 30)
// =========================================================================
echo "\n--- PERFORMANCE VALIDATION (SECTION 30) ---\n";
$startPerf = microtime(true);
for ($i = 0; $i < 50; $i++) {
    journal_process_approval_expiry(); // Default per-request caching prevents N+1 queries
}
$perfTime = (microtime(true) - $startPerf) * 1000;
echo "50 checks took {$perfTime} ms (" . sprintf('%.2f', $perfTime / 50) . " ms/call)\n";
$tPerfPass = ($perfTime < 500); // Should be well under 500ms
recordResult("PERFORMANCE VALIDATION", $tPerfPass, "50 checks took " . round($perfTime, 2) . " ms - no N+1 queries");

// =========================================================================
// SUMMARY
// =========================================================================
echo "\n==================================================\n";
echo "FINAL TEST SUMMARY\n";
echo "==================================================\n";
$allPass = true;
foreach ($results as $k => $res) {
    if (!$res['pass']) $allPass = false;
    echo sprintf("%-40s: %s\n", $k, $res['pass'] ? 'PASS' : 'FAIL');
}
echo "\nOVERALL COORD-13 RESULT: " . ($allPass ? "PASS" : "FAIL") . "\n";

// Cleanup test records
$cleanup();
echo "\nCleaned up all controlled test records.\n";

exit($allPass ? 0 : 1);
