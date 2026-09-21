<?php
/**
 * Test Coordinator Presentation Access & Department Isolation
 */
require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/inc/auth.php';
require_once __DIR__ . '/../php-app/inc/helpers.php';
require_once __DIR__ . '/../php-app/models/FacultyAchievement.php';
require_once __DIR__ . '/../php-app/models/User.php';

$errors = 0;
function assert_test(bool $cond, string $msg, &$errors) {
    if ($cond) {
        echo " [PASS] $msg\n";
    } else {
        echo " [FAIL] $msg\n";
        $errors++;
    }
}

echo "=== 1. Testing can_user_view_faculty_report() ===\n";
$coordCsbs = [
    'id' => 4,
    'name' => 'Coordinator',
    'role' => 'Coordinator',
    'department' => 'CSBS',
];
$coordCse = [
    'id' => 60013,
    'name' => 'CSE Coord User',
    'role' => 'Coordinator',
    'department' => 'CSE',
];

// Target 4: CSBS Coordinator (self)
assert_test(can_user_view_faculty_report($coordCsbs, 4) === true, "CSBS Coordinator can view own report (ID 4)", $errors);

// Target 5: VR (CSBS Faculty)
assert_test(can_user_view_faculty_report($coordCsbs, 5) === true, "CSBS Coordinator can view CSBS faculty (VR, ID 5)", $errors);

// Target 60021: Jack (Aero Coordinator)
assert_test(can_user_view_faculty_report($coordCsbs, 60021) === false, "CSBS Coordinator CANNOT view Aero faculty (Jack, ID 60021) -> Access Denied", $errors);

// Target 60007: Dr. Rajesh Kumar (department: Computer Science and Engineering)
assert_test(can_user_view_faculty_report($coordCse, 60007) === true, "CSE Coordinator can view Dr. Rajesh Kumar (ID 60007, Computer Science and Engineering) via alias match", $errors);

// Target 60011: CSE Faculty Test (department: CSE)
assert_test(can_user_view_faculty_report($coordCse, 60011) === true, "CSE Coordinator can view CSE Faculty Test (ID 60011)", $errors);

// Target 5: VR (CSBS Faculty) - CSE Coordinator accessing CSBS
assert_test(can_user_view_faculty_report($coordCse, 5) === false, "CSE Coordinator CANNOT view CSBS faculty (VR, ID 5) -> Access Denied", $errors);


echo "\n=== 2. Testing resolve_faculty_achievement_scope() ===\n";
assert_test(resolve_faculty_achievement_scope($coordCsbs, null) === 'CSBS', "CSBS Coord scope defaults to CSBS", $errors);
assert_test(resolve_faculty_achievement_scope($coordCsbs, 'Aero') === 'CSBS', "CSBS Coord cannot tamper scope with ?department=Aero", $errors);
assert_test(resolve_faculty_achievement_scope($coordCse, null) === 'CSE', "CSE Coord scope defaults to CSE", $errors);
assert_test(resolve_faculty_achievement_scope($coordCse, 'CSBS') === 'CSE', "CSE Coord cannot tamper scope with ?department=CSBS", $errors);


echo "\n=== 3. Testing faculty_achievements_grid() scoping ===\n";
// CSBS Coordinator grid
$csbsGrid = faculty_achievements_grid($coordCsbs);
$csbsDepts = array_unique(array_column($csbsGrid, 'department'));
echo " CSBS Coordinator saw departments: " . implode(', ', $csbsDepts) . "\n";
assert_test(count($csbsGrid) > 0, "CSBS Coordinator grid is not empty", $errors);
$hasNonCsbs = false;
foreach ($csbsGrid as $row) {
    if (!department_names_match($row['department'], 'CSBS')) {
        $hasNonCsbs = true;
    }
}
assert_test(!$hasNonCsbs, "CSBS Coordinator ONLY sees CSBS faculty (no Aero, no CSE, no AI&DS)", $errors);

// CSE Coordinator grid
$cseGrid = faculty_achievements_grid($coordCse);
$cseDepts = array_unique(array_column($cseGrid, 'department'));
echo " CSE Coordinator saw departments: " . implode(', ', $cseDepts) . "\n";
assert_test(count($cseGrid) > 0, "CSE Coordinator grid is not empty", $errors);
$hasNonCse = false;
$foundRajeshKumar = false;
foreach ($cseGrid as $row) {
    if (!department_names_match($row['department'], 'CSE')) {
        $hasNonCse = true;
    }
    if ((int)$row['id'] === 60007) {
        $foundRajeshKumar = true;
    }
}
assert_test(!$hasNonCse, "CSE Coordinator ONLY sees CSE faculty (no other depts)", $errors);
assert_test($foundRajeshKumar, "CSE Coordinator sees Dr. Rajesh Kumar (ID 60007) with 'Computer Science and Engineering'", $errors);


echo "\n=== 4. Testing faculty_achievement_presentation_data() ===\n";
$presRajesh = faculty_achievement_presentation_data(60007);
assert_test(!empty($presRajesh['faculty']), "Dr. Rajesh Kumar faculty presentation info generated", $errors);
assert_test(isset($presRajesh['slides']) && is_array($presRajesh['slides']), "Dr. Rajesh Kumar slides generated", $errors);
echo " Slides count: " . count($presRajesh['slides']) . "\n";


echo "\n=== 5. Testing department_achievements_comparison() isolation ===\n";
$compCsbs = department_achievements_comparison($coordCsbs);
$compCsbsDepts = array_column($compCsbs, 'department');
echo " CSBS comparison returned: " . implode(', ', $compCsbsDepts) . "\n";
assert_test(count($compCsbs) === 1 && department_names_match($compCsbs[0]['department'], 'CSBS'), "CSBS comparison only returns CSBS", $errors);

$compCse = department_achievements_comparison($coordCse);
$compCseDepts = array_column($compCse, 'department');
echo " CSE comparison returned: " . implode(', ', $compCseDepts) . "\n";
assert_test(count($compCse) === 1 && department_names_match($compCse[0]['department'], 'CSE'), "CSE comparison only returns CSE", $errors);


echo "\n============================================\n";
if ($errors === 0) {
    echo "ALL TESTS PASSED WITH ZERO ERRORS!\n";
} else {
    echo "FAILED: $errors error(s) encountered.\n";
}
exit($errors > 0 ? 1 : 0);
