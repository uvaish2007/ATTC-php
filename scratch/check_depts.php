<?php
require_once __DIR__ . '/../php-app/inc/auth.php';
require_once __DIR__ . '/../php-app/models/Record.php';

$types = record_types();
foreach ($types as $key => $t) {
    $table = $t['table'];
    $stmt = db()->query("SELECT DISTINCT department FROM `{$table}`");
    $depts = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "$key: " . json_encode($depts) . "\n";
}
