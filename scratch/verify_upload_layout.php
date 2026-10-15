<?php
/**
 * Verification test suite for ATTS Upload UI Alignment & 30-Metric Navigation
 */
require_once __DIR__ . '/../php-app/inc/auth.php';
require_once __DIR__ . '/../php-app/inc/upload_sections.php';

$base = 'http://localhost:8000/php-app';
$cookieFile = __DIR__ . '/test_cookie_layout.txt';
if (file_exists($cookieFile)) {
    unlink($cookieFile);
}

// 1. Log in as Faculty
$ch = curl_init("$base/login.php");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR      => $cookieFile,
    CURLOPT_COOKIEFILE     => $cookieFile,
]);
$body = curl_exec($ch);
preg_match('/name="csrf"[^>]*value="([^"]+)"/', $body, $cm);
$csrf = $cm[1] ?? '';

curl_setopt_array($ch, [
    CURLOPT_URL            => "$base/login.php",
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'csrf'     => $csrf,
        'role'     => 'Faculty',
        'email'    => 'faculty@atts.edu',
        'password' => 'faculty123',
    ]),
    CURLOPT_FOLLOWLOCATION => false,
]);
curl_exec($ch);

$fetchPage = function(string $url) use ($ch): string {
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => false,
        CURLOPT_HTTPGET        => true,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    return (string) curl_exec($ch);
};

$testsPassed = 0;
$testsFailed = 0;

function assertCheck(string $desc, bool $condition, string $detail = '') {
    global $testsPassed, $testsFailed;
    if ($condition) {
        $testsPassed++;
        echo "  [PASS] $desc" . ($detail ? " ($detail)" : "") . "\n";
    } else {
        $testsFailed++;
        echo "  [FAIL] $desc" . ($detail ? " ($detail)" : "") . "\n";
    }
}

echo "=================================================================\n";
echo "SCENARIO 1: First Metric (Metric 1: University Pass Percentage)\n";
echo "=================================================================\n";
$page1 = $fetchPage("$base/upload.php?section=upercent&type=inst_pass_percentage");
assertCheck("HTTP 200 returned for Metric 1", curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200);
assertCheck("Contains Institutional Academic Targets header", strpos($page1, 'Institutional Academic Targets') !== false);
assertCheck("Previous button is disabled at boundary (Metric 1)", preg_match('/<a\b[^>]*id="metricPrevBtn"[^>]*class="[^"]*\bdisabled\b[^"]*"|<a\b[^>]*class="[^"]*\bdisabled\b[^"]*"[^>]*id="metricPrevBtn"/i', $page1) === 1);
assertCheck("Next button links to Metric 2 (student_cgpa)", strpos($page1, 'id="metricNextBtn"') !== false && strpos($page1, 'section=student_cgpa') !== false);
assertCheck("Metric 1 tab has active class", preg_match('/class="[^"]*atts-metric-tab[^"]*active[^"]*"[^>]*data-section="upercent"/', $page1) === 1);
assertCheck("Metric 1 tab has aria-selected=true", preg_match('/data-section="upercent"[^>]*aria-selected="true"/', $page1) === 1);

echo "\n=================================================================\n";
echo "SCENARIO 2: Middle Metric with Subcategories (Metric 4: Quality Publications)\n";
echo "=================================================================\n";
$page4 = $fetchPage("$base/upload.php?section=publications&type=journal");
assertCheck("HTTP 200 returned for Metric 4", curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200);
assertCheck("Previous button links to Metric 3 (placement_mnc)", strpos($page4, 'id="metricPrevBtn"') !== false && strpos($page4, 'section=placement_mnc') !== false);
assertCheck("Previous button is NOT disabled", preg_match('/id="metricPrevBtn"[^>]*class="[^"]*disabled[^"]*"/', $page4) === 0);
assertCheck("Next button links to Metric 5 (books)", strpos($page4, 'id="metricNextBtn"') !== false && strpos($page4, 'section=books') !== false);
assertCheck("Next button is NOT disabled", preg_match('/id="metricNextBtn"[^>]*class="[^"]*disabled[^"]*"/', $page4) === 0);
assertCheck("Metric 4 tab has active class", preg_match('/class="[^"]*atts-metric-tab[^"]*active[^"]*"[^>]*data-section="publications"/', $page4) === 1);
assertCheck("Subcategory card is rendered", strpos($page4, 'atts-subcategory-card') !== false);
assertCheck("Subcategory Journal Publication pill is active", preg_match('/class="[^"]*atts-subcat-pill[^"]*active[^"]*"[^>]*[\s\S]*?Journal Publication/', $page4) === 1);
assertCheck("Subcategory Conference Publication pill exists", strpos($page4, 'Conference Publication') !== false);
assertCheck("Supporting description is rendered", strpos($page4, 'Scopus, SCI, Springer, UGC CARE indexed journal') !== false);

echo "\n=================================================================\n";
echo "SCENARIO 3: Subcategory Switching (Conference Publication)\n";
echo "=================================================================\n";
$page4Conf = $fetchPage("$base/upload.php?section=publications&type=conference");
assertCheck("HTTP 200 returned for Conference subcategory", curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200);
assertCheck("Subcategory Conference Publication pill is active", preg_match('/class="[^"]*atts-subcat-pill[^"]*active[^"]*"[^>]*[\s\S]*?Conference Publication/', $page4Conf) === 1);
assertCheck("Conference upload form is rendered", strpos($page4Conf, 'Conference Name') !== false);

echo "\n=================================================================\n";
echo "SCENARIO 4: Final Metric (Metric 30: NSS / YRC)\n";
echo "=================================================================\n";
$page30 = $fetchPage("$base/upload.php?section=nss_activities&type=nss");
assertCheck("HTTP 200 returned for Metric 30", curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200);
assertCheck("Previous button links to Metric 29 (awards_recognitions)", strpos($page30, 'id="metricPrevBtn"') !== false && strpos($page30, 'section=awards_recognitions') !== false);
assertCheck("Next button is disabled at boundary (Metric 30)", preg_match('/<a\b[^>]*id="metricNextBtn"[^>]*class="[^"]*\bdisabled\b[^"]*"|<a\b[^>]*class="[^"]*\bdisabled\b[^"]*"[^>]*id="metricNextBtn"/i', $page30) === 1);
assertCheck("Metric 30 tab has active class", preg_match('/class="[^"]*atts-metric-tab[^"]*active[^"]*"[^>]*data-section="nss_activities"/', $page30) === 1);
assertCheck("Metric 30 tab has aria-selected=true", preg_match('/data-section="nss_activities"[^>]*aria-selected="true"/', $page30) === 1);

echo "\n=================================================================\n";
echo "SCENARIO 5: All 30 Approved Metrics Integrity & Order\n";
echo "=================================================================\n";
$approvedSections = upload_approved_sections();
assertCheck("Exactly 30 approved sections defined", count($approvedSections) === 30);
$foundCount = 0;
$orderCorrect = true;
$expectedOrder = 1;
foreach ($approvedSections as $secId => $sec) {
    if ((int)$sec['order'] !== $expectedOrder) {
        $orderCorrect = false;
    }
    if (strpos($page4, 'data-section="' . $secId . '"') !== false) {
        $foundCount++;
    }
    $expectedOrder++;
}
assertCheck("All 30 metrics rendered in DOM tabs", $foundCount === 30, "Found $foundCount of 30");
assertCheck("Metric orders sequential 1 to 30", $orderCorrect);

echo "\n=================================================================\n";
echo "SCENARIO 6: Quick Jump Dropdown Verification\n";
echo "=================================================================\n";
preg_match('/<select[^>]*id="quickJumpSelect"[^>]*>([\s\S]*?)<\/select>/', $page4, $mSelect);
$selectHtml = $mSelect[1] ?? '';
$optCount = substr_count($selectHtml, '<option');
assertCheck("Quick Jump dropdown has exactly 30 options", $optCount === 30, "Count: $optCount");
assertCheck("Quick Jump has optgroups for target categories", strpos($selectHtml, '<optgroup label="RESEARCH AND PUBLICATIONS">') !== false);
assertCheck("Current metric selected in dropdown", preg_match('/<option[^>]*section=publications[^>]*selected/', $selectHtml) === 1);

echo "\n=================================================================\n";
echo "SCENARIO 7: Long Metric Labels Readability & Tooltips\n";
echo "=================================================================\n";
assertCheck("Long label Metric 4 has full title tooltip", strpos($page4, 'title="4. Quality Publications in Scopus / SCI / Springer / UGC CARE / H-Index"') !== false);
assertCheck("Long label Metric 14 has full title tooltip", strpos($page4, 'title="14. Quality Student Projects and Publish the Projects on YouTube"') !== false);
assertCheck("Long label Metric 20 has full title tooltip", strpos($page4, 'title="20. Student Participation in Inter-Institute Events and Awards / Medals"') !== false);

echo "\n=================================================================\n";
echo "SCENARIO 8: CSS Design Tokens & Classes Verification\n";
echo "=================================================================\n";
$css = file_get_contents(__DIR__ . '/../php-app/assets/css/app.css');
assertCheck("app.css contains .atts-metric-nav-card", strpos($css, '.atts-metric-nav-card') !== false);
assertCheck("app.css contains .atts-targets-header", strpos($css, '.atts-targets-header') !== false);
assertCheck("app.css contains .atts-metric-tab", strpos($css, '.atts-metric-tab') !== false);
assertCheck("app.css contains .atts-metric-indicator", strpos($css, '.atts-metric-indicator') !== false);
assertCheck("app.css contains .atts-subcategory-card", strpos($css, '.atts-subcategory-card') !== false);
assertCheck("app.css contains primary orange token #FF4F01", strpos($css, '#FF4F01') !== false);
assertCheck("app.css contains mobile responsive media query (max-width: 768px)", strpos($css, '@media (max-width: 768px)') !== false);
assertCheck("app.css contains tablet responsive media query (max-width: 1024px)", strpos($css, '@media (max-width: 1024px)') !== false);

echo "\n=================================================================\n";
echo "SUMMARY: Tests Passed: $testsPassed | Tests Failed: $testsFailed\n";
echo "=================================================================\n";
if ($testsFailed > 0) {
    exit(1);
}
