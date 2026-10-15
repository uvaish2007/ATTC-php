<?php
require_once __DIR__ . '/../php-app/inc/config.php';
require_once __DIR__ . '/../php-app/models/TargetPresentation.php';

$d = targets_presentation_dataset(['role' => 'Admin']);
echo "Dataset loaded successfully!\n";
echo "Active Year: " . $d['academic_year'] . "\n";
echo "Total targets: " . $d['summary']['total_targets'] . "\n";
echo "Achieved targets: " . $d['summary']['achieved_targets'] . "\n";
echo "In Progress targets: " . $d['summary']['in_progress_targets'] . "\n";
echo "Not Completed targets: " . $d['summary']['not_completed_targets'] . "\n";
echo "Categories count: " . count($d['categories']) . "\n";
echo "Top departments: " . count($d['top_departments']) . "\n";
echo "All departments: " . count($d['all_departments']) . "\n";
foreach ($d['categories'] as $c) {
    echo sprintf("Category: %-25s | Fixed: %4d | Achieved: %4d | Uploaded: %2d\n",
        substr($c['title'], 0, 25), $c['target_fixed'], $c['achieved_value'], count($c['uploaded_records']));
}
