<?php
session_start();
// Simulate login as Faculty user VR (ID 5 for example)
require_once __DIR__ . '/../php-app/inc/auth.php';
require_once __DIR__ . '/../php-app/inc/db.php';

// Find VR user in database
$stmt = db()->prepare("SELECT * FROM users WHERE role = 'Faculty' LIMIT 1");
$stmt->execute();
$facultyUser = $stmt->fetch(PDO::FETCH_ASSOC);

print_r($facultyUser);

$_SESSION['user_id'] = $facultyUser['id'];

// Test calling faculty_achievement_details
require_once __DIR__ . '/../php-app/models/FacultyAchievement.php';
$data = faculty_achievement_details($facultyUser['id'], '2025-26');
echo "FACULTY DETAILS SUCCESS:\n";
echo "Faculty Name: " . $data['faculty']['name'] . "\n";
echo "Total Records: " . count($data['records']) . "\n";
