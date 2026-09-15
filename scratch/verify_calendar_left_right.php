<?php
$cookieFile = sys_get_temp_dir() . '/atts_cal_lr_cookie.txt';
if (file_exists($cookieFile)) unlink($cookieFile);

// Login
$ch = curl_init('http://localhost:8000/login.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
$loginHtml = curl_exec($ch);

preg_match('/name="csrf" value="([^"]+)"/', $loginHtml, $m);
$csrf = $m[1] ?? '';

$ch = curl_init('http://localhost:8000/login.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'csrf' => $csrf,
    'email' => 'director@atts.edu',
    'password' => 'principal123',
    'role' => 'Principal',
]));
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
$res = curl_exec($ch);

if (strpos($res, 'Select Academic Year') !== false) {
    preg_match('/name="_csrf" value="([^"]+)"/', $res, $m2);
    $ch = curl_init('http://localhost:8000/select-year.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        '_csrf' => $m2[1] ?? '',
        'action' => 'select_year',
        'academic_year' => '2026-27',
    ]));
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_exec($ch);
}
unset($ch);

function fetch(string $qs, string $cookieFile): string {
    $url = 'http://localhost:8000/announcements.php' . ($qs ? '?' . $qs : '');
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    return curl_exec($ch);
}

echo "=== 1. CHECK DEFAULT PRESENT MONTH (SEPTEMBER 2026) ===\n";
$htmlSep = fetch('', $cookieFile);

$hasPrevMonth = strpos($htmlSep, 'title="Past Month (Left Arrow)"') !== false;
$hasNextDisabled = strpos($htmlSep, 'Future months are not available') !== false;
// Check SVG icons for chevron-left
$hasSvgLeftSep = strpos($htmlSep, 'points="15 18 9 12 15 6"') !== false;

echo "Prev Month (Left Arrow): " . ($hasPrevMonth ? "PASS" : "FAIL") . "\n";
echo "Next Month Disabled for Future: " . ($hasNextDisabled ? "PASS" : "FAIL") . "\n";
echo "SVG Left rendered: " . ($hasSvgLeftSep ? "PASS" : "FAIL") . "\n";

echo "\n=== 2. CHECK PAST MONTH (AUGUST 2026) ===\n";
$htmlAug = fetch('cal_year=2026&cal_month=8', $cookieFile);

$augHasPrev = strpos($htmlAug, 'cal_month=7') !== false && strpos($htmlAug, 'title="Past Month (Left Arrow)"') !== false;
$augHasNext = strpos($htmlAug, 'title="Next Month (Right Arrow)"') !== false;
$hasSvgLeft = strpos($htmlAug, 'points="15 18 9 12 15 6"') !== false;
$hasSvgRight = strpos($htmlAug, 'points="9 18 15 12 9 6"') !== false;

echo "August Left Arrow (July): " . ($augHasPrev ? "PASS" : "FAIL") . "\n";
echo "August Right Arrow (September): " . ($augHasNext ? "PASS" : "FAIL") . "\n";
echo "SVG chevron-left rendered: " . ($hasSvgLeft ? "PASS" : "FAIL") . "\n";
echo "SVG chevron-right rendered: " . ($hasSvgRight ? "PASS" : "FAIL") . "\n";

echo "\n=== 3. CHECK SPECIFIC DATE STEPPER (AUGUST 15, 2026) ===\n";
$htmlDay = fetch('cal_year=2026&cal_month=8&cal_day=15', $cookieFile);

$hasDateLeft = strpos($htmlDay, 'title="Previous Date (Left Arrow)"') !== false && strpos($htmlDay, 'cal_day=14') !== false;
$hasDateRight = strpos($htmlDay, 'title="Next Date (Right Arrow)"') !== false && strpos($htmlDay, 'cal_day=16') !== false;

echo "Date Stepper Left Arrow (day 14): " . ($hasDateLeft ? "PASS" : "FAIL") . "\n";
echo "Date Stepper Right Arrow (day 16): " . ($hasDateRight ? "PASS" : "FAIL") . "\n";

$allPassed = $hasPrevMonth && $hasNextDisabled && $augHasPrev && $augHasNext && $hasSvgLeft && $hasSvgRight && $hasDateLeft && $hasDateRight;
if ($allPassed) {
    echo "\n>>> ALL LEFT & RIGHT ARROW CHECKS PASSED! <<<\n";
} else {
    echo "\n>>> CHECKS FAILED! <<<\n";
}
