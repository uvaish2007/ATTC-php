<?php
require_once __DIR__ . '/../php-app/inc/config.php';
require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/models/Target.php';

$activeYear = active_academic_year();
$targets = targets_all(null, $activeYear);

echo "Targets in $activeYear:\n";
foreach ($targets as $t) {
    $tVal = (int) $t['target_value'];
    $aVal = (int) $t['achieved_value'];
    $dl = $t['target_deadline'] ?: 'No deadline';
    $st = $t['status'];
    
    $today = date('Y-m-d');
    $classification = 'Pending / In Progress';
    if ($tVal > 0 && $aVal >= $tVal) {
        $classification = 'Achieved';
    } elseif (!empty($t['target_deadline']) && $t['target_deadline'] < $today && $aVal < $tVal) {
        $classification = 'Not Completed';
    }
    
    echo sprintf("ID: %d | Dept: %-6s | Metric: %-25s | Target: %4d | Achieved: %4d | DL: %-10s | Status: %-12s => Classification: %s\n",
        $t['id'], $t['department'], substr($t['metric'], 0, 25), $tVal, $aVal, $dl, $st, $classification);
}
