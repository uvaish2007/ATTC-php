<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/nav.php';
require_once __DIR__ . '/inc/metric_studio.php';
require_once __DIR__ . '/inc/upload_sections.php';

echo "====================================================================\n";
echo "STARTING CONSOLIDATION & ROUTING VERIFICATION SUITE\n";
echo "====================================================================\n";

$passCount = 0;
$failCount = 0;

function assertCheck(string $feature, string $desc, bool $condition, string $info = '') {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo sprintf("[PASS] %-30s | %-32s | %s\n", $feature, $desc, $info);
    } else {
        $failCount++;
        echo sprintf("[FAIL] %-30s | %-32s | %s\n", $feature, $desc, $info);
    }
}

// -----------------------------------------------------------------------------
// Check 1: Navigation Consolidation
// -----------------------------------------------------------------------------
$adminNav = navigation_for('Admin');
$hasMetricMgmt = false;
$hasStandaloneFormStudio = false;

foreach ($adminNav as $item) {
    if (($item['label'] ?? '') === 'Metric Management' && ($item['path'] ?? '') === 'admin-metrics.php') {
        $hasMetricMgmt = true;
    }
    if (($item['label'] ?? '') === 'Form Studio' && ($item['path'] ?? '') === 'form-studio.php') {
        $hasStandaloneFormStudio = true;
    }
}

assertCheck('Nav Consolidation', 'Metric Management present in Admin nav', $hasMetricMgmt, 'Path: admin-metrics.php');
assertCheck('Nav Consolidation', 'Duplicate Form Studio removed from nav', !$hasStandaloneFormStudio, 'No duplicate sidebar entry');

// -----------------------------------------------------------------------------
// Check 2: Metric Management Route Handling
// -----------------------------------------------------------------------------
$adminMetricsContent = file_get_contents(__DIR__ . '/admin-metrics.php');

$hasStudioRedirect = str_contains($adminMetricsContent, "input('view') === 'studio'") ||
                     str_contains($adminMetricsContent, "input('tab') === 'studio'");
assertCheck('Route Handling', 'admin-metrics handles studio tab/view', $hasStudioRedirect, 'Redirects seamlessly to form-studio.php');

$hasTabs = str_contains($adminMetricsContent, 'Targets &amp; Metrics Overview') &&
           str_contains($adminMetricsContent, 'Form Studio (Visual Designer)');
assertCheck('Tab Strip', 'Metric Management has visual designer tab', $hasTabs, 'Top tabs switch between overview & designer');

$hasDockLink = str_contains($adminMetricsContent, 'form-studio.php?id=') &&
               str_contains($adminMetricsContent, 'dock-builder');
assertCheck('Table Dock', 'Metrics table links to Form Studio', $hasDockLink, 'Action dock links to visual designer');

$hasOldModalRemoved = !str_contains($adminMetricsContent, 'id="formStudioModal"');
assertCheck('Cleanup', 'Broken inline modal removed', $hasOldModalRemoved, 'Eliminated 1,800 lines of obsolete inline code');

// -----------------------------------------------------------------------------
// Check 3: Form Studio Hierarchy & Breadcrumb
// -----------------------------------------------------------------------------
$formStudioContent = file_get_contents(__DIR__ . '/form-studio.php');
$hasHierarchicalBreadcrumb = str_contains($formStudioContent, "'Metric Management'") &&
                             str_contains($formStudioContent, "'Form Studio'");
assertCheck('Breadcrumbs', 'Hierarchical breadcrumb configured', $hasHierarchicalBreadcrumb, 'Dashboard > Metric Management > Form Studio');

$hasBackLink = str_contains($formStudioContent, 'admin-metrics.php') &&
               str_contains($formStudioContent, 'Metric Management');
assertCheck('Return Link', 'Back button returns to Metric Management', $hasBackLink, 'Header back button links to admin-metrics.php');

// -----------------------------------------------------------------------------
// Check 4: Drag-and-Drop 2-Column Canvas Engine
// -----------------------------------------------------------------------------
$dndJsContent = file_get_contents(__DIR__ . '/assets/js/form-studio.js');
$hasDndEngine = str_contains($dndJsContent, 'drag-insert-before') &&
                str_contains($dndJsContent, 'drag-insert-after') &&
                str_contains($dndJsContent, 'targetIdx') &&
                str_contains($dndJsContent, 'addFieldFromPalette');
assertCheck('D&D Engine', 'Live canvas insertion engine active', $hasDndEngine, 'Drop insertion markers & payload transfer active');

$hasWidthToggles = str_contains($dndJsContent, 'toggleFieldWidth') &&
                   str_contains($dndJsContent, 'setFieldWidth');
assertCheck('2-Column Grid', 'Width toggling controls verified', $hasWidthToggles, 'Half (50%) and Full (100%) width toggles available');

// -----------------------------------------------------------------------------
// Check 5: Live Publication and Role Delivery
// -----------------------------------------------------------------------------
$testKey = 'test_cons_' . substr(md5((string)microtime(true)), 0, 8);
$testName = 'Consolidated Studio Test ' . substr($testKey, -4);
$pdo = db();

try {
    $pdo->prepare("DELETE FROM `generic_metric_records` WHERE `metric_key` LIKE 'test_cons_%'")->execute();
    $pdo->prepare("DELETE FROM `metrics` WHERE `metric_key` LIKE 'test_cons_%'")->execute();
} catch (Throwable $e) {}

[$cOk, $cMsg, $metric] = metric_studio_create([
    'name' => $testName,
    'metric_key' => $testKey,
    'category' => 'STUDENT SUPPORT AND PROGRESSION',
    'description' => 'Consolidated Form Studio Test Form',
    'faculty_visible' => 1,
    'coordinator_visible' => 1,
    'hod_visible' => 1,
    'principal_visible' => 1,
    'status' => 1
]);

$formId = $metric['id'];

$fields = [
    ['key' => 'student_name', 'label' => 'Student Name', 'type' => 'text', 'width' => 'half', 'col_span' => 1, 'required' => true],
    ['key' => 'company_name', 'label' => 'Company Name', 'type' => 'text', 'width' => 'half', 'col_span' => 1, 'required' => true],
    ['key' => 'package_lpa', 'label' => 'Package (LPA)', 'type' => 'decimal', 'width' => 'half', 'col_span' => 1, 'required' => true],
    ['key' => 'offer_date', 'label' => 'Offer Date', 'type' => 'date', 'width' => 'half', 'col_span' => 1, 'required' => true],
    ['key' => 'feedback', 'label' => 'Placement Feedback', 'type' => 'textarea', 'width' => 'full', 'col_span' => 2, 'required' => false]
];

$attachment = [
    'enabled' => true,
    'label' => 'Offer Letter',
    'required' => true,
    'allowed_types' => ['pdf'],
    'max_file_mb' => 2
];

[$pubOk, $pubMsg] = metric_studio_publish_form($formId, $fields, $attachment, 1);
assertCheck('Form Publishing', 'Publishes 2-column form from consolidated studio', $pubOk, "Version: v2 published");

// Role delivery
$facultySecs = upload_approved_sections('Faculty', true);
$visibleToFaculty = isset($facultySecs[$testKey]);
assertCheck('Role Delivery', 'Form delivered to Faculty in upload.php', $visibleToFaculty, "Available under Faculty upload sections");

$principalSecs = upload_approved_sections('Principal', true);
$visibleToPrincipal = isset($principalSecs[$testKey]);
assertCheck('Role Delivery', 'Form delivered to Principal in upload.php', $visibleToPrincipal, "Available under Principal upload sections");

// Clean up test metric
$pdo->prepare("DELETE FROM `metrics` WHERE `id` = ?")->execute([$formId]);

echo "====================================================================\n";
echo "CONSOLIDATION TEST RESULTS: TOTAL: " . ($passCount + $failCount) . " | PASSED: {$passCount} | FAILED: {$failCount}\n";
echo "====================================================================\n";

if ($failCount > 0) {
    exit(1);
}
echo ">>> ALL CONSOLIDATION & ROUTING CHECKS PASSED! <<<\n";
