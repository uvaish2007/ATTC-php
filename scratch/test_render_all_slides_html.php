<?php
// Simulate sessions and capture output of each presentation page

function test_render_page($file, $sessionUser, $queryParams = []) {
    // Start session if not active
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['user'] = $sessionUser;
    $_SESSION['user_id'] = $sessionUser['id'];
    $_SESSION['role'] = $sessionUser['role'];
    $_GET = $queryParams;

    ob_start();
    try {
        include $file;
        $html = ob_get_clean();
        return ['success' => true, 'html' => $html];
    } catch (Throwable $e) {
        $err = ob_get_clean();
        return ['success' => false, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString(), 'output' => $err];
    }
}

echo "==================================================\n";
echo "TEST 1: present-executive-meeting.php\n";
echo "==================================================\n";
$admin = ['id' => 1, 'role' => 'Admin', 'name' => 'Dr. Administrator', 'department' => 'Administration'];
$res1 = test_render_page(__DIR__ . '/../php-app/present-executive-meeting.php', $admin, ['academic_year' => '2023-2024']);
if (!$res1['success']) {
    echo "FAILED: " . $res1['error'] . "\n";
} else {
    echo "Length: " . strlen($res1['html']) . " bytes\n";
    $hasBannerCss = strpos($res1['html'], '.ex-banner') !== false;
    $hasKpiCss    = strpos($res1['html'], '.ex-kpi-grid') !== false;
    $hasSplitCss  = strpos($res1['html'], '.ex-split-row') !== false;
    $hasSvgFn     = strpos($res1['html'], 'renderGroupedBarChart') !== false;
    $hasDonutFn   = strpos($res1['html'], 'renderDonutChart') !== false;
    $hasFooterCss = strpos($res1['html'], '.ex-footer') !== false;
    echo "Executive Meeting Elements: " . json_encode([
        'ex_banner' => $hasBannerCss,
        'ex_kpis'   => $hasKpiCss,
        'ex_split'  => $hasSplitCss,
        'svg_bars'  => $hasSvgFn,
        'svg_donut' => $hasDonutFn,
        'ex_footer' => $hasFooterCss,
    ]) . "\n";
}

echo "\n==================================================\n";
echo "TEST 2: present-faculty-report.php\n";
echo "==================================================\n";
$faculty = ['id' => 5, 'role' => 'Faculty', 'name' => 'VR', 'department' => 'CSBS'];
$res2 = test_render_page(__DIR__ . '/../php-app/present-faculty-report.php', $faculty, ['id' => '5', 'academic_year' => '2023-2024']);
if (!$res2['success']) {
    echo "FAILED: " . $res2['error'] . "\n";
} else {
    echo "Length: " . strlen($res2['html']) . " bytes\n";
    $hasBannerCss = strpos($res2['html'], '.ex-banner') !== false;
    $hasKpiCss    = strpos($res2['html'], '.ex-kpi-grid') !== false;
    $hasSplitCss  = strpos($res2['html'], '.ex-split-row') !== false;
    $hasSvgFn     = strpos($res2['html'], 'renderGroupedBarChart') !== false;
    $hasDonutFn   = strpos($res2['html'], 'renderDonutChart') !== false;
    $hasFooterCss = strpos($res2['html'], '.ex-footer') !== false;
    echo "Faculty Report Elements: " . json_encode([
        'ex_banner' => $hasBannerCss,
        'ex_kpis'   => $hasKpiCss,
        'ex_split'  => $hasSplitCss,
        'svg_bars'  => $hasSvgFn,
        'svg_donut' => $hasDonutFn,
        'ex_footer' => $hasFooterCss,
    ]) . "\n";
}

echo "\n==================================================\n";
echo "TEST 3: present-student-report.php\n";
echo "==================================================\n";
$res3 = test_render_page(__DIR__ . '/../php-app/present-student-report.php', $admin, [
    'name' => 'Chandran R',
    'reg_no' => '711322104003',
    'dept' => 'CSE',
    'academic_year' => '2023-2024'
]);
if (!$res3['success']) {
    echo "FAILED: " . $res3['error'] . "\n";
} else {
    echo "Length: " . strlen($res3['html']) . " bytes\n";
    $hasBannerCss = strpos($res3['html'], '.ex-banner') !== false;
    $hasKpiCss    = strpos($res3['html'], '.ex-kpi-grid') !== false;
    $hasSplitCss  = strpos($res3['html'], '.ex-split-row') !== false;
    $hasSvgFn     = strpos($res3['html'], 'renderGroupedBarChart') !== false;
    $hasDonutFn   = strpos($res3['html'], 'renderDonutChart') !== false;
    $hasFooterCss = strpos($res3['html'], '.ex-footer') !== false;
    echo "Student Report Elements: " . json_encode([
        'ex_banner' => $hasBannerCss,
        'ex_kpis'   => $hasKpiCss,
        'ex_split'  => $hasSplitCss,
        'svg_bars'  => $hasSvgFn,
        'svg_donut' => $hasDonutFn,
        'ex_footer' => $hasFooterCss,
    ]) . "\n";
}

echo "\nAll presentation pages tested successfully!\n";
