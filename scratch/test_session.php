<?php
/**
 * Uses the saved admin cookie to test pages directly.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$base    = 'http://localhost:8000';
$cookieF = __DIR__ . '/cookie_admin_20.txt';

function get_page(string $url, string $cookieF): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEFILE     => $cookieF,
        CURLOPT_COOKIEJAR      => $cookieF,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HEADER         => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0',
    ]);
    $r    = curl_exec($ch);
    $hSz  = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $eff  = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $head = substr($r, 0, $hSz);
    $body = substr($r, $hSz);
    return ['code' => $code, 'url' => $eff, 'body' => $body, 'head' => $head];
}

$r = get_page("$base/dashboard.php", $cookieF);
echo "Dashboard: HTTP {$r['code']} — {$r['url']}\n";
$dashOk = !str_contains($r['url'], 'login') && $r['code'] === 200;
echo "Authenticated: " . ($dashOk ? "YES" : "NO") . "\n\n";

if (!$dashOk) {
    echo "Session expired. Need fresh login.\n";
    // Try to login again with fresh CSRF
    $loginGet = get_page("$base/login.php", __DIR__ . '/cookie_fresh.txt');
    preg_match('/name="csrf"[^>]*value="([^"]+)"/', $loginGet['body'], $cm);
    $csrf = $cm[1] ?? '';
    echo "CSRF: $csrf\n";
    
    // POST login
    $ch2 = curl_init("$base/login.php");
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEFILE     => __DIR__ . '/cookie_fresh.txt',
        CURLOPT_COOKIEJAR      => __DIR__ . '/cookie_fresh.txt',
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'csrf' => $csrf, 'email' => 'mohameduvaish132@gmail.com',
            'password' => 'Admin@123', 'role' => 'Admin',
        ]),
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_USERAGENT      => 'Mozilla/5.0',
    ]);
    $r2   = curl_exec($ch2);
    $hSz  = curl_getinfo($ch2, CURLINFO_HEADER_SIZE);
    $code = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    $h2   = substr($r2, 0, $hSz);
    $b2   = substr($r2, $hSz);
    echo "POST => HTTP $code\n";
    echo "Headers: $h2\n";
    $errTxt = preg_replace('/\s+/', ' ', strip_tags($b2));
    echo "Body: " . substr($errTxt, 0, 800) . "\n";
    exit(1);
}

// If authenticated, test pages
echo "=== Testing Pages ===\n\n";

$tests = [
    ["$base/executive-meeting-report.php", 'EM Report'],
    ["$base/present-executive-meeting.php?embed=1", 'Present Page'],
];

foreach ($tests as [$url, $label]) {
    $r = get_page($url, $cookieF);
    $auth = !str_contains($r['url'], 'login');
    echo "$label: HTTP {$r['code']} — " . ($auth ? "OK" : "REDIRECTED TO LOGIN") . "\n";
    echo "URL: {$r['url']}\n";
    echo "Body length: " . strlen($r['body']) . " bytes\n";
    if (!$auth) {
        echo "STATUS: UNAUTHENTICATED\n\n";
        continue;
    }
    // Key checks
    $b = $r['body'];
    echo "AUTO_ADVANCE_MS=5000: " . (str_contains($b, 'AUTO_ADVANCE_MS = 5000') ? 'YES' : 'NO') . "\n";
    echo "clearAutoTimer():     " . (str_contains($b, 'function clearAutoTimer()') ? 'YES' : 'NO') . "\n";
    echo "ArrowRight handler:   " . (str_contains($b, "case 'ArrowRight':") ? 'YES' : 'NO') . "\n";
    echo "slides array:         " . (preg_match('/const slides = \[/', $b) ? 'YES' : 'NO') . "\n";
    echo "\n";
}
