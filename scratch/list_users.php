<?php
require 'php-app/inc/db.php';
$stmt = db()->query("SELECT id, name, email, role, department FROM users");
foreach ($stmt as $u) {
    echo "{$u['role']} (ID: {$u['id']}) -> {$u['email']} | Dept: {$u['department']}\n";
}
