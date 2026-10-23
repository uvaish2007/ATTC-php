<?php
$cookieFile = __DIR__ . '/cookie_jar.txt';
if (file_exists($cookieFile)) unlink($cookieFile);

$ch = curl_init('http://localhost:8000/php-app/login.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, [
    'email' => 'master@atts.edu',
    'password' => 'master123',
    'login' => '1'
]);
$res = curl_exec($ch);
$info = curl_getinfo($ch);
echo "Login HTTP code: " . $info['http_code'] . "\n";
echo "Effective URL after login: " . $info['url'] . "\n";

$ch2 = curl_init('http://localhost:8000/php-app/upload.php?section=value_added');
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch2, CURLOPT_COOKIEJAR, $cookieFile);
curl_setopt($ch2, CURLOPT_COOKIEFILE, $cookieFile);
$res2 = curl_exec($ch2);
$info2 = curl_getinfo($ch2);
echo "Upload HTTP code: " . $info2['http_code'] . "\n";
echo "Upload Effective URL: " . $info2['url'] . "\n";
echo "Body length: " . strlen($res2) . "\n";

// check title
if (preg_match('/<title>(.*?)<\/title>/i', $res2, $m)) {
    echo "Page title: " . $m[1] . "\n";
}

// check active section or selected type
if (preg_match('/name="section"\s+value="([^"]+)"/', $res2, $m)) {
    echo "Form section: " . $m[1] . "\n";
}

// look for value_added in body
echo "Occurrences of 'value_added': " . substr_count($res2, 'value_added') . "\n";
