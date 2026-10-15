<?php
require_once __DIR__ . '/../php-app/inc/config.php';
require_once __DIR__ . '/../php-app/inc/db.php';

$u = db()->query("SELECT password FROM users WHERE email = 'hod@atts.edu'")->fetchColumn();
$list = ['hod', 'hod@atts.edu', 'password123', 'atts123', 'atts', '12345678', 'admin', 'csbs', 'hodcsbs', 'cse', 'secret', 'demo123', 'hod@123'];
foreach ($list as $w) {
    if (password_verify($w, $u)) {
        echo "MATCH: $w\n";
    }
}
