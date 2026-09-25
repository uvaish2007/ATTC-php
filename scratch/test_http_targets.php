<?php
/**
 * Test HTTP responses for targets.php and report pages using cookie jar
 */

$cookieFile = __DIR__ . '/cookie.txt';
if (file_exists($cookieFile)) {
    unlink($cookieFile);
}

function curl_get(string $url, string $cookieFile): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => $body];
}

function curl_post(string $url, array $data, string $cookieFile): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => $body];
}

echo "1. Fetching login page...\n";
$loginPage = curl_get('http://localhost:8000/php-app/login.php', $cookieFile);
preg_match('/name="csrf"\s+value="([^"]+)"/', $loginPage['body'], $m);
$csrf = $m[1] ?? '';
echo "CSRF token: " . substr($csrf, 0, 10) . "...\n";

echo "2. Logging in as Admin...\n";
$loginRes = curl_post('http://localhost:8000/php-app/login.php', [
    'csrf'     => $csrf,
    'email'    => 'admin',
    'password' => 'admin123',
    'role'     => 'Admin'
], $cookieFile);
echo "Login HTTP Code: {$loginRes['code']}\n";

echo "3. Fetching targets.php (default active year)...\n";
$res1 = curl_get('http://localhost:8000/php-app/targets.php', $cookieFile);
echo "HTTP Code: {$res1['code']}\n";
if (strpos($res1['body'], 'Review Targets') !== false && strpos($res1['body'], 'Academic Year') !== false) {
    echo "  [PASS] targets.php rendered correctly with Academic Year filter\n";
} else {
    echo "  [FAIL] targets.php response missing expected content\n";
}

echo "4. Fetching targets.php?year=2025-26 (historical year)...\n";
$res2 = curl_get('http://localhost:8000/php-app/targets.php?year=2025-26', $cookieFile);
echo "HTTP Code: {$res2['code']}\n";
if (strpos($res2['body'], 'Historical Academic Year') !== false && strpos($res2['body'], '2025-26') !== false) {
    echo "  [PASS] targets.php?year=2025-26 rendered correctly with Historical banner\n";
} else {
    echo "  [FAIL] targets.php?year=2025-26 response missing historical indicator\n";
}

echo "5. Fetching template-report.php?year=2025-26&department=CSBS...\n";
$res3 = curl_get('http://localhost:8000/php-app/template-report.php?year=2025-26&department=CSBS', $cookieFile);
echo "HTTP Code: {$res3['code']}\n";
if ($res3['code'] === 200 && (strpos($res3['body'], 'CSBS') !== false || strpos($res3['body'], 'Executive Meeting Report') !== false)) {
    echo "  [PASS] template-report.php rendered correctly for 2025-26\n";
} else {
    echo "  [FAIL] template-report.php response check\n";
}

if (file_exists($cookieFile)) {
    unlink($cookieFile);
}
