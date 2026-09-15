<?php
// Verify Calendar Async Navigation & No-Refresh Implementation
$cookieFile = sys_get_temp_dir() . '/atts_cal_ajax_cookie.txt';
if (file_exists($cookieFile)) unlink($cookieFile);

// Step 1: GET login page to get CSRF
$ch = curl_init('http://localhost:8000/login.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
$loginHtml = curl_exec($ch);

preg_match('/name="csrf" value="([^"]+)"/', $loginHtml, $m);
$csrf = $m[1] ?? '';

// Step 2: POST login as Principal
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

// If redirected to select-year.php, complete year selection:
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

function fetch_announcements(string $queryString, string $cookieFile): string {
    $url = 'http://localhost:8000/announcements.php' . ($queryString ? '?' . $queryString : '');
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    return curl_exec($ch);
}

$html = fetch_announcements('', $cookieFile);

$checks = [
    'Container #announcements_notices_col' => strpos($html, 'id="announcements_notices_col"') !== false,
    'Container #announcements_calendar_card' => strpos($html, 'id="announcements_calendar_card"') !== false,
    'Container #announcements_modals_container' => strpos($html, 'id="announcements_modals_container"') !== false,
    'Container #announcements_stats' => strpos($html, 'id="announcements_stats"') !== false,
    'CSS .cal-loading' => strpos($html, '.cal-loading') !== false,
    'CSS transitions for smooth update' => strpos($html, '#announcements_notices_col,') !== false,
    'Class cal-async-link present' => strpos($html, 'cal-async-link') !== false,
    'Function openAnnModal' => strpos($html, 'function openAnnModal(') !== false,
    'Function loadCalendarAsync' => strpos($html, 'function loadCalendarAsync(') !== false,
    'Event delegation click handler' => strpos($html, 'announcements_calendar_card a, .cal-async-link') !== false,
    'Popstate handler' => strpos($html, "addEventListener('popstate'") !== false,
    'Initial history state replace' => strpos($html, 'replaceState({ calUrl:') !== false,
];

$allPassed = true;
echo "=== VERIFY CALENDAR AJAX ASYNC UPDATE (NO PAGE REFRESH) ===\n";
foreach ($checks as $name => $ok) {
    echo ($ok ? "[PASS] " : "[FAIL] ") . $name . "\n";
    if (!$ok) $allPassed = false;
}

// Test requesting with past month (August 2026)
$augHtml = fetch_announcements('cal_year=2026&cal_month=8', $cookieFile);

$augChecks = [
    'August 2026 title present' => strpos($augHtml, 'August 2026') !== false,
    'August Return to Present Month link with cal-async-link' => strpos($augHtml, 'Return to Present Month') !== false && strpos($augHtml, 'cal-async-link') !== false,
    'August cal-nav-btn present' => strpos($augHtml, 'cal-nav-btn') !== false,
];

echo "\n=== VERIFY AUGUST 2026 RESPONSE ===\n";
foreach ($augChecks as $name => $ok) {
    echo ($ok ? "[PASS] " : "[FAIL] ") . $name . "\n";
    if (!$ok) $allPassed = false;
}

// Test requesting with specific day (August 18)
$dayHtml = fetch_announcements('cal_year=2026&cal_month=8&cal_day=18', $cookieFile);

$dayChecks = [
    'August 18 date banner present' => strpos($dayHtml, '18 August 2026') !== false,
    'View all month link with cal-async-link' => strpos($dayHtml, 'View All Aug 2026') !== false && strpos($dayHtml, 'cal-async-link') !== false,
];

echo "\n=== VERIFY AUGUST 18 2026 RESPONSE ===\n";
foreach ($dayChecks as $name => $ok) {
    echo ($ok ? "[PASS] " : "[FAIL] ") . $name . "\n";
    if (!$ok) $allPassed = false;
}

if ($allPassed) {
    echo "\n>>> ALL VERIFICATION CHECKS PASSED! <<<\n";
} else {
    echo "\n>>> SOME VERIFICATION CHECKS FAILED! <<<\n";
}
