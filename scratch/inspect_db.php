<?php
require_once __DIR__ . '/../php-app/inc/db.php';
$pdo = db();
$stmt = $pdo->query("SELECT DISTINCT metric FROM targets");
print_r($stmt->fetchAll(PDO::FETCH_COLUMN));
