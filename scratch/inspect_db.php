<?php
require_once __DIR__ . '/../php-app/inc/db.php';
$stmt = db()->query("SHOW TABLES");
print_r($stmt->fetchAll(PDO::FETCH_COLUMN));
