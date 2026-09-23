<?php
require_once __DIR__ . '/../php-app/inc/auth.php';

echo "COMPREHENSIVE LOGIN VERIFICATION:\n\n";

$tests = [
    // Standard credential logins
    ['label' => 'Admin via email & uvaish123', 'email' => 'mohameduvaish132@gmail.com', 'pw' => 'uvaish123', 'role' => 'Admin', 'expectedRole' => 'Admin'],
    ['label' => 'Admin via shortcut "admin" & uvaish123', 'email' => 'admin', 'pw' => 'uvaish123', 'role' => 'Admin', 'expectedRole' => 'Admin'],
    ['label' => 'Admin via shortcut "admin" & admin123', 'email' => 'admin', 'pw' => 'admin123', 'role' => 'Admin', 'expectedRole' => 'Admin'],
    ['label' => 'Director via director@atts.edu & director123', 'email' => 'director@atts.edu', 'pw' => 'director123', 'role' => 'Principal', 'expectedRole' => 'Principal'],
    ['label' => 'Director via shortcut "director"', 'email' => 'director', 'pw' => 'director123', 'role' => 'Principal', 'expectedRole' => 'Principal'],
    ['label' => 'Principal via principal@atts.edu', 'email' => 'principal@atts.edu', 'pw' => 'director123', 'role' => 'Principal', 'expectedRole' => 'Principal'],
    ['label' => 'Dean via dean@atts.edu & dean1234', 'email' => 'dean@atts.edu', 'pw' => 'dean1234', 'role' => 'Dean', 'expectedRole' => 'Dean'],
    ['label' => 'Dean via shortcut "dean" & dean1234', 'email' => 'dean', 'pw' => 'dean1234', 'role' => 'Dean', 'expectedRole' => 'Dean'],
    ['label' => 'Dean via dean123 (legacy)', 'email' => 'dean@atts.edu', 'pw' => 'dean123', 'role' => 'Dean', 'expectedRole' => 'Dean'],
    ['label' => 'HoD via hod@atts.edu & hod12345', 'email' => 'hod@atts.edu', 'pw' => 'hod12345', 'role' => 'HoD', 'expectedRole' => 'HoD'],
    ['label' => 'HoD via shortcut "hod" & hod12345', 'email' => 'hod', 'pw' => 'hod12345', 'role' => 'HoD', 'expectedRole' => 'HoD'],
    ['label' => 'HoD via hod123 (legacy)', 'email' => 'hod@atts.edu', 'pw' => 'hod123', 'role' => 'HoD', 'expectedRole' => 'HoD'],
    ['label' => 'Coordinator via coordinator@atts.edu & coord1234', 'email' => 'coordinator@atts.edu', 'pw' => 'coord1234', 'role' => 'Coordinator', 'expectedRole' => 'Coordinator'],
    ['label' => 'Coordinator via shortcut "coordinator" & coord1234', 'email' => 'coordinator', 'pw' => 'coord1234', 'role' => 'Coordinator', 'expectedRole' => 'Coordinator'],
    ['label' => 'Coordinator via shortcut "coord"', 'email' => 'coord', 'pw' => 'coord1234', 'role' => 'Coordinator', 'expectedRole' => 'Coordinator'],
    ['label' => 'Coordinator via coordinator123 (legacy)', 'email' => 'coordinator@atts.edu', 'pw' => 'coordinator123', 'role' => 'Coordinator', 'expectedRole' => 'Coordinator'],
    ['label' => 'Faculty via faculty@atts.edu & faculty12', 'email' => 'faculty@atts.edu', 'pw' => 'faculty12', 'role' => 'Faculty', 'expectedRole' => 'Faculty'],
    ['label' => 'Faculty via faculty@atts.edu & faculty123', 'email' => 'faculty@atts.edu', 'pw' => 'faculty123', 'role' => 'Faculty', 'expectedRole' => 'Faculty'],
    ['label' => 'Faculty via shortcut "faculty" & faculty12', 'email' => 'faculty', 'pw' => 'faculty12', 'role' => 'Faculty', 'expectedRole' => 'Faculty'],

    // Role mismatch scenario (e.g. user selected Admin on Step 1, but entered Coordinator)
    ['label' => 'Selected Admin on Step 1, but entered Coordinator credentials', 'email' => 'coordinator@atts.edu', 'pw' => 'coord1234', 'role' => 'Admin', 'expectedRole' => 'Coordinator', 'autoRecover' => true],
    ['label' => 'Selected Coordinator on Step 1, but entered Faculty credentials', 'email' => 'faculty@atts.edu', 'pw' => 'faculty12', 'role' => 'Coordinator', 'expectedRole' => 'Faculty', 'autoRecover' => true],
];

$pass = 0;
$fail = 0;

foreach ($tests as $t) {
    $failReason = null;
    $user = attempt_login($t['email'], $t['pw'], $t['role'], $failReason);
    if (!$user && !empty($t['autoRecover']) && $failReason && str_starts_with($failReason, 'role_mismatch:')) {
        $user = attempt_login($t['email'], $t['pw'], null, $failReason);
    }

    if ($user && $user['role'] === $t['expectedRole']) {
        $pass++;
        echo "[PASS] {$t['label']} -> logged in as {$user['role']} ({$user['email']})\n";
    } else {
        $fail++;
        echo "[FAIL] {$t['label']} -> reason: {$failReason}\n";
    }
}

echo "\n============================================\n";
echo "RESULT: {$pass} PASS, {$fail} FAIL\n";
echo "============================================\n";
