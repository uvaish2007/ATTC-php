<?php
require_once __DIR__ . '/../php-app/inc/config.php';
require_once __DIR__ . '/../php-app/inc/db.php';

$users = db()->query("SELECT id, name, email, role, department FROM users WHERE department = 'ECE'")->fetchAll(PDO::FETCH_ASSOC);
echo "ECE users count: " . count($users) . "\n";
foreach ($users as $u) {
    echo "ID: {$u['id']} | Name: {$u['name']} | Role: {$u['role']} | Email: {$u['email']}\n";
}
