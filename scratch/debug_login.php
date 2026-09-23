<?php
$cookieF = tempnam(sys_get_temp_dir(), 'em09_');

function curl_get(string $url, string $cookieF, bool $noFollow = false): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEFILE     => $cookieF,
        CURLOPT_COOKIEJAR      => $cookieF,
        CURLOPT_FOLLOWLOCATION => !$noFollow,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HEADER         => true,
    ]);
    $resp  = curl_exec($ch);
    $hSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $effUrl= curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $head  = substr($resp, 0, $hSize);
    $body  = substr($resp, $hSize);
    return compact('code', 'head', 'body', 'effUrl');
}

function curl_post(string $url, array $fields, string $cookieF): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_COOKIEFILE     => $cookieF,
        CURLOPT_COOKIEJAR      => $cookieF,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HEADER         => true,
    ]);
    $resp  = curl_exec($ch);
    $hSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $head  = substr($resp, 0, $hSize);
    $body  = substr($resp, $hSize);
    return compact('code', 'head', 'body');
}

// 1. GET login page
$r = curl_get('http://localhost:8000/login.php', $cookieF);
echo "GET /login.php => HTTP {$r['code']}\n";
// Extract form fields
preg_match_all('/name="([^"]+)"/', $r['body'], $m);
echo "Form fields found: " . implode(', ', array_unique($m[1])) . "\n\n";

// 2. POST login
$r2 = curl_post('http://localhost:8000/login.php', [
    'email'    => 'mohameduvaish132@gmail.com',
    'password' => 'Admin@123',
    'role'     => 'Admin',
], $cookieF);
echo "POST /login.php => HTTP {$r2['code']}\n";
echo "Response headers:\n{$r2['head']}\n";
echo "Body snippet: " . substr($r2['body'], 0, 500) . "\n\n";
echo "Cookie file:\n" . file_get_contents($cookieF) . "\n";
