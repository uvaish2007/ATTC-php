<?php
require __DIR__ . '/../php-app/inc/db.php';
$stmt = db()->query('SELECT id, name, email, role, status, password FROM users');
$users = $stmt->fetchAll();
foreach ($users as $u) {
    echo sprintf("%d | %s | %s | %s | %d | %s\n", $u['id'], $u['name'], $u['email'], $u['role'], $u['status'], $u['password']);
}
