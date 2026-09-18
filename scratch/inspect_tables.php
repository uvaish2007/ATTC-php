<?php
require_once __DIR__ . '/../php-app/inc/auth.php';
require_once __DIR__ . '/../php-app/models/Record.php';

$types = record_types();
foreach ($types as $key => $t) {
    $table = $t['table'];
    try {
        $depts = db()->query("SELECT DISTINCT department FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
        $years = db()->query("SELECT DISTINCT academic_year FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
        echo sprintf("%-22s | Depts: %s | Years: %s\n", $key, json_encode($depts), json_encode($years));
    } catch (Exception $e) {
        echo "$key: " . $e->getMessage() . "\n";
    }
}
