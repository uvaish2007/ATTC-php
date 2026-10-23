<?php
require_once __DIR__ . '/../php-app/inc/auth.php';
$u = db()->query("SELECT id, name, email, role, password FROM users WHERE id = 60021")->fetch();
print_r($u);
