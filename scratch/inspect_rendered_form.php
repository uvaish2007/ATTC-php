<?php
require_once __DIR__ . '/../php-app/inc/env.php';
require_once __DIR__ . '/../php-app/inc/db.php';

$ch = curl_init('http://localhost:8000/upload.php?type=placement');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$html = curl_exec($ch);
if (preg_match('/<div class="field">\s*<label for="pay_scale">[\s\S]*?<\/div>\s*<\/div>/', $html, $m)) {
    echo "Found Pay Scale HTML block:\n" . $m[0] . "\n";
} else {
    echo "Pattern not matched. Searching for pay_scale:\n";
    if (preg_match('/pay_scale[\s\S]{0,300}/', $html, $m2)) {
        echo $m2[0] . "\n";
    }
}
if (preg_match('/saveDraft[\s\S]{0,100}/', $html, $m3)) {
    echo "Found saveDraft:\n" . $m3[0] . "\n";
} else {
    echo "saveDraft not found in HTML (maybe unauthenticated redirect to login?)\n";
    echo "Page title: ";
    if (preg_match('/<title>(.*?)<\/title>/', $html, $mt)) echo $mt[1] . "\n";
}
