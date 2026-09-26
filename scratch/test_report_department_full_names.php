<?php
/**
 * Automated Verification Suite for BUG-REP-14:
 * Validates that exported Excel, PDF, Word, and CSV reports display full official
 * department names (e.g., "Computer Science and Business Systems") instead of
 * abbreviated codes (e.g., "CSBS", "ECE").
 */

require_once __DIR__ . '/../php-app/inc/config.php';
require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/inc/helpers.php';

$baseUrl = 'http://localhost:8000/php-app';

class HttpSession {
    private string $cookieFile;
    public int $lastHttpCode = 0;
    public string $lastUrl = '';

    public function __construct() {
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'atts_sess_');
    }

    public function __destruct() {
        if (file_exists($this->cookieFile)) {
            @unlink($this->cookieFile);
        }
    }

    public function request(string $url, string $method = 'GET', array $data = []): string {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookieFile);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }

        $resp = curl_exec($ch);
        $this->lastHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $this->lastUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

        return $resp ?: '';
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
        $html = $this->request('http://localhost:8000/php-app/login.php');
        $csrf = $this->extractCsrf($html);
        $post = [
            'csrf' => $csrf,
            'email' => $email,
            'password' => $password,
            'role' => $role,
        ];
        $resp = $this->request('http://localhost:8000/php-app/login.php', 'POST', $post);
        return strpos($this->lastUrl, 'dashboard.php') !== false || strpos($resp, 'logout.php') !== false;
    }
}

$testsPassed = 0;
$testsFailed = 0;

function assertCondition(bool $cond, string $message): void {
    global $testsPassed, $testsFailed;
    if ($cond) {
        echo "  [PASS] " . $message . "\n";
        $testsPassed++;
    } else {
        echo "  [FAIL] " . $message . "\n";
        $testsFailed++;
    }
}

function extractXlsxText(string $xlsxData): string {
    if (empty($xlsxData)) return '';
    if (str_starts_with(trim($xlsxData), '<') || str_contains($xlsxData, '<html') || str_contains($xlsxData, '<table')) {
        return strip_tags($xlsxData);
    }
    if (class_exists('ZipArchive')) {
        $temp = tempnam(sys_get_temp_dir(), 'test_xlsx_');
        file_put_contents($temp, $xlsxData);
        $zip = new ZipArchive();
        $text = '';
        if ($zip->open($temp) === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (str_ends_with($name, '.xml')) {
                    $xml = $zip->getFromIndex($i);
                    $text .= ' ' . strip_tags($xml);
                }
            }
            $zip->close();
        }
        @unlink($temp);
        return $text;
    }
    return $xlsxData;
}

echo "=== STARTING BUG-REP-14 AUTOMATED VERIFICATION ===\n\n";

$admin = new HttpSession();
if (!$admin->login('admin.test@atts.local', 'pass123', 'Admin')) {
    echo "Failed to log in as Admin!\n";
    exit(1);
}
echo "Logged in as Admin successfully.\n";

$csbsFaculty = db()->query("SELECT * FROM users WHERE department = 'CSBS' AND role = 'Faculty' LIMIT 1")->fetch();
if (!$csbsFaculty) {
    $csbsFaculty = db()->query("SELECT * FROM users WHERE department = 'CSBS' LIMIT 1")->fetch();
}
$facultyId = $csbsFaculty['id'] ?? 1;
$deptCode = 'CSBS';
$fullDeptName = 'Computer Science and Business Systems';

// --------------------------------------------------------------------------
// 1. export-individual-faculty-report.php
// --------------------------------------------------------------------------
echo "\n--- 1. Individual Faculty Achievement Report (export-individual-faculty-report.php) ---\n";

// 1.1 Excel
$out = $admin->request($baseUrl . '/export-individual-faculty-report.php?id=' . $facultyId . '&format=excel');
$text = extractXlsxText($out);
assertCondition(
    str_contains($text, $fullDeptName),
    "Excel (.xlsx): Contains '$fullDeptName'"
);
assertCondition(
    !preg_match('/Department:\s*' . $deptCode . '\b(?!\s*Science)/', $text),
    "Excel (.xlsx): No unexpanded 'Department: CSBS'"
);

// 1.2 CSV
$out = $admin->request($baseUrl . '/export-individual-faculty-report.php?id=' . $facultyId . '&format=csv');
assertCondition(
    str_contains($out, "Department: " . $fullDeptName),
    "CSV (.csv): Contains 'Department: $fullDeptName'"
);
assertCondition(
    !str_contains($out, "Department: CSBS\r") && !str_contains($out, "Department: CSBS\n"),
    "CSV (.csv): Does not have raw 'Department: CSBS'"
);

// 1.3 Word
$out = $admin->request($baseUrl . '/export-individual-faculty-report.php?id=' . $facultyId . '&format=word');
assertCondition(
    str_contains($out, $fullDeptName),
    "Word (.doc): Contains '$fullDeptName'"
);

// 1.4 PDF
$out = $admin->request($baseUrl . '/export-individual-faculty-report.php?id=' . $facultyId . '&format=pdf');
assertCondition(
    str_contains($out, $fullDeptName),
    "PDF: Contains '$fullDeptName'"
);

// --------------------------------------------------------------------------
// 2. export-faculty-achievements.php
// --------------------------------------------------------------------------
echo "\n--- 2. Department & Faculty Achievements Report (export-faculty-achievements.php) ---\n";

// 2.1 Excel
$out = $admin->request($baseUrl . '/export-faculty-achievements.php?department=' . $deptCode . '&format=excel');
$text = extractXlsxText($out);
assertCondition(
    str_contains($text, $fullDeptName),
    "Excel (.xlsx): Contains '$fullDeptName'"
);

// 2.2 CSV
$out = $admin->request($baseUrl . '/export-faculty-achievements.php?department=' . $deptCode . '&format=csv');
assertCondition(
    str_contains($out, "DEPARTMENT: " . $fullDeptName) || str_contains($out, "DEPARTMENT: " . strtoupper($fullDeptName)),
    "CSV (.csv): Contains 'DEPARTMENT: $fullDeptName'"
);

// 2.3 Word
$out = $admin->request($baseUrl . '/export-faculty-achievements.php?department=' . $deptCode . '&format=word');
assertCondition(
    str_contains($out, strtoupper($fullDeptName)),
    "Word (.doc): Contains uppercase '$fullDeptName'"
);

// 2.4 PDF
$out = $admin->request($baseUrl . '/export-faculty-achievements.php?department=' . $deptCode . '&format=pdf');
assertCondition(
    str_contains($out, strtoupper($fullDeptName)),
    "PDF: Contains uppercase '$fullDeptName'"
);

// --------------------------------------------------------------------------
// 3. consolidated-report.php
// --------------------------------------------------------------------------
echo "\n--- 3. Consolidated All-Department Report (consolidated-report.php) ---\n";

// 3.1 Excel
$out = $admin->request($baseUrl . '/consolidated-report.php?format=excel');
$text = extractXlsxText($out);
assertCondition(
    str_contains($text, "DEPARTMENT: " . strtoupper($fullDeptName)),
    "Excel (.xlsx): Contains 'DEPARTMENT: " . strtoupper($fullDeptName) . "'"
);

// 3.2 Word
$out = $admin->request($baseUrl . '/consolidated-report.php?format=word');
assertCondition(
    str_contains($out, "DEPARTMENT OF " . strtoupper($fullDeptName)),
    "Word (.doc): Contains 'DEPARTMENT OF " . strtoupper($fullDeptName) . "'"
);

// 3.3 PDF
$out = $admin->request($baseUrl . '/consolidated-report.php?format=pdf');
assertCondition(
    str_contains($out, "DEPARTMENT OF " . strtoupper($fullDeptName)),
    "PDF: Contains 'DEPARTMENT OF " . strtoupper($fullDeptName) . "'"
);

// --------------------------------------------------------------------------
// 4. template-report.php
// --------------------------------------------------------------------------
echo "\n--- 4. Executive Meeting Report (template-report.php) ---\n";

// 4.1 Single Dept Excel
$out = $admin->request($baseUrl . '/template-report.php?department=' . $deptCode . '&format=excel');
$text = extractXlsxText($out);
assertCondition(
    str_contains($text, "Department: " . $fullDeptName),
    "Excel (.xlsx): Contains 'Department: $fullDeptName'"
);

// 4.2 Single Dept Word
$out = $admin->request($baseUrl . '/template-report.php?department=' . $deptCode . '&format=word');
assertCondition(
    str_contains($out, "DEPARTMENT OF " . strtoupper($fullDeptName)),
    "Word (.doc): Contains 'DEPARTMENT OF " . strtoupper($fullDeptName) . "'"
);

// 4.3 Single Dept PDF
$out = $admin->request($baseUrl . '/template-report.php?department=' . $deptCode . '&format=pdf');
assertCondition(
    str_contains($out, "DEPARTMENT OF " . strtoupper($fullDeptName)),
    "PDF: Contains 'DEPARTMENT OF " . strtoupper($fullDeptName) . "'"
);

// --------------------------------------------------------------------------
// 5. record-report.php
// --------------------------------------------------------------------------
echo "\n--- 5. Metric Record Report (record-report.php) ---\n";

// 5.1 Excel
$out = $admin->request($baseUrl . '/record-report.php?type=journal&department=' . $deptCode . '&format=excel');
$text = extractXlsxText($out);
assertCondition(
    str_contains($text, "Department: " . $fullDeptName),
    "Excel (.xlsx): Contains 'Department: $fullDeptName'"
);

// 5.2 Word
$out = $admin->request($baseUrl . '/record-report.php?type=journal&department=' . $deptCode . '&format=word');
assertCondition(
    str_contains($out, "DEPARTMENT OF " . strtoupper($fullDeptName)),
    "Word (.doc): Contains 'DEPARTMENT OF " . strtoupper($fullDeptName) . "'"
);

// 5.3 PDF
$out = $admin->request($baseUrl . '/record-report.php?type=journal&department=' . $deptCode . '&format=pdf');
assertCondition(
    str_contains($out, "DEPARTMENT OF " . strtoupper($fullDeptName)),
    "PDF: Contains 'DEPARTMENT OF " . strtoupper($fullDeptName) . "'"
);

// --------------------------------------------------------------------------
// 6. export.php
// --------------------------------------------------------------------------
echo "\n--- 6. Academic Records Report (export.php) ---\n";

// 6.1 Excel
$out = $admin->request($baseUrl . '/export.php?department=' . $deptCode . '&format=excel');
$text = extractXlsxText($out);
assertCondition(
    str_contains($text, "Department: " . $fullDeptName),
    "Excel (.xlsx): Contains 'Department: $fullDeptName'"
);

// 6.2 CSV
$out = $admin->request($baseUrl . '/export.php?department=' . $deptCode . '&format=csv');
assertCondition(
    str_contains($out, "Department: " . $fullDeptName),
    "CSV (.csv): Contains 'Department: $fullDeptName'"
);

// 6.3 Word
$out = $admin->request($baseUrl . '/export.php?department=' . $deptCode . '&format=word');
assertCondition(
    str_contains($out, $fullDeptName),
    "Word (.doc): Contains '$fullDeptName'"
);

// 6.4 PDF
$out = $admin->request($baseUrl . '/export.php?department=' . $deptCode . '&format=pdf');
assertCondition(
    str_contains($out, $fullDeptName),
    "PDF: Contains '$fullDeptName'"
);

// --------------------------------------------------------------------------
// 7. metrics-report.php
// --------------------------------------------------------------------------
echo "\n--- 7. Metrics Summary Report (metrics-report.php) ---\n";

// 7.1 Excel
$out = $admin->request($baseUrl . '/metrics-report.php?department=' . $deptCode . '&format=excel');
$text = extractXlsxText($out);
assertCondition(
    str_contains($text, "Department: " . $fullDeptName),
    "Excel (.xlsx): Contains 'Department: $fullDeptName'"
);

// 7.2 Word
$out = $admin->request($baseUrl . '/metrics-report.php?department=' . $deptCode . '&format=word');
assertCondition(
    str_contains($out, $fullDeptName),
    "Word (.doc): Contains '$fullDeptName'"
);

// 7.3 PDF
$out = $admin->request($baseUrl . '/metrics-report.php?department=' . $deptCode . '&format=pdf');
assertCondition(
    str_contains($out, $fullDeptName),
    "PDF: Contains '$fullDeptName'"
);

// --------------------------------------------------------------------------
// 8. reports.php (UI Scope and Chips)
// --------------------------------------------------------------------------
echo "\n--- 8. Reports Hub (reports.php) ---\n";
$out = $admin->request($baseUrl . '/reports.php?department=' . $deptCode);
assertCondition(
    str_contains($out, "Dept: " . $fullDeptName),
    "reports.php: Active filter chip displays 'Dept: $fullDeptName'"
);

// --------------------------------------------------------------------------
// 9. Role-based download (HoD CSE)
// --------------------------------------------------------------------------
echo "\n--- 9. Role-based download (HoD CSE) ---\n";
$hod = new HttpSession();
$cseExpected = 'Computer Science and Engineering';
if ($hod->login('hod.cse@atts.local', 'pass123', 'HoD')) {
    $out = $hod->request($baseUrl . '/export.php?format=excel');
    $text = extractXlsxText($out);
    assertCondition(
        str_contains($text, "Department: " . $cseExpected),
        "HoD CSE export.php Excel: Contains 'Department: $cseExpected'"
    );

    $out = $hod->request($baseUrl . '/template-report.php?format=word');
    assertCondition(
        str_contains($out, "DEPARTMENT OF " . strtoupper($cseExpected)),
        "HoD CSE template-report.php Word: Contains 'DEPARTMENT OF " . strtoupper($cseExpected) . "'"
    );
} else {
    echo "  [WARN] Could not log in as HoD\n";
}

echo "\n=================================================================\n";
echo "SUMMARY: Passed: $testsPassed, Failed: $testsFailed\n";
if ($testsFailed === 0) {
    echo "ALL TESTS PASSED SUCCESSFULLY!\n";
    exit(0);
} else {
    echo "SOME TESTS FAILED!\n";
    exit(1);
}
