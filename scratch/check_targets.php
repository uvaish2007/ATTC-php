<?php
require_once __DIR__ . '/../php-app/inc/config.php';
require_once __DIR__ . '/../php-app/inc/db.php';
$pdo = db();
$cols = $pdo->query("SHOW COLUMNS FROM `targets`")->fetchAll(PDO::FETCH_ASSOC);
echo "Columns in targets: " . implode(', ', array_column($cols, 'Field')) . PHP_EOL;

$stmt = $pdo->query("SELECT * FROM `targets` LIMIT 5");
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    print_r($r);
}
