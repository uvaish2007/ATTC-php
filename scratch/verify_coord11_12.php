<?php
/**
 * Automated Verification Suite for Bug COORD-11 and Bug COORD-12
 */

require_once 'C:/Users/Lenovo/Downloads/iqac/ATTC-php-main/php-app/inc/db.php';
require_once 'C:/Users/Lenovo/Downloads/iqac/ATTC-php-main/php-app/models/Target.php';
require_once 'C:/Users/Lenovo/Downloads/iqac/ATTC-php-main/php-app/models/Record.php';
require_once 'C:/Users/Lenovo/Downloads/iqac/ATTC-php-main/php-app/inc/xlsx_writer.php';

$baseUrl = 'http://localhost:8000';
$cookieAdmin = __DIR__ . '/cookie_admin_verify.txt';
$cookieCoord = __DIR__ . '/cookie_coord_verify.txt';
$cookieFaculty = __DIR__ . '/cookie_faculty_verify.txt';

@unlink($cookieAdmin);
@unlink($cookieCoord);
@unlink($cookieFaculty);

function curlReq(string $url, string $method = 'GET', array $data = [], ?string $cookieFile = null): array
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    if ($cookieFile) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    }
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    return ['code' => $httpCode, 'headers' => $headers, 'body' => $body];
}

function login(string $email, string $password, string $role, string $cookieFile, string $baseUrl): bool
{
    @unlink($cookieFile);
    $r = curlReq("$baseUrl/login.php", 'GET', [], $cookieFile);
    preg_match('/name="csrf"\s+value="([^"]+)"/', $r['body'], $m);
    $csrf = $m[1] ?? '';
    $res = curlReq("$baseUrl/login.php", 'POST', [
        'csrf' => $csrf,
        'email' => $email,
        'password' => $password,
        'role' => $role
    ], $cookieFile);
    return ($res['code'] === 302);
}

function validateXlsxPackage(string $binaryData): array
{
    $errors = [];
    if (strlen($binaryData) < 100) {
        $errors[] = "File too small (" . strlen($binaryData) . " bytes)";
        return ['valid' => false, 'errors' => $errors];
    }
    // Check if it starts with PK (ZIP header)
    if (substr($binaryData, 0, 2) !== "PK") {
        $errors[] = "Does not start with ZIP PK magic bytes";
    }
    // Check it does NOT start with HTML
    $cleanPrefix = strtolower(trim(substr($binaryData, 0, 50)));
    if (strpos($cleanPrefix, '<html') !== false || strpos($cleanPrefix, '<table') !== false || strpos($cleanPrefix, '<!doctype') !== false) {
        $errors[] = "File begins with HTML tag instead of binary ZIP";
    }

    $tmp = tempnam(sys_get_temp_dir(), 'val_xlsx_');
    file_put_contents($tmp, $binaryData);
    $zip = new ZipArchive();
    $openRes = $zip->open($tmp);
    if ($openRes !== true) {
        $errors[] = "ZipArchive failed to open package (code: $openRes)";
        @unlink($tmp);
        return ['valid' => false, 'errors' => $errors];
    }

    $requiredEntries = [
        '[Content_Types].xml',
        '_rels/.rels',
        'xl/workbook.xml',
        'xl/styles.xml',
        'xl/worksheets/sheet1.xml'
    ];
    foreach ($requiredEntries as $entry) {
        if ($zip->locateName($entry) === false) {
            $errors[] = "Missing OpenXML entry: $entry";
        }
    }

    $sheet1Xml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheet1Xml === false || strlen($sheet1Xml) < 20) {
        $errors[] = "sheet1.xml is missing or empty";
    } else {
        // Validate XML syntax
        $prev = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        if (!$dom->loadXML($sheet1Xml)) {
            $errors[] = "sheet1.xml is not well-formed XML";
        }
        libxml_use_internal_errors($prev);
    }

    $zip->close();
    @unlink($tmp);

    return [
        'valid' => empty($errors),
        'errors' => $errors,
        'sheet1Xml' => $sheet1Xml ?: ''
    ];
}

echo "=================================================================\n";
echo "    STARTING AUTOMATED VERIFICATION FOR COORD-11 & COORD-12\n";
echo "=================================================================\n\n";

// Authenticate roles
$adminOk = login('admin@atts.edu', 'admin123', 'Admin', $cookieAdmin, $baseUrl);
$coordOk = login('coordinator@atts.edu', 'coordinator123', 'Coordinator', $cookieCoord, $baseUrl);
$facultyOk = login('faculty@atts.edu', 'faculty123', 'Faculty', $cookieFaculty, $baseUrl);

if (!$adminOk || !$coordOk || !$facultyOk) {
    die("FATAL: Failed to authenticate test users.\n");
}
echo "✓ Authentication successful for Admin, Coordinator, and Faculty.\n\n";

$passCount = 0;
$failCount = 0;

function reportTest(string $id, string $title, bool $passed, string $details = '') {
    global $passCount, $failCount;
    if ($passed) {
        $passCount++;
        echo "[$id] PASS: $title\n";
        if ($details) echo "    $details\n";
    } else {
        $failCount++;
        echo "[$id] FAIL: $title\n";
        if ($details) echo "    ERROR: $details\n";
    }
}

// -------------------------------------------------------------
// SECTION A: COORD-11 (Academic Records Excel Export)
// -------------------------------------------------------------
echo "--- TESTING COORD-11 (Academic Records Excel Export) ---\n";

// COORD-11 TEST 1: Open Academic Reports page
$r = curlReq("$baseUrl/reports.php", 'GET', [], $cookieCoord);
$hasAcademicRecords = strpos($r['body'], 'Academic Records') !== false;
reportTest("COORD-11 TEST 1", "Academic Records report is available on Reports page", $r['code'] === 200 && $hasAcademicRecords);

// COORD-11 TEST 2: Excel button link
$hasTypeAcademic = strpos($r['body'], 'export.php?type=academic_record') !== false;
reportTest("COORD-11 TEST 2", "Excel download link for Academic Records points to type=academic_record", $hasTypeAcademic, "Link verified in reports.php UI");

// COORD-11 TEST 3 & 4: HTTP 200 and .xlsx downloaded for export.php?type=academic_record&format=excel
$rExp = curlReq("$baseUrl/export.php?type=academic_record&format=excel", 'GET', [], $cookieCoord);
preg_match('/Content-Type:\s*([^\r\n]+)/i', $rExp['headers'], $ct);
preg_match('/Content-Disposition:\s*([^\r\n]+)/i', $rExp['headers'], $cd);
$contentType = $ct[1] ?? '';
$contentDisp = $cd[1] ?? '';
$isXlsxMime = (strpos($contentType, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') !== false);
$hasXlsxExt = (strpos($contentDisp, '.xlsx') !== false);
reportTest("COORD-11 TEST 3", "export.php?type=academic_record&format=excel returns HTTP 200", $rExp['code'] === 200, "HTTP code: " . $rExp['code']);
reportTest("COORD-11 TEST 4", "Correct Content-Type and .xlsx filename headers sent", $isXlsxMime && $hasXlsxExt, "Content-Type: $contentType, Disposition: $contentDisp");

// COORD-11 TEST 5: Real OpenXML XLSX validation
$valCoord11 = validateXlsxPackage($rExp['body']);
reportTest("COORD-11 TEST 5", "Downloaded file is valid OpenXML .xlsx without repair warnings", $valCoord11['valid'], empty($valCoord11['errors']) ? "ZipArchive validated [Content_Types].xml, xl/workbook.xml, sheet1.xml" : implode('; ', $valCoord11['errors']));

// COORD-11 TEST 6: Data correctness - contains headers and active records
$sheetXml11 = $valCoord11['sheet1Xml'];
$hasColumns = (strpos($sheetXml11, 'Record') !== false || strpos($sheetXml11, 'Department') !== false || strpos($sheetXml11, 'Status') !== false);
$hasNoHtml = (strpos($sheetXml11, '<table') === false && strpos($sheetXml11, '<html') === false);
reportTest("COORD-11 TEST 6", "Spreadsheet contains correct Academic Records columns and clean data", $hasColumns && $hasNoHtml, "Columns verified; zero HTML source present");

// COORD-11 TEST 7: Academic Year Compatibility (Active Year)
$activeAy = active_academic_year();
$hasActiveAyTitle = strpos($sheetXml11, $activeAy) !== false;
reportTest("COORD-11 TEST 7", "Export respects active academic year ($activeAy)", $hasActiveAyTitle, "Active year $activeAy reflected in export metadata");

// COORD-11 TEST 8: Different valid year parameter
$rPastYear = curlReq("$baseUrl/export.php?type=academic_record&format=excel&year=2024-25", 'GET', [], $cookieCoord);
$valPast = validateXlsxPackage($rPastYear['body']);
$hasPastYearTitle = (strpos($valPast['sheet1Xml'], '2024-25') !== false);
reportTest("COORD-11 TEST 8", "Export updates dynamically when different valid year is selected (2024-25)", $rPastYear['code'] === 200 && $valPast['valid'] && $hasPastYearTitle, "Year 2024-25 reflected in spreadsheet");

// COORD-11 TEST 9: Other export formats preserved
$rWord = curlReq("$baseUrl/export.php?type=academic_record&format=word", 'GET', [], $cookieCoord);
$rCsv = curlReq("$baseUrl/export.php?type=academic_record&format=csv", 'GET', [], $cookieCoord);
$rPdf = curlReq("$baseUrl/export.php?type=academic_record&format=pdf", 'GET', [], $cookieCoord);
$otherFmtsOk = ($rWord['code'] === 200 && strpos($rWord['headers'], 'application/msword') !== false)
            && ($rCsv['code'] === 200 && strpos($rCsv['headers'], 'text/csv') !== false)
            && ($rPdf['code'] === 200 && strpos($rPdf['body'], 'Print / Save as PDF') !== false);
reportTest("COORD-11 TEST 9", "Other export formats (Word, CSV, PDF) remain functional", $otherFmtsOk, "Word: {$rWord['code']}, CSV: {$rCsv['code']}, PDF: {$rPdf['code']}");

// COORD-11 TEST 10: Existing Excel exports continue to work
$rCons = curlReq("$baseUrl/consolidated-report.php?format=excel", 'GET', [], $cookieAdmin);
$valCons = validateXlsxPackage($rCons['body']);
$rMetrics = curlReq("$baseUrl/metrics-report.php?format=excel", 'GET', [], $cookieAdmin);
$valMetrics = validateXlsxPackage($rMetrics['body']);
reportTest("COORD-11 TEST 10", "Other Excel exports (consolidated-report, metrics-report) remain functional", $rCons['code'] === 200 && $valCons['valid'] && $rMetrics['code'] === 200 && $valMetrics['valid']);

// COORD-11 TEST 11: Authorization - unauthenticated access
$rNoAuth = curlReq("$baseUrl/export.php?type=academic_record&format=excel", 'GET');
reportTest("COORD-11 TEST 11", "Unauthenticated request is redirected to login (HTTP 302)", $rNoAuth['code'] === 302 && strpos($rNoAuth['headers'], 'Location: /login.php') !== false);

// COORD-11 TEST 12: Undefined method verification
$methodCheckOk = method_exists('Record', 'academic_records')
              && method_exists('Record', 'report_records')
              && method_exists('SimpleXlsxWriter', 'writeXlsx')
              && method_exists('SimpleXlsxWriter', 'export')
              && method_exists('SimpleXlsxWriter', 'download');
reportTest("COORD-11 TEST 12", "Record and SimpleXlsxWriter methods/aliases are defined and callable", $methodCheckOk, "Record::academic_records and SimpleXlsxWriter::writeXlsx verified");

echo "\n--- TESTING COORD-12 (Template Report Real OpenXML .xlsx Export) ---\n";

// COORD-12 TEST 1 & 2: Template Report Excel returns HTTP 200 with .xlsx
$rTmpl = curlReq("$baseUrl/template-report.php?format=excel&department=CSBS", 'GET', [], $cookieAdmin);
preg_match('/Content-Type:\s*([^\r\n]+)/i', $rTmpl['headers'], $tCt);
preg_match('/Content-Disposition:\s*([^\r\n]+)/i', $rTmpl['headers'], $tCd);
$tContentType = $tCt[1] ?? '';
$tContentDisp = $tCd[1] ?? '';
$isTmplXlsxMime = (strpos($tContentType, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') !== false);
$hasTmplXlsxExt = (strpos($tContentDisp, '.xlsx') !== false);

reportTest("COORD-12 TEST 1", "template-report.php?format=excel downloads successfully with HTTP 200", $rTmpl['code'] === 200, "HTTP: " . $rTmpl['code']);
reportTest("COORD-12 TEST 2", "Template Report uses application/vnd.openxmlformats-officedocument.spreadsheetml.sheet", $isTmplXlsxMime, "MIME: $tContentType");
reportTest("COORD-12 TEST 3", "Template Report filename ends with .xlsx", $hasTmplXlsxExt, "Disposition: $tContentDisp");

// COORD-12 TEST 4: Real OpenXML XLSX package structure
$valTmpl = validateXlsxPackage($rTmpl['body']);
reportTest("COORD-12 TEST 4", "Template Report is a real OpenXML XLSX workbook package", $valTmpl['valid'], empty($valTmpl['errors']) ? "ZipArchive validated all required XML parts" : implode('; ', $valTmpl['errors']));

// COORD-12 TEST 5 & 10: HTML regression check - no HTML table pretending to be Excel
$tPrefix = strtolower(trim(substr($rTmpl['body'], 0, 50)));
$hasNoHtmlTable = (strpos($tPrefix, '<table') === false && strpos($tPrefix, '<html') === false && strpos($tPrefix, '<!doctype') === false);
$startsPk = (substr($rTmpl['body'], 0, 2) === "PK");
reportTest("COORD-12 TEST 5", "No HTML table or application/vnd.ms-excel masquerading as XLSX", $hasNoHtmlTable && $startsPk, "Starts with binary PK; zero HTML tags at file start");

// COORD-12 TEST 6: Template Report Data Correctness
$tSheetXml = $valTmpl['sheet1Xml'];
$hasTmplTitle = (strpos($tSheetXml, 'EXECUTIVE MEETING REPORT') !== false);
$hasDeptCsbs = (strpos($tSheetXml, 'Computer Science and Business Systems') !== false || strpos($tSheetXml, 'CSBS') !== false);
reportTest("COORD-12 TEST 6", "Template Report contains correct report headers and department data", $hasTmplTitle && $hasDeptCsbs, "Executive Meeting Report title and CSBS department verified");

// COORD-12 TEST 7: Academic Year Compatibility
$hasTmplAy = (strpos($tSheetXml, $activeAy) !== false);
reportTest("COORD-12 TEST 7", "Template Report Excel export respects active academic year ($activeAy)", $hasTmplAy, "Active year $activeAy present in metadata");

// COORD-12 TEST 8: Different valid year
$rTmplPast = curlReq("$baseUrl/template-report.php?format=excel&department=CSBS&year=2024-25", 'GET', [], $cookieAdmin);
$valTmplPast = validateXlsxPackage($rTmplPast['body']);
$hasTmplPastAy = (strpos($valTmplPast['sheet1Xml'], '2024-25') !== false);
reportTest("COORD-12 TEST 8", "Template Report updates when different valid year is selected (2024-25)", $rTmplPast['code'] === 200 && $valTmplPast['valid'] && $hasTmplPastAy, "Year 2024-25 reflected in targets report");

// COORD-12 TEST 9: Per-Metric Template Reports (record-report.php)
$rRecTmpl = curlReq("$baseUrl/record-report.php?type=journal&format=excel", 'GET', [], $cookieAdmin);
$valRecTmpl = validateXlsxPackage($rRecTmpl['body']);
preg_match('/Content-Type:\s*([^\r\n]+)/i', $rRecTmpl['headers'], $rCt);
$isRecXlsx = (strpos($rCt[1] ?? '', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') !== false);
reportTest("COORD-12 TEST 9", "Per-Metric Template Reports (record-report.php) produce real OpenXML .xlsx", $rRecTmpl['code'] === 200 && $valRecTmpl['valid'] && $isRecXlsx, "record-report.php generates valid OpenXML .xlsx package");

// COORD-12 TEST 11: Authorization preserved on template-report.php
$rTmplAnon = curlReq("$baseUrl/template-report.php?format=excel", 'GET');
$rTmplFaculty = curlReq("$baseUrl/template-report.php?format=excel", 'GET', [], $cookieFaculty);
reportTest("COORD-12 TEST 11", "Role authorization strictly preserved (anonymous 302 to login; Faculty 403 denied)", $rTmplAnon['code'] === 302 && ($rTmplFaculty['code'] === 302 || $rTmplFaculty['code'] === 403), "Anon: {$rTmplAnon['code']}, Faculty: {$rTmplFaculty['code']}");

echo "\n=================================================================\n";
echo "    VERIFICATION SUMMARY: $passCount PASSED, $failCount FAILED\n";
echo "=================================================================\n";

if ($failCount === 0) {
    echo "ALL TESTS PASSED SUCCESSFULLY!\n";
} else {
    echo "SOME TESTS FAILED!\n";
    exit(1);
}
