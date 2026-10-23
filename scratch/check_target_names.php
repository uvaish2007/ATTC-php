<?php
require_once __DIR__ . '/../php-app/inc/config.php';
require_once __DIR__ . '/../php-app/inc/db.php';
$pdo = db();

$stmt = $pdo->query("SELECT DISTINCT metric FROM `targets` WHERE metric LIKE '%STATE%' OR metric LIKE '%INTER%' OR metric LIKE '%AWARD%' OR metric LIKE '%PRIZE%'");
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "Metric: {$r['metric']}" . PHP_EOL;
}
