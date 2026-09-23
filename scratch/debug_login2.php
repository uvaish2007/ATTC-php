<?php
/**
 * Debug login with single cURL handle (share session).
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$base    = 'http://localhost:8000';
$cookieF = tempnam(sys_get_temp_dir(), 'em09dbg_');

$sh = curl_share_init();
curl_share_setopt($sh, CURLSHOPT_SHARE, CURL_LOCK_DATA_COOKIE);
curl_share_setopt($sh, CURLSHOPT_SHARE, CURL_LOCK_DATA_DNS);

// === GET login page ===
$ch = curl_init("$base/login.php");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE     => $cookieF,
    CURLOPT_COOKIEJAR      => $cookieF,
    CURLOPT_HEADER         => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_SHARE          => $sh,
    CURLOPT_USERAGENT      => 'Mozilla/5.0',
]);
$r1   = curl_exec($ch);
$hSz  = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$head = substr($r1, 0, $hSz);
$body = substr($r1, $hSz);
echo "GET /login.php HTTP " . curl_getinfo($ch, CURLINFO_HTTP_CODE) . "\n";
echo "Set-Cookie: " . (preg_match('/Set-Cookie: ([^\r\n]+)/i', $head, $m) ? $m[1] : 'none') . "\n";

// Extract CSRF
preg_match('/name=["\']csrf["\'][^>]*value=["\']([0-9a-f]+)["\']/', $body, $cm);
if (empty($cm[1])) {
    preg_match('/value=["\']([0-9a-f]{40,})["\']/', $body, $cm);
}
$csrf = $cm[1] ?? '';
echo "CSRF token: $csrf\n\n";

if (empty($csrf)) {
    echo "ERROR: Could not extract CSRF token\n";
    exit(1);
}

// === POST login ===
$postData = http_build_query([
    'csrf'     => $csrf,
    'email'    => 'mohameduvaish132@gmail.com',
    'password' => 'Admin@123',
    'role'     => 'Admin',
]);
curl_setopt_array($ch, [
    CURLOPT_URL            => "$base/login.php",
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $postData,
    CURLOPT_FOLLOWLOCATION => false,
]);
$r2   = curl_exec($ch);
$hSz  = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$h2   = substr($r2, 0, $hSz);
$b2   = substr($r2, $hSz);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
echo "POST /login.php => HTTP $code\n";
echo "Headers:\n$h2\n";
if ($code !== 302) {
    // Check for error message
    preg_match('/<[^>]*class="[^"]*error[^"]*"[^>]*>([^<]+)/', $b2, $em);
    if (!empty($em[1])) echo "Error: " . trim($em[1]) . "\n";
    // Generic snippet
    echo "Body: " . substr(strip_tags($b2), 0, 300) . "\n";
}

if ($code === 302) {
    // Follow redirect
    preg_match('/Location:\s*([^\r\n]+)/i', $h2, $loc);
    $dest = trim($loc[1] ?? '/dashboard.php');
    if (!str_starts_with($dest, 'http')) $dest = $base . $dest;
    curl_setopt_array($ch, [
        CURLOPT_URL            => $dest,
        CURLOPT_POST           => false,
        CURLOPT_HTTPGET        => true,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $r3   = curl_exec($ch);
    $code3= curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $eff  = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    echo "Follow => HTTP $code3 — $eff\n";
    echo "Logged in: " . (str_contains($r3, 'logout') || str_contains($eff, 'dashboard') ? "YES" : "NO") . "\n";
}

echo "\nCookies saved to: $cookieF\n";
echo file_get_contents($cookieF) . "\n";
