<?php
/**
 * E2E HTTP Integration Test for ATTS-METRIC-DRAG-SORT
 * Tests actual HTTP requests against http://localhost:8000/php-app/
 */

require_once __DIR__ . '/inc/db.php';

$baseUrl = 'http://localhost:8000/php-app';
$cookieFile = __DIR__ . '/cookie_test.txt';
if (file_exists($cookieFile)) unlink($cookieFile);

echo "====================================================================\n";
echo "HTTP E2E TEST: ATTS-METRIC-DRAG-SORT\n";
echo "====================================================================\n\n";

function http_req(string $url, string $method = 'GET', $data = null, array $headers = []) {
    global $cookieFile;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_HEADER, true);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if (is_array($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }
    }

    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerStr = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    if (PHP_VERSION_ID < 80500) {
        @curl_close($ch);
    }

    return ['code' => $httpCode, 'headers' => $headerStr, 'body' => $body];
}

// 1. Unauthenticated request to admin-metrics.php -> should redirect
$res1 = http_req($baseUrl . '/admin-metrics.php');
$isRedirect1 = in_array($res1['code'], [302, 303, 307]);
echo ($isRedirect1 ? "[PASS]" : "[FAIL]") . " 1. Unauthenticated admin-metrics.php redirects (HTTP {$res1['code']})\n";

// 2. Fetch login page to extract CSRF token
$resLoginGet = http_req($baseUrl . '/login.php');
preg_match('/name="csrf"\s+value="([^"]+)"/i', $resLoginGet['body'], $csrfMatches);
$csrfToken = $csrfMatches[1] ?? '';
if (empty($csrfToken)) {
    preg_match('/name="csrf_token"\s+value="([^"]+)"/i', $resLoginGet['body'], $csrfMatches);
    $csrfToken = $csrfMatches[1] ?? '';
}
echo (!empty($csrfToken) ? "[PASS]" : "[FAIL]") . " 2. Acquired CSRF token from login page: " . substr($csrfToken, 0, 10) . "...\n";

// 3. Log in as Admin
$loginData = [
    'role'       => 'Admin',
    'email'      => 'master.admin@atts.edu',
    'password'   => 'master123',
    'csrf'       => $csrfToken,
    'csrf_token' => $csrfToken,
];
$resLoginPost = http_req($baseUrl . '/login.php', 'POST', $loginData);
$loginSuccess = ($resLoginPost['code'] === 302);
echo ($loginSuccess ? "[PASS]" : "[FAIL]") . " 3. Admin login POST returned 302 redirect\n";

// 4. Request admin-metrics.php as authenticated Admin
$resAdmin = http_req($baseUrl . '/admin-metrics.php');
echo ($resAdmin['code'] === 200 ? "[PASS]" : "[FAIL]") . " 4. Authenticated admin-metrics.php returned HTTP 200\n";

// 5. Verify HTML: Order column contains drag handles and order badges
$hasDragHandles = strpos($resAdmin['body'], 'class="metric-drag-handle') !== false;
$hasOrderPills  = strpos($resAdmin['body'], 'data-order-pill') !== false;
$hasGripIcons   = strpos($resAdmin['body'], 'circle cx="9" cy="12"') !== false;
echo ($hasDragHandles ? "[PASS]" : "[FAIL]") . " 5a. Metric drag handles exist in HTML\n";
echo ($hasOrderPills ? "[PASS]" : "[FAIL]") . " 5b. Order pills (data-order-pill) exist in HTML\n";
echo ($hasGripIcons ? "[PASS]" : "[FAIL]") . " 5c. Grip icons rendered inside drag handles\n";

// 6. Verify Up/Down arrow buttons are COMPLETELY GONE from Order column
$hasOldArrowBtns = strpos($resAdmin['body'], 'order-arrow-btn') !== false;
$hasOldMoveForms = strpos($resAdmin['body'], 'name="direction" value="up"') !== false;
echo (!$hasOldArrowBtns ? "[PASS]" : "[FAIL]") . " 6a. Old arrow buttons (order-arrow-btn) completely removed\n";
echo (!$hasOldMoveForms ? "[PASS]" : "[FAIL]") . " 6b. Old arrow form controls (direction=up) completely removed\n";

// 7. Verify Drag Hint Banner exists
$hasDragHint = strpos($resAdmin['body'], 'id="metricsDragHint"') !== false;
echo ($hasDragHint ? "[PASS]" : "[FAIL]") . " 7. Drag hint banner exists in header\n";

// 8. Test AJAX reorder_metrics POST endpoint
// Extract CSRF token from admin-metrics page
preg_match('/name="csrf"\s+value="([^"]+)"/i', $resAdmin['body'], $adminCsrfMatches);
$adminCsrf = $adminCsrfMatches[1] ?? $csrfToken;

// Fetch current order from DB
$pdo = db();
$stmt = $pdo->query("SELECT id FROM metrics ORDER BY display_order ASC, id ASC");
$currentIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Create new test order: swap first 2 items
$newTestOrder = $currentIds;
$temp = $newTestOrder[0];
$newTestOrder[0] = $newTestOrder[1];
$newTestOrder[1] = $temp;

$ajaxData = [
    'action'      => 'reorder_metrics',
    'csrf'        => $adminCsrf,
    'csrf_token'  => $adminCsrf,
    'ajax'        => '1',
    'ordered_ids' => $newTestOrder,
];

$resAjax = http_req($baseUrl . '/admin-metrics.php', 'POST', $ajaxData, [
    'X-Requested-With: XMLHttpRequest',
]);

$ajaxJson = json_decode($resAjax['body'], true);
$ajaxOk = ($resAjax['code'] === 200 && ($ajaxJson['success'] ?? false) === true);
echo ($ajaxOk ? "[PASS]" : "[FAIL]") . " 8. AJAX reorder_metrics POST succeeded: " . ($ajaxJson['message'] ?? 'Error') . "\n";

// Verify DB order changed
$stmt = $pdo->query("SELECT id FROM metrics ORDER BY display_order ASC, id ASC LIMIT 2");
$dbFirst2 = $stmt->fetchAll(PDO::FETCH_COLUMN);
$dbMatches = ($dbFirst2[0] == $newTestOrder[0] && $dbFirst2[1] == $newTestOrder[1]);
echo ($dbMatches ? "[PASS]" : "[FAIL]") . " 9. Database display_order updated to swapped position\n";

// 10. Restore initial order
$restoreData = [
    'action'      => 'reorder_metrics',
    'csrf'        => $adminCsrf,
    'csrf_token'  => $adminCsrf,
    'ajax'        => '1',
    'ordered_ids' => $currentIds,
];
$resRestore = http_req($baseUrl . '/admin-metrics.php', 'POST', $restoreData, [
    'X-Requested-With: XMLHttpRequest',
]);
$restoreJson = json_decode($resRestore['body'], true);
$restoreOk = ($resRestore['code'] === 200 && ($restoreJson['success'] ?? false) === true);
echo ($restoreOk ? "[PASS]" : "[FAIL]") . " 10. Restored initial approved order successfully\n";

// 11. Test filtered view shows lock warning
$resFiltered = http_req($baseUrl . '/admin-metrics.php?category=RESEARCH+AND+PUBLICATIONS');
$hasLockWarning = strpos($resFiltered['body'], 'Drag-and-drop reordering is locked') !== false;
$hasDisabledHandles = strpos($resFiltered['body'], 'class="metric-drag-handle disabled"') !== false;
echo ($hasLockWarning ? "[PASS]" : "[FAIL]") . " 11a. Filtered view shows 'reordering is locked' warning\n";
echo ($hasDisabledHandles ? "[PASS]" : "[FAIL]") . " 11b. Filtered view disables drag handles with disabled attribute\n";

if (file_exists($cookieFile)) unlink($cookieFile);

// 12. Test unauthorized reorder attempt (no session cookie)
$resUnauthReorder = http_req($baseUrl . '/admin-metrics.php', 'POST', [
    'action' => 'reorder_metrics',
    'ordered_ids' => [30010, 30011],
], ['X-Requested-With: XMLHttpRequest']);
$unauthBlocked = in_array($resUnauthReorder['code'], [302, 303, 401, 403]);
echo ($unauthBlocked ? "[PASS]" : "[FAIL]") . " 12. Unauthenticated reorder request blocked (HTTP {$resUnauthReorder['code']})\n";

echo "\nHTTP E2E Testing complete.\n";
