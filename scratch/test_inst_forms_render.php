<?php
/**
 * HTTP functional test for institutional upload forms.
 * Simulates login via session + direct page output capture.
 */
require_once __DIR__ . '/../php-app/inc/db.php';

// Verify server is running
$urls = [
    'http://localhost:8000/php-app/upload.php?type=inst_pass_percentage',
    'http://localhost:8000/php-app/upload.php?type=inst_publications',
    'http://localhost:8000/php-app/upload.php?type=inst_placement_mnc',
    'http://localhost:8000/php-app/upload.php?type=inst_google_ratings',
    'http://localhost:8000/php-app/upload.php?type=inst_patents_granted',
    'http://localhost:8000/php-app/upload.php?type=inst_internships',
    'http://localhost:8000/php-app/upload.php?type=inst_startups',
];

// First login to get session cookie
$ch = curl_init('http://localhost:8000/php-app/login.php');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query(['email' => 'master@atts.edu', 'password' => 'master123', 'csrf' => '']),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_COOKIEFILE => '',
    CURLOPT_COOKIEJAR  => '',
    CURLOPT_HEADER => true,
]);
$resp = curl_exec($ch);
$cookies = [];
preg_match_all('/Set-Cookie:\s*([^;\r\n]+)/i', $resp, $m);
foreach ($m[1] as $c) { $cookies[] = $c; }
$cookieStr = implode('; ', $cookies);
curl_close($ch);

echo "Login cookies: " . (empty($cookies) ? 'none (expected — redirects)' : count($cookies) . ' set') . "\n\n";

$pass = 0; $fail = 0;
function chk(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "[PASS] $name\n"; }
    else      { $fail++; echo "[FAIL] $name" . ($detail ? " — $detail" : '') . "\n"; }
}

// For each URL, check that the page returns HTTP 200 or 302 (redirect to login is fine, means server is up)
foreach ($urls as $url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER => true,
        CURLOPT_COOKIE => $cookieStr,
        CURLOPT_TIMEOUT => 10,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $body = substr($resp, strpos($resp, "\r\n\r\n") ?: 0);
    curl_close($ch);

    $typeParam = parse_url($url, PHP_URL_QUERY);
    preg_match('/type=(\w+)/', $typeParam, $tm);
    $typeKey = $tm[1] ?? '?';

    $serverUp = $code > 0;
    chk("Server responds to $typeKey (HTTP $code)", $serverUp);
    
    // If authenticated (200), check form elements
    if ($code === 200) {
        chk("$typeKey — has <form tag", str_contains($body, '<form'));
        if ($typeKey === 'inst_pass_percentage') {
            chk("inst_pass_percentage — has instPpAddRow JS", str_contains($body, 'instPpAddRow'));
            chk("inst_pass_percentage — has pass_percentage_rows hidden input", str_contains($body, 'pass_percentage_rows'));
            chk("inst_pass_percentage — has multi-row table", str_contains($body, 'instPpTable') || str_contains($body, 'instPpBody'));
        }
    }
}

echo "\n";

// Direct PHP output test (bypass auth — check template rendering)
// Simulate the variables upload.php exposes to the form include
$selectedType = 'inst_pass_percentage';
$effectiveYear = '2026-27';
$user = ['id' => 1, 'name' => 'Test User', 'role' => 'Faculty', 'department' => 'CSE'];
$departments = [['id' => 1, 'name' => 'CSE']];
$activeYear = '2026-27';

ob_start();
try {
    require __DIR__ . '/../php-app/inc/inst_upload_forms.php';
    $html = ob_get_clean();
    chk("inst_pass_percentage form rendered without PHP error", strlen($html) > 100, 'Got ' . strlen($html) . ' bytes');
    chk("inst_pass_percentage — instPpAddRow function in output", str_contains($html, 'instPpAddRow'));
    chk("inst_pass_percentage — pass_percentage_rows input in output", str_contains($html, 'pass_percentage_rows'));
    chk("inst_pass_percentage — semester select in output", str_contains($html, 'name="semester"'));
    chk("inst_pass_percentage — programme input in output", str_contains($html, 'name="programme"'));
} catch (\Throwable $e) {
    ob_end_clean();
    chk("inst_pass_percentage form rendered without PHP error", false, $e->getMessage());
}

// Test inst_publications form
$selectedType = 'inst_publications';
ob_start();
try {
    require __DIR__ . '/../php-app/inc/inst_upload_forms.php';
    $html = ob_get_clean();
    chk("inst_publications form rendered", strlen($html) > 50);
    chk("inst_publications — journal_name input", str_contains($html, 'name="journal_name"'));
    chk("inst_publications — publication_type select", str_contains($html, 'name="publication_type"'));
    chk("inst_publications — Scopus option", str_contains($html, 'Scopus'));
} catch (\Throwable $e) {
    ob_end_clean();
    chk("inst_publications form rendered", false, $e->getMessage());
}

// Test inst_internships (duration validation)
$selectedType = 'inst_internships';
ob_start();
try {
    require __DIR__ . '/../php-app/inc/inst_upload_forms.php';
    $html = ob_get_clean();
    chk("inst_internships form rendered", strlen($html) > 50);
    chk("inst_internships — calcInt JS", str_contains($html, 'calcInt'));
    chk("inst_internships — duration_weeks input", str_contains($html, 'name="duration_weeks"'));
} catch (\Throwable $e) {
    ob_end_clean();
    chk("inst_internships form rendered", false, $e->getMessage());
}

// Test inst_student_cgpa (CGPA sync JS)
$selectedType = 'inst_student_cgpa';
ob_start();
try {
    require __DIR__ . '/../php-app/inc/inst_upload_forms.php';
    $html = ob_get_clean();
    chk("inst_student_cgpa form rendered", strlen($html) > 50);
    chk("inst_student_cgpa — syncCgpa JS", str_contains($html, 'syncCgpa'));
    chk("inst_student_cgpa — eligibility select", str_contains($html, 'name="eligibility"'));
} catch (\Throwable $e) {
    ob_end_clean();
    chk("inst_student_cgpa form rendered", false, $e->getMessage());
}

// Test inst_sports_state (level = State enforced)
$selectedType = 'inst_sports_state';
ob_start();
try {
    require __DIR__ . '/../php-app/inc/inst_upload_forms.php';
    $html = ob_get_clean();
    chk("inst_sports_state form rendered", strlen($html) > 50);
    chk("inst_sports_state — level = State readonly", str_contains($html, 'value="State"') && str_contains($html, 'readonly'));
} catch (\Throwable $e) {
    ob_end_clean();
    chk("inst_sports_state form rendered", false, $e->getMessage());
}

// Test inst_google_ratings (0-5 validation)
$selectedType = 'inst_google_ratings';
ob_start();
try {
    require __DIR__ . '/../php-app/inc/inst_upload_forms.php';
    $html = ob_get_clean();
    chk("inst_google_ratings form rendered", strlen($html) > 50);
    chk("inst_google_ratings — max=5 attribute", str_contains($html, 'max="5"'));
    chk("inst_google_ratings — measurement_date", str_contains($html, 'name="measurement_date"'));
} catch (\Throwable $e) {
    ob_end_clean();
    chk("inst_google_ratings form rendered", false, $e->getMessage());
}

echo "\n=== SUMMARY: {$pass} PASS, {$fail} FAIL ===\n";
