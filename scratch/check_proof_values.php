<?php
require_once __DIR__ . '/../php-app/inc/auth.php';
require_once __DIR__ . '/../php-app/models/Record.php';

$types = record_types();
foreach ($types as $key => $t) {
    $table = $t['table'];
    $stmt = db()->query("SELECT id, status, proof_file FROM `{$table}` LIMIT 3");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "$key: " . json_encode($rows) . "\n";
}
