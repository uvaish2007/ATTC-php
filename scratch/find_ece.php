<?php
require_once __DIR__ . '/../php-app/inc/config.php';
require_once __DIR__ . '/../php-app/inc/db.php';

$u = db()->query("SELECT * FROM users WHERE email LIKE '%ece%'")->fetchAll(PDO::FETCH_ASSOC);
echo "Users with ece in email:\n";
print_r($u);
