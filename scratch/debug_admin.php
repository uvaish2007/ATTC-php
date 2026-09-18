<?php
require_once __DIR__ . '/test_http_ui_flow.php';

$client = http_client();
$res = ($client['request'])('http://localhost:8000/php-app/login.php');
$csrf = extract_csrf($res['body']);
$loginRes = ($client['request'])('http://localhost:8000/php-app/login.php', 'POST', [
    'email' => 'admin@atts.local', 'password' => 'pass123', 'role' => 'Admin', 'csrf' => $csrf
]);

$reqs = ($client['request'])('http://localhost:8000/php-app/approvals.php?tab=requests');
echo "Admin status code: " . $reqs['status'] . "\n";
echo "Admin reqs contains 'Approved by Dean': " . (str_contains($reqs['body'], 'Approved by Dean') ? 'YES' : 'NO') . "\n";

if (!str_contains($reqs['body'], 'Approved by Dean')) {
    echo "Body sample:\n" . substr($reqs['body'], 0, 1500) . "\n";
}

$recs = ($client['request'])('http://localhost:8000/php-app/approvals.php?tab=records');
echo "Admin recs contains 'Approved by Dean': " . (str_contains($recs['body'], 'Approved by Dean') ? 'YES' : 'NO') . "\n";

($client['cleanup'])();
