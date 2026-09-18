<?php
require_once __DIR__ . '/../php-app/inc/auth.php';
require_once __DIR__ . '/../php-app/models/Record.php';

$types = record_types();
$hodUser = db()->query("SELECT * FROM users WHERE role = 'HoD' AND department = 'CSBS' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

echo "Testing HoD: {$hodUser['name']} ({$hodUser['department']})\n";

foreach ($types as $key => $t) {
    $table = $t['table'];
    // Find a record in CSBS
    $rec = db()->query("SELECT * FROM `{$table}` WHERE department = 'CSBS' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$rec) {
        echo "Type: $key | No CSBS record found in $table\n";
        continue;
    }
    
    // Check record_find($key, $rec['id'])
    $found = record_find($key, (int)$rec['id']);
    if (!$found) {
        echo "Type: $key | ERROR: record_find failed for ID {$rec['id']}\n";
        continue;
    }
    
    echo "Type: $key | ID: {$rec['id']} | Title: {$found['_title']} | Status: {$rec['status']} | Dept: {$rec['department']} | AY: " . ($rec['academic_year'] ?? 'N/A') . "\n";
}
