<?php
require_once __DIR__ . '/../php-app/inc/config.php';
require_once __DIR__ . '/../php-app/inc/db.php';
$admins = db()->query("SELECT id, name, email, role FROM users WHERE role = 'Admin'")->fetchAll();
print_r($admins);
