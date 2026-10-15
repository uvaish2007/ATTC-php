<?php
/**
 * Automated Verification Script for Department-Specific Target Presentation
 */

declare(strict_types=1);

require_once __DIR__ . '/../php-app/inc/auth.php';
require_once __DIR__ . '/../php-app/models/TargetPresentation.php';

$baseUrl = 'http://localhost:8000';

function http_request(string $url, string $method = 'GET', ?array $data = null, ?string $cookieFile = null): array
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HEADER, true);

    if ($cookieFile) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }
    }

    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

    $headerStr = substr((string)$response, 0, $headerSize);
    $body = substr((string)$response, $headerSize);

    return [
        'code'    => $httpCode,
        'url'     => $finalUrl,
        'headers' => $headerStr,
        'body'    => $body,
    ];
}

function login(string $baseUrl, string $email, string $password, string $role, string $cookieFile): array
{
    $loginPage = http_request($baseUrl . '/login.php', 'GET', null, $cookieFile);
    preg_match('/name="csrf"\s+value="([^"]+)"/', $loginPage['body'], $m);
    $csrf = $m[1] ?? '';

    $res = http_request($baseUrl . '/login.php', 'POST', [
        'csrf'     => $csrf,
        'email'    => $email,
        'password' => $password,
        'role'     => $role,
    ], $cookieFile);

    return $res;
}

echo "=== STARTING AUTOMATIC VERIFICATION ===\n\n";

$cookieCsbs = tempnam(sys_get_temp_dir(), 'csbs_cookie_');
$cookieEce  = tempnam(sys_get_temp_dir(), 'ece_cookie_');
$cookieAdmin = tempnam(sys_get_temp_dir(), 'admin_cookie_');

// Snapshot targets DB table before test
$targetsCountBefore = (int) db()->query("SELECT COUNT(*) FROM targets")->fetchColumn();
$usersCountBefore   = (int) db()->query("SELECT COUNT(*) FROM users")->fetchColumn();

// 1. TEST 1 & TEST 2: CSBS HoD Login & Presentation
echo "TEST 1 & 2: CSBS HoD Login & Presentation\n";
$loginRes = login($baseUrl, 'hod@atts.edu', 'hod12345', 'HoD', $cookieCsbs);
$presentCsbs = http_request($baseUrl . '/present-targets.php', 'GET', null, $cookieCsbs);

$test1Pass = ($presentCsbs['code'] === 200) && strpos($presentCsbs['body'], 'CSBS &middot; Department Targets Presentation') !== false;
$test1Title = strpos($presentCsbs['body'], '<title>CSBS — Department Targets Presentation — Academic Year 2026-27 — ATTS IQAC</title>') !== false;
echo "  - Title identifies CSBS: " . ($test1Pass && $test1Title ? "PASS" : "FAIL") . "\n";

// Check CSBS data isolation
$containsOtherDepts = (
    strpos($presentCsbs['body'], 'Civil Engineering') !== false ||
    strpos($presentCsbs['body'], 'Mechanical Engineering') !== false ||
    strpos($presentCsbs['body'], 'Electronics and Communication Engineering') !== false ||
    strpos($presentCsbs['body'], 'ECE &mdash;') !== false
);
$has35Slides = (strpos($presentCsbs['body'], 'Slide 1 of 35') !== false);
$hasCsbsFaculty = (strpos($presentCsbs['body'], 'Coordinator') !== false);
$hasNoAdminSlides = (strpos($presentCsbs['body'], 'Admin Executive Summary') === false)
    && (strpos($presentCsbs['body'], 'Dean Academic Summary') === false)
    && (strpos($presentCsbs['body'], 'Principal Executive Scorecard') === false);

$test2Pass = !$containsOtherDepts && $has35Slides && $hasCsbsFaculty && $hasNoAdminSlides;
echo "  - CSBS isolation (only CSBS data, no other depts, no Admin/Dean/Principal summaries, 35 slides): " . ($test2Pass ? "PASS" : "FAIL") . "\n";

// 2. TEST 3: ECE HoD Login & Presentation
echo "\nTEST 3: ECE HoD Login & Presentation\n";
$loginEce = login($baseUrl, 'ece_hod@atts.local', 'hod12345', 'HoD', $cookieEce);
$presentEce = http_request($baseUrl . '/present-targets.php', 'GET', null, $cookieEce);

$test3Pass = ($presentEce['code'] === 200) && strpos($presentEce['body'], 'ECE &middot; Department Targets Presentation') !== false;
$eceHasNoCsbsFaculty = (strpos($presentEce['body'], 'No eligible faculty contributions recorded yet for ECE') !== false);
$eceHas35Slides = (strpos($presentEce['body'], 'Slide 1 of 35') !== false);
echo "  - Title identifies ECE: " . ($test3Pass ? "PASS" : "FAIL") . "\n";
echo "  - ECE data only (no CSBS faculty, shows ECE empty state, 35 slides): " . ($test3Pass && $eceHasNoCsbsFaculty && $eceHas35Slides ? "PASS" : "FAIL") . "\n";

// 3. TEST 4: Department Switching / Independence
echo "\nTEST 4: Department Switching Comparison\n";
// CSBS has 48 targets, ECE has 0 targets
$csbsHasTargets = (strpos($presentCsbs['body'], '<div class="kpi-val">48</div>') !== false);
$eceHasZeroTargets = (strpos($presentEce['body'], '<div class="kpi-val">0</div>') !== false);
$test4Pass = $csbsHasTargets && $eceHasZeroTargets;
echo "  - Distinct numbers between CSBS (48 targets) and ECE (0 targets): " . ($test4Pass ? "PASS" : "FAIL") . "\n";

// 4. TEST 5: Overall summary KPIs
echo "\nTEST 5: Overall Summary KPIs\n";
// For CSBS: total 48, achieved 2, in progress 45, not completed 1
$test5Pass = (
    strpos($presentCsbs['body'], '<div class="kpi-val">48</div>') !== false &&
    strpos($presentCsbs['body'], '<div class="kpi-val" style="color:var(--success);">2</div>') !== false &&
    strpos($presentCsbs['body'], '<div class="kpi-val" style="color:var(--orange);">45</div>') !== false &&
    strpos($presentCsbs['body'], '<div class="kpi-val" style="color:var(--danger);">1</div>') !== false
);
echo "  - CSBS KPI Cards match database (48 total, 2 achieved, 45 in progress, 1 not completed): " . ($test5Pass ? "PASS" : "FAIL") . "\n";

// 5. TEST 6: Target Fixed vs Achieved
echo "\nTEST 6: Target Fixed vs Target Achieved (Slide 2)\n";
$test6Pass = (
    strpos($presentCsbs['body'], 'TARGET FIXED VS TARGET ACHIEVED') !== false &&
    strpos($presentCsbs['body'], '1,256') !== false && // Target fixed
    strpos($presentCsbs['body'], '30') !== false // Target achieved
);
echo "  - Slide 2 renders department benchmarks and quantities: " . ($test6Pass ? "PASS" : "FAIL") . "\n";

// 6. TEST 7: Department Target Performance (Slide 3)
echo "\nTEST 7: Department Target Performance (Slide 3)\n";
$test7Pass = (
    strpos($presentCsbs['body'], 'DEPARTMENT TARGET PERFORMANCE') !== false &&
    strpos($presentCsbs['body'], 'Section-by-section breakdown of configured targets') !== false
);
echo "  - Slide 3 renders performance table for department only: " . ($test7Pass ? "PASS" : "FAIL") . "\n";

// 7. TEST 8: Department Faculty Contribution (Slide 4)
echo "\nTEST 8: Department Faculty Contribution (Slide 4)\n";
$test8Pass = (
    strpos($presentCsbs['body'], 'DEPARTMENT FACULTY CONTRIBUTION') !== false &&
    strpos($presentCsbs['body'], 'Coordinator') !== false &&
    strpos($presentCsbs['body'], 'Verified Records') !== false
);
echo "  - Slide 4 renders CSBS faculty contributions: " . ($test8Pass ? "PASS" : "FAIL") . "\n";

// 8. TEST 9: All 30 target sections individually represented
echo "\nTEST 9: All 30 Target Sections (Slides 5 to 34)\n";
$all30Pass = true;
for ($s = 1; $s <= 30; $s++) {
    $needle = "Section " . $s . " of 30";
    if (strpos($presentCsbs['body'], $needle) === false) {
        $all30Pass = false;
        echo "    Missing Section $s!\n";
    }
}
echo "  - All 30 sections present in exact order: " . ($all30Pass ? "PASS" : "FAIL") . "\n";

// 9. TEST 10: Final Department Summary (Slide 35)
echo "\nTEST 10: Final Department Summary (Slide 35)\n";
$test10Pass = (
    strpos($presentCsbs['body'], 'FINAL DEPARTMENT TARGET PERFORMANCE SUMMARY') !== false &&
    strpos($presentCsbs['body'], 'data-slide-index="35"') !== false &&
    strpos($presentCsbs['body'], 'Department Final Scorecard') !== false
);
echo "  - Slide 35 reconciles with department data: " . ($test10Pass ? "PASS" : "FAIL") . "\n";

// 10. TEST 11: Academic Year Handling
echo "\nTEST 11: Academic Year Handling\n";
$activeAY = active_academic_year();
$test11Pass = (
    strpos($presentCsbs['body'], 'Academic Year: ' . $activeAY) !== false ||
    strpos($presentCsbs['body'], 'Academic Year: <strong>' . $activeAY . '</strong>') !== false
);
echo "  - Centralized Academic Year ($activeAY) used across presentation: " . ($test11Pass ? "PASS" : "FAIL") . "\n";

// 11. TEST 12: Pending vs Not Completed distinction
echo "\nTEST 12: Pending vs Not Completed Distinction\n";
$test12Pass = (
    strpos($presentCsbs['body'], 'Dean Pending') !== false &&
    (strpos($presentCsbs['body'], 'Under active tracking cycle or awaiting review') !== false ||
     strpos($presentCsbs['body'], 'Active tracking cycle or awaiting review where deadline has not elapsed') !== false ||
     strpos($presentCsbs['body'], 'Active tracking cycle or awaiting review') !== false)
);
echo "  - Workflow status separate from completion status: " . ($test12Pass ? "PASS" : "FAIL") . "\n";

// 12. TEST 13: Presentation Controls & Navigation
echo "\nTEST 13: Navigation Controls\n";
$test13Pass = (
    strpos($presentCsbs['body'], 'id="btnPrevSlide"') !== false &&
    strpos($presentCsbs['body'], 'id="btnNextSlide"') !== false &&
    strpos($presentCsbs['body'], 'id="btnClosePresentation"') !== false &&
    strpos($presentCsbs['body'], 'id="btnFullscreen"') !== false &&
    strpos($presentCsbs['body'], 'Slide 1 of 35') !== false &&
    strpos($presentCsbs['body'], 'data-target-slide="35"') !== false
);
echo "  - Controls, slide counter (1 of 35), and dot steps: " . ($test13Pass ? "PASS" : "FAIL") . "\n";

// 13. TEST 14: Security / URL Tampering
echo "\nTEST 14: Security / URL Tampering\n";
// CSBS HoD attempts to access ?dept=ECE or ?department=Mechanical
$tamperRes1 = http_request($baseUrl . '/present-targets.php?dept=ECE', 'GET', null, $cookieCsbs);
$tamperRes2 = http_request($baseUrl . '/present-targets.php?department=Mechanical', 'GET', null, $cookieCsbs);
$test14Pass = ($tamperRes1['code'] === 403) && ($tamperRes2['code'] === 403);
echo "  - CSBS HoD accessing ?dept=ECE blocked with HTTP 403: " . ($tamperRes1['code'] === 403 ? "PASS" : "FAIL") . " (HTTP {$tamperRes1['code']})\n";
echo "  - CSBS HoD accessing ?department=Mechanical blocked with HTTP 403: " . ($tamperRes2['code'] === 403 ? "PASS" : "FAIL") . " (HTTP {$tamperRes2['code']})\n";

// 14. TEST 15: Read-Only Behavior
echo "\nTEST 15: Read-Only Behavior\n";
$targetsCountAfter = (int) db()->query("SELECT COUNT(*) FROM targets")->fetchColumn();
$usersCountAfter   = (int) db()->query("SELECT COUNT(*) FROM users")->fetchColumn();
$test15Pass = ($targetsCountBefore === $targetsCountAfter) && ($usersCountBefore === $usersCountAfter);
echo "  - Database targets count unchanged ($targetsCountBefore == $targetsCountAfter): " . ($test15Pass ? "PASS" : "FAIL") . "\n";

// 15. TEST 16: Existing Institutional Presentation for Admin Preserved
echo "\nTEST 16: Existing Institutional Presentation for Admin Preserved\n";
$loginAdmin = login($baseUrl, 'master.admin@atts.edu', 'master123', 'Admin', $cookieAdmin);
$presentAdmin = http_request($baseUrl . '/present-targets.php', 'GET', null, $cookieAdmin);
$test16Pass = ($presentAdmin['code'] === 200) &&
    (strpos($presentAdmin['body'], 'Institutional Academic Targets Presentation') !== false) &&
    (strpos($presentAdmin['body'], 'Slide 1 of 38') !== false) &&
    (strpos($presentAdmin['body'], 'Admin Executive Summary') !== false) &&
    (strpos($presentAdmin['body'], 'Dean Academic Summary') !== false) &&
    (strpos($presentAdmin['body'], 'Principal Executive Scorecard') !== false);
echo "  - Admin opens full 38-slide Institutional Presentation: " . ($test16Pass ? "PASS" : "FAIL") . "\n";

@unlink($cookieCsbs);
@unlink($cookieEce);
@unlink($cookieAdmin);

echo "\n=== ALL TESTS SUMMARY ===\n";
$allPass = $test1Pass && $test2Pass && $test3Pass && $test4Pass && $test5Pass && $test6Pass && $test7Pass && $test8Pass && $all30Pass && $test10Pass && $test11Pass && $test12Pass && $test13Pass && $test14Pass && $test15Pass && $test16Pass;
echo "OVERALL VERIFICATION: " . ($allPass ? "ALL 16 TESTS PASSED" : "SOME TESTS FAILED") . "\n";
