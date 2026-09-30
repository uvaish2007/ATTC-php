<?php
$cookieFaculty = tempnam(sys_get_temp_dir(), 'cook_fac_');
$baseUrl = 'http://localhost:8000';

function curlReq(string $url, string $method = 'GET', $data = [], ?string $cookieFile = null, bool $follow = true): array {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if ($follow) curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    if ($cookieFile) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    }
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return ['code' => $code, 'body' => $resp];
}

function getCsrf(string $html): string {
    if (preg_match('/name="csrf"\s+value="([^"]+)"/', $html, $m)) return $m[1];
    if (preg_match('/value="([^"]+)"\s+name="csrf"/', $html, $m)) return $m[1];
    return '';
}

$resLogin = curlReq("$baseUrl/login.php", 'GET', [], $cookieFaculty);
$csrfLogin = getCsrf($resLogin['body']);
$postLogin = [
    'csrf'     => $csrfLogin,
    'email'    => 'faculty@atts.edu',
    'password' => 'faculty12',
    'role'     => 'Faculty',
];
curlReq("$baseUrl/login.php", 'POST', http_build_query($postLogin), $cookieFaculty, true);

$formResp = curlReq("$baseUrl/upload.php?type=mou", 'GET', [], $cookieFaculty);
$formHtml = $formResp['body'];

// Extract the form fields
if (preg_match('/<form[^>]*enctype="multipart\/form-data"[^>]*>([\s\S]*?)<\/form>/i', $formHtml, $mForm)) {
    echo "Form container found.\n";
    // Let's find all field labels inside this form:
    preg_match_all('/<label[^>]*>(.*?)<\/label>/i', $mForm[1], $mLabels);
    echo "Labels inside form:\n";
    foreach ($mLabels[1] as $idx => $lbl) {
        echo "  " . ($idx + 1) . ". " . trim(strip_tags($lbl)) . "\n";
    }
    
    // Check for Exam Session / Academic Session
    echo "\nHas 'Exam Session': " . (stripos($mForm[1], 'Exam Session') !== false ? 'YES' : 'NO') . "\n";
    echo "Has 'Academic Session': " . (stripos($mForm[1], 'Academic Session') !== false ? 'YES' : 'NO') . "\n";
} else {
    echo "Could not find form in HTML.\n";
}
