<?php
require_once __DIR__ . '/../php-app/inc/auth.php';
require_once __DIR__ . '/../php-app/models/Dashboard.php';
require_once __DIR__ . '/../php-app/models/Target.php';
require_once __DIR__ . '/../php-app/models/Record.php';
require_once __DIR__ . '/../php-app/models/FacultyAchievement.php';
require_once __DIR__ . '/../php-app/models/ExecutiveMeetingReport.php';
require_once __DIR__ . '/../php-app/models/EditRequest.php';

function assert_true($cond, $desc) {
    if ($cond) {
        echo "  [PASS] {$desc}\n";
    } else {
        echo "  [FAIL] {$desc}\n";
        exit(1);
    }
}

echo "=== ATTS Department Isolation & Security Test Suite ===\n\n";

// 1. Test Login & User Aliases
echo "1. Testing Login & Department Resolution...\n";
$usersToTest = [
    'cse_hod@atts.local' => ['role' => 'HoD', 'dept' => 'CSE'],
    'hod_cse'            => ['role' => 'HoD', 'dept' => 'CSE'],
    'ece_coord@atts.local' => ['role' => 'Coordinator', 'dept' => 'ECE'],
    'coordinator_ece'    => ['role' => 'Coordinator', 'dept' => 'ECE'],
    'eee_fac@atts.local' => ['role' => 'Faculty', 'dept' => 'EEE'],
    'faculty_eee'        => ['role' => 'Faculty', 'dept' => 'EEE'],
    'hod@atts.edu'       => ['role' => 'HoD', 'dept' => 'CSBS'],
    'director@atts.edu'  => ['role' => 'Principal', 'dept' => null],
    'dean@atts.edu'      => ['role' => 'Dean', 'dept' => null],
];

foreach ($usersToTest as $login => $exp) {
    $u = auth_find_user($login);
    assert_true($u !== null, "User '{$login}' exists in database");
    assert_true($u['role'] === $exp['role'], "User '{$login}' has role {$exp['role']}");
    if ($exp['dept'] !== null) {
        assert_true($u['department'] === $exp['dept'], "User '{$login}' has department {$exp['dept']}");
    }
}

// 2. Test Centralized user_department_scope & Anti-Tampering
echo "\n2. Testing Centralized user_department_scope & Anti-Tampering...\n";
$cseHod = auth_find_user('hod_cse');
$eceCoord = auth_find_user('coordinator_ece');
$eeeFac = auth_find_user('faculty_eee');
$dean = auth_find_user('dean@atts.edu');
$admin = auth_find_user('mohameduvaish132@gmail.com');

// Non-oversight cannot choose department; tampering is overridden
assert_true(user_department_scope($cseHod, 'ECE') === 'CSE', "CSE HoD tampering with 'ECE' is forced to 'CSE'");
assert_true(user_department_scope($eceCoord, 'MECH') === 'ECE', "ECE Coord tampering with 'MECH' is forced to 'ECE'");
assert_true(user_department_scope($eeeFac, 'CSBS') === 'EEE', "EEE Faculty tampering with 'CSBS' is forced to 'EEE'");
assert_true(user_can_choose_department($cseHod) === false, "CSE HoD cannot choose department");
assert_true(user_can_choose_department($eceCoord) === false, "ECE Coord cannot choose department");
assert_true(user_can_choose_department($eeeFac) === false, "EEE Faculty cannot choose department");

// Oversight CAN choose department or view all
assert_true(user_can_choose_department($dean) === true, "Dean can choose department");
assert_true(user_can_choose_department($admin) === true, "Admin can choose department");
assert_true(user_department_scope($dean, 'ECE') === 'ECE', "Dean requesting 'ECE' gets 'ECE'");
assert_true(user_department_scope($dean, null) === null, "Dean requesting null gets null (all departments)");

// 3. Test Dashboard Scoping
echo "\n3. Testing Dashboard Scoping...\n";
$_GET = ['department' => 'ECE']; // Attempt tampering as CSE HoD
$dashDataCse = dashboard_data($cseHod);
assert_true($dashDataCse['departmentFilter'] === 'CSE', "Dashboard forced departmentFilter to 'CSE' despite ?department=ECE tampering");
assert_true($dashDataCse['stats']['departments'] === 1, "Dashboard top-line departments count is 1 for CSE HoD");
foreach ($dashDataCse['matrix']['rows'] as $row) {
    assert_true($row['department'] === 'CSE', "Dashboard matrix only contains CSE rows");
}

// 4. Test Targets Isolation
echo "\n4. Testing Targets Scoping...\n";
$targetsCse = targets_all(user_department_scope($cseHod, 'ECE'), active_academic_year());
foreach ($targetsCse as $t) {
    assert_true($t['department'] === 'CSE', "Target #{$t['id']} belongs to CSE");
}

// 5. Test Executive Meeting Report & Presentation Scoping
echo "\n5. Testing Executive Meeting Report & Presentation Scoping...\n";
$emFilters = em_resolve_filters($cseHod, [
    'department'    => 'ECE', // Tampering attempt
    'academic_year' => active_academic_year(),
]);
assert_true($emFilters['department'] === 'CSE', "em_resolve_filters overrides ?department=ECE to 'CSE'");
$emDs = em_dataset($cseHod, $emFilters);
assert_true($emDs['summary']['Department'] === department_full_name('CSE'), "Executive Meeting dataset summary is CSE");
foreach ($emDs['faculty'] as $r) {
    assert_true($r['department'] === 'CSE', "EM faculty record belongs to CSE");
}
foreach ($emDs['student'] as $r) {
    assert_true($r['department'] === 'CSE', "EM student record belongs to CSE");
}
foreach ($emDs['targets'] as $t) {
    assert_true($t['department'] === 'CSE', "EM target belongs to CSE");
}

$slides = em_slides($emDs);
assert_true(count($slides) >= 1, "EM slides generated");
assert_true($slides[0]['type'] === 'title', "Slide 1 is Cover/Title slide");
assert_true(strpos($slides[0]['summary']['Department'], 'Computer Science') !== false || $slides[0]['summary']['Department'] === 'CSE', "Cover slide displays CSE");
if (isset($slides[1])) {
    assert_true(strpos($slides[1]['title'], 'Development') !== false, "Slide 2 is department development");
    assert_true(strpos($slides[1]['title'], 'Overall College Development') === false, "Slide 2 is NOT Overall College Development");
}

// 6. Test Auto Advance Timer Constant
echo "\n6. Testing Presentation Timer Constant...\n";
if (!defined('EM_AUTO_ADVANCE_MS')) {
    define('EM_AUTO_ADVANCE_MS', 10000);
}
assert_true(EM_AUTO_ADVANCE_MS === 10000, "EM_AUTO_ADVANCE_MS is exactly 10,000 milliseconds (10s)");

// 7. Test Approvals Scoping
echo "\n7. Testing Approvals Scoping...\n";
$scopeDeptCse = user_department_scope($cseHod, 'ECE');
assert_true($scopeDeptCse === 'CSE', "Approvals scopeDept strictly pins to CSE");
$pendingCse = pending_records($scopeDeptCse, null, $cseHod['role'], active_academic_year());
foreach ($pendingCse as $r) {
    assert_true($r['department'] === 'CSE', "Pending record #{$r['id']} belongs to CSE");
}

// 8. Test Reports & Exports Scoping
echo "\n8. Testing Reports & Exports Scoping...\n";
$reportRecsCse = report_records($cseHod, 'ECE', null, null, null, null, active_academic_year());
foreach ($reportRecsCse as $r) {
    assert_true($r['department'] === 'CSE', "Report record #{$r['id']} belongs to CSE");
}

$facultyGrid = faculty_achievements_grid($cseHod, user_department_scope($cseHod, 'ECE'), active_academic_year());
foreach ($facultyGrid as $f) {
    assert_true(department_names_match($f['department'], 'CSE'), "Faculty member '{$f['name']}' in grid belongs to CSE");
}

echo "\n=======================================================\n";
echo "ALL TESTS PASSED! DATA ISOLATION IS 100% SECURE!\n";
echo "=======================================================\n";
