<?php
/**
 * Automated Verification for BUG-REP-16:
 * Consolidated All-Department Report multi-tab export and access control.
 *
 * Requirements:
 * 1. Admin, Dean, Principal have access to 'Consolidated All-Department Report'.
 * 2. Unauthorized roles (HoD, Faculty) are blocked (HTTP 403) and do not see the export in reports.php.
 * 3. Excel export generates a valid multi-tab .xlsx file with:
 *    - Sheet 1: College Overview (institutional summary of all departments, totals, signoff)
 *    - Sheets 2..N: Department-wise tabs (records grouped department-wise, subtotal, signoff)
 * 4. Word (.doc) and PDF (?format=pdf) exports generate full college data grouped department-wise.
 */

require_once __DIR__ . '/../php-app/inc/config.php';
require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/inc/helpers.php';
require_once __DIR__ . '/../php-app/inc/xlsx_writer.php';

$baseUrl = 'http://localhost:8000/php-app';

class HttpSession {
    private string $cookieFile;
    public int $lastHttpCode = 0;
    public string $lastUrl = '';
    public string $lastHeaders = '';

    public function __construct() {
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'atts_sess_');
    }

    public function __destruct() {
        if (file_exists($this->cookieFile)) {
            @unlink($this->cookieFile);
        }
    }

    public function request(string $url, string $method = 'GET', array $data = [], bool $followLocation = false): string {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $followLocation);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookieFile);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }

        $raw = curl_exec($ch);
        $this->lastHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $this->lastUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $this->lastHeaders = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);

        return $body ?: '';
    }

    public function extractCsrf(string $html): string {
        if (preg_match('/name="csrf"\s+value="([^"]+)"/', $html, $m)) {
            return $m[1];
        }
        if (preg_match('/value="([^"]+)"\s+name="csrf"/', $html, $m)) {
            return $m[1];
        }
        return '';
    }

    public function login(string $email, string $password, string $role): bool {
        global $baseUrl;
        $html = $this->request("{$baseUrl}/login.php", 'GET', [], true);
        $csrf = $this->extractCsrf($html);

        $this->request("{$baseUrl}/login.php", 'POST', [
            'email' => $email,
            'password' => $password,
            'role' => $role,
            'csrf' => $csrf,
        ], false);

        return in_array($this->lastHttpCode, [200, 302], true);
    }
}

$tests = [];
function record_test(string $name, bool $passed, string $details = '') {
    global $tests;
    $tests[] = ['name' => $name, 'passed' => $passed, 'details' => $details];
    echo ($passed ? "[PASS] " : "[FAIL] ") . $name . ($details ? " - {$details}" : "") . "\n";
}

echo "========================================================\n";
echo "Testing BUG-REP-16: Consolidated All-Department Report\n";
echo "========================================================\n";

// 1. Authenticate users
$admin = new HttpSession();
$adminLoggedIn = $admin->login('admin.test@atts.local', 'pass123', 'Admin');
record_test("Admin Login", $adminLoggedIn, "Admin session initiated");

$dean = new HttpSession();
$deanLoggedIn = $dean->login('dean@atts.edu', 'dean123', 'Dean');
record_test("Dean Login", $deanLoggedIn, "Dean session initiated");

$principal = new HttpSession();
$principalLoggedIn = $principal->login('director@atts.edu', 'principal123', 'Principal');
record_test("Principal Login", $principalLoggedIn, "Principal session initiated");

$hod = new HttpSession();
$hodLoggedIn = $hod->login('hod.cse@atts.local', 'pass123', 'HoD');
record_test("HoD Login", $hodLoggedIn, "HoD session initiated");

$faculty = new HttpSession();
$facultyLoggedIn = $faculty->login('faculty@atts.edu', 'faculty123', 'Faculty');
record_test("Faculty Login", $facultyLoggedIn, "Faculty session initiated");

// 2. Permission / RBAC checks on consolidated-report.php
$hodBody = $hod->request("{$baseUrl}/consolidated-report.php?format=excel", 'GET');
record_test(
    "HoD Role Denied Access (HTTP 403)",
    $hod->lastHttpCode === 403,
    "Status code: {$hod->lastHttpCode}"
);

$facultyBody = $faculty->request("{$baseUrl}/consolidated-report.php?format=excel", 'GET');
record_test(
    "Faculty Role Denied Access (HTTP 403)",
    $faculty->lastHttpCode === 403,
    "Status code: {$faculty->lastHttpCode}"
);

// 3. Authorized access for Admin, Dean, Principal
$adminExcelBody = $admin->request("{$baseUrl}/consolidated-report.php?format=excel", 'GET');
record_test(
    "Admin Allowed Access to Excel Export (HTTP 200)",
    $admin->lastHttpCode === 200,
    "Status code: {$admin->lastHttpCode}"
);

$deanExcelBody = $dean->request("{$baseUrl}/consolidated-report.php?format=excel", 'GET');
record_test(
    "Dean Allowed Access to Excel Export (HTTP 200)",
    $dean->lastHttpCode === 200,
    "Status code: {$dean->lastHttpCode}"
);

$principalExcelBody = $principal->request("{$baseUrl}/consolidated-report.php?format=excel", 'GET');
record_test(
    "Principal Allowed Access to Excel Export (HTTP 200)",
    $principal->lastHttpCode === 200,
    "Status code: {$principal->lastHttpCode}"
);

// 4. Verify Excel Multi-Tab (.xlsx) Format
$isXlsxHeader = stripos($admin->lastHeaders, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') !== false;
record_test(
    "Excel Response has OpenXML Spreadsheet Content-Type",
    $isXlsxHeader,
    "Content-Type matches .xlsx"
);

$tmpFile = tempnam(sys_get_temp_dir(), 'atts_xlsx_');
file_put_contents($tmpFile, $adminExcelBody);

$zip = new ZipArchive();
$openResult = $zip->open($tmpFile);
record_test(
    "Excel Export is a Valid Zip Archive (.xlsx)",
    $openResult === true,
    "Zip open result: " . ($openResult === true ? 'OK' : $openResult)
);

if ($openResult === true) {
    // Check workbook.xml for sheets
    $workbookXml = $zip->getFromName('xl/workbook.xml');
    record_test(
        "xl/workbook.xml Exists in XLSX",
        !empty($workbookXml),
        "Length: " . strlen((string)$workbookXml) . " bytes"
    );

    // Parse sheets
    preg_match_all('/<sheet\s+name="([^"]+)"\s+sheetId="(\d+)"/i', (string)$workbookXml, $sheetMatches);
    $sheetNames = $sheetMatches[1] ?? [];
    
    record_test(
        "Workbook Contains Multiple Sheets (> 5 tabs)",
        count($sheetNames) >= 5,
        "Total sheets found: " . count($sheetNames) . " (" . implode(', ', array_slice($sheetNames, 0, 8)) . "...)"
    );

    record_test(
        "Sheet 1 is 'College Overview'",
        isset($sheetNames[0]) && $sheetNames[0] === 'College Overview',
        "First sheet name: " . ($sheetNames[0] ?? 'NONE')
    );

    // Check that department sheets exist (e.g. CSBS, CSE, etc.)
    $hasCse = in_array('CSE', $sheetNames, true) || in_array('CSBS', $sheetNames, true);
    record_test(
        "Dedicated Department Sheets Exist (e.g. CSE/CSBS)",
        $hasCse,
        "Found department sheets: " . implode(', ', array_slice($sheetNames, 1, 6))
    );

    // Check Sheet 1 contents (College Overview)
    $sheet1Xml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $hasSummaryTitle = stripos($sheet1Xml, 'INSTITUTIONAL OVERVIEW') !== false || stripos($sheet1Xml, 'College Overview') !== false;
    record_test(
        "Sheet 1 (College Overview) has Institutional Overview Content",
        $hasSummaryTitle,
        "Overview title detected in sheet 1"
    );

    // Check signature block in sheet 1
    $hasDeanSig = stripos($sheet1Xml, 'DEAN / ACADEMICS') !== false;
    record_test(
        "Sheet 1 Contains 'DEAN / ACADEMICS' in Signature Block",
        $hasDeanSig,
        "Found DEAN / ACADEMICS sign-off block"
    );

    // Check department sheet contents (sheet 2)
    $sheet2Xml = $zip->getFromName('xl/worksheets/sheet2.xml');
    $hasRecordsOrSubtotal = stripos($sheet2Xml, 'Subtotal') !== false || stripos($sheet2Xml, 'No records') !== false;
    record_test(
        "Department Sheet Contains Records / Subtotal / Empty Placeholder Rows",
        $hasRecordsOrSubtotal,
        "Department data rows verified"
    );

    $zip->close();
}
@unlink($tmpFile);

// 5. Verify Word Export (.doc)
$adminWordBody = $admin->request("{$baseUrl}/consolidated-report.php?format=word", 'GET');
record_test(
    "Admin Allowed Access to Word Export (HTTP 200)",
    $admin->lastHttpCode === 200,
    "Status code: {$admin->lastHttpCode}"
);
$isDocHeader = stripos($admin->lastHeaders, 'application/msword') !== false;
record_test(
    "Word Response has application/msword Content-Type",
    $isDocHeader,
    "Content-Type verified"
);
$hasWordSummary = stripos($adminWordBody, 'INSTITUTIONAL SUMMARY') !== false;
$hasWordDean    = stripos($adminWordBody, 'DEAN / ACADEMICS') !== false;
record_test(
    "Word Document Contains Institutional Summary and DEAN / ACADEMICS Block",
    $hasWordSummary && $hasWordDean,
    "Word layout validated"
);

// 6. Verify PDF View (?format=pdf)
$adminPdfBody = $admin->request("{$baseUrl}/consolidated-report.php?format=pdf", 'GET');
record_test(
    "Admin Allowed Access to PDF Print View (HTTP 200)",
    $admin->lastHttpCode === 200,
    "Status code: {$admin->lastHttpCode}"
);
$hasPdfBar     = stripos($adminPdfBody, 'Print / Save as PDF') !== false;
$hasPageBreaks = stripos($adminPdfBody, 'page-break-before:always') !== false;
$hasPdfDean    = stripos($adminPdfBody, 'DEAN / ACADEMICS') !== false;
record_test(
    "PDF View Contains Print Bar, Department Page Breaks, and DEAN / ACADEMICS Block",
    $hasPdfBar && $hasPageBreaks && $hasPdfDean,
    "PDF layout validated"
);

// 7. Verify Reports Hub page rendering (reports.php) for Admin, Dean, Principal
$adminReportsBody = $admin->request("{$baseUrl}/reports.php", 'GET');
$hasHeroCard = stripos($adminReportsBody, 'Consolidated All-Department Report') !== false;
$hasMultiTabLabel = stripos($adminReportsBody, 'Excel (Multi-Tab)') !== false;
record_test(
    "Admin Reports Page Displays 'Consolidated All-Department Report' and 'Excel (Multi-Tab)'",
    $hasHeroCard && $hasMultiTabLabel,
    "Found hero card and summary report rows with multi-tab export links"
);

$deanReportsBody = $dean->request("{$baseUrl}/reports.php", 'GET');
$deanHasHeroCard = stripos($deanReportsBody, 'Consolidated All-Department Report') !== false;
record_test(
    "Dean Reports Page Displays 'Consolidated All-Department Report'",
    $deanHasHeroCard,
    "Dean can access Consolidated Report from Reports hub"
);

$principalReportsBody = $principal->request("{$baseUrl}/reports.php", 'GET');
$principalHasHeroCard = stripos($principalReportsBody, 'Consolidated All-Department Report') !== false;
record_test(
    "Principal Reports Page Displays 'Consolidated All-Department Report'",
    $principalHasHeroCard,
    "Principal can access Consolidated Report from Reports hub"
);

// 8. Verify HoD and Faculty do NOT see Consolidated Report on reports.php
$hodReportsBody = $hod->request("{$baseUrl}/reports.php", 'GET');
$hodHasConsolidated = stripos($hodReportsBody, 'consolidated-report.php') !== false;
record_test(
    "HoD Reports Hub Does NOT Expose Consolidated All-Department Report",
    !$hodHasConsolidated,
    "Strict role boundary confirmed"
);

$facultyReportsBody = $faculty->request("{$baseUrl}/reports.php", 'GET');
$facultyHasConsolidated = stripos($facultyReportsBody, 'consolidated-report.php') !== false;
record_test(
    "Faculty Reports Hub Does NOT Expose Consolidated All-Department Report",
    !$facultyHasConsolidated,
    "Strict role boundary confirmed"
);

echo "\n========================================================\n";
$totalTests = count($tests);
$passedTests = count(array_filter($tests, fn($t) => $t['passed']));
echo "Test Summary: {$passedTests} / {$totalTests} Passed\n";
echo "========================================================\n";

if ($passedTests < $totalTests) {
    exit(1);
}
exit(0);
