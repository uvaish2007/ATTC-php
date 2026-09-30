<?php
require_once __DIR__ . '/../php-app/inc/env.php';
require_once __DIR__ . '/../php-app/inc/db.php';

$pdo = db();
$res = $pdo->query("SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'atts_main' AND COLUMN_NAME = 'academic_year'")->fetchAll(PDO::FETCH_COLUMN);
echo "Tables with academic_year:\n";
print_r($res);
