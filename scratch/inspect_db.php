<?php
require_once __DIR__ . '/../php-app/inc/db.php';
$stmt = db()->query("SELECT id, name, email, role FROM users");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
