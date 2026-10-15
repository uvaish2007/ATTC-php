<?php
/**
 * Test Metric Delete and Faculty Sync
 */

declare(strict_types=1);

require_once __DIR__ . '/test_http_endpoints.php';
require_once __DIR__ . '/../php-app/inc/config.php';
require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/inc/metric_studio.php';
require_once __DIR__ . '/../php-app/inc/upload_sections.php';

echo "=== STARTING METRIC DELETE & FACULTY SYNC TEST ===\n\n";

$adminClient = new TestHttpClient();
$facClient = new TestHttpClient();

$adminLogin = login($adminClient, 'admin@atts.edu', 'admin123', 'Admin');
$facLogin = login($facClient, 'faculty@atts.edu', 'faculty123', 'Faculty');

echo "Admin logged in: " . ($adminLogin ? 'YES' : 'NO') . "\n";
echo "Faculty logged in: " . ($facLogin ? 'YES' : 'NO') . "\n\n";

// 1. Verify deleteModal and Delete buttons exist on admin-metrics.php
$adminPage = $adminClient->get('http://localhost:8000/php-app/admin-metrics.php');
$hasDeleteModal = str_contains($adminPage['body'], 'id="deleteModal"');
$hasDeleteBtn = str_contains($adminPage['body'], 'openDeleteModal(');
$hasDeleteFromEdit = str_contains($adminPage['body'], 'openDeleteFromEdit()');
$hasDeleteFromBuilder = str_contains($adminPage['body'], 'openDeleteFromBuilder()');

echo ($hasDeleteModal ? " [PASS] " : " [FAIL] ") . "deleteModal dialog present in admin-metrics.php\n";
echo ($hasDeleteBtn ? " [PASS] " : " [FAIL] ") . "Delete Metric button present in table actions\n";
echo ($hasDeleteFromEdit ? " [PASS] " : " [FAIL] ") . "Delete Metric button present in Edit Metric modal\n";
echo ($hasDeleteFromBuilder ? " [PASS] " : " [FAIL] ") . "Delete Metric button present in Form Template Builder\n";

// 2. Check current metric 31 (new_one) in DB
$newOne = metric_studio_find('new_one');
if ($newOne) {
    echo "Found user's metric 'new_one' (ID: {$newOne['id']}, status: {$newOne['status']})\n";

    // Test Deleting new_one
    // Fetch CSRF token for POST
    preg_match('/name="csrf"\s+value="([^"]+)"/i', $adminPage['body'], $m);
    $csrf = $m[1] ?? '';

    $delPost = $adminClient->post('http://localhost:8000/php-app/admin-metrics.php', [
        'csrf'   => $csrf,
        'action' => 'delete',
        'id'     => $newOne['id'],
    ]);

    echo ($delPost['code'] === 302 ? " [PASS] " : " [FAIL] ") . "POST action=delete successfully processed (HTTP {$delPost['code']})\n";

    // Verify it is gone from DB
    $newOneAfter = metric_studio_find('new_one');
    echo ($newOneAfter === null ? " [PASS] " : " [FAIL] ") . "'new_one' permanently deleted from database\n";

    // Verify it does NOT appear in Faculty Upload
    $facUpload = $facClient->get('http://localhost:8000/php-app/upload.php', true);
    $notInFac = !str_contains($facUpload['body'], 'new_one') && !str_contains($facUpload['body'], 'new one');
    echo ($notInFac ? " [PASS] " : " [FAIL] ") . "Deleted metric immediately removed from Faculty Upload Data page\n";
} else {
    echo "Notice: 'new_one' was not in DB, continuing to full add/delete sync test.\n";
}

// 3. Test Full Add & Delete Sync Cycle
// A. Admin creates a new metric
$adminPage = $adminClient->get('http://localhost:8000/php-app/admin-metrics.php');
preg_match('/name="csrf"\s+value="([^"]+)"/i', $adminPage['body'], $m);
$csrf = $m[1] ?? '';

$createPost = $adminClient->post('http://localhost:8000/php-app/admin-metrics.php', [
    'csrf'                => $csrf,
    'action'              => 'create',
    'name'                => 'Industry Mentorship Program',
    'metric_key'          => 'industry_mentorship',
    'category'            => 'CAREER AND PLACEMENTS',
    'description'         => 'Tracking student-alumni/industry 1-on-1 career mentorship engagements.',
    'help_text'           => 'Submit mentor details and signed completion certificate.',
    'display_order'       => 31,
    'metric_type'         => 'generic',
    'faculty_visible'     => 1,
    'coordinator_visible' => 1,
    'status'              => 1,
]);

echo ($createPost['code'] === 302 ? " [PASS] " : " [FAIL] ") . "Admin added new metric 'Industry Mentorship Program' (HTTP {$createPost['code']})\n";

// B. Verify Faculty sees it immediately in Upload Data page
$facUploadAfterAdd = $facClient->get('http://localhost:8000/php-app/upload.php', true);
$facHasNewMetric = str_contains($facUploadAfterAdd['body'], 'Industry Mentorship Program')
                && str_contains($facUploadAfterAdd['body'], 'metric-tab-industry_mentorship');
echo ($facHasNewMetric ? " [PASS] " : " [FAIL] ") . "Newly added active metric immediately updated in Faculty Upload Data\n";

// C. Admin Deletes the metric
$createdMetric = metric_studio_find('industry_mentorship');
$createdId = $createdMetric['id'];

$adminPage2 = $adminClient->get('http://localhost:8000/php-app/admin-metrics.php');
preg_match('/name="csrf"\s+value="([^"]+)"/i', $adminPage2['body'], $m);
$csrf2 = $m[1] ?? '';

$delPost2 = $adminClient->post('http://localhost:8000/php-app/admin-metrics.php', [
    'csrf'   => $csrf2,
    'action' => 'delete',
    'id'     => $createdId,
]);
echo ($delPost2['code'] === 302 ? " [PASS] " : " [FAIL] ") . "Admin deleted metric 'industry_mentorship' (HTTP {$delPost2['code']})\n";

// D. Verify Faculty Upload Data page no longer shows the deleted metric
$facUploadAfterDel = $facClient->get('http://localhost:8000/php-app/upload.php', true);
$facHasDeletedMetric = str_contains($facUploadAfterDel['body'], 'Industry Mentorship Program')
                    || str_contains($facUploadAfterDel['body'], 'metric-tab-industry_mentorship');
echo (!$facHasDeletedMetric ? " [PASS] " : " [FAIL] ") . "Deleted metric immediately removed from Faculty Upload Data\n";

// E. Verify database count returns to exactly 30 approved metrics
$finalMetrics = metric_studio_all(['sort' => 'order']);
echo (count($finalMetrics) === 30 ? " [PASS] " : " [FAIL] ") . "Total metrics count returned to exactly 30 in database\n";

echo "\n=== ALL TESTS COMPLETED ===\n";
