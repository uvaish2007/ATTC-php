<?php
require_once __DIR__ . '/../php-app/inc/env.php';
require_once __DIR__ . '/../php-app/inc/db.php';

$pdo = db();
echo "--- DESCRIBE placements ---\n";
$cols = $pdo->query("DESCRIBE placements")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    echo "{$c['Field']} - {$c['Type']} - Null:{$c['Null']} - Default:{$c['Default']}\n";
}

echo "\n--- SAMPLE ROWS ---\n";
$rows = $pdo->query("SELECT * FROM placements LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
print_r($rows);
