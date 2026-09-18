<?php
require_once __DIR__ . '/../php-app/inc/auth.php';
require_once __DIR__ . '/../php-app/models/Record.php';

$types = record_types();
$totalUpdated = 0;

foreach ($types as $key => $t) {
    $table = $t['table'];
    $cols = db()->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('academic_year', $cols, true)) {
        $stmt = db()->prepare("UPDATE `{$table}` SET academic_year = '2025-26' WHERE academic_year = '2025-2026'");
        $stmt->execute();
        $cnt = $stmt->rowCount();
        if ($cnt > 0) {
            echo "Updated $cnt rows in $table from 2025-2026 to 2025-26\n";
            $totalUpdated += $cnt;
        }
    }
}

echo "Total rows updated across all tables: $totalUpdated\n";
