<?php
// Test live dev server via cURL with real session cookie
$cookieFile = sys_get_temp_dir() . '/atts_test_cookie.txt';
@unlink($cookieFile);

// 0. Initial GET to obtain session cookie and csrf_token
$ch = curl_init('http://localhost:8000/php-app/login.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
$getRes = curl_exec($ch);
curl_close($ch);

preg_match('/name=["\']csrf["\']\s+value=["\']([^"\']+)["\']/', $getRes, $matches);
$csrfToken = $matches[1] ?? '';

// 1. Login as Faculty (VR / CSBS)
$ch = curl_init('http://localhost:8000/php-app/login.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'csrf'     => $csrfToken,
    'email'    => 'master.faculty@atts.edu',
    'password' => 'master123',
    'role'     => 'Faculty',
]));
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
$loginRes = curl_exec($ch);
$loginCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "1. Faculty Login HTTP Status: {$loginCode}\n";
if (str_contains($loginRes, 'class="alert')) {
    preg_match('/<div class="alert[^>]*>(.*?)<\/div>/s', $loginRes, $errMatch);
    echo "Login Alert: " . trim(strip_tags($errMatch[1] ?? 'unknown')) . "\n";
}
preg_match('/<title>(.*?)<\/title>/', $loginRes, $titleMatch);
echo "Page Title after login: " . ($titleMatch[1] ?? 'unknown') . "\n";

// 2. Fetch Student Online Certification Upload Form
$ch = curl_init('http://localhost:8000/php-app/upload.php?section=student_certifications&type=inst_student_certifications');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
$html1 = curl_exec($ch);
$code1 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "2. Student Online Certification HTTP Status: {$code1}, Length: " . strlen($html1) . " bytes\n";
echo "   - Contains 'Individual Entry': " . (str_contains($html1, 'Individual Entry') ? "PASS" : "FAIL") . "\n";
echo "   - Contains 'Bulk Upload': " . (str_contains($html1, 'Bulk Upload') ? "PASS" : "FAIL") . "\n";
echo "   - Contains 'Download Student Online Certification Template': " . (str_contains($html1, 'Download Student Online Certification Template') ? "PASS" : "FAIL") . "\n";
echo "   - Contains 'certBulkDropzone': " . (str_contains($html1, 'certBulkDropzone') ? "PASS" : "FAIL") . "\n";
echo "   - Contains 'certPreviewTable': " . (str_contains($html1, 'certPreviewTable') ? "PASS" : "FAIL") . "\n";

// 3. Fetch IIT-Bombay Spoken Tutorials Upload Form
$ch = curl_init('http://localhost:8000/php-app/upload.php?section=spoken_tutorials&type=inst_spoken_tutorials');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
$html2 = curl_exec($ch);
$code2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "3. IIT-Bombay Spoken Tutorials HTTP Status: {$code2}, Length: " . strlen($html2) . " bytes\n";
echo "   - Contains 'Individual Entry': " . (str_contains($html2, 'Individual Entry') ? "PASS" : "FAIL") . "\n";
echo "   - Contains 'Bulk Upload': " . (str_contains($html2, 'Bulk Upload') ? "PASS" : "FAIL") . "\n";
echo "   - Contains 'Download IIT-Bombay Spoken Tutorial Template': " . (str_contains($html2, 'Download IIT-Bombay Spoken Tutorial Template') ? "PASS" : "FAIL") . "\n";
echo "   - Contains 'tutBulkDropzone': " . (str_contains($html2, 'tutBulkDropzone') ? "PASS" : "FAIL") . "\n";
echo "   - Contains 'tutPreviewTable': " . (str_contains($html2, 'tutPreviewTable') ? "PASS" : "FAIL") . "\n";

// 4. Download Student Online Certification Template directly via cURL
$ch = curl_init('http://localhost:8000/php-app/bulk-import.php?action=template&module=student_certification');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
$tplContent1 = curl_exec($ch);
$tplCode1 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$tplType1 = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

echo "4. Student Online Certification Template Download Status: {$tplCode1}, Size: " . strlen($tplContent1) . " bytes\n";
echo "   - Content-Type: {$tplType1}\n";
echo "   - Valid XLSX Magic (PK): " . (str_starts_with($tplContent1, "PK\x03\x04") ? "PASS" : "FAIL") . "\n";

// 5. Download IIT-Bombay Spoken Tutorial Template directly via cURL
$ch = curl_init('http://localhost:8000/php-app/bulk-import.php?action=template&module=spoken_tutorial');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
$tplContent2 = curl_exec($ch);
$tplCode2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$tplType2 = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

echo "5. IIT-Bombay Spoken Tutorial Template Download Status: {$tplCode2}, Size: " . strlen($tplContent2) . " bytes\n";
echo "   - Content-Type: {$tplType2}\n";
echo "   - Valid XLSX Magic (PK): " . (str_starts_with($tplContent2, "PK\x03\x04") ? "PASS" : "FAIL") . "\n";

// 6. Test Coordinator Role Access
@unlink($cookieFile);
$ch = curl_init('http://localhost:8000/php-app/login.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
$getRes = curl_exec($ch);
curl_close($ch);

preg_match('/name=["\']csrf["\']\s+value=["\']([^"\']+)["\']/', $getRes, $matches);
$coordCsrf = $matches[1] ?? '';

$ch = curl_init('http://localhost:8000/php-app/login.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'csrf'     => $coordCsrf,
    'email'    => 'master.coordinator@atts.edu',
    'password' => 'master123',
    'role'     => 'Coordinator',
]));
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_exec($ch);
$coordLoginCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "\n6. Coordinator Login HTTP Status: {$coordLoginCode}\n";

$ch = curl_init('http://localhost:8000/php-app/upload.php?section=student_certifications&type=inst_student_certifications');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
$coordHtml1 = curl_exec($ch);
curl_close($ch);

echo "   - Coordinator Certification Form: " . (str_contains($coordHtml1, 'Bulk Upload') ? "PASS" : "FAIL") . "\n";

$ch = curl_init('http://localhost:8000/php-app/upload.php?section=spoken_tutorials&type=inst_spoken_tutorials');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
$coordHtml2 = curl_exec($ch);
curl_close($ch);

echo "   - Coordinator Spoken Tutorial Form: " . (str_contains($coordHtml2, 'Bulk Upload') ? "PASS" : "FAIL") . "\n";

@unlink($cookieFile);
