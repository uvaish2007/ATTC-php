<?php
require_once __DIR__ . '/../php-app/inc/db.php';
$tables = db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$inst = array_filter($tables, fn($t) => str_starts_with($t, 'inst_'));
echo "=== Existing inst_ tables ===\n";
foreach ($inst as $t) { echo "  $t\n"; }
echo "Total: " . count($inst) . "\n\n";
echo "=== All tables ===\n";
foreach ($tables as $t) { echo "  $t\n"; }
