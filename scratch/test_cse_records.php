<?php
require_once __DIR__ . '/../php-app/inc/auth.php';
require_once __DIR__ . '/../php-app/models/Record.php';

$types = record_types();
$hodUser = db()->query("SELECT * FROM users WHERE role = 'HoD' AND department = 'CSE' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

echo "Testing HoD: {$hodUser['name']} ({$hodUser['department']})\n";

foreach ($types as $key => $t) {
    $table = $t['table'];
    $rec = db()->query("SELECT * FROM `{$table}` WHERE department = 'CSE' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$rec) {
        echo "Type: $key | No CSE record found in $table\n";
        continue;
    }
    
    $found = record_find($key, (int)$rec['id']);
    echo "Type: $key | ID: {$rec['id']} | Title: {$found['_title']} | Status: {$rec['status']} | Dept: {$rec['department']} | AY: " . ($rec['academic_year'] ?? 'N/A') . "\n";
}
