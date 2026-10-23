<?php
require_once __DIR__ . '/../php-app/inc/helpers.php';
require_once __DIR__ . '/../php-app/inc/auth.php';

$pdo = db();
$rows = $pdo->query("SELECT id, metric_key, name, attachment_config, proof_required FROM `metrics` WHERE metric_key = 'value_added' OR name LIKE '%Value Added%'")->fetchAll(PDO::FETCH_ASSOC);
print_r($rows);
