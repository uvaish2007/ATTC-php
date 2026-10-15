<?php
require_once __DIR__ . '/../php-app/inc/config.php';
require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/models/Target.php';

$ts = targets_all(null, '2025-26');
echo "2025-26 targets: " . count($ts) . "\n";
$ach = 0; $prog = 0; $not = 0;
foreach ($ts as $t) {
    $tv = (int) $t['target_value'];
    $av = (int) $t['achieved_value'];
    if ($tv > 0 && $av >= $tv) $ach++;
    elseif (!empty($t['target_deadline']) && $t['target_deadline'] < date('Y-m-d') && $av < $tv) $not++;
    else $prog++;
}
echo "Achieved: $ach, In Progress: $prog, Not Completed: $not\n";
