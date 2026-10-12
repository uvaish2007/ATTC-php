<?php
require_once __DIR__ . '/../php-app/inc/env.php';
require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/inc/helpers.php';
require_once __DIR__ . '/../php-app/inc/record_specs.php';
require_once __DIR__ . '/../php-app/models/Record.php';

$pdo = db();
$row = $pdo->query("SELECT * FROM placements WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
echo "Raw row:\n";
print_r($row);
$attrs = record_display_attributes('placement', $row);
echo "\nDisplay attributes:\n";
print_r($attrs);
