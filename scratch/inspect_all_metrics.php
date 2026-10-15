<?php
require_once __DIR__ . '/../php-app/inc/db.php';
$pdo = db();
$stmt = $pdo->query("SELECT id, metric_key, name, display_order FROM metrics ORDER BY id");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo $r['id'] . " | " . ($r['metric_key'] ?: 'NULL') . " | " . $r['name'] . " | " . $r['display_order'] . PHP_EOL;
}
