<?php
require_once __DIR__ . '/../php-app/inc/config.php';
require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/inc/upload_sections.php';

$rows = db()->query('SELECT id, department, academic_year, metric, target_value, achieved_value, status, target_deadline FROM targets ORDER BY academic_year, id')->fetchAll(PDO::FETCH_ASSOC);
echo "Targets table count: " . count($rows) . "\n";
foreach ($rows as $r) {
    echo "#{$r['id']} [{$r['academic_year']}] dept={$r['department']} metric='{$r['metric']}' target={$r['target_value']} achieved={$r['achieved_value']} status={$r['status']}\n";
}

echo "\n--- Approved Sections ---\n";
$sections = upload_approved_sections();
echo "Sections count: " . count($sections) . "\n";
foreach ($sections as $k => $s) {
    echo "{$s['order']}. [{$s['id']}] {$s['title']} (types: " . implode(',', $s['types']) . ")\n";
}
