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
preg_match('/name="csrf"[^>]*value="([^"]+)"/', $body, $cm);
if (empty($cm[1])) preg_match('/value="([^"]+)"[^>]*name="csrf"/', $body, $cm);
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
$h2   = substr($r2, 0, $hSz);
$b2   = substr($r2, $hSz);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
echo "POST => HTTP $code\n";
echo "Location header: ";
preg_match('/Location:\s*([^\r\n]+)/i', $h2, $loc);
echo ($loc[1] ?? 'none') . "\n";

// Find error divs or alerts
preg_match_all('/<[^>]*class="[^"]*(?:alert|error|danger)[^"]*"[^>]*>([\s\S]*?)<\/[^>]+>/', $b2, $alerts);
if (!empty($alerts[1])) {
    foreach ($alerts[1] as $a) {
        echo "Alert: " . trim(strip_tags($a)) . "\n";
    }
} else {
    // Look for text between body
    $txt = preg_replace('/\s+/', ' ', strip_tags($b2));
    $txt = trim(preg_replace('/\s{2,}/', ' ', $txt));
    file_put_contents(__DIR__ . '/../scratch/login_body.txt', $txt);
    echo "Body written to scratch/login_body.txt, length: " . strlen($txt) . "\n";
    echo "First 600 chars: " . substr($txt, 0, 600) . "\n";
}
