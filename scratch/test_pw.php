<?php
require __DIR__ . '/../php-app/inc/db.php';
$stmt = db()->query('SELECT id, name, email, role, password FROM users');
$users = $stmt->fetchAll();

$testPasswords = [
    'uvaish123',
    'director123',
    'dean1234',
    'hod12345',
    'coord1234',
    'faculty12',
    'admin123',
    'Admin@123',
    'admin',
    'password',
    '123456',
    'principal123',
    'dean123',
    'hod123',
    'coordinator123',
    'faculty123',
    'pass'
];

foreach ($users as $u) {
    echo "User {$u['id']} ({$u['email']}, {$u['role']}): ";
    $matched = [];
    foreach ($testPasswords as $pw) {
        if (password_verify($pw, $u['password']) || $pw === $u['password']) {
            $matched[] = $pw;
        }
    }
    if ($matched) {
        echo "MATCH: " . implode(', ', $matched) . "\n";
    } else {
        echo "NO MATCH\n";
    }
}
