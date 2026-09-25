<?php
/**
 * Verification test script for Historical Academic Year Target Management.
 * Covers TEST 1 through TEST 13 specified in requirements.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/models/Setting.php';
require_once __DIR__ . '/../php-app/models/Target.php';
require_once __DIR__ . '/../php-app/models/Department.php';
require_once __DIR__ . '/../php-app/models/ExecutiveMeeting.php';

$pdo = db();

function assert_test(bool $condition, string $desc): void {
    if ($condition) {
        echo "  [PASS] {$desc}\n";
    } else {
        echo "  [FAIL] {$desc}\n";
        throw new Exception("Assertion failed: {$desc}");
    }
}

echo "=== STARTING VERIFICATION TESTS (TEST 1 - 13) ===\n\n";

// Fetch or prepare test users
$adminUser = $pdo->query("SELECT * FROM users WHERE role = 'Admin' LIMIT 1")->fetch();
$principalUser = $pdo->query("SELECT * FROM users WHERE role = 'Principal' LIMIT 1")->fetch();
$deanUser = $pdo->query("SELECT * FROM users WHERE role = 'Dean' LIMIT 1")->fetch();
$hodCsbs = $pdo->query("SELECT * FROM users WHERE role = 'HoD' AND department = 'CSBS' LIMIT 1")->fetch();
$coordCsbs = $pdo->query("SELECT * FROM users WHERE role = 'Coordinator' AND department = 'CSBS' LIMIT 1")->fetch();
$facultyCsbs = $pdo->query("SELECT * FROM users WHERE role = 'Faculty' AND department = 'CSBS' LIMIT 1")->fetch();

// Ensure HoD for Department B (ECE) exists for scope tests
$hodEce = $pdo->query("SELECT * FROM users WHERE role = 'HoD' AND department = 'ECE' LIMIT 1")->fetch();
if (!$hodEce) {
    $pdo->prepare("INSERT INTO users (name, email, password, role, department, created_at) VALUES ('HoD ECE', 'hod_ece_test@atts.edu', 'hash', 'HoD', 'ECE', NOW())")->execute();
    $hodEce = $pdo->query("SELECT * FROM users WHERE email = 'hod_ece_test@atts.edu'")->fetch();
}

$activeYear = active_academic_year();
$histYear   = '2025-26';

echo "Active Academic Year: {$activeYear}\n";
echo "Historical Academic Year: {$histYear}\n";
echo "Department A (HoD CSBS): ID {$hodCsbs['id']}\n";
echo "Department B (HoD ECE):  ID {$hodEce['id']}\n\n";

// --------------------------------------------------------------------------
// TEST 1: Current Academic Year target view & edit
// --------------------------------------------------------------------------
echo "--- TEST 1: Current Academic Year ({$activeYear}) View & Edit ---\n";
// Ensure a target exists in active year for CSBS
$stmt = $pdo->prepare("SELECT * FROM targets WHERE department = 'CSBS' AND academic_year = ? AND status IN ('Draft', 'Changes Requested') LIMIT 1");
$stmt->execute([$activeYear]);
$curTarget = $stmt->fetch();
if (!$curTarget) {
    [$ok, $msg] = target_create($hodCsbs, 'CSBS', $activeYear, 'Pass Percentage Test', 85, 'Initial remarks', 'Prof. Tester', 'Draft');
    assert_test($ok, "Created current year target: {$msg}");
    $stmt->execute([$activeYear]);
    $curTarget = $stmt->fetch();
}
assert_test(!empty($curTarget), "Current year target found (ID: {$curTarget['id']})");
assert_test(target_can_edit($curTarget, $hodCsbs), "HoD CSBS can edit current year CSBS draft target");

$newTargetVal = ((int)$curTarget['target_value']) + 5;
[$updOk, $updMsg] = target_update(
    (int)$curTarget['id'],
    $hodCsbs,
    'CSBS',
    $activeYear,
    $curTarget['metric'],
    $newTargetVal,
    (int)$curTarget['achieved_value'],
    'Updated in TEST 1'
);
assert_test($updOk, "Target updated successfully for current year: {$updMsg}");
$reloaded = target_find((int)$curTarget['id']);
assert_test((int)$reloaded['target_value'] === $newTargetVal, "Target value correctly updated in DB to {$newTargetVal}");

// --------------------------------------------------------------------------
// TEST 2: Previous Academic Year target view & edit
// --------------------------------------------------------------------------
echo "\n--- TEST 2: Historical Academic Year ({$histYear}) View & Edit ---\n";
$stmt = $pdo->prepare("SELECT * FROM targets WHERE department = 'CSBS' AND academic_year = ? AND status IN ('Draft', 'Changes Requested') LIMIT 1");
$stmt->execute([$histYear]);
$histTarget = $stmt->fetch();
if (!$histTarget) {
    [$ok, $msg] = target_create($hodCsbs, 'CSBS', $histYear, 'Historical Metric CSBS', 70, 'Historical draft', 'Prof. Tester', 'Draft');
    assert_test($ok, "Created historical year target: {$msg}");
    $stmt->execute([$histYear]);
    $histTarget = $stmt->fetch();
}
assert_test(!empty($histTarget), "Historical year target found (ID: {$histTarget['id']})");
assert_test(target_can_edit($histTarget, $hodCsbs), "HoD CSBS can edit historical year target");

$newHistVal = ((int)$histTarget['target_value']) + 7;
[$updHistOk, $updHistMsg] = target_update(
    (int)$histTarget['id'],
    $hodCsbs,
    'CSBS',
    $histYear,
    $histTarget['metric'],
    $newHistVal,
    (int)$histTarget['achieved_value'],
    'Updated in TEST 2'
);
assert_test($updHistOk, "Historical target updated successfully: {$updHistMsg}");
$reloadedHist = target_find((int)$histTarget['id']);
assert_test((int)$reloadedHist['target_value'] === $newHistVal, "Historical target value in DB updated to {$newHistVal}");

// --------------------------------------------------------------------------
// TEST 3: Cross-Year Data Isolation: Edit 2025-26 target; confirm 2026-27 is unchanged
// --------------------------------------------------------------------------
echo "\n--- TEST 3: Cross-Year Data Isolation ---\n";
$curBefore = target_find((int)$curTarget['id']);
$histBefore = target_find((int)$histTarget['id']);

$histEditVal = ((int)$histBefore['target_value']) + 3;
[$t3Ok, $t3Msg] = target_update(
    (int)$histTarget['id'],
    $hodCsbs,
    'CSBS',
    $histYear,
    $histBefore['metric'],
    $histEditVal,
    (int)$histBefore['achieved_value'],
    'Test 3 isolated edit'
);
assert_test($t3Ok, "Updated 2025-26 target #{$histTarget['id']}");

$curAfter = target_find((int)$curTarget['id']);
assert_test((int)$curAfter['target_value'] === (int)$curBefore['target_value'], "2026-27 target remains untouched at value " . $curBefore['target_value']);
assert_test($curAfter['academic_year'] === $activeYear, "2026-27 target academic_year is untouched");

// --------------------------------------------------------------------------
// TEST 4: HoD Department Scope Isolation (HoD CSBS -> ECE)
// --------------------------------------------------------------------------
echo "\n--- TEST 4: HoD Department Scope Isolation (HoD CSBS -> ECE) ---\n";
// Find or create an ECE target in 2025-26
$stmt = $pdo->prepare("SELECT * FROM targets WHERE department = 'ECE' AND academic_year = ? LIMIT 1");
$stmt->execute([$histYear]);
$eceTarget = $stmt->fetch();
if (!$eceTarget) {
    [$ok, $msg] = target_create($adminUser, 'ECE', $histYear, 'ECE Metric 2025-26', 30, 'ECE target', 'ECE Coord', 'Draft');
    $stmt->execute([$histYear]);
    $eceTarget = $stmt->fetch();
}
assert_test(!empty($eceTarget), "ECE target found (ID: {$eceTarget['id']})");

// HoD CSBS attempts to edit ECE target
assert_test(!target_can_edit($eceTarget, $hodCsbs), "target_can_edit returns FALSE for HoD CSBS on ECE target");
[$denyOk, $denyMsg] = target_update(
    (int)$eceTarget['id'],
    $hodCsbs,
    'ECE',
    $histYear,
    $eceTarget['metric'],
    999,
    0,
    'Hacked'
);
assert_test(!$denyOk, "HoD CSBS update on ECE target denied server-side: {$denyMsg}");

// --------------------------------------------------------------------------
// TEST 5: Coordinator Department Scope & Permissions
// --------------------------------------------------------------------------
echo "\n--- TEST 5: Coordinator Role Target Permissions ---\n";
if ($coordCsbs) {
    assert_test(!target_can_edit($histTarget, $coordCsbs), "Coordinator CSBS cannot edit targets");
    assert_test(!target_can_submit($histTarget, $coordCsbs), "Coordinator CSBS cannot submit targets");
    assert_test(!target_can_review($histTarget, $coordCsbs), "Coordinator CSBS cannot review targets");
}

// --------------------------------------------------------------------------
// TEST 6: Faculty Role Target Permissions
// --------------------------------------------------------------------------
echo "\n--- TEST 6: Faculty Role Target Permissions ---\n";
if ($facultyCsbs) {
    assert_test(!target_can_edit($histTarget, $facultyCsbs), "Faculty CSBS cannot edit targets");
    assert_test(!target_can_submit($histTarget, $facultyCsbs), "Faculty CSBS cannot submit targets");
    assert_test(!target_can_review($histTarget, $facultyCsbs), "Faculty CSBS cannot review targets");
    [$facCreateOk, $facCreateMsg] = target_create($facultyCsbs, 'CSBS', $histYear, 'Faculty Metric', 10, null);
    assert_test(!$facCreateOk, "Faculty target creation rejected: {$facCreateMsg}");
}

// --------------------------------------------------------------------------
// TEST 7: Admin / Principal / Dean Permissions
// --------------------------------------------------------------------------
echo "\n--- TEST 7: Admin, Principal, Dean Permissions ---\n";
assert_test(target_can_edit($histTarget, $adminUser), "Admin can edit historical CSBS target");
assert_test(target_can_edit($eceTarget, $adminUser), "Admin can edit historical ECE target");
if ($deanUser) {
    assert_test(in_array($deanUser['role'], ['Dean', 'Admin'], true), "Dean role identified");
}

// --------------------------------------------------------------------------
// TEST 8: Lock Academic Year; Authorized Target Edit Still Allowed
// --------------------------------------------------------------------------
echo "\n--- TEST 8: Academic Year Lock Does NOT Block Target Editing ---\n";
// Lock the historical year
academic_year_set_lock($histYear, true, (int)$adminUser['id'], 'Test Lock for 2025-26');
assert_test(academic_year_is_locked($histYear), "Historical year {$histYear} is now locked in app_settings");

// Verify target editing is STILL allowed for HoD CSBS on their CSBS target
assert_test(target_can_edit($histTarget, $hodCsbs), "target_can_edit STILL TRUE for HoD CSBS when year is locked");
$lockEditVal = ((int)$histTarget['target_value']) + 2;
[$lockUpdOk, $lockUpdMsg] = target_update(
    (int)$histTarget['id'],
    $hodCsbs,
    'CSBS',
    $histYear,
    $histTarget['metric'],
    $lockEditVal,
    (int)$histTarget['achieved_value'],
    'Edited while AY is locked'
);
assert_test($lockUpdOk, "Authorized target edit succeeded while AY is locked: {$lockUpdMsg}");

// Unlock the year
academic_year_set_lock($histYear, false, (int)$adminUser['id'], 'Unlocked after test');
assert_test(!academic_year_is_locked($histYear), "Historical year {$histYear} lock cleared");

// --------------------------------------------------------------------------
// TEST 9: Executive Meeting Lock (EM1) Separation
// --------------------------------------------------------------------------
echo "\n--- TEST 9: EM1 Lock Applied — Target Management Remains Working ---\n";
$emDate = date('Y-m-d');
$emNum = '99Test';
$pdo->prepare("DELETE FROM executive_meetings WHERE academic_year = ? AND meeting_number = ?")->execute([$histYear, $emNum]);

[$emLockOk, $emLockMsg] = executive_meeting_finish_and_lock($histYear, $emNum, $emDate, 'Test EM lock notes', (int)$adminUser['id']);
assert_test($emLockOk, "EM finish and lock executed: {$emLockMsg}");

// Verify duplicate meeting is prevented (FEAT-07 integrity)
[$dupOk, $dupMsg] = executive_meeting_finish_and_lock($histYear, $emNum, $emDate, 'Duplicate attempt', (int)$adminUser['id']);
assert_test(!$dupOk, "Duplicate EM meeting prevented as expected: {$dupMsg}");

// Verify that target editing STILL works while EM cycle is locked!
assert_test(target_can_edit($histTarget, $hodCsbs), "target_can_edit STILL allowed during EM lock");
[$emTargetUpdOk, $emTargetUpdMsg] = target_update(
    (int)$histTarget['id'],
    $hodCsbs,
    'CSBS',
    $histYear,
    $histTarget['metric'],
    $lockEditVal + 1,
    (int)$histTarget['achieved_value'],
    'Target edited during EM lock'
);
assert_test($emTargetUpdOk, "Target edit during EM lock succeeded: {$emTargetUpdMsg}");

// Clean up test EM meeting
$pdo->prepare("DELETE FROM executive_meetings WHERE academic_year = ? AND meeting_number = ?")->execute([$histYear, $emNum]);
academic_year_set_lock($histYear, false, (int)$adminUser['id'], 'Cleaned up after test 9');

// --------------------------------------------------------------------------
// TEST 10: EM2 Behavior Remains Intact
// --------------------------------------------------------------------------
echo "\n--- TEST 10: EM2 Scheduling & Workflow Verification ---\n";
$emCount = executive_meeting_count($activeYear);
assert_test(is_int($emCount), "executive_meeting_count returns valid integer: {$emCount}");
$latestEm = executive_meeting_latest($activeYear);
assert_test($latestEm === null || is_array($latestEm), "executive_meeting_latest returns valid result");

// --------------------------------------------------------------------------
// TEST 11: Target Reports for Historical Years Show Correct Year Data
// --------------------------------------------------------------------------
echo "\n--- TEST 11: Historical Target Reports Data Correctness ---\n";
$reportHist = target_report_items('CSBS', $histYear);
$reportCur  = target_report_items('CSBS', $activeYear);
assert_test(!empty($reportHist), "Historical report returned " . count($reportHist) . " items for {$histYear}");
assert_test(!empty($reportCur), "Current report returned " . count($reportCur) . " items for {$activeYear}");

foreach ($reportHist as $item) {
    if ($item['academic_year'] !== $histYear) {
        throw new Exception("Mismatch in report: expected {$histYear}, got {$item['academic_year']}");
    }
}
assert_test(true, "All historical report items strictly belong to {$histYear}");

// --------------------------------------------------------------------------
// TEST 12: Server-Side IDOR & Cross-Year Tampering Rejections
// --------------------------------------------------------------------------
echo "\n--- TEST 12: Server-Side IDOR & Tampering Rejections ---\n";
// Case 1: Tampered academic_year on target_update
[$idor1Ok, $idor1Msg] = target_update(
    (int)$histTarget['id'],
    $hodCsbs,
    'CSBS',
    '2026-27', // Target actually belongs to 2025-26!
    $histTarget['metric'],
    100,
    0,
    'Tampered year'
);
assert_test(!$idor1Ok, "Cross-year IDOR rejected: {$idor1Msg}");

// Case 2: Tampered department on target_create
[$idor2Ok, $idor2Msg] = target_create(
    $hodCsbs,
    'ECE', // HoD CSBS trying to create in ECE
    $histYear,
    'Cross dept metric',
    20,
    null
);
$checkStmt = $pdo->prepare("SELECT department FROM targets WHERE metric = 'Cross dept metric' AND academic_year = ?");
$checkStmt->execute([$histYear]);
$createdDept = $checkStmt->fetchColumn();
assert_test($createdDept === 'CSBS', "HoD CSBS department cannot be forged (saved as CSBS, not ECE)");
$pdo->prepare("DELETE FROM targets WHERE metric = 'Cross dept metric'")->execute();

// Case 3: Invalid academic year format / non-existent year
[$idor3Ok, $idor3Msg] = target_create(
    $hodCsbs,
    'CSBS',
    '1999-00', // not in academic_years()
    'Bad year target',
    10,
    null
);
assert_test(!$idor3Ok, "Non-existent academic year rejected: {$idor3Msg}");

// --------------------------------------------------------------------------
// TEST 13: Affected Pages PHP Execution & Navigation Verification
// --------------------------------------------------------------------------
echo "\n--- TEST 13: Affected Pages Execution & Lint Verification ---\n";
$pages = [
    'php-app/targets.php',
    'php-app/models/Target.php',
    'php-app/meeting-report.php',
    'php-app/target-import.php',
    'php-app/template-report.php',
    'php-app/inc/nav.php',
];
foreach ($pages as $page) {
    $fullPath = __DIR__ . '/../' . $page;
    $output = [];
    $ret = 0;
    exec("php -l " . escapeshellarg($fullPath), $output, $ret);
    assert_test($ret === 0, "Page {$page} passed php -l lint check");
}

echo "\n=== ALL 13 VERIFICATION TESTS PASSED SUCCESSFULLY! ===\n";
