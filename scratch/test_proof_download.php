<?php
$cookieFile = __DIR__ . '/live_cookies.txt';
$fileName = 'record_05e0f957c1a25b34_1792645201.pdf';

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'http://localhost:8000/php-app/view-proof.php?file=' . urlencode($fileName));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
curl_setopt($ch, CURLOPT_HEADER, true);

$res = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
echo "Admin Download HTTP Code: $code\n";
echo "Content-Type: " . curl_getinfo($ch, CURLINFO_CONTENT_TYPE) . "\n";
echo "Body snippet: " . substr($res, -100) . "\n";
