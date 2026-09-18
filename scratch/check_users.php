<?php
require_once __DIR__ . '/../php-app/inc/db.php';
$stmt = db()->query("SELECT email, role, department, password FROM users");
foreach ($stmt->fetchAll() as $r) {
    echo $r['role'] . ': ' . $r['email'] . ' (' . $r['department'] . ') | hash: ' . substr($r['password'], 0, 15) . "...\n";
}
