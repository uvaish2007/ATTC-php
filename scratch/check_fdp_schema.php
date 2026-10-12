<?php
require_once __DIR__ . '/../php-app/inc/env.php';
require_once __DIR__ . '/../php-app/inc/db.php';
$pdo = db();
echo "--- FDP COLUMNS ---\n";
foreach ($pdo->query("DESCRIBE fdp")->fetchAll(PDO::FETCH_ASSOC) as $c) {
    echo "  {$c['Field']} ({$c['Type']})\n";
}
echo "\n--- FDP SAMPLE ROWS ---\n";
$rows = $pdo->query("SELECT * FROM fdp LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
print_r($rows);

echo "\n--- CONFERENCE COLUMNS ---\n";
foreach ($pdo->query("DESCRIBE conference_publications")->fetchAll(PDO::FETCH_ASSOC) as $c) {
    echo "  {$c['Field']} ({$c['Type']})\n";
}
echo "\n--- CONFERENCE COUNT ---\n";
echo "Count: " . $pdo->query("SELECT COUNT(*) FROM conference_publications")->fetchColumn() . "\n";
