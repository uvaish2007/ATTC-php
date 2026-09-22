<?php
require_once __DIR__ . '/../php-app/inc/db.php';
$users = db()->query("SELECT id, name, email, role, department FROM users WHERE role IN ('Principal', 'Director')")->fetchAll();
print_r($users);
