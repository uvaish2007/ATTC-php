<?php
require_once __DIR__ . '/../php-app/inc/config.php';
require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/models/TargetPresentation.php';

$dataset = targets_presentation_dataset(['id' => 1, 'role' => 'Admin', 'name' => 'Mohamed Uvaish']);
echo "Academic Year: {$dataset['academic_year']}\n";
echo "Total sections_30 count: " . count($dataset['sections_30']) . "\n";
echo "Total categories count: " . count($dataset['categories']) . "\n";
assert(count($dataset['sections_30']) === 30);
assert(count($dataset['categories']) === 30);

foreach ($dataset['sections_30'] as $order => $sec) {
    echo "Slide " . ($order + 7) . " -> Section {$order}: [{$sec['id']}] {$sec['title']} | Fixed: {$sec['target_fixed']} {$sec['unit']}, Achieved: {$sec['achieved_value']} {$sec['unit']} | Records: {$sec['total_records']}\n";
}
