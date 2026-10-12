<?php
require_once __DIR__ . '/../php-app/inc/record_specs.php';
require_once __DIR__ . '/../php-app/models/Record.php';
require_once __DIR__ . '/../php-app/models/Department.php';
require_once __DIR__ . '/../php-app/models/Target.php';
require_once __DIR__ . '/../php-app/inc/helpers.php';
require_once __DIR__ . '/../php-app/inc/db.php';

// Let's inspect users in DB
$pdo = db();
$users = $pdo->query("SELECT id, name, role, department FROM users ORDER BY role, id")->fetchAll(PDO::FETCH_ASSOC);
echo "Sample users:\n";
foreach ($users as $u) {
    echo "ID: {$u['id']} | Role: {$u['role']} | Dept: {$u['department']} | Name: {$u['name']}\n";
}
