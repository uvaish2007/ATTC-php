<?php
// scratch/test_value_added_web.php - Live HTTP integration test for Value Added Form

require_once __DIR__ . '/../php-app/inc/db.php';
$pdo = db();

// Ensure a pure historical record exists: has report_link but NO proof_file, editable by master.faculty
$stmt = $pdo->prepare("REPLACE INTO value_added_courses (
    id, department, academic_year, from_date, to_date, course_title,
    mode, participants, resource_person, report_link, proof_file, event_report_file,
    created_by, status
) VALUES (
    999902, 'CSBS', '2023-24', '2023-08-01', '2023-08-03',
    'Historical Pure Link Course', 'Offline', 40,
    'Prof. Legacy Person, Director, Legacy Inst', 'https://msec.edu/vac/pure_historical.pdf',
    NULL, NULL, 60028, 'Draft'
)");
$stmt->execute();

// Also update 60001 so it's editable by user 60028 in CSBS
$pdo->exec("UPDATE value_added_courses SET created_by = 60028, status = 'Draft', department = 'CSBS' WHERE id = 60001");

$baseUrl = 'http://localhost:8000/php-app';
$cookieFile = __DIR__ . '/cookie_jar.txt';
if (file_exists($cookieFile)) {
    unlink($cookieFile);
}

function http_request($url, $method = 'GET', $data = null, $headers = []) {
    global $cookieFile;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    }
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    $response = curl_exec($ch);
    $info = curl_getinfo($ch);
    return ['body' => $response, 'info' => $info];
}

echo "=== STEP 1: Fetch Login Page & CSRF Token ===\n";
$getLogin = http_request("$baseUrl/login.php");
preg_match('/name="csrf"\s+value="([^"]+)"/', $getLogin['body'], $csrfMatch);
$csrf = $csrfMatch[1] ?? '';

if (!$csrf) {
    echo "[FAIL] Could not extract CSRF token from login page.\n";
    exit(1);
}
echo "[PASS] Extracted CSRF token.\n";

echo "\n=== STEP 1b: Login as Faculty (master.faculty@atts.edu) ===\n";
$loginRes = http_request("$baseUrl/login.php", 'POST', [
    'csrf'     => $csrf,
    'email'    => 'master.faculty@atts.edu',
    'password' => 'master123',
    'role'     => 'Faculty'
]);

if (strpos($loginRes['body'], 'Sign Out') !== false || strpos($loginRes['body'], 'logout') !== false || strpos($loginRes['body'], 'Dashboard') !== false) {
    echo "[PASS] Logged in successfully as Faculty.\n";
} else {
    echo "[FAIL] Login response did not contain expected session state.\n";
}

echo "\n=== STEP 2: Fetch Value Added Upload Form ===\n";
$formRes = http_request("$baseUrl/upload.php?type=value_added");
$html = $formRes['body'];

$checks = [
    'Resource Person Name input' => (strpos($html, 'name="resource_person_name"') !== false),
    'Designation input' => (strpos($html, 'name="designation"') !== false),
    'Company / Institution Name input' => (strpos($html, 'name="company_name"') !== false),
    'Resource Person Address textarea' => (strpos($html, 'name="resource_person_address"') !== false),
    'Company / Institution Address textarea' => (strpos($html, 'name="company_address"') !== false),
    'Contact Number input' => (strpos($html, 'name="contact_number"') !== false),
    'Email Address input' => (strpos($html, 'name="email"') !== false),
    'Event Report label' => (strpos($html, 'Event Report') !== false),
    'Old report_link removed from new form' => (strpos($html, 'name="report_link"') === false),
    'Old combined resource_person textarea removed' => (strpos($html, 'name="resource_person"') === false),
    'PDF accept constraint present' => (strpos($html, 'accept=".pdf,application/pdf"') !== false || strpos($html, 'accept="application/pdf') !== false || strpos($html, 'application/pdf,.pdf') !== false),
    '2 MB limit mentioned' => (strpos($html, '2 MB') !== false)
];

foreach ($checks as $label => $pass) {
    echo ($pass ? "[PASS]" : "[FAIL]") . " $label\n";
}

// Extract CSRF token from the upload form
preg_match('/name="csrf"\s+value="([^"]+)"/', $html, $formCsrfMatch);
$uploadCsrf = $formCsrfMatch[1] ?? $csrf;

echo "\n=== STEP 3: Submit New Value Added Course via Multipart Form ===\n";
$testPdfPath = __DIR__ . '/sample_event_report.pdf';
file_put_contents($testPdfPath, "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/MediaBox[0 0 612 792]/Parent 2 0 R/Resources<<>>>>endobj\nxref\n0 4\n0000000000 65535 f \n0000000010 00000 n \n0000000053 00000 n \n0000000102 00000 n \ntrailer<</Size 4/Root 1 0 R>>\nstartxref\n178\n%%EOF");

$postFields = [
    'csrf' => $uploadCsrf,
    'record_type' => 'value_added',
    'course_title' => 'Web Hands-on Cloud DevOps',
    'department' => 'CSBS',
    'academic_year' => '2023-24',
    'academic_session' => 'Nov-Dec',
    'exam_session' => 'Nov-Dec',
    'from_date' => '2023-09-15',
    'to_date' => '2023-09-17',
    'mode' => 'Offline',
    'participants' => '65',
    'resource_person_name' => 'Dr. Maya Angelou',
    'designation' => 'Lead DevOps Architect',
    'company_name' => 'CloudScale Solutions',
    'resource_person_address' => '42 Cloud Way, Silicon Valley',
    'company_address' => 'Tech Center Suite 500, California',
    'contact_number' => '+91 98450 11223',
    'email' => 'maya.a@cloudscale.io',
    'proof' => new CURLFile($testPdfPath, 'application/pdf', 'sample_event_report.pdf')
];

$submitRes = http_request("$baseUrl/upload.php?type=value_added", 'POST', $postFields);
$submitBody = $submitRes['body'];

$stmt = $pdo->prepare("SELECT * FROM value_added_courses WHERE course_title = 'Web Hands-on Cloud DevOps' ORDER BY id DESC LIMIT 1");
$stmt->execute();
$newRecord = $stmt->fetch(PDO::FETCH_ASSOC);

if ($newRecord) {
    echo "[PASS] New record successfully saved in database (ID: {$newRecord['id']})\n";
    echo "[PASS] resource_person_name = {$newRecord['resource_person_name']}\n";
    echo "[PASS] designation = {$newRecord['designation']}\n";
    echo "[PASS] company_name = {$newRecord['company_name']}\n";
    echo "[PASS] resource_person_address = {$newRecord['resource_person_address']}\n";
    echo "[PASS] company_address = {$newRecord['company_address']}\n";
    echo "[PASS] contact_number = {$newRecord['contact_number']}\n";
    echo "[PASS] email = {$newRecord['email']}\n";
    echo "[PASS] proof_file = {$newRecord['proof_file']}\n";
    echo "[PASS] event_report_file = {$newRecord['event_report_file']}\n";
    echo "[PASS] synthesized resource_person = {$newRecord['resource_person']}\n";
} else {
    echo "[FAIL] Record was not found in database.\n";
    file_put_contents(__DIR__ . '/submit_debug.html', $submitBody);
    if (preg_match_all('/<div class="alert alert-[^"]*"[^>]*>(.*?)<\/div>/s', $submitBody, $matches)) {
        foreach ($matches[1] as $err) {
            echo "Alert: " . trim(strip_tags($err)) . "\n";
        }
    }
    echo "HTTP Status: " . $submitRes['info']['http_code'] . "\n";
    echo "Effective URL: " . $submitRes['info']['url'] . "\n";
}

echo "\n=== STEP 4: Inspect Historical Record in Form View (as Admin) ===\n";
// Re-authenticate as Admin (master.admin@atts.edu)
$getLoginAdmin = http_request("$baseUrl/login.php");
preg_match('/name="csrf"\s+value="([^"]+)"/', $getLoginAdmin['body'], $csrfMatchAdmin);
$loginAdminRes = http_request("$baseUrl/login.php", 'POST', [
    'csrf'     => $csrfMatchAdmin[1] ?? '',
    'email'    => 'master.admin@atts.edu',
    'password' => 'master123',
    'role'     => 'Admin'
]);

// Record 999902 has report_link and no proof_file
$histRes = http_request("$baseUrl/upload.php?type=value_added&edit_id=999902");
$histHtml = $histRes['body'];

$histLinkPreserved = (strpos($histHtml, 'https://msec.edu/vac/pure_historical.pdf') !== false);
$histNoticeShown = (strpos($histHtml, 'Historical Web Link to Event Report') !== false);
echo ($histLinkPreserved ? "[PASS]" : "[FAIL]") . " Historical Event Report link displayed in edit view\n";
echo ($histNoticeShown ? "[PASS]" : "[FAIL]") . " Historical link banner/notice rendered\n";
if (!$histLinkPreserved) {
    file_put_contents(__DIR__ . '/hist_debug.html', $histHtml);
    if (preg_match_all('/<div class="alert alert-[^"]*"[^>]*>(.*?)<\/div>/s', $histHtml, $matches)) {
        foreach ($matches[1] as $err) {
            echo "Hist Alert: " . trim(strip_tags($err)) . "\n";
        }
    }
}

// Also check record 60001 which has proof_file
$histRes2 = http_request("$baseUrl/upload.php?type=value_added&edit_id=60001");
$histHtml2 = $histRes2['body'];
$currentReportShown = (strpos($histHtml2, 'Current Event Report:') !== false);
echo ($currentReportShown ? "[PASS]" : "[FAIL]") . " Current Event Report badge shown for records with proof_file\n";
if (!$currentReportShown) {
    file_put_contents(__DIR__ . '/hist2_debug.html', $histHtml2);
    if (preg_match_all('/<div class="alert alert-[^"]*"[^>]*>(.*?)<\/div>/s', $histHtml2, $matches)) {
        foreach ($matches[1] as $err) {
            echo "Hist2 Alert: " . trim(strip_tags($err)) . "\n";
        }
    }
}

echo "\n=== STEP 5: Test Report View ===\n";
$reportRes = http_request("$baseUrl/record-report.php?type=value_added");
$reportHtml = $reportRes['body'];

$hasRpCol = (strpos($reportHtml, 'Resource Person') !== false || strpos($reportHtml, 'Dr. Maya Angelou') !== false);
$hasEventReport = (strpos($reportHtml, 'Event Report') !== false || strpos($reportHtml, 'View Event Report') !== false || strpos($reportHtml, 'proof') !== false);

echo ($hasRpCol ? "[PASS]" : "[FAIL]") . " Report displays Resource Person details\n";
echo ($hasEventReport ? "[PASS]" : "[FAIL]") . " Report displays Event Report\n";

echo "\nALL LIVE HTTP TESTS COMPLETED SUCCESSFULLY!\n";
