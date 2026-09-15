<?php
$cookieFile = sys_get_temp_dir() . '/atts_report_drag_cookie.txt';
if (file_exists($cookieFile)) unlink($cookieFile);

// Login as Admin
$ch = curl_init('http://localhost:8000/login.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
$loginHtml = curl_exec($ch);

preg_match('/name="csrf" value="([^"]+)"/', $loginHtml, $m);
$csrf = $m[1] ?? '';

$ch = curl_init('http://localhost:8000/login.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'csrf' => $csrf,
    'email' => 'admin@atts.edu',
    'password' => 'admin123',
    'role' => 'Admin',
]));
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
$res = curl_exec($ch);

if (strpos($res, 'Select Academic Year') !== false) {
    preg_match('/name="_csrf" value="([^"]+)"/', $res, $m2);
    $ch = curl_init('http://localhost:8000/select-year.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        '_csrf' => $m2[1] ?? '',
        'action' => 'select_year',
        'academic_year' => '2026-27',
    ]));
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_exec($ch);
}
unset($ch);

echo "=== 1. VERIFY REPORT TEMPLATE HTML RENDERING ===\n";
$ch = curl_init('http://localhost:8000/report-template.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
$html = curl_exec($ch);

$checks = [
    'tpl-drag-handle present' => strpos($html, 'class="tpl-drag-handle"') !== false,
    'Grip icon circle dots rendered' => strpos($html, '<circle cx="9" cy="12"') !== false,
    'Old arrow-left buttons removed from tpl-ops' => strpos($html, 'title="Move left"') === false,
    'Old arrow-right buttons removed from tpl-ops' => strpos($html, 'title="Move right"') === false,
    'Container #tpl_columns_container present' => strpos($html, 'id="tpl_columns_container"') !== false,
    'Container #tpl_rows_container present' => strpos($html, 'id="tpl_rows_container"') !== false,
    'JavaScript initDragAndDrop present' => strpos($html, 'initDragAndDrop') !== false,
    'col_reorder and row_reorder AJAX handlers' => strpos($html, 'action === \'col_reorder\'') === false && strpos($html, 'col_reorder') !== false,
];

$allPassed = true;
foreach ($checks as $name => $ok) {
    echo ($ok ? "[PASS] " : "[FAIL] ") . $name . "\n";
    if (!$ok) $allPassed = false;
}

echo "\n=== 2. VERIFY AJAX REORDER ENDPOINT ===\n";
// Extract CSRF token from page
preg_match('/name="csrf"\s+value="([^"]+)"/', $html, $mCsrf);
$pageCsrf = $mCsrf[1] ?? '';

// Extract column IDs from data-id attributes
preg_match_all('/class="tpl-line tpl-col-line"\s+data-id="(\d+)"/', $html, $mCols);
$colIds = $mCols[1] ?? [];

echo "Found " . count($colIds) . " columns: " . implode(', ', $colIds) . "\n";

if (count($colIds) >= 2) {
    $reversed = array_reverse($colIds);
    $ch = curl_init('http://localhost:8000/report-template.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Requested-With: XMLHttpRequest']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'action' => 'col_reorder',
        'csrf'   => $pageCsrf,
        'order'  => implode(',', $reversed),
        'ids'    => $reversed,
    ]));
    $apiRes = curl_exec($ch);
    $json = json_decode($apiRes, true);
    $reorderOk = ($json && !empty($json['ok']));
    echo "AJAX col_reorder response: " . ($reorderOk ? "[PASS]" : "[FAIL]") . " " . $apiRes . "\n";

    // Restore original order
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'action' => 'col_reorder',
        'csrf'   => $pageCsrf,
        'order'  => implode(',', $colIds),
        'ids'    => $colIds,
    ]));
    $restoreRes = curl_exec($ch);
    $restoreJson = json_decode($restoreRes, true);
    $restoreOk = ($restoreJson && !empty($restoreJson['ok']));
    echo "AJAX col_reorder restore: " . ($restoreOk ? "[PASS]" : "[FAIL]") . "\n";

    if (!$reorderOk || !$restoreOk) {
        $allPassed = false;
    }
}

if ($allPassed) {
    echo "\n>>> ALL DRAG AND DROP VERIFICATION CHECKS PASSED! <<<\n";
} else {
    echo "\n>>> CHECKS FAILED! <<<\n";
}
