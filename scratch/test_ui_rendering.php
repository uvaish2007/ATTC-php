<?php
/**
 * Test HTML rendering for HoD, Coordinator, and Dean
 */
require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/inc/auth.php';
require_once __DIR__ . '/../php-app/inc/helpers.php';
require_once __DIR__ . '/../php-app/models/Record.php';

function simulate_render($url, $user) {
    $_SESSION['user'] = $user;
    $_SESSION['academic_year'] = '2025-26';
    
    // Simulate GET request to approvals.php or dashboard.php
    ob_start();
    include $url;
    return ob_get_clean();
}

$hod = [
    'id' => 3,
    'name' => 'Dr. HoD CSBS',
    'email' => 'hod@atts.edu',
    'role' => 'HoD',
    'department' => 'CSBS'
];

$coordinator = [
    'id' => 4,
    'name' => 'Prof. Coordinator CSBS',
    'email' => 'coordinator@atts.edu',
    'role' => 'Coordinator',
    'department' => 'CSBS'
];

$dean = [
    'id' => 6,
    'name' => 'Dr. Dean',
    'email' => 'dean@atts.edu',
    'role' => 'Dean',
    'department' => null
];

echo "=== VERIFYING HTML RENDERING ===\n";

// 1. HoD approvals.php
$_GET = ['tab' => 'records'];
$hod_approvals_html = simulate_render(__DIR__ . '/../php-app/approvals.php', $hod);

$has_review_records = (strpos($hod_approvals_html, 'Review Records') !== false);
$has_request_edit_modal = (strpos($hod_approvals_html, 'Submit Edit Request to Dean') !== false);
$has_approve_button = (stripos($hod_approvals_html, 'name="action" value="approve"') !== false);
$has_reject_button = (stripos($hod_approvals_html, 'name="action" value="reject"') !== false);

echo "HoD Approvals Page:\n";
echo "  - Contains 'Review Records': " . ($has_review_records ? "YES [PASS]" : "NO [FAIL]") . "\n";
echo "  - Contains 'Submit Edit Request to Dean' modal: " . ($has_request_edit_modal ? "YES [PASS]" : "NO [FAIL]") . "\n";
echo "  - Contains HoD Approve button: " . ($has_approve_button ? "YES [FAIL]" : "NO [PASS]") . "\n";
echo "  - Contains HoD Reject button: " . ($has_reject_button ? "YES [FAIL]" : "NO [PASS]") . "\n";

// 2. HoD sidebar / nav
$nav_items = navigation_for($hod['role']);
$review_records_item = null;
foreach ($nav_items as $item) {
    if (isset($item['label']) && $item['label'] === 'Review Records') {
        $review_records_item = $item;
    }
}
$nav_has_review_records = ($review_records_item !== null);
$nav_has_pending_approvals = false;
foreach ($nav_items as $item) {
    if (isset($item['label']) && stripos($item['label'], 'Approvals') !== false) {
        $nav_has_pending_approvals = true;
    }
}
echo "HoD Navigation Sidebar:\n";
echo "  - Sidebar label is 'Review Records': " . ($nav_has_review_records ? "YES [PASS]" : "NO [FAIL]") . "\n";
echo "  - Sidebar has NO 'Pending Approvals': " . (!$nav_has_pending_approvals ? "YES [PASS]" : "NO [FAIL]") . "\n";
echo "  - HoD badge count is 0: " . (pending_approvals_count($hod) === 0 ? "YES [PASS]" : "NO [FAIL]") . "\n";


// 3. Coordinator approvals.php
$_GET = ['tab' => 'pending'];
$coord_approvals_html = simulate_render(__DIR__ . '/../php-app/approvals.php', $coordinator);
$coord_has_pending_verif = (strpos($coord_approvals_html, 'Pending Verification') !== false);
$coord_has_authorized_corr = (strpos($coord_approvals_html, 'Authorized Corrections') !== false);

echo "Coordinator Approvals Page:\n";
echo "  - Contains 'Pending Verification': " . ($coord_has_pending_verif ? "YES [PASS]" : "NO [FAIL]") . "\n";
echo "  - Contains 'Authorized Corrections': " . ($coord_has_authorized_corr ? "YES [PASS]" : "NO [FAIL]") . "\n";

// 4. Dean approvals.php
$_GET = ['tab' => 'requests'];
$dean_approvals_html = simulate_render(__DIR__ . '/../php-app/approvals.php', $dean);
$dean_has_edit_requests = (strpos($dean_approvals_html, 'Edit Requests') !== false);
$dean_has_decision_modal = (strpos($dean_approvals_html, 'Decision on Edit Request') !== false);

echo "Dean Approvals Page:\n";
echo "  - Contains 'Edit Requests': " . ($dean_has_edit_requests ? "YES [PASS]" : "NO [FAIL]") . "\n";
echo "  - Contains 'Decision on Edit Request' Decision Modal: " . ($dean_has_decision_modal ? "YES [PASS]" : "NO [FAIL]") . "\n";


echo "\nALL UI RENDERING CHECKS COMPLETED!\n";
