<?php
$cookie = tempnam(sys_get_temp_dir(), 'test_cookie_');
$ch = curl_init('http://localhost:8000/login.php');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR => $cookie,
]);
$html = curl_exec($ch);
preg_match('/name="csrf"\s+value="([^"]+)"/', $html, $m);
$csrf = $m[1] ?? '';

$ch = curl_init('http://localhost:8000/login.php');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR => $cookie,
    CURLOPT_COOKIEFILE => $cookie,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'csrf' => $csrf,
        'email' => 'faculty@atts.edu',
        'password' => 'faculty12',
        'role' => 'Faculty'
    ]),
]);
curl_exec($ch);

$ch = curl_init('http://localhost:8000/upload.php?type=placement');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR => $cookie,
    CURLOPT_COOKIEFILE => $cookie,
]);
$page = curl_exec($ch);
echo "Page length: " . strlen($page) . "\n";
echo "Has saveDraft: " . (strpos($page, 'saveDraft') !== false ? 'YES' : 'NO') . "\n";
echo "Has restoreDraft: " . (strpos($page, 'restoreDraft') !== false ? 'YES' : 'NO') . "\n";
echo "Has LPA: " . (strpos($page, 'LPA') !== false ? 'YES' : 'NO') . "\n";
preg_match('/name="pay_scale"[\s\S]{0,350}LPA/i', $page, $mLpa);
echo "LPA match with {0,350}: " . (!empty($mLpa) ? 'YES' : 'NO') . "\n";
