<?php
require_once __DIR__ . '/../php-app/inc/auth.php';
require_once __DIR__ . '/../php-app/models/Record.php';
require_once __DIR__ . '/../php-app/models/Department.php';

echo "Active Academic Year: " . active_academic_year() . "\n";

echo "\n--- DEPARTMENTS ---\n";
foreach (departments_all() as $d) {
    echo "ID: " . $d['id'] . " | Name: " . $d['name'] . "\n";
}

echo "\n--- USERS ---\n";
$users = db()->query("SELECT id, name, email, role, department FROM users ORDER BY role, id")->fetchAll(PDO::FETCH_ASSOC);
foreach ($users as $u) {
    echo "ID: {$u['id']} | Role: {$u['role']} | Dept: {$u['department']} | Email: {$u['email']}\n";
}

echo "\n--- RECORD TYPES & RECORD COUNTS ---\n";
$types = record_types();
foreach ($types as $key => $t) {
    $table = $t['table'];
    try {
        $count = db()->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        $stCounts = db()->query("SELECT status, COUNT(*) as cnt FROM `{$table}` GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
        $stStr = [];
        foreach ($stCounts as $st => $c) {
            $stStr[] = "$st: $c";
        }
        echo sprintf("%-22s (table: %-22s): Total: %2d | %s\n", $key, $table, $count, implode(', ', $stStr));
    } catch (Exception $e) {
        echo sprintf("%-22s: ERROR - %s\n", $key, $e->getMessage());
    }
}

echo "\n--- EDIT REQUESTS ---\n";
$reqs = db()->query("SELECT * FROM edit_requests ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
foreach ($reqs as $er) {
    echo "ER-#{$er['id']} | Rec: {$er['record_type']}#{$er['record_id']} | Dept: {$er['department']} | Status: {$er['status']} | Reason: {$er['reason']}\n";
}
