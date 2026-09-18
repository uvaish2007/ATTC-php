<?php
require_once __DIR__ . '/../php-app/inc/auth.php';
require_once __DIR__ . '/../php-app/models/Record.php';

$types = record_types();
foreach ($types as $key => $t) {
    $table = $t['table'];
    $cols = db()->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
    $proofCols = array_filter($cols, fn($c) => str_contains($c, 'proof') || str_contains($c, 'doc') || str_contains($c, 'link') || str_contains($c, 'file') || str_contains($c, 'cert'));
    echo sprintf("%-22s | Table: %-25s | Proof/Doc cols: %s\n", $key, $table, json_encode(array_values($proofCols)));
}
