<?php
require_once __DIR__ . '/../php-app/inc/env.php';
require_once __DIR__ . '/../php-app/inc/db.php';

$pdo = db();
$cols = $pdo->query("SHOW COLUMNS FROM placements")->fetchAll(PDO::FETCH_COLUMN);

if (!in_array('academic_year', $cols, true)) {
    echo "Adding academic_year to placements...\n";
    $pdo->exec("ALTER TABLE placements ADD COLUMN academic_year VARCHAR(20) NULL AFTER department");
    $pdo->exec("UPDATE placements SET academic_year = '2026-27' WHERE academic_year IS NULL");
}

if (!in_array('academic_session', $cols, true)) {
    echo "Adding academic_session to placements...\n";
    $pdo->exec("ALTER TABLE placements ADD COLUMN academic_session VARCHAR(20) NULL AFTER exam_session");
    $pdo->exec("UPDATE placements SET academic_session = '2026-27' WHERE academic_session IS NULL");
}

echo "Current columns in placements:\n";
$currCols = $pdo->query("DESCRIBE placements")->fetchAll(PDO::FETCH_ASSOC);
foreach ($currCols as $c) {
    echo "{$c['Field']} ({$c['Type']})\n";
}
