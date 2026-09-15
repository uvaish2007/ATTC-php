<?php
require_once 'c:/Users/ELCOT/Downloads/ATTC-php-main-20260912T093104Z-1-001/ATTC-php-main/php-app/inc/db.php';
require_once 'c:/Users/ELCOT/Downloads/ATTC-php-main-20260912T093104Z-1-001/ATTC-php-main/php-app/inc/auth.php';
require_once 'c:/Users/ELCOT/Downloads/ATTC-php-main-20260912T093104Z-1-001/ATTC-php-main/php-app/inc/nav.php';
require_once 'c:/Users/ELCOT/Downloads/ATTC-php-main-20260912T093104Z-1-001/ATTC-php-main/php-app/models/Dashboard.php';
require_once 'c:/Users/ELCOT/Downloads/ATTC-php-main-20260912T093104Z-1-001/ATTC-php-main/php-app/models/Target.php';

echo "=== 1. VERIFY LOGIN.PHP ROLES ARRAY ===\n";
$loginSrc = file_get_contents('c:/Users/ELCOT/Downloads/ATTC-php-main-20260912T093104Z-1-001/ATTC-php-main/php-app/login.php');
assert(strpos($loginSrc, "'Principal'") !== false, "Principal must be present in login.php");
assert(strpos($loginSrc, "'Director'") === false, "Director must not be present in login.php roles");
echo "PASS: login.php has 'Principal' and no 'Director' role card.\n";

echo "\n=== 2. VERIFY ATTEMPT_LOGIN AS PRINCIPAL ===\n";
// Test 2a: login with role 'Principal', email 'director@atts.edu', pw 'director123'
$fail = null;
$user1 = attempt_login('director@atts.edu', 'director123', 'Principal', $fail);
assert($user1 !== null, "Login 1 failed: $fail");
assert($user1['role'] === 'Principal', "User role must be Principal, got {$user1['role']}");
echo "PASS: Login as Principal with director@atts.edu + director123 succeeded.\n";

// Test 2b: login with role 'Principal', email 'principal@atts.edu', pw 'director123'
$fail = null;
$user2 = attempt_login('principal@atts.edu', 'director123', 'Principal', $fail);
assert($user2 !== null, "Login 2 failed: $fail");
assert($user2['role'] === 'Principal', "User role must be Principal, got {$user2['role']}");
echo "PASS: Login as Principal with principal@atts.edu + director123 succeeded.\n";

// Test 2c: login with role 'Principal', email 'principal@atts.edu', pw 'principal123'
$fail = null;
$user3 = attempt_login('principal@atts.edu', 'principal123', 'Principal', $fail);
assert($user3 !== null, "Login 3 failed: $fail");
assert($user3['role'] === 'Principal', "User role must be Principal, got {$user3['role']}");
echo "PASS: Login as Principal with principal@atts.edu + principal123 succeeded.\n";

echo "\n=== 3. VERIFY DASHBOARD TITLE & DATA FOR PRINCIPAL ===\n";
$titles = [
    'Admin'       => 'Admin Dashboard',
    'Principal'   => 'Principal Dashboard',
    'Director'    => 'Principal Dashboard',
    'Dean'        => 'Dean Dashboard',
    'HoD'         => 'HoD Dashboard',
    'Coordinator' => 'Coordinator Dashboard',
];
assert($titles[$user1['role']] === 'Principal Dashboard', "Dashboard title must be Principal Dashboard");
echo "PASS: Dashboard title for Principal is 'Principal Dashboard'.\n";

$data = dashboard_data($user1);
assert($data['isOversight'] === true, "Principal must have isOversight = true");
assert(isset($data['usersByRole']['Principal']), "usersByRole must have Principal");
assert($data['usersByRole']['Principal'] >= 1, "Principal count must be >= 1");
echo "PASS: Dashboard data isOversight is true, usersByRole contains Principal.\n";

echo "\n=== 4. VERIFY NAVIGATION FOR PRINCIPAL ===\n";
$nav = navigation_for('Principal');
assert(!empty($nav), "Navigation for Principal must not be empty");
$navLabels = array_column($nav, 'label');
echo "Principal Navigation Items: " . implode(', ', $navLabels) . "\n";
assert(in_array('Dashboard', $navLabels, true));
assert(in_array('Announcements', $navLabels, true));
assert(in_array('Reports', $navLabels, true));
assert(in_array('Targets', $navLabels, true));
echo "PASS: Principal navigation verified.\n";

echo "\n=== 5. VERIFY REQUIRE_ROLE FOR PRINCIPAL ===\n";
// Simulated session user
$_SESSION['user'] = $user1;
$checked = require_role(['Admin', 'Director', 'Dean']);
assert($checked['role'] === 'Principal', "require_role must accept Principal for Director");
echo "PASS: require_role(['Admin', 'Director', 'Dean']) accepts Principal.\n";

echo "\nALL PRINCIPAL TESTS PASSED SUCCESSFULLY!\n";
