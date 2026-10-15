<?php
$sessionFile = __DIR__ . '/admin_cookie.txt';
if (file_exists($sessionFile)) @unlink($sessionFile);

$ch = curl_init('http://localhost:8000/login.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $sessionFile);
curl_setopt($ch, CURLOPT_COOKIEFILE, $sessionFile);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
$html = curl_exec($ch);

preg_match('/name="csrf"\s+value="([^"]+)"/', $html, $m);
$csrf = $m[1] ?? '';
echo "CSRF token: $csrf\n";

curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'csrf' => $csrf,
    'role' => 'Admin',
    'email' => 'mohameduvaish132@gmail.com',
    'password' => 'uvaish123'
]));
$res = curl_exec($ch);
$info = curl_getinfo($ch);
echo "Effective URL after login: " . $info['url'] . " | HTTP: " . $info['http_code'] . "\n";
if (strpos($res, 'Dashboard') !== false) {
    echo "SUCCESS: Logged in to Dashboard!\n";
} else {
    echo "Response preview:\n" . substr($res, 0, 500) . "\n";
}
