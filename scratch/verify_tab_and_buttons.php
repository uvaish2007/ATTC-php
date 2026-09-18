<?php
require_once __DIR__ . '/../php-app/inc/auth.php';
require_once __DIR__ . '/../php-app/inc/helpers.php';
require_once __DIR__ . '/test_http_ui_flow.php';

echo "========================================================================\n";
echo "VERIFYING TAB ROUTING, WARNING REMOVAL & ONCLICK SYNTAX\n";
echo "========================================================================\n\n";

// 1. Test HoD on approvals.php?tab=requests
$clientHod = http_client();
$loginPage = ($clientHod['request'])('http://localhost:8000/php-app/login.php');
$csrf = extract_csrf($loginPage['body']);
($clientHod['request'])(
    'http://localhost:8000/php-app/login.php',
    'POST',
    ['email' => 'hod.test@atts.local', 'password' => 'pass123', 'role' => 'HoD', 'csrf' => $csrf]
);

$hodRequestsPage = ($clientHod['request'])('http://localhost:8000/php-app/approvals.php?tab=requests');
$bodyHodReq = $hodRequestsPage['body'];

$noWarningHod = !str_contains($bodyHodReq, 'Undefined variable $currentTab');
$isRequestsTabActive = str_contains($bodyHodReq, 'My Edit Requests to Dean') || str_contains($bodyHodReq, 'Track status of modification requests');
echo "HoD tab=requests - Undefined variable warning absent: " . ($noWarningHod ? "PASS" : "FAIL") . "\n";
echo "HoD tab=requests - Edit Requests tab actively rendered: " . ($isRequestsTabActive ? "PASS" : "FAIL") . "\n";

// Test HoD on approvals.php?tab=records - inspect onclick attribute syntax
$hodRecordsPage = ($clientHod['request'])('http://localhost:8000/php-app/approvals.php?tab=records');
$bodyHodRec = $hodRecordsPage['body'];

// Match any onclick with openHodEditRequest
if (preg_match('/onclick=["\'](openHodEditRequest\([^"\']+\))["\']/', $bodyHodRec, $m)) {
    echo "HoD openHodEditRequest onclick attribute cleanly parsed: PASS\n";
    echo "  Snippet: " . htmlspecialchars_decode($m[1]) . "\n";
} else {
    echo "HoD openHodEditRequest onclick attribute parse: FAIL\n";
}

// 2. Test Dean on approvals.php?tab=history
$clientDean = http_client();
$deanLogin = ($clientDean['request'])('http://localhost:8000/php-app/login.php');
$csrfDean = extract_csrf($deanLogin['body']);
($clientDean['request'])(
    'http://localhost:8000/php-app/login.php',
    'POST',
    ['email' => 'dean.test@atts.local', 'password' => 'pass123', 'role' => 'Dean', 'csrf' => $csrfDean]
);

$deanHistoryPage = ($clientDean['request'])('http://localhost:8000/php-app/approvals.php?tab=history');
$bodyDeanHist = $deanHistoryPage['body'];

$noWarningDean = !str_contains($bodyDeanHist, 'Undefined variable $currentTab');
$isHistoryTabActive = str_contains($bodyDeanHist, 'Edit Request Decision History') && str_contains($bodyDeanHist, 'Audit log of previously approved, rejected, and completed edit requests');
echo "Dean tab=history - Undefined variable warning absent: " . ($noWarningDean ? "PASS" : "FAIL") . "\n";
echo "Dean tab=history - Decision History tab actively rendered: " . ($isHistoryTabActive ? "PASS" : "FAIL") . "\n";

// Test Dean on approvals.php?tab=requests - inspect onclick attribute syntax for Approve Request
$deanRequestsPage = ($clientDean['request'])('http://localhost:8000/php-app/approvals.php?tab=requests');
$bodyDeanReq = $deanRequestsPage['body'];

if (preg_match('/onclick="([^"]*openDecisionModal[^"]*)"/', $bodyDeanReq, $m)) {
    echo "Dean openDecisionModal onclick attribute cleanly parsed: PASS\n";
    echo "  Snippet: " . htmlspecialchars_decode($m[1]) . "\n";
} else {
    echo "Dean openDecisionModal onclick attribute parse: FAIL\n";
}

($clientHod['cleanup'])();
($clientDean['cleanup'])();
