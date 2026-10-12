<?php
$base = 'http://localhost:8000';
$cookieF = tempnam(sys_get_temp_dir(), 'httplog_');

// 1. GET login page to get CSRF
$ch = curl_init("$base/login.php");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE     => $cookieF,
    CURLOPT_COOKIEJAR      => $cookieF,
    CURLOPT_TIMEOUT        => 10,
]);
$body = curl_exec($ch);
curl_close($ch);

preg_match('/name="csrf"[^>]*value="([^"]+)"/', $body, $cm);
$csrf = $cm[1] ?? '';

// 2. POST login
$ch = curl_init("$base/login.php");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'csrf'     => $csrf,
        'role'     => 'Faculty',
        'email'    => 'faculty@atts.edu',
        'password' => 'faculty12',
    ]),
    CURLOPT_COOKIEFILE     => $cookieF,
    CURLOPT_COOKIEJAR      => $cookieF,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_HEADER         => true,
    CURLOPT_TIMEOUT        => 10,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "Login HTTP code: $code\n";

// 3. GET /upload.php?type=placement
$ch = curl_init("$base/upload.php?type=placement");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE     => $cookieF,
    CURLOPT_COOKIEJAR      => $cookieF,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 10,
]);
$uploadHtml = curl_exec($ch);
$upUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
curl_close($ch);
echo "Upload effective URL: $upUrl\n";

if (preg_match('/<label for="pay_scale">[\s\S]*?<\/div>/', $uploadHtml, $m)) {
    echo "Found Pay Scale in uploadHtml:\n" . $m[0] . "\n";
} else {
    echo "Pay Scale block not found.\n";
}

if (stripos($uploadHtml, 'saveDraft') !== false) {
    echo "saveDraft IS in uploadHtml!\n";
} else {
    echo "saveDraft is NOT in uploadHtml.\n";
}

if (stripos($uploadHtml, 'js-form-status-badge') !== false) {
    echo "js-form-status-badge IS in uploadHtml!\n";
} else {
    echo "js-form-status-badge is NOT in uploadHtml.\n";
}
