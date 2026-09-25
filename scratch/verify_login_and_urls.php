<?php
/**
 * Test HTTP responses for presentation and report pages via session cookie.
 */
require_once __DIR__ . '/../php-app/inc/auth.php';

function testHttpUser(string $email, string $password, string $role) {
    echo "--- Testing HTTP Session for $email ($role) ---\n";
    $cookieFile = __DIR__ . '/cookie_' . preg_replace('/[^a-zA-Z0-9]/', '', $email) . '.txt';
    if (file_exists($cookieFile)) @unlink($cookieFile);
    
    // Step 1: GET login page to get CSRF token
    $ch1 = curl_init('http://localhost:8000/php-app/login.php');
    curl_setopt($ch1, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch1, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch1, CURLOPT_COOKIEFILE, $cookieFile);
    $loginHtml = curl_exec($ch1);
    curl_close($ch1);
    
    preg_match('/name="csrf"\s+value="([^"]+)"/', $loginHtml, $csrfMatches);
    $csrfToken = $csrfMatches[1] ?? '';
    
    // Step 2: POST login
    $ch2 = curl_init('http://localhost:8000/php-app/login.php');
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_POST, true);
    curl_setopt($ch2, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch2, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch2, CURLOPT_POSTFIELDS, http_build_query([
        'csrf' => $csrfToken,
        'email' => $email,
        'password' => $password,
        'role' => $role,
    ]));
    curl_setopt($ch2, CURLOPT_HEADER, true);
    $res = curl_exec($ch2);
    $httpCode = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    echo "Login POST HTTP Code: $httpCode\n";
    preg_match('/class="alert alert-error">.*?<span>(.*?)<\/span>/s', $res, $eMatch);
    if (!empty($eMatch[1])) echo "Error found: " . trim($eMatch[1]) . "\n";
    curl_close($ch2);
    
    // Step 3: Fetch Executive Meeting Report Page
    $ch3 = curl_init('http://localhost:8000/php-app/executive-meeting-report.php');
    curl_setopt($ch3, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch3, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch3, CURLOPT_COOKIEFILE, $cookieFile);
    $htmlReport = curl_exec($ch3);
    
    // Check if Department indicator is present
    $hasIndicator = strpos($htmlReport, 'Department automatically determined from your login') !== false;
    echo "Report page has read-only Department indicator: " . ($hasIndicator ? "PASS" : "FAIL") . "\n";
    
    // Step 4: Fetch Presentation page
    $ch4 = curl_init('http://localhost:8000/php-app/present-executive-meeting.php');
    curl_setopt($ch4, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch4, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch4, CURLOPT_COOKIEFILE, $cookieFile);
    $htmlPresent = curl_exec($ch4);
    
    $hasSlides = strpos($htmlPresent, 'const slides =') !== false;
    $has10s = strpos($htmlPresent, 'const AUTO_ADVANCE_MS = 10000;') !== false;
    echo "Presentation page loaded slides: " . ($hasSlides ? "PASS" : "FAIL") . "\n";
    echo "Presentation page has 10-second timer: " . ($has10s ? "PASS" : "FAIL") . "\n";
    
    // Step 5: Test URL tampering: ?department=ECE
    $ch5 = curl_init('http://localhost:8000/php-app/present-executive-meeting.php?department=ECE');
    curl_setopt($ch5, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch5, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch5, CURLOPT_COOKIEFILE, $cookieFile);
    $htmlTampered = curl_exec($ch5);
    
    // Check what department appears in slides JSON
    preg_match('/const slides = (\[.*?\]);/s', $htmlTampered, $sMatch);
    if (!empty($sMatch[1])) {
        $slidesData = json_decode($sMatch[1], true);
        $deptInSlide1 = $slidesData[0]['summary']['Department'] ?? '';
        echo "Tampering attempt ?department=ECE resulted in Department: $deptInSlide1\n";
    }
}

testHttpUser('cse_hod@atts.local', 'hod123', 'HoD');
echo "\n";
testHttpUser('hod@atts.edu', 'hod123', 'HoD');
