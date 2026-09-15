<?php
require_once __DIR__ . '/../php-app/inc/config.php';
require_once __DIR__ . '/../php-app/inc/auth.php';

auth_boot();
$_SESSION['user'] = [
    'id' => 1,
    'name' => 'Local Test User',
    'email' => 'admin@atts.edu',
    'role' => 'Admin',
    'department' => null,
];
$_SESSION['admin_academic_year_selected'] = true;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/academic-years.php';
$_GET['year'] = '2026-27';

ob_start();
try {
    include __DIR__ . '/../php-app/academic-years.php';
    $html = ob_get_clean();
} catch (Throwable $e) {
    $html = ob_get_clean();
    echo "ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString();
    exit(1);
}

echo "=== VERIFYING ACADEMIC YEARS UI RESTRUCTURING ===\n";
echo "Rendered HTML length: " . strlen($html) . " bytes\n";

$checks = [
    'Toggle Registry Button in Header'     => strpos($html, 'id="btnToggleRegistry"') !== false,
    'View Academic Years Registry Text'    => strpos($html, 'View Academic Years Registry') !== false,
    'Tabs Navigation Bar'                  => strpos($html, 'class="ay-tabs-nav"') !== false,
    'Tab 1: Overview Tab Container'        => strpos($html, 'id="ay_tab_overview"') !== false,
    'Tab 2: Registry Tab Container'        => strpos($html, 'id="ay_tab_registry"') !== false,
    'Registry Tab Hidden by Default'       => strpos($html, 'id="ay_tab_registry" style="display:none"') !== false,
    'Live Search Input in Registry'        => strpos($html, 'id="ay_registry_search"') !== false,
    'Tab Switch JS Function'               => strpos($html, 'function switchAyTab(') !== false,
    'Client Filter JS Function'            => strpos($html, 'function filterRegistryTable(') !== false,
    'Spacious Form Grid (2-row structure)' => strpos($html, 'class="ay-form-grid"') !== false,
    'Form Bottom Container'                => strpos($html, 'class="ay-form-bottom"') !== false,
    'Hero Stat Grid'                       => strpos($html, 'class="ay-stat-grid"') !== false,
    'Scope Card'                           => strpos($html, 'ay-card-scope') !== false,
    'Lock Status Card'                     => strpos($html, 'ay-card-lock') !== false,
    'Calendar Card'                        => strpos($html, 'ay-card-cal') !== false,
    'Switch Operating Year Dialog'         => strpos($html, 'id="switchYearDlg"') !== false,
    'Unlock Cycle Dialog'                  => strpos($html, 'id="unlockCycleDlg"') !== false,
    'Direct Lock Dialog'                   => strpos($html, 'id="directLockDlg"') !== false,
    'Discovery Banner at Bottom'           => strpos($html, 'View Full Academic Years Registry') !== false,
];

$allPassed = true;
foreach ($checks as $name => $passed) {
    echo ($passed ? "  [PASS] " : "  [FAIL] ") . $name . "\n";
    if (!$passed) $allPassed = false;
}

if ($allPassed) {
    echo "\nALL CHECKS PASSED SUCCESSFULLY!\n";
} else {
    echo "\nSOME CHECKS FAILED!\n";
    exit(1);
}
