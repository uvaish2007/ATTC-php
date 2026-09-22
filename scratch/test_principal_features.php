<?php
/**
 * Test Principal role features and page rendering
 */
require_once __DIR__ . '/../php-app/inc/auth.php';
require_once __DIR__ . '/../php-app/inc/db.php';

// Fetch user #2 (Principal)
$stmt = db()->prepare("SELECT * FROM users WHERE id = 2");
$stmt->execute();
$principal = $stmt->fetch(PDO::FETCH_ASSOC);

echo "User #2: Name=" . $principal['name'] . ", Role=" . $principal['role'] . "\n";

// Function to buffer page execution with mock session
function render_page(string $scriptPath, array $user, array $get = []) {
    $_SESSION['user'] = $user;
    $_SESSION['active_academic_year'] = '2026-27';
    $_GET = $get;
    $_POST = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['SCRIPT_NAME'] = '/' . basename($scriptPath);
    $_SERVER['PHP_SELF'] = '/' . basename($scriptPath);

    ob_start();
    try {
        include $scriptPath;
        $html = ob_get_clean();
        return [true, strlen($html), $html];
    } catch (\Throwable $t) {
        ob_end_clean();
        return [false, 0, $t->getMessage()];
    }
}

// 1. Dashboard
[$ok1, $len1, $html1] = render_page(__DIR__ . '/../php-app/dashboard.php', $principal);
echo "1. dashboard.php: " . ($ok1 ? "OK (length: $len1)" : "FAIL: $html1") . "\n";
if ($ok1) {
    echo "   - Contains 'Principal Dashboard': " . (strpos($html1, 'Principal Dashboard') !== false ? "YES" : "NO") . "\n";
    echo "   - Contains 'Institution-wide overview': " . (strpos($html1, 'Institution-wide overview') !== false ? "YES" : "NO") . "\n";
}

// 2. Reports
[$ok2, $len2, $html2] = render_page(__DIR__ . '/../php-app/reports.php', $principal);
echo "2. reports.php: " . ($ok2 ? "OK (length: $len2)" : "FAIL: $html2") . "\n";
if ($ok2) {
    echo "   - Contains 'Consolidated Report': " . (strpos($html2, 'Consolidated Report') !== false ? "YES" : "NO") . "\n";
    echo "   - Contains 'Download Excel': " . (strpos($html2, 'Download Excel') !== false ? "YES" : "NO") . "\n";
    echo "   - Contains 'Download PDF': " . (strpos($html2, 'Download PDF') !== false ? "YES" : "NO") . "\n";
    echo "   - Contains 'Download Word': " . (strpos($html2, 'Download Word') !== false ? "YES" : "NO") . "\n";
    echo "   - Contains Top Button 'Executive Meeting Report': " . (strpos($html2, 'Executive Meeting Report') !== false ? "YES" : "NO") . "\n";
}

// 3. Executive Meeting Report
[$ok3, $len3, $html3] = render_page(__DIR__ . '/../php-app/executive-meeting-report.php', $principal);
echo "3. executive-meeting-report.php: " . ($ok3 ? "OK (length: $len3)" : "FAIL: $html3") . "\n";
if ($ok3) {
    echo "   - Contains 'PRESENT' button: " . (strpos($html3, 'PRESENT') !== false ? "YES" : "NO") . "\n";
    echo "   - Contains 'present-executive-meeting.php': " . (strpos($html3, 'present-executive-meeting.php') !== false ? "YES" : "NO") . "\n";
}

// 4. Targets
[$ok4, $len4, $html4] = render_page(__DIR__ . '/../php-app/targets.php', $principal);
echo "4. targets.php: " . ($ok4 ? "OK (length: $len4)" : "FAIL: $html4") . "\n";

// 5. Faculty Achievements
[$ok5, $len5, $html5] = render_page(__DIR__ . '/../php-app/faculty-achievements.php', $principal);
echo "5. faculty-achievements.php: " . ($ok5 ? "OK (length: $len5)" : "FAIL: $html5") . "\n";

// 6. Consolidated Report (PDF format)
[$ok6, $len6, $html6] = render_page(__DIR__ . '/../php-app/consolidated-report.php', $principal, ['format' => 'pdf']);
echo "6. consolidated-report.php (PDF): " . ($ok6 ? "OK (length: $len6)" : "FAIL: $html6") . "\n";
if ($ok6) {
    echo "   - Contains 'Consolidated': " . (stripos($html6, 'Consolidated') !== false ? "YES" : "NO") . "\n";
    echo "   - Contains 'Dean': " . (stripos($html6, 'Dean') !== false ? "YES" : "NO") . "\n";
}

echo "\nAll checks completed.\n";
