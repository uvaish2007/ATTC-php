<?php
require_once __DIR__ . '/../php-app/inc/helpers.php';
require_once __DIR__ . '/../php-app/inc/auth.php';

$pdo = db();
try {
    $cols = $pdo->query("SHOW FULL COLUMNS FROM `inst_value_added_courses`")->fetchAll(PDO::FETCH_ASSOC);
    echo "=== COLUMNS OF inst_value_added_courses ===\n";
    foreach ($cols as $c) {
        echo "{$c['Field']} ({$c['Type']})\n";
    }
} catch (Exception $e) {
    echo "inst_value_added_courses does not exist or error: " . $e->getMessage() . "\n";
}
