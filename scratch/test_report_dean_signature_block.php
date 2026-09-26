<?php
/**
 * Automated Verification Suite for BUG-REP-15:
 * Validates that generated report documents across Excel (.xlsx/.xls), Word (.doc),
 * PDF, and CSV include a formal signature block for 'Dean / Academics' at the end.
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

echo "=== STARTING BUG-REP-15 AUTOMATED VERIFICATION ===\n\n";

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
$targetSig = 'DEAN / ACADEMICS';

// --------------------------------------------------------------------------
// 1. export-individual-faculty-report.php
// --------------------------------------------------------------------------
echo "\n--- 1. Individual Faculty Achievement Report (export-individual-faculty-report.php) ---\n";

// 1.1 Excel
$out = $admin->request($baseUrl . '/export-individual-faculty-report.php?id=' . $facultyId . '&format=excel');
$text = extractXlsxText($out);
assertCondition(
    str_contains($text, $targetSig),
    "Excel: Contains formal '$targetSig' signature block"
);

// 1.2 CSV
$out = $admin->request($baseUrl . '/export-individual-faculty-report.php?id=' . $facultyId . '&format=csv');
assertCondition(
    str_contains($out, $targetSig),
    "CSV: Contains formal '$targetSig' signature block"
);

// 1.3 Word
$out = $admin->request($baseUrl . '/export-individual-faculty-report.php?id=' . $facultyId . '&format=word');
assertCondition(
    str_contains($out, $targetSig),
    "Word: Contains formal '$targetSig' signature block"
);

// 1.4 PDF
$out = $admin->request($baseUrl . '/export-individual-faculty-report.php?id=' . $facultyId . '&format=pdf');
assertCondition(
    str_contains($out, $targetSig),
    "PDF: Contains formal '$targetSig' signature block"
);

// --------------------------------------------------------------------------
// 2. export-faculty-achievements.php
// --------------------------------------------------------------------------
echo "\n--- 2. Department & Faculty Achievements Report (export-faculty-achievements.php) ---\n";

// 2.1 Excel
$out = $admin->request($baseUrl . '/export-faculty-achievements.php?department=CSBS&format=excel');
$text = extractXlsxText($out);
assertCondition(
    str_contains($text, $targetSig),
    "Excel: Contains formal '$targetSig' signature block"
);

// 2.2 CSV
$out = $admin->request($baseUrl . '/export-faculty-achievements.php?department=CSBS&format=csv');
assertCondition(
    str_contains($out, $targetSig),
    "CSV: Contains formal '$targetSig' signature block"
);

// 2.3 Word
$out = $admin->request($baseUrl . '/export-faculty-achievements.php?department=CSBS&format=word');
assertCondition(
    str_contains($out, $targetSig),
    "Word: Contains formal '$targetSig' signature block"
);

// 2.4 PDF
$out = $admin->request($baseUrl . '/export-faculty-achievements.php?department=CSBS&format=pdf');
assertCondition(
    str_contains($out, $targetSig),
    "PDF: Contains formal '$targetSig' signature block"
);

// --------------------------------------------------------------------------
// 3. consolidated-report.php
// --------------------------------------------------------------------------
echo "\n--- 3. Consolidated All-Department Report (consolidated-report.php) ---\n";

// 3.1 Excel
$out = $admin->request($baseUrl . '/consolidated-report.php?format=excel');
$text = extractXlsxText($out);
assertCondition(
    str_contains($text, $targetSig),
    "Excel: Contains formal '$targetSig' signature block"
);

// 3.2 Word
$out = $admin->request($baseUrl . '/consolidated-report.php?format=word');
assertCondition(
    str_contains($out, $targetSig),
    "Word: Contains formal '$targetSig' signature block"
);

// 3.3 PDF
$out = $admin->request($baseUrl . '/consolidated-report.php?format=pdf');
assertCondition(
    str_contains($out, $targetSig),
    "PDF: Contains formal '$targetSig' signature block"
);

// --------------------------------------------------------------------------
// 4. template-report.php
// --------------------------------------------------------------------------
echo "\n--- 4. Executive Meeting Report (template-report.php) ---\n";

// 4.1 Excel
$out = $admin->request($baseUrl . '/template-report.php?department=CSBS&format=excel');
$text = extractXlsxText($out);
assertCondition(
    str_contains($text, $targetSig),
    "Excel: Contains formal '$targetSig' signature block"
);

// 4.2 Word
$out = $admin->request($baseUrl . '/template-report.php?department=CSBS&format=word');
assertCondition(
    str_contains($out, $targetSig),
    "Word: Contains formal '$targetSig' signature block"
);

// 4.3 PDF
$out = $admin->request($baseUrl . '/template-report.php?department=CSBS&format=pdf');
assertCondition(
    str_contains($out, $targetSig),
    "PDF: Contains formal '$targetSig' signature block"
);

// --------------------------------------------------------------------------
// 5. record-report.php
// --------------------------------------------------------------------------
echo "\n--- 5. Metric Record Report (record-report.php) ---\n";

// 5.1 Excel
$out = $admin->request($baseUrl . '/record-report.php?type=journal&department=CSBS&format=excel');
$text = extractXlsxText($out);
assertCondition(
    str_contains($text, $targetSig),
    "Excel: Contains formal '$targetSig' signature block"
);

// 5.2 Word
$out = $admin->request($baseUrl . '/record-report.php?type=journal&department=CSBS&format=word');
assertCondition(
    str_contains($out, $targetSig),
    "Word: Contains formal '$targetSig' signature block"
);

// 5.3 PDF
$out = $admin->request($baseUrl . '/record-report.php?type=journal&department=CSBS&format=pdf');
assertCondition(
    str_contains($out, $targetSig),
    "PDF: Contains formal '$targetSig' signature block"
);

// --------------------------------------------------------------------------
// 6. export.php
// --------------------------------------------------------------------------
echo "\n--- 6. Academic Records Report (export.php) ---\n";

// 6.1 Excel
$out = $admin->request($baseUrl . '/export.php?department=CSBS&format=excel');
$text = extractXlsxText($out);
assertCondition(
    str_contains($text, $targetSig),
    "Excel: Contains formal '$targetSig' signature block"
);

// 6.2 CSV
$out = $admin->request($baseUrl . '/export.php?department=CSBS&format=csv');
assertCondition(
    str_contains($out, $targetSig),
    "CSV: Contains formal '$targetSig' signature block"
);

// 6.3 Word
$out = $admin->request($baseUrl . '/export.php?department=CSBS&format=word');
assertCondition(
    str_contains($out, $targetSig),
    "Word: Contains formal '$targetSig' signature block"
);

// 6.4 PDF
$out = $admin->request($baseUrl . '/export.php?department=CSBS&format=pdf');
assertCondition(
    str_contains($out, $targetSig),
    "PDF: Contains formal '$targetSig' signature block"
);

// --------------------------------------------------------------------------
// 7. metrics-report.php
// --------------------------------------------------------------------------
echo "\n--- 7. Metrics Summary Report (metrics-report.php) ---\n";

// 7.1 Excel
$out = $admin->request($baseUrl . '/metrics-report.php?department=CSBS&format=excel');
$text = extractXlsxText($out);
assertCondition(
    str_contains($text, $targetSig),
    "Excel: Contains formal '$targetSig' signature block"
);

// 7.2 Word
$out = $admin->request($baseUrl . '/metrics-report.php?department=CSBS&format=word');
assertCondition(
    str_contains($out, $targetSig),
    "Word: Contains formal '$targetSig' signature block"
);

// 7.3 PDF
$out = $admin->request($baseUrl . '/metrics-report.php?department=CSBS&format=pdf');
assertCondition(
    str_contains($out, $targetSig),
    "PDF: Contains formal '$targetSig' signature block"
);

echo "\n=================================================================\n";
echo "SUMMARY: Passed: $testsPassed, Failed: $testsFailed\n";
if ($testsFailed === 0) {
    echo "ALL TESTS PASSED SUCCESSFULLY!\n";
    exit(0);
} else {
    echo "SOME TESTS FAILED!\n";
    exit(1);
}
