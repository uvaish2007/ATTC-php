<?php
require_once __DIR__ . '/../php-app/inc/record_specs.php';
require_once __DIR__ . '/../php-app/models/Record.php';
require_once __DIR__ . '/../php-app/models/Department.php';
require_once __DIR__ . '/../php-app/inc/db.php';

$pdo = db();
$cseTargets = $pdo->query("SELECT metric, target_value, achieved_value FROM targets WHERE department='CSE' AND academic_year='2025-26'")->fetchAll(PDO::FETCH_ASSOC);

echo "CSE Targets count: " . count($cseTargets) . "\n";
foreach (array_slice($cseTargets, 0, 15) as $t) {
    echo "{$t['metric']} | Target: {$t['target_value']} | Achieved: {$t['achieved_value']}\n";
}
