<?php
require_once __DIR__ . '/../php-app/inc/auth.php';

auth_boot();

$accounts = [
    ['role' => 'Admin', 'email' => 'mohameduvaish132@gmail.com', 'pw' => 'uvaish123'],
    ['role' => 'Principal', 'email' => 'director@atts.edu', 'pw' => 'director123'],
    ['role' => 'Dean', 'email' => 'dean@atts.edu', 'pw' => 'dean1234'],
    ['role' => 'HoD', 'email' => 'hod@atts.edu', 'pw' => 'hod12345'],
    ['role' => 'Coordinator', 'email' => 'coordinator@atts.edu', 'pw' => 'coord1234'],
    ['role' => 'Faculty', 'email' => 'faculty@atts.edu', 'pw' => 'faculty12'],
];

echo "Testing user credentials:\n";
foreach ($accounts as $acc) {
    $failReason = null;
    $user = attempt_login($acc['email'], $acc['pw'], $acc['role'], $failReason);
    if ($user) {
        echo "[SUCCESS] {$acc['role']} ({$acc['email']}) logged in as {$user['name']} (ID {$user['id']})\n";
    } else {
        echo "[FAILED] {$acc['role']} ({$acc['email']}) failed: {$failReason}\n";
    }
}
