<?php
require_once __DIR__ . '/../php-app/inc/config.php';
require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/models/Target.php';
$t1 = target_find(30001);
echo "Target 30001 stored achieved_value: " . $t1['achieved_value'] . "\n";
echo "target_record_count: " . target_record_count($t1) . "\n";
