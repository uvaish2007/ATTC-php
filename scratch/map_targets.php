<?php
require_once __DIR__ . '/../php-app/inc/record_specs.php';
require_once __DIR__ . '/../php-app/models/Record.php';
require_once __DIR__ . '/../php-app/inc/db.php';

$specs = record_report_specs();
$types = record_types();
$pdo = db();

$dbMetrics = $pdo->query("SELECT DISTINCT metric FROM targets ORDER BY metric")->fetchAll(PDO::FETCH_COLUMN);

echo "Mapping 56 specs against existing targets in DB:\n";
foreach ($specs as $key => $spec) {
    $title = $spec['title'];
    $matches = [];
    foreach ($dbMetrics as $m) {
        // Test fuzzy / partial matching
        if (strcasecmp($m, $title) === 0) {
            $matches[] = "= $m";
        } elseif (stripos($m, $title) !== false || stripos($title, $m) !== false) {
            $matches[] = "~ $m";
        }
    }
    echo sprintf("%-28s | %-40s | %s\n", $key, substr($title, 0, 40), implode(', ', $matches) ?: 'NO MATCH');
}
