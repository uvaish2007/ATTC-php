<?php
require_once __DIR__ . '/../php-app/inc/auth.php';
require_once __DIR__ . '/../php-app/models/Record.php';

$pdo = db();
$users = $pdo->query("SELECT id, name, email, role, department FROM users WHERE status = 1 ORDER BY department, role, name")->fetchAll(PDO::FETCH_ASSOC);

echo "Total active users: " . count($users) . "\n";
$byDept = [];
foreach ($users as $u) {
    $byDept[$u['department']][] = $u;
}
foreach ($byDept as $dept => $uList) {
    echo "Dept: $dept (" . count($uList) . " users)\n";
    foreach (array_slice($uList, 0, 2) as $u) {
        echo "  - [ID: {$u['id']}] {$u['name']} ({$u['role']}) email: {$u['email']}\n";
    }
}
