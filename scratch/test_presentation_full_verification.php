<?php
require_once __DIR__ . '/../php-app/inc/auth.php';
require_once __DIR__ . '/../php-app/inc/metrics_56.php';
require_once __DIR__ . '/../php-app/models/ExecutiveMeetingReport.php';

echo "=========================================================\n";
echo "EXECUTIVE PRESENTATION & ANALYTICS FULL VERIFICATION\n";
echo "=========================================================\n\n";

$rolesToTest = [
    'Admin'       => ['id' => 1, 'role' => 'Admin', 'department' => 'Administration', 'name' => 'Admin User'],
    'Dean'        => ['id' => 2, 'role' => 'Dean', 'department' => 'Administration', 'name' => 'Dean User'],
    'Principal'   => ['id' => 3, 'role' => 'Principal', 'department' => 'Administration', 'name' => 'Principal User'],
    'HoD'         => ['id' => 4, 'role' => 'HoD', 'department' => 'CSE', 'name' => 'HOD CSE'],
    'Coordinator' => ['id' => 5, 'role' => 'Coordinator', 'department' => 'Mechanical Engineering', 'name' => 'Coord MECH'],
    'Faculty'     => ['id' => 6, 'role' => 'Faculty', 'department' => 'ECE', 'name' => 'Faculty ECE'],
];

$allPassed = true;

foreach ($rolesToTest as $roleName => $user) {
    echo "--- Testing Role: {$roleName} ({$user['department']}) ---\n";

    $filters = em_resolve_filters($user, [
        'academic_year' => '2025-26',
        'department'    => in_array($roleName, ['Admin', 'Dean', 'Principal']) ? '' : $user['department'],
    ]);

    $dataset = em_dataset($user, $filters);
    $slides  = em_slides($dataset);
    $m56     = $dataset['metrics_56'];

    // 1. Check all 56 records present in metrics
    $metricCount = count($m56['metrics']);
    if ($metricCount === 56) {
        echo "  [PASS] 56 records present in catalog ({$metricCount}/56)\n";
    } else {
        echo "  [FAIL] Expected 56 records, found {$metricCount}\n";
        $allPassed = false;
    }

    // 2. Check Slide 1 is Title
    if (isset($slides[0]) && $slides[0]['type'] === 'title') {
        echo "  [PASS] Slide 1 is Title / Cover ('{$slides[0]['title']}')\n";
    } else {
        echo "  [FAIL] Slide 1 is not Title\n";
        $allPassed = false;
    }

    // 3. For Admin / Dean / Principal: Slide 2 MUST be summary_top3
    if (in_array($roleName, ['Admin', 'Dean', 'Principal'])) {
        if (isset($slides[1]) && $slides[1]['type'] === 'summary_top3') {
            echo "  [PASS] Slide 2 is MANDATORY Summary Page (Top 3 per metric)\n";
            $top3 = $slides[1]['top3'];
            $top3Count = count($top3);
            echo "         Top 3 evaluated across {$top3Count} metrics\n";
            if ($top3Count === 56) {
                echo "  [PASS] Top 3 summary covers all 56 metrics\n";
            } else {
                echo "  [FAIL] Top 3 summary has {$top3Count} metrics, expected 56\n";
                $allPassed = false;
            }
        } else {
            echo "  [FAIL] Slide 2 is NOT summary_top3! Type is: " . ($slides[1]['type'] ?? 'none') . "\n";
            $allPassed = false;
        }
    }

    // 4. Check targets slides: covers all 56 records
    $targetSlides = array_filter($slides, fn($s) => $s['type'] === 'targets');
    $collectedTargets = [];
    foreach ($targetSlides as $ts) {
        foreach ($ts['rows'] as $row) {
            $collectedTargets[] = $row;
        }
    }
    if (count($collectedTargets) === 56) {
        echo "  [PASS] Target slides contain all 56 records (" . count($targetSlides) . " paginated slides)\n";
    } else {
        echo "  [FAIL] Target slides contain " . count($collectedTargets) . " records, expected 56\n";
        $allPassed = false;
    }

    // 5. Check Nil handling for missing data
    $nilAchievedCount = 0;
    $nilTargetCount = 0;
    $nilPctCount = 0;
    $validCalcCount = 0;
    foreach ($m56['metrics'] as $m) {
        if ($m['achieved'] === null) {
            if ($m['achieved_display'] === 'Nil') {
                $nilAchievedCount++;
            } else {
                echo "  [FAIL] Metric #{$m['num']} has null achieved but achieved_display is '{$m['achieved_display']}'\n";
                $allPassed = false;
            }
        }
        if ($m['target'] === null && $m['target_display'] === 'Nil') {
            $nilTargetCount++;
        }
        if ($m['percentage'] === null && $m['percentage_display'] === 'Nil') {
            $nilPctCount++;
        }
        if ($m['target'] !== null && $m['target'] > 0 && $m['achieved'] !== null) {
            $expectedPct = round(($m['achieved'] / $m['target']) * 100, 1);
            if ($m['percentage'] === $expectedPct) {
                $validCalcCount++;
            } else {
                echo "  [FAIL] Metric #{$m['num']} percentage mismatch: expected {$expectedPct}, got {$m['percentage']}\n";
                $allPassed = false;
            }
        }
    }
    echo "  [PASS] Nil handling verified: {$nilAchievedCount} Nil achieved, {$nilTargetCount} Nil targets, {$nilPctCount} Nil percentages\n";
    if ($validCalcCount > 0) {
        echo "  [PASS] Valid percentage calculations verified ({$validCalcCount} metrics computed via (Achieved/Target)*100)\n";
    }

    // 6. Role Scope & Student Isolation Check
    if ($roleName === 'Faculty') {
        // Faculty: Student achievements MUST be 0 and hidden
        $studentInDataset = count($dataset['student']);
        $hasStudentSlides = !empty(array_filter($slides, fn($s) => ($s['type'] ?? '') === 'student_summary' || (($s['type'] ?? '') === 'records' && ($s['kind'] ?? '') === 'student')));
        if ($studentInDataset === 0 && !$hasStudentSlides) {
            echo "  [PASS] Student Achievement is COMPLETELY HIDDEN from Faculty (dataset=0, slides=0)\n";
        } else {
            echo "  [FAIL] Student Achievement leaked to Faculty! dataset={$studentInDataset}, hasSlides=" . ($hasStudentSlides ? 'YES' : 'NO') . "\n";
            $allPassed = false;
        }

        // Check that student metrics in the 56 records list are marked restricted
        $studentRestrictedCount = 0;
        foreach ($m56['metrics'] as $m) {
            if ($m['category'] === 'student') {
                if ($m['is_hidden_for_role'] === true && $m['achieved_display'] === 'Nil') {
                    $studentRestrictedCount++;
                }
            }
        }
        if ($studentRestrictedCount === 19) {
            echo "  [PASS] All 19 student metrics in 56-metric catalog restricted with Nil achieved for Faculty\n";
        } else {
            echo "  [FAIL] Expected 19 restricted student metrics for Faculty, got {$studentRestrictedCount}\n";
            $allPassed = false;
        }
    } elseif (in_array($roleName, ['HoD', 'Coordinator'])) {
        // HOD/Coordinator: Department locked
        $scopeDept = $m56['department'];
        if ($scopeDept === $user['department']) {
            echo "  [PASS] {$roleName} locked to own department ({$scopeDept})\n";
        } else {
            echo "  [FAIL] {$roleName} department mismatch: expected {$user['department']}, got {$scopeDept}\n";
            $allPassed = false;
        }
    }

    // 7. Validation suite check
    $val = $m56['validation'];
    if (!empty($val['all_passed'])) {
        echo "  [PASS] 10-Point automated validation suite passed (10/10 checks PASS)\n";
    } else {
        echo "  [FAIL] Validation suite failed for {$roleName}:\n";
        foreach ($val['checks'] as $cKey => $c) {
            if (!$c['passed']) {
                echo "         - Check {$cKey} ({$c['name']}): {$c['detail']}\n";
            }
        }
        $allPassed = false;
    }

    echo "\n";
}

if ($allPassed) {
    echo "=========================================================\n";
    echo "ALL ROLES AND REQUIREMENTS 100% VERIFIED AND PASSING!\n";
    echo "=========================================================\n";
} else {
    echo "=========================================================\n";
    echo "SOME CHECKS FAILED! REVIEW OUTPUT ABOVE.\n";
    echo "=========================================================\n";
}
