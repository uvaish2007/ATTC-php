<?php
require_once __DIR__ . '/../php-app/inc/auth.php';

echo "BUG-LOGIN-01 AUTOMATED TEST SUITE:\n";

// Test 1: 'admin ' with trailing space in username, 'admin123 ' with trailing space in password, role Admin
$user1 = attempt_login("  admin   ", "  admin123  ", "Admin");
echo "1. Login with '  admin   ' (spaces) & '  admin123  ': " . ($user1 ? "PASS (User: {$user1['email']})" : "FAIL") . "\n";

// Test 2: 'admin@atts.edu ' with trailing space
$user2 = attempt_login("admin@atts.edu ", "admin123", "Admin");
echo "2. Login with 'admin@atts.edu ': " . ($user2 ? "PASS (User: {$user2['email']})" : "FAIL") . "\n";

// Test 3: 'principal ' with trailing space, role Principal
$user3 = attempt_login("  principal  ", "principal123  ", "Principal");
echo "3. Login with '  principal  ' & 'principal123  ': " . ($user3 ? "PASS (User: {$user3['email']})" : "FAIL") . "\n";

// Test 4: 'faculty ' with trailing space, role Faculty
$user4 = attempt_login("  faculty  ", "  faculty123  ", "Faculty");
echo "4. Login with '  faculty  ': " . ($user4 ? "PASS (User: {$user4['email']})" : "FAIL") . "\n";

// Test 5: 'hod ' with trailing space, role HoD
$user5 = attempt_login("hod ", "hod123", "HoD");
echo "5. Login with 'hod ': " . ($user5 ? "PASS (User: {$user5['email']})" : "FAIL") . "\n";

// Test 6: 'dean ' with trailing space, role Dean
$user6 = attempt_login("dean ", "dean123", "Dean");
echo "6. Login with 'dean ': " . ($user6 ? "PASS (User: {$user6['email']})" : "FAIL") . "\n";
