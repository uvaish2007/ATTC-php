<?php
require_once __DIR__ . '/../php-app/inc/env.php';
require_once __DIR__ . '/../php-app/inc/db.php';

$pdo = db();
$cols = $pdo->query("SHOW COLUMNS FROM fdp")->fetchAll(PDO::FETCH_COLUMN);

if (!in_array('academic_year', $cols, true)) {
    echo "Adding academic_year to fdp...\n";
    $pdo->exec("ALTER TABLE fdp ADD COLUMN academic_year VARCHAR(20) NULL AFTER department");
} else {
    echo "academic_year already exists in fdp.\n";
}

if (!in_array('academic_session', $cols, true)) {
    echo "Adding academic_session to fdp...\n";
    $pdo->exec("ALTER TABLE fdp ADD COLUMN academic_session VARCHAR(50) NULL AFTER exam_session");
} else {
    echo "academic_session already exists in fdp.\n";
}

echo "Current columns in fdp:\n";
foreach ($pdo->query("DESCRIBE fdp")->fetchAll(PDO::FETCH_ASSOC) as $c) {
    echo "  {$c['Field']} ({$c['Type']})\n";
}
