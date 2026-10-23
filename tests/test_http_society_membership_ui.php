<?php
/**
 * HTTP Integration Test: Verifies HTML served by the live server at localhost:8000
 * for Membership in Professional Societies.
 */

$cookieJar = tempnam(sys_get_temp_dir(), 'atts_cookie_');

// 1. Fetch login page to get CSRF token
$ch = curl_init('http://localhost:8000/php-app/login.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
$loginHtml = curl_exec($ch);

preg_match('/name="csrf"\s+value="([^"]+)"/', $loginHtml, $mCsrf);
$csrfToken = $mCsrf[1] ?? '';

// 2. Perform login as Faculty
$postData = http_build_query([
    'csrf'     => $csrfToken,
    'email'    => 'faculty@atts.edu',
    'password' => 'faculty12',
    'role'     => 'Faculty',
]);

$ch = curl_init('http://localhost:8000/php-app/login.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
$postLoginHtml = curl_exec($ch);

$ch = curl_init('http://localhost:8000/php-app/upload.php?section=society_memberships&type=inst_society_memberships');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
$uploadHtml = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
echo "Upload HTTP Code: {$httpCode}, Effective URL: {$effectiveUrl}, HTML Length: " . strlen($uploadHtml) . "\n";
if (strlen($uploadHtml) < 1000) {
    echo "Response snippet:\n" . substr($uploadHtml, 0, 500) . "\n";
}
@unlink($cookieJar);

// 4. Assertions on rendered HTML
$tests = [
    'Membership Category Dropdown' => str_contains($uploadHtml, 'id="socCategorySelect"') && str_contains($uploadHtml, 'name="membership_category"') && str_contains($uploadHtml, 'value="Faculty"') && str_contains($uploadHtml, 'value="Student"'),
    'Input Mode Selector' => str_contains($uploadHtml, 'Individual Entry') && str_contains($uploadHtml, 'Bulk Upload') && str_contains($uploadHtml, 'id="socBtnModeInd"') && str_contains($uploadHtml, 'id="socBtnModeBulk"'),
    'Faculty Fields Row' => str_contains($uploadHtml, 'id="socFacultyFieldsRow"'),
    'Student Fields Row' => str_contains($uploadHtml, 'id="socStudentFieldsRow"'),
    'Student Register Number Field' => str_contains($uploadHtml, 'id="socInputRegNo"') && str_contains($uploadHtml, 'Student Register Number'),
    'Student Name Field' => str_contains($uploadHtml, 'id="socInputStudentName"') && str_contains($uploadHtml, 'Student Name'),
    'Certificate Label in Form' => str_contains($uploadHtml, 'Certificate') && (str_contains($uploadHtml, 'PDF') || str_contains($uploadHtml, 'pdf')),
    'Template Download Button' => str_contains($uploadHtml, 'id="socBtnDownloadTemplate"') && str_contains($uploadHtml, 'Download Faculty Membership Template (.xlsx)'),
    'Student Template String' => str_contains($uploadHtml, 'Download Student Membership Template (.xlsx)'),
    'Student Lookup Script' => str_contains($uploadHtml, 'action=student_lookup'),
    'Category Switch Warning Script' => str_contains($uploadHtml, 'Switching membership category will clear the entered'),
];

echo "=== LIVE HTTP UI VERIFICATION (Faculty: faculty@atts.edu) ===\n\n";
$allPass = true;
foreach ($tests as $name => $passed) {
    if ($passed) {
        echo "[PASS] {$name}\n";
    } else {
        echo "[FAIL] {$name}\n";
        $allPass = false;
    }
}

// 5. Test Coordinator access
$cookieJarCoord = tempnam(sys_get_temp_dir(), 'atts_coord_');
$ch = curl_init('http://localhost:8000/php-app/login.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJarCoord);
$loginHtml = curl_exec($ch);
preg_match('/name="csrf"\s+value="([^"]+)"/', $loginHtml, $mCsrf);
$csrfToken = $mCsrf[1] ?? '';

$postDataCoord = http_build_query([
    'csrf'     => $csrfToken,
    'email'    => 'coordinator@atts.edu',
    'password' => 'coord1234',
    'role'     => 'Coordinator',
]);

$ch = curl_init('http://localhost:8000/php-app/login.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postDataCoord);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJarCoord);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJarCoord);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_exec($ch);

$ch = curl_init('http://localhost:8000/php-app/upload.php?section=society_memberships&type=inst_society_memberships');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJarCoord);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
$coordUploadHtml = curl_exec($ch);
$httpCodeCoord = curl_getinfo($ch, CURLINFO_HTTP_CODE);
@unlink($cookieJarCoord);

$coordPass = ($httpCodeCoord === 200) && str_contains($coordUploadHtml, 'id="socCategorySelect"') && str_contains($coordUploadHtml, 'id="socBtnModeBulk"');
echo "\n=== LIVE HTTP UI VERIFICATION (Coordinator: coordinator@atts.edu) ===\n\n";
echo ($coordPass ? "[PASS] Coordinator Access & Society Form Rendered\n" : "[FAIL] Coordinator Access\n");
if (!$coordPass) $allPass = false;

echo "\nResult: " . ($allPass ? "ALL LIVE HTTP CHECKS PASSED!\n" : "SOME FAILED!\n");
exit($allPass ? 0 : 1);
