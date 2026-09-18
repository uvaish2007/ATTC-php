<?php
require_once __DIR__ . '/../php-app/inc/auth.php';
require_once __DIR__ . '/../php-app/models/Record.php';

$types = record_types();
foreach ($types as $key => $t) {
    $table = $t['table'];
    $cols = db()->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
    $docCols = array_intersect(['document_link', 'certificate_link', 'report_link', 'proceedings_link', 'appointment_order_link'], $cols);
    if (!empty($docCols)) {
        $firstDoc = reset($docCols);
        $cnt = db()->query("SELECT COUNT(*) FROM `{$table}` WHERE (proof_file IS NULL OR proof_file = '') AND ($firstDoc IS NOT NULL AND $firstDoc <> '')")->fetchColumn();
        if ($cnt > 0) {
            echo "Table $table has $cnt rows with empty proof_file but has $firstDoc!\n";
        }
    }
}
