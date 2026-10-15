<?php
require_once __DIR__ . '/../php-app/inc/config.php';
require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/inc/auth.php';

auth_boot();

$u = db()->query("SELECT id, name, email, role, department FROM users WHERE email = 'ece_hod@atts.local'")->fetch(PDO::FETCH_ASSOC);
echo "ECE HoD seeded user:\n";
print_r($u);
