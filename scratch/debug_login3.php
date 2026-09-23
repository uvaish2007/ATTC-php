<?php
error_reporting(E_ALL & ~E_DEPRECATED);
$base    = 'http://localhost:8000';
$cookieF = tempnam(sys_get_temp_dir(), 'em09dbg_');

$ch = curl_init("$base/login.php");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE     => $cookieF,
    CURLOPT_COOKIEJAR      => $cookieF,
    CURLOPT_HEADER         => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_USERAGENT      => 'Mozilla/5.0',
]);
$r1 = curl_exec($ch);
$hSz = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$body = substr($r1, $hSz);

// Extract CSRF
if (!preg_match('/name=["\']csrf["\'][^>]*value=["\']([0-9a-f]{40,})["\']/', $body, $cm)) {
    preg_match('/name=["\']csrf["\'][\s\S]{0,200}value=["\']([0-9a-f]{40,})["\']/', $body, $cm);
}
$csrf = $cm[1] ?? '';
echo "CSRF: $csrf\n";

// POST
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
    CURLOPT_HEADER         => true,
]);
$r2   = curl_exec($ch);
$hSz  = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$b2   = substr($r2, $hSz);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
echo "POST => HTTP $code\n";

// Show all visible text on the login page
echo "=== LOGIN FORM RESPONSE TEXT ===\n";
// Strip tags, remove blank lines
$txt = preg_replace('/\s+/', ' ', strip_tags($b2));
echo $txt . "\n";
echo "=== END ===\n";
