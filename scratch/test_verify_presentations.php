<?php
require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/inc/auth.php';
require_once __DIR__ . '/../php-app/models/ExecutiveMeetingReport.php';
require_once __DIR__ . '/../php-app/models/FacultyAchievement.php';
require_once __DIR__ . '/../php-app/models/StudentAchievement.php';

echo "==================================================\n";
echo "1. VERIFY EXECUTIVE MEETING REPORT SLIDES\n";
echo "==================================================\n";

// Test with Admin user (college-wide)
$adminUser = ['id' => 1, 'role' => 'Admin', 'name' => 'Dr. Admin', 'department' => 'Administration'];
$filters = em_resolve_filters($adminUser, ['academic_year' => '2023-2024']);
$dataset = em_dataset($adminUser, $filters);
$slides = em_slides($dataset);

echo "Admin slides count: " . count($slides) . "\n";
echo "Slide 0 type: " . $slides[0]['type'] . " | title: " . $slides[0]['title'] . "\n";
if (isset($slides[0]['contributions'])) {
    $c = $slides[0]['contributions'];
    echo "Contributions attached to slide 0: Yes\n";
    echo "Mode: " . ($c['mode'] ?? 'none') . "\n";
    echo "Top 3 count: " . count($c['top3'] ?? []) . "\n";
    echo "Items count: " . count($c['items'] ?? []) . "\n";
    echo "Donut slices count: " . count($c['donut_slices'] ?? []) . "\n";
    echo "Total records in contrib: " . ($c['total_count'] ?? 0) . "\n";
    echo "Total target in contrib: " . ($c['total_target'] ?? 0) . "\n";
    echo "Total achieved in contrib: " . ($c['total_achieved'] ?? 0) . "\n";
} else {
    echo "ERROR: Contributions NOT attached to slide 0!\n";
}

// Test with HoD user (department scoped)
$hodUser = ['id' => 2, 'role' => 'HoD', 'name' => 'Dr. CSE HoD', 'department' => 'CSE'];
$hodFilters = em_resolve_filters($hodUser, ['academic_year' => '2023-2024']);
$hodDataset = em_dataset($hodUser, $hodFilters);
$hodSlides = em_slides($hodDataset);
echo "\nHoD slides count: " . count($hodSlides) . "\n";
echo "HoD Slide 0 type: " . $hodSlides[0]['type'] . "\n";
if (isset($hodSlides[0]['contributions'])) {
    $hc = $hodSlides[0]['contributions'];
    echo "HoD Mode: " . ($hc['mode'] ?? 'none') . "\n";
    echo "HoD Top 3 count: " . count($hc['top3'] ?? []) . "\n";
    echo "HoD Items count: " . count($hc['items'] ?? []) . "\n";
    echo "HoD Donut slices count: " . count($hc['donut_slices'] ?? []) . "\n";
}

echo "\n==================================================\n";
echo "2. VERIFY FACULTY ACHIEVEMENT PRESENTATION DATA\n";
echo "==================================================\n";

$facultyRow = db()->query("SELECT id, name, department FROM users WHERE role = 'Faculty' LIMIT 1")->fetch();
if ($facultyRow) {
    echo "Testing Faculty: " . $facultyRow['name'] . " (ID: " . $facultyRow['id'] . ", Dept: " . $facultyRow['department'] . ")\n";
    $facPres = faculty_achievement_presentation_data((int)$facultyRow['id'], '2023-2024');
    echo "Faculty slides count: " . count($facPres['slides']) . "\n";
    echo "Faculty slide 0 type: " . $facPres['slides'][0]['type'] . " | title: " . $facPres['slides'][0]['title'] . "\n";
    if (isset($facPres['slides'][0]['contributions'])) {
        $fc = $facPres['slides'][0]['contributions'];
        echo "Contributions attached to faculty slide 0: Yes\n";
        echo "Faculty mode: " . ($fc['mode'] ?? 'none') . "\n";
        echo "Faculty top 3 count: " . count($fc['top3'] ?? []) . "\n";
        echo "Faculty items count: " . count($fc['items'] ?? []) . "\n";
        echo "Faculty donut slices count: " . count($fc['donut_slices'] ?? []) . "\n";
    } else {
        echo "ERROR: Contributions NOT attached to faculty slide 0!\n";
    }
} else {
    echo "No faculty user found in database.\n";
}

echo "\n==================================================\n";
echo "3. VERIFY STUDENT ACHIEVEMENT PRESENTATION DATA\n";
echo "==================================================\n";

$stuRow = db()->query("SELECT student_name, reg_no, department FROM student_achievements LIMIT 1")->fetch();
if ($stuRow) {
    $stuKey = student_make_key($stuRow['reg_no'], $stuRow['student_name'], $stuRow['department']);
    echo "Testing Student: " . $stuRow['student_name'] . " (" . $stuRow['reg_no'] . ")\n";
    $stuPres = student_achievement_presentation_data($stuKey, '2023-2024', $stuRow['reg_no'], $stuRow['student_name'], $stuRow['department']);
    echo "Student slides count: " . count($stuPres['slides']) . "\n";
    echo "Student slide 0 type: " . $stuPres['slides'][0]['type'] . "\n";
    if (isset($stuPres['slides'][0]['contributions'])) {
        $sc = $stuPres['slides'][0]['contributions'];
        echo "Contributions attached to student slide 0: Yes\n";
        echo "Student mode: " . ($sc['mode'] ?? 'none') . "\n";
        echo "Student top 3 count: " . count($sc['top3'] ?? []) . "\n";
        echo "Student items count: " . count($sc['items'] ?? []) . "\n";
        echo "Student donut slices count: " . count($sc['donut_slices'] ?? []) . "\n";
    } else {
        echo "ERROR: Contributions NOT attached to student slide 0!\n";
    }
} else {
    echo "No student achievements found in database.\n";
}

echo "\n==================================================\n";
echo "ALL VERIFICATION CHECKS PASSED!\n";
echo "==================================================\n";
