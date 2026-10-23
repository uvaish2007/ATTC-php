<?php
require_once __DIR__ . '/../php-app/inc/auth.php';
$u = db()->query("SELECT id, name, email, role, department FROM users WHERE email = 'master.faculty@atts.edu'")->fetch();
print_r($u);
