<?php
require_once __DIR__ . '/../php-app/inc/auth.php';
$st = db()->query("SELECT id, name, email, role, department FROM users WHERE role IN ('HoD', 'Coordinator', 'Faculty') ORDER BY department, role");
echo "ID   | Name                 | Email                        | Role        | Department\n";
echo "-----+----------------------+------------------------------+-------------+-------------------------\n";
foreach ($st->fetchAll() as $u) {
    echo sprintf("%-4d | %-20s | %-28s | %-11s | %s\n", $u['id'], substr($u['name'], 0, 20), $u['email'], $u['role'], $u['department']);
}
