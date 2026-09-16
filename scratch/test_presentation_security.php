<?php
require_once __DIR__ . '/../php-app/inc/auth.php';
require_once __DIR__ . '/../php-app/models/FacultyAchievement.php';
require_once __DIR__ . '/../php-app/models/User.php';

echo "PRESENTATION MODE & ROLE AUTHORIZATION TEST SUITE:\n";

// 1. Fetch sample users
$adminUser  = db()->query("SELECT * FROM users WHERE role = 'Admin' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$hodCsbs    = db()->query("SELECT * FROM users WHERE role = 'HoD' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$facA       = db()->query("SELECT * FROM users WHERE role = 'Faculty' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$facB       = db()->query("SELECT * FROM users WHERE role = 'Faculty' AND id != " . (int)$facA['id'] . " LIMIT 1")->fetch(PDO::FETCH_ASSOC);

// Test 1: Admin presenting any faculty (facB)
$canAdminView = can_user_view_faculty_report($adminUser, (int) $facB['id']);
echo "1. Admin presenting Faculty B (ID {$facB['id']}): " . ($canAdminView ? "PASS (Allowed)" : "FAIL") . "\n";

// Test 2: HoD presenting Faculty in their dept
$uHodDept = $hodCsbs['department'];
$facDeptMatch = db()->query("SELECT * FROM users WHERE role = 'Faculty' AND department = " . db()->quote($uHodDept) . " LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$facDeptMatch) {
    // Make facA belong to HoD department for test
    db()->exec("UPDATE users SET department = " . db()->quote($uHodDept) . " WHERE id = " . (int)$facA['id']);
    $facDeptMatch = $facA;
}

$canHodViewSelfDept = can_user_view_faculty_report($hodCsbs, (int) $facDeptMatch['id']);
echo "2. HoD {$uHodDept} presenting {$uHodDept} Faculty (ID {$facDeptMatch['id']}): " . ($canHodViewSelfDept ? "PASS (Allowed)" : "FAIL") . "\n";

// Test 3: HoD attempting to present Faculty in another dept
db()->exec("UPDATE users SET department = 'Other Department' WHERE id = " . (int)$facB['id']);
$canHodViewOtherDept = can_user_view_faculty_report($hodCsbs, (int) $facB['id']);
echo "3. HoD {$uHodDept} presenting Other Dept Faculty (ID {$facB['id']}): " . (!$canHodViewOtherDept ? "PASS (Forbidden 403)" : "FAIL") . "\n";

// Test 4: Faculty presenting self
$canFacViewSelf = can_user_view_faculty_report($facA, (int) $facA['id']);
echo "4. Faculty presenting self (ID {$facA['id']}): " . ($canFacViewSelf ? "PASS (Allowed)" : "FAIL") . "\n";

// Test 5: Faculty attempting to present another faculty
$canFacViewOther = can_user_view_faculty_report($facA, (int) $facB['id']);
echo "5. Faculty presenting another faculty (ID {$facB['id']}): " . (!$canFacViewOther ? "PASS (Forbidden 403)" : "FAIL") . "\n";

// Test 6: Verify presentation data structure payload
$presData = faculty_achievement_presentation_data((int) $facA['id'], '2025-26');
$hasSlides = !empty($presData['slides']);
$introSlide = $presData['slides'][0]['type'] ?? '';
$overallSlide = $presData['slides'][1]['type'] ?? '';
$finalSlide = end($presData['slides'])['type'] ?? '';

echo "6. Presentation Data Payload Build: " . ($hasSlides && $introSlide === 'intro' && $overallSlide === 'overall_summary' && $finalSlide === 'final_summary' ? "PASS (" . count($presData['slides']) . " slides generated)" : "FAIL") . "\n";
