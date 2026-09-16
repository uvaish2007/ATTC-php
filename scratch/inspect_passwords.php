<?php
require_once __DIR__ . '/../php-app/inc/auth.php';

$users = db()->query("SELECT email, password, role FROM users")->fetchAll(PDO::FETCH_ASSOC);
foreach ($users as $u) {
    echo "User: " . $u['email'] . " | Role: " . $u['role'] . "\n";
    echo "  Verify 'hod123': " . (password_verify('hod123', $u['password']) ? 'YES' : 'NO') . "\n";
    echo "  Verify 'dean123': " . (password_verify('dean123', $u['password']) ? 'YES' : 'NO') . "\n";
    echo "  Verify 'faculty123': " . (password_verify('faculty123', $u['password']) ? 'YES' : 'NO') . "\n";
    echo "  Verify 'password': " . (password_verify('password', $u['password']) ? 'YES' : 'NO') . "\n";
    echo "  Verify '123456': " . (password_verify('123456', $u['password']) ? 'YES' : 'NO') . "\n";
    echo "  Verify 'admin123': " . (password_verify('admin123', $u['password']) ? 'YES' : 'NO') . "\n";
}
