<?php
require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/models/FacultyAchievement.php';

$facA = ['id' => 5, 'role' => 'Faculty', 'department' => 'CSBS'];
$facB_ID = 6; // different faculty member

$admin = ['id' => 1, 'role' => 'Admin', 'department' => null];
$hodCSBS = ['id' => 2, 'role' => 'HoD', 'department' => 'CSBS'];

echo "SECURITY AUTHORIZATION TESTS:\n";
echo "1. Faculty A accessing self (ID 5): " . (can_user_view_faculty_report($facA, 5) ? 'PASS (Allowed)' : 'FAIL') . "\n";
echo "2. Faculty A accessing Faculty B (ID 6): " . (!can_user_view_faculty_report($facA, 6) ? 'PASS (Forbidden)' : 'FAIL') . "\n";
echo "3. Admin accessing Faculty B (ID 6): " . (can_user_view_faculty_report($admin, 6) ? 'PASS (Allowed)' : 'FAIL') . "\n";
echo "4. HoD CSBS accessing CSBS faculty: " . (can_user_view_faculty_report($hodCSBS, 5) ? 'PASS (Allowed)' : 'FAIL') . "\n";
