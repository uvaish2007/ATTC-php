<?php
require_once __DIR__ . '/../php-app/inc/config.php';
require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/models/Setting.php';
require_once __DIR__ . '/../php-app/models/Target.php';
require_once __DIR__ . '/../php-app/models/Record.php';
require_once __DIR__ . '/../php-app/models/Department.php';

$activeYear = active_academic_year();
echo "Testing target presentation data for Active Year: $activeYear\n";

sync_all_target_achieved();

$targets = targets_all(null, $activeYear);
echo "Fetched " . count($targets) . " targets.\n";

$totalTargets = count($targets);
$achievedCount = 0;
$inProgressCount = 0;
$notCompletedCount = 0;
$totalFixed = 0;
$totalAchieved = 0;
$deanPendingCount = 0;

$today = date('Y-m-d');

foreach ($targets as $t) {
    $tv = (int) ($t['target_value'] ?? 0);
    $av = (int) ($t['achieved_value'] ?? 0);
    $dl = !empty($t['target_deadline']) ? $t['target_deadline'] : null;
    $st = $t['status'] ?? 'Draft';

    $totalFixed += $tv;
    $totalAchieved += $av;

    if ($st === 'Dean Pending' || $st === 'Pending Review') {
        $deanPendingCount++;
    }

    if ($tv > 0 && $av >= $tv) {
        $achievedCount++;
    } elseif ($dl !== null && $dl < $today && $av < $tv) {
        $notCompletedCount++;
    } else {
        $inProgressCount++;
    }
}

echo "Summary: Total Targets: $totalTargets | Achieved: $achievedCount | In Progress: $inProgressCount | Not Completed: $notCompletedCount\n";
echo "Values: Fixed: $totalFixed | Achieved: $totalAchieved | Dean Pending: $deanPendingCount\n";

// Department breakdown
$byDept = [];
foreach ($targets as $t) {
    $dept = $t['department'] ?: 'Unassigned';
    if (!isset($byDept[$dept])) {
        $byDept[$dept] = [
            'name' => $dept,
            'targets_count' => 0,
            'fixed' => 0,
            'achieved' => 0,
            'achieved_count' => 0,
            'in_progress_count' => 0,
            'not_completed_count' => 0,
        ];
    }
    $byDept[$dept]['targets_count']++;
    $tv = (int) $t['target_value'];
    $av = (int) $t['achieved_value'];
    $dl = !empty($t['target_deadline']) ? $t['target_deadline'] : null;
    $byDept[$dept]['fixed'] += $tv;
    $byDept[$dept]['achieved'] += $av;
    if ($tv > 0 && $av >= $tv) {
        $byDept[$dept]['achieved_count']++;
    } elseif ($dl !== null && $dl < $today && $av < $tv) {
        $byDept[$dept]['not_completed_count']++;
    } else {
        $byDept[$dept]['in_progress_count']++;
    }
}

foreach ($byDept as &$d) {
    $d['attainment_pct'] = $d['fixed'] > 0 ? min(100, round(($d['achieved'] / $d['fixed']) * 100, 1)) : 0;
}
unset($d);

print_r($byDept);

echo "\n--- Approved Records for Target 30001 (Journal Publications) ---\n";
$t1 = target_find(30001);
$recs = target_approved_records($t1);
echo "Count: " . count($recs) . "\n";
if (count($recs) > 0) {
    echo "First record:\n";
    print_r(array_intersect_key($recs[0], array_flip(['id', 'paper_title', 'faculty_name', 'department', 'status', 'created_at'])));
}

