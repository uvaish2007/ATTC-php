<?php
require_once __DIR__ . '/../php-app/inc/config.php';
require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/inc/auth.php';

$u = db()->query("SELECT * FROM users WHERE email = 'hod@atts.edu'")->fetch(PDO::FETCH_ASSOC);
echo "User hod@atts.edu:\n";
print_r($u);

$passwords = ['password', 'hod123', 'admin123', 'uvaish123', 'master123', '123456'];
foreach ($passwords as $p) {
    if (password_verify($p, $u['password'])) {
        echo "Password for hod@atts.edu is: '$p'\n";
        break;
    }
}

// Check master.hod@atts.edu
$mh = db()->query("SELECT * FROM users WHERE email = 'master.hod@atts.edu'")->fetch(PDO::FETCH_ASSOC);
echo "master.hod password verify 'master123': " . (password_verify('master123', $mh['password']) ? 'YES' : 'NO') . "\n";
