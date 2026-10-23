<?php
require_once __DIR__ . '/../php-app/inc/db.php';
$pdo = db();
$stmt = $pdo->query("SELECT DISTINCT metric FROM targets ORDER BY metric");
while ($row = $stmt->fetch(PDO::FETCH_COLUMN)) {
    echo $row . "\n";
}
