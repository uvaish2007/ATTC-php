<?php
require_once __DIR__ . '/../php-app/inc/metrics_56.php';

$activeYear = '2025-26';

echo "=== 1. Testing Admin View (All Departments) ===\n";
$adminUser = ['id' => 1, 'role' => 'Admin', 'department' => ''];
$adminData = metrics_56_get_data($adminUser, null, $activeYear);
echo "Total metrics: {$adminData['total_metrics']}\n";
echo "Top 3 Summary count: " . count($adminData['top3_summary']) . "\n";
echo "Validation all passed: " . ($adminData['validation']['all_passed'] ? 'YES' : 'NO') . "\n";
foreach ($adminData['validation']['checks'] as $k => $c) {
    echo "  - {$c['name']}: " . ($c['passed'] ? 'PASS' : 'FAIL') . " ({$c['detail']})\n";
}

// Sample Top 3
echo "\nSample Top 3 metrics:\n";
$sampleKeys = ['journal', 'inst_pass_percentage', 'nptel', 'patent'];
foreach ($sampleKeys as $k) {
    $t3 = $adminData['top3_summary'][$k] ?? null;
    if ($t3) {
        echo "Metric: {$t3['metric_title']} ({$k})\n";
        echo "  Rank 1: " . ($t3['rank1'] ? "{$t3['rank1']['code']} (Achieved: {$t3['rank1']['achieved']}, Target: " . ($t3['rank1']['target'] ?? 'Nil') . ", Pct: " . ($t3['rank1']['percentage'] ?? 'Nil') . "%)" : "Nil") . "\n";
        echo "  Rank 2: " . ($t3['rank2'] ? "{$t3['rank2']['code']} (Achieved: {$t3['rank2']['achieved']}, Target: " . ($t3['rank2']['target'] ?? 'Nil') . ", Pct: " . ($t3['rank2']['percentage'] ?? 'Nil') . "%)" : "Nil") . "\n";
        echo "  Rank 3: " . ($t3['rank3'] ? "{$t3['rank3']['code']} (Achieved: {$t3['rank3']['achieved']}, Target: " . ($t3['rank3']['target'] ?? 'Nil') . ", Pct: " . ($t3['rank3']['percentage'] ?? 'Nil') . "%)" : "Nil") . "\n";
    }
}

echo "\n=== 2. Testing HOD View (CSBS) ===\n";
$hodUser = ['id' => 3, 'role' => 'HoD', 'department' => 'CSBS'];
// Even if HOD passes requestedDepartment = 'CSE', it must be forced to CSBS!
$hodData = metrics_56_get_data($hodUser, 'CSE', $activeYear);
echo "Resolved department: {$hodData['department']} (Expected: CSBS)\n";
echo "Total metrics: {$hodData['total_metrics']}\n";
echo "Validation all passed: " . ($hodData['validation']['all_passed'] ? 'YES' : 'NO') . "\n";

echo "\n=== 3. Testing Coordinator View (CSE) ===\n";
$coordUser = ['id' => 60013, 'role' => 'Coordinator', 'department' => 'CSE'];
$coordData = metrics_56_get_data($coordUser, null, $activeYear);
echo "Resolved department: {$coordData['department']} (Expected: CSE)\n";
echo "Total metrics: {$coordData['total_metrics']}\n";
echo "Validation all passed: " . ($coordData['validation']['all_passed'] ? 'YES' : 'NO') . "\n";

echo "\n=== 4. Testing Faculty View (Faculty 2 - CSBS, ID 60007) ===\n";
$facultyUser = ['id' => 60007, 'role' => 'Faculty', 'department' => 'CSBS'];
$facultyData = metrics_56_get_data($facultyUser, 'CSE', $activeYear);
echo "Resolved scope: {$facultyData['scope']}\n";
echo "Total metrics: {$facultyData['total_metrics']}\n";
echo "Validation all passed: " . ($facultyData['validation']['all_passed'] ? 'YES' : 'NO') . "\n";

$studentLeaked = false;
foreach ($facultyData['metrics'] as $m) {
    if ($m['category'] === 'student' && $m['achieved'] !== null) {
        $studentLeaked = true;
    }
}
echo "Did student achievement leak to Faculty? " . ($studentLeaked ? 'YES (BUG!)' : 'NO (SECURE!)') . "\n";
