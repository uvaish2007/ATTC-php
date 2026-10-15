<?php
require_once __DIR__ . '/../php-app/inc/config.php';
require_once __DIR__ . '/../php-app/inc/db.php';

$hods = db()->query("SELECT id, name, email, role, department FROM users WHERE role = 'HoD'")->fetchAll(PDO::FETCH_ASSOC);
echo "HoD accounts count: " . count($hods) . "\n";
foreach ($hods as $h) {
    echo "ID: {$h['id']} | Name: {$h['name']} | Email: {$h['email']} | Dept: {$h['department']}\n";
}
