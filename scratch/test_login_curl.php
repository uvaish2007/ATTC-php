<?php
$cookieFile = __DIR__ . '/cookie.txt';
$ch = curl_init('http://localhost:8000/php-app/login.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
$html = curl_exec($ch);

preg_match('/name="csrf"\s+value="([^"]+)"/', $html, $m);
$csrf = $m[1] ?? '';
echo "CSRF token: $csrf\n";

curl_setopt($ch, CURLOPT_URL, 'http://localhost:8000/php-app/login.php');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'email' => 'hod.test@atts.local',
    'password' => 'pass123',
    'csrf' => $csrf
]));
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
$res = curl_exec($ch);
echo "LOGIN RESPONSE:\n" . substr($res, 0, 1000) . "\n";

// Follow redirect
curl_setopt($ch, CURLOPT_URL, 'http://localhost:8000/php-app/approvals.php');
curl_setopt($ch, CURLOPT_POST, false);
curl_setopt($ch, CURLOPT_HTTPGET, true);
$appRes = curl_exec($ch);
echo "APPROVALS RESPONSE HEADERS:\n" . substr($appRes, 0, 600) . "\n";
echo "APPROVALS BODY PREVIEW:\n" . substr(strstr($appRes, "\r\n\r\n"), 0, 600) . "\n";
