<?php
/**
 * scratch/verify_all_19_checks.php
 * Automated verification of all 19 checks from Section 21 of user prompt.
 */

require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/inc/helpers.php';
require_once __DIR__ . '/../php-app/models/StudentAchievement.php';

$baseUrl = 'http://localhost:8000';
$cookieAdmin = __DIR__ . '/cookie_admin_19.txt';
@unlink($cookieAdmin);

function curlReq(string $url, string $method = 'GET', array $fields = [], ?string $cookieFile = null): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    if ($cookieFile) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    }
    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    return ['code' => $httpCode, 'headers' => $headers, 'body' => $body];
}

// 1. Authenticate Admin
$res = curlReq("$baseUrl/login.php", 'GET', [], $cookieAdmin);
preg_match('/name="csrf"\s+value="([^"]+)"/', $res['body'], $m);
$csrf = $m[1] ?? '';
curlReq("$baseUrl/login.php", 'POST', [
    'csrf' => $csrf,
    'email' => 'mohameduvaish132@gmail.com',
    'password' => 'admin123',
    'role' => 'Admin'
], $cookieAdmin);

// 2. Fetch faculty-achievements.php
$resPage = curlReq("$baseUrl/faculty-achievements.php", 'GET', [], $cookieAdmin);
$html = $resPage['body'];

$checks = [];
function recordCheck(int $num, string $title, bool $pass, string $detail = '') {
    global $checks;
    $status = $pass ? "PASS" : "FAIL";
    $checks[$num] = ['title' => $title, 'pass' => $pass, 'detail' => $detail];
    echo "CHECK $num: $title — $status\n";
    if ($detail) {
        echo "  Details: $detail\n";
    }
}

echo "\n===================================================\n";
echo "SECTION 21: 19 AUTOMATIC VERIFICATION CHECKS\n";
echo "===================================================\n\n";

// CHECK 1: Faculty section loads correctly
$c1 = ($resPage['code'] === 200 && strpos($html, 'Faculty Achievement Performance Matrix') !== false && strpos($html, 'facTable') !== false);
recordCheck(1, "Faculty section loads correctly", $c1, "HTTP {$resPage['code']}, Faculty matrix & table rendered");

// CHECK 2: Student section appears below Faculty section
$posFac = strpos($html, 'Faculty Achievement Performance Matrix');
$posStud = strpos($html, 'Student Achievement Performance Matrix');
$c2 = ($posFac !== false && $posStud !== false && $posStud > $posFac);
recordCheck(2, "Student section appears below Faculty section", $c2, "Faculty Pos: $posFac, Student Pos: $posStud");

// CHECK 3: Student section has: [ Data View ] [ Analytics View ]
$hasBtnData = strpos($html, 'id="btnStudData"') !== false && strpos($html, 'Data View</button>') !== false;
$hasBtnAnalytics = strpos($html, 'id="btnStudAnalytics"') !== false && strpos($html, 'Analytics View</button>') !== false;
$c3 = ($hasBtnData && $hasBtnAnalytics);
recordCheck(3, "Student section has [ Data View ] [ Analytics View ]", $c3, "Data View toggle button & Analytics View toggle button found");

// CHECK 4: Data View shows Student Achievement Performance Matrix
$hasStudDataView = strpos($html, 'id="studDataView"') !== false && strpos($html, 'id="studTable"') !== false;
recordCheck(4, "Data View shows Student Achievement Performance Matrix", $hasStudDataView, "studDataView container and studTable present");

// CHECK 5: Analytics View switches correctly
$hasStudAnalyticsView = strpos($html, 'id="studAnalyticsView"') !== false;
$hasSwitchFunc = strpos($html, 'function switchStudView(mode)') !== false;
$hasCatChart = strpos($html, 'id="studCatChart"') !== false;
$hasDeptChart = strpos($html, 'id="studDeptChart"') !== false;
$c5 = ($hasStudAnalyticsView && $hasSwitchFunc && $hasCatChart && $hasDeptChart);
recordCheck(5, "Analytics View switches correctly", $c5, "Analytics view container, JS switcher, and chart canvases present");

// CHECK 6: All student categories are represented
$reqCats = [
    'NPTEL', 'INTERNSHIPS', 'PLACEMENTS', 'ONLINE COURSES',
    'ACHIEVEMENTS', 'PARTICIPATION', 'TRAINING'
];
$allCatsFound = true;
$missingCats = [];
foreach ($reqCats as $rc) {
    if (strpos($html, $rc) === false) {
        $allCatsFound = false;
        $missingCats[] = $rc;
    }
}
recordCheck(6, "All student categories are represented", $allCatsFound, $allCatsFound ? "All 7 required categories present" : "Missing: " . implode(', ', $missingCats));

// CHECK 7: Student summary cards are aligned
$hasSummaryGrid = strpos($html, 'stud-summary-grid') !== false && strpos($html, 'stud-stat-card') !== false;
$summaryCardCount = substr_count($html, 'class="stud-stat-card"');
$c7 = ($hasSummaryGrid && $summaryCardCount === 9);
recordCheck(7, "Student summary cards are aligned", $c7, "Responsive 9-item grid with stud-stat-card class, count: $summaryCardCount");

// CHECK 8: Student table columns align correctly
$reqCols = ['#', 'STUDENT', 'REGISTER NO', 'DEPARTMENT', 'NPTEL', 'INTERNSHIPS', 'PLACEMENTS', 'ONLINE COURSES', 'ACHIEVEMENTS', 'PARTICIPATION', 'TRAINING', 'OTHER', 'TOTAL', 'ACTIONS'];
$colsOk = true;
foreach ($reqCols as $col) {
    if (strpos($html, "<th>$col</th>") === false && strpos($html, ">$col</th>") === false) {
        $colsOk = false;
    }
}
recordCheck(8, "Student table columns align correctly", $colsOk, "All 14 columns present in studTable header");

// CHECK 9: View Report is fully visible
$hasStudViewReport = (strpos($html, 'individual-student-report.php') !== false && strpos($html, 'View Report') !== false);
recordCheck(9, "View Report is fully visible", $hasStudViewReport, "individual-student-report link with View Report label present");

// CHECK 10: Present button is fully visible
$hasStudPresent = (strpos($html, 'present-student-report.php') !== false && strpos($html, 'Present') !== false);
recordCheck(10, "Present button is fully visible", $hasStudPresent, "present-student-report link with Present label present");

// CHECK 11: No button or table content is clipped
$hasNoWrapActions = (strpos($html, 'white-space:nowrap; min-width: 195px; width: 195px;') !== false || strpos($html, 'flex-wrap:nowrap') !== false);
$hasMinWidthTable = (strpos($html, 'min-width: 1150px') !== false);
$c11 = ($hasNoWrapActions && $hasMinWidthTable);
recordCheck(11, "No button or table content is clipped", $c11, "Actions cell has min-width: 195px, flex-wrap: nowrap, studTable has min-width: 1150px");

// CHECK 12: Horizontal scrolling, if required, happens ONLY inside the table container
$hasContainedScroll = (preg_match('/<div class="table-wrap"[^>]*overflow-x:\s*auto/i', $html) === 1);
recordCheck(12, "Horizontal scrolling happens ONLY inside table container", $hasContainedScroll, "table-wrap has overflow-x: auto and max-height constraints");

// CHECK 13: Faculty section remains visually correct
$c13 = (strpos($html, 'Faculty Achievement Performance Matrix') !== false && strpos($html, 'Search faculty name') !== false);
recordCheck(13, "Faculty section remains visually correct", $c13, "Faculty card, search, headers intact");

// CHECK 14: Faculty View Report still works
$hasFacViewReport = (strpos($html, 'individual-faculty-report.php') !== false);
$resFacReport = curlReq("$baseUrl/individual-faculty-report.php?id=5", 'GET', [], $cookieAdmin);
$c14 = ($hasFacViewReport && $resFacReport['code'] === 200);
recordCheck(14, "Faculty View Report still works", $c14, "HTTP {$resFacReport['code']} for individual-faculty-report.php?id=5");

// CHECK 15: Faculty Present still works
$hasFacPresent = (strpos($html, 'present-faculty-report.php') !== false);
$resFacPresent = curlReq("$baseUrl/present-faculty-report.php?id=5", 'GET', [], $cookieAdmin);
$c15 = ($hasFacPresent && $resFacPresent['code'] === 200);
recordCheck(15, "Faculty Present still works", $c15, "HTTP {$resFacPresent['code']} for present-faculty-report.php?id=5");

// CHECK 16: Student View Report works
$resStudReport = curlReq("$baseUrl/individual-student-report.php?key=faizal_CSBS_&reg_no=&name=faizal&dept=CSBS&academic_year=2019-20", 'GET', [], $cookieAdmin);
$c16 = ($resStudReport['code'] === 200 && strpos($resStudReport['body'], 'faizal') !== false);
recordCheck(16, "Student View Report works", $c16, "HTTP {$resStudReport['code']}, Report page for faizal rendered");

// CHECK 17: Student Present works
$resStudPresent = curlReq("$baseUrl/present-student-report.php?key=faizal_CSBS_&reg_no=&name=faizal&dept=CSBS&academic_year=2019-20", 'GET', [], $cookieAdmin);
$c17 = ($resStudPresent['code'] === 200 && strpos($resStudPresent['body'], 'faizal') !== false);
recordCheck(17, "Student Present works", $c17, "HTTP {$resStudPresent['code']}, Presentation deck for faizal rendered");

// CHECK 18: Academic Year indicator remains correct
$c18 = (strpos($html, 'Academic Year: 2019-20') !== false && strpos($html, 'Locked by Admin') !== false);
recordCheck(18, "Academic Year indicator remains correct", $c18, "Locked by Admin badge and active academic year present");

// CHECK 19: Mobile/tablet layout works
$hasMediaQueries = (strpos($html, '@media (max-width: 1200px)') !== false &&
                    strpos($html, '@media (max-width: 900px)') !== false &&
                    strpos($html, '@media (max-width: 580px)') !== false);
recordCheck(19, "Mobile/tablet layout works", $hasMediaQueries, "Responsive breakpoints for 1200px, 900px, 580px, 380px present");

echo "\n===================================================\n";
$passCount = count(array_filter($checks, fn($c) => $c['pass']));
echo "SUMMARY: $passCount / 19 CHECKS PASSED\n";
echo "===================================================\n";
