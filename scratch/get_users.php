<?php
require_once __DIR__ . '/../php-app/inc/db.php';
$users = db()->query('SELECT id, name, email, role FROM users LIMIT 5')->fetchAll(PDO::FETCH_ASSOC);
foreach ($users as $u) {
    echo $u['id'] . ' | ' . $u['role'] . ' | ' . $u['email'] . "\n";
}
