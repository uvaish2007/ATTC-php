<?php
require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/models/FacultyAchievement.php';

$mockAdmin = [
    'id' => 1,
    'name' => 'Test Admin',
    'role' => 'Admin',
    'department' => null,
];

try {
    $summary = faculty_achievements_summary($mockAdmin);
    echo "SUMMARY SUCCESS:\n";
    print_r($summary);

    $grid = faculty_achievements_grid($mockAdmin);
    echo "GRID SUCCESS: Total faculty: " . count($grid) . "\n";
    if (!empty($grid)) {
        print_r($grid[0]);
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString();
}
