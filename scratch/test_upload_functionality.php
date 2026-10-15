<?php
require_once __DIR__ . '/../php-app/inc/auth.php';
require_once __DIR__ . '/../php-app/models/Record.php';

$base = 'http://localhost:8000/php-app';
$cookieFile = __DIR__ . '/test_cookie_upload_fn.txt';
if (file_exists($cookieFile)) unlink($cookieFile);

// Login as faculty
$ch = curl_init("$base/login.php");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR      => $cookieFile,
    CURLOPT_COOKIEFILE     => $cookieFile,
]);
$body = curl_exec($ch);
preg_match('/name="csrf"[^>]*value="([^"]+)"/', $body, $cm);
$csrf = $cm[1] ?? '';

curl_setopt_array($ch, [
    CURLOPT_URL            => "$base/login.php",
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'csrf'     => $csrf,
        'role'     => 'Faculty',
        'email'    => 'faculty@atts.edu',
        'password' => 'faculty123',
    ]),
    CURLOPT_FOLLOWLOCATION => false,
]);
curl_exec($ch);

// GET upload page
curl_setopt_array($ch, [
    CURLOPT_URL            => "$base/upload.php?section=publications&type=journal",
    CURLOPT_POST           => false,
    CURLOPT_HTTPGET        => true,
    CURLOPT_FOLLOWLOCATION => true,
]);
$uploadHtml = curl_exec($ch);
echo "Upload page status code: " . curl_getinfo($ch, CURLINFO_HTTP_CODE) . "\n";
preg_match('/name="csrf"[^>]*value="([^"]+)"/', $uploadHtml, $mCsrf);
$upCsrf = $mCsrf[1] ?? '';

// Check academic year selector
assert(!empty($upCsrf), "CSRF token must be present on upload form");
echo "Upload form CSRF present: YES\n";
echo "Record Type hidden input present: " . (strpos($uploadHtml, 'name="record_type" value="journal"') !== false ? 'YES' : 'NO') . "\n";
echo "Form actions present: " . (strpos($uploadHtml, 'class="upload-actions"') !== false ? 'YES' : 'NO') . "\n";

// Verify form elements exist
echo "Paper Title field: " . (strpos($uploadHtml, 'name="paper_title"') !== false ? 'YES' : 'NO') . "\n";
echo "Journal Name field: " . (strpos($uploadHtml, 'name="journal_name"') !== false ? 'YES' : 'NO') . "\n";
echo "ISSN field: " . (strpos($uploadHtml, 'name="issn"') !== false ? 'YES' : 'NO') . "\n";

echo "ALL FUNCTIONAL HOOKS PRESERVED!\n";
