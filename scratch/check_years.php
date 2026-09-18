<?php
require_once __DIR__ . '/../php-app/inc/auth.php';
require_once __DIR__ . '/../php-app/models/Record.php';

$types = record_types();
foreach ($types as $key => $t) {
    $table = $t['table'];
    $cols = db()->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('academic_year', $cols, true)) {
        $stmt = db()->query("SELECT academic_year, COUNT(*) as cnt FROM `{$table}` GROUP BY academic_year");
        $counts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        echo "$key: " . json_encode($counts) . "\n";
    } else {
        echo "$key: NO academic_year column\n";
    }
}
