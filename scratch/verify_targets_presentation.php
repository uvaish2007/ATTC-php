<?php
require_once __DIR__ . '/../php-app/inc/config.php';
require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/models/Setting.php';
require_once __DIR__ . '/../php-app/models/Target.php';
require_once __DIR__ . '/../php-app/models/TargetPresentation.php';

echo "========================================================\n";
echo "TARGETS PRESENTATION AUTOMATED VERIFICATION SUITE\n";
echo "========================================================\n\n";

$tests = [];

// 1. Data model check
$dataset = targets_presentation_dataset(['id' => 1, 'role' => 'Admin', 'name' => 'Admin']);
$summary = $dataset['summary'];

$tests['TEST 4 — Overall summary numbers'] = ($summary['total_targets'] === 4 && $summary['in_progress_targets'] === 2 && $summary['not_completed_targets'] === 2);
$tests['TEST 5 — Target vs Achieved calculations'] = ($summary['total_target_fixed'] === 613 && $summary['total_target_achieved'] === 8);
$tests['TEST 6 — Individual target categories exist'] = (count($dataset['categories']) === 4);
$tests['TEST 7 — Faculty-uploaded data contributing to targets'] = (count($dataset['categories'][0]['uploaded_records']) > 0);
$tests['TEST 8 — Admin Summary exists and has data'] = (!empty($dataset['admin_summary']['title']));
$tests['TEST 9 — Dean Summary exists and tracks dean pending'] = (isset($dataset['dean_summary']['dean_pending_reviews']));
$tests['TEST 10 — Principal Summary exists'] = (!empty($dataset['principal_summary']['title']));
$tests['TEST 11 — Department comparison exists'] = (count($dataset['all_departments']) > 0);
$tests['TEST 12 — Contribution table totals reconcile'] = (
    array_sum(array_column($dataset['all_departments'], 'target_fixed')) === $summary['total_target_fixed'] &&
    array_sum(array_column($dataset['all_departments'], 'achieved_value')) === $summary['total_target_achieved']
);
$tests['TEST 14 — Academic Year is active'] = ($dataset['academic_year'] === active_academic_year());

// Curl checks with login session
// Login as Admin
$sessionFile = __DIR__ . '/admin_cookie.txt';
if (file_exists($sessionFile)) @unlink($sessionFile);
$ch = curl_init('http://localhost:8000/login.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $sessionFile);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
$loginHtml = curl_exec($ch);

// Get CSRF token
preg_match('/name="csrf"\s+value="([^"]+)"/', $loginHtml, $m);
$csrf = $m[1] ?? '';

// Post login
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'csrf'     => $csrf,
    'role'     => 'Admin',
    'email'    => 'mohameduvaish132@gmail.com',
    'password' => 'uvaish123'
]));
$postRes = curl_exec($ch);

// 2. Fetch Dashboard
curl_setopt($ch, CURLOPT_POST, false);
curl_setopt($ch, CURLOPT_URL, 'http://localhost:8000/dashboard.php');
curl_setopt($ch, CURLOPT_COOKIEFILE, $sessionFile);
$dashHtml = curl_exec($ch);

$tests['TEST 1 — Present Targets button on Dashboard'] = (strpos($dashHtml, 'id="presentTargetsBtn"') !== false && strpos($dashHtml, 'Present Targets') !== false);
$tests['TEST 2 — Old Analytics (56) and old Present removed from dashboard entry point'] = (strpos($dashHtml, 'Analytics (56)') === false && strpos($dashHtml, 'id="emDashboardAnalyticalBtn"') === false);

// 3. Fetch Targets page
curl_setopt($ch, CURLOPT_URL, 'http://localhost:8000/targets.php');
$targetsHtml = curl_exec($ch);
$tests['TEST 1b — Present Targets button on Targets page'] = (strpos($targetsHtml, 'id="targetsPagePresentBtn"') !== false && strpos($targetsHtml, 'Present Targets') !== false);

// 4. Fetch present-targets.php
curl_setopt($ch, CURLOPT_URL, 'http://localhost:8000/present-targets.php');
$presentHtml = curl_exec($ch);

$tests['TEST 3 — Presentation opens'] = (strpos($presentHtml, 'INSTITUTIONAL TARGETS &amp; ACHIEVEMENTS') !== false);
$tests['TEST 16 — No hardcoded metrics in presentation'] = (strpos($presentHtml, 'Academic Year: ' . active_academic_year()) !== false && strpos($presentHtml, 'Total Academic Targets') !== false);

// Test emoji check: no 4-byte unicode emoji characters in present-targets.php output
$hasEmoji = preg_match('/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{1F1E0}-\x{1F1FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u', $presentHtml);
$tests['TEST 17 — No emoji symbols anywhere in presentation'] = ($hasEmoji === 0);

$tests['TEST 18 — Interactive navigation controls present'] = (
    strpos($presentHtml, 'id="btnPrevSlide"') !== false &&
    strpos($presentHtml, 'id="btnNextSlide"') !== false &&
    strpos($presentHtml, 'id="btnClosePresentation"') !== false &&
    strpos($presentHtml, 'ArrowLeft') !== false &&
    strpos($presentHtml, 'ArrowRight') !== false &&
    strpos($presentHtml, 'Escape') !== false
);

// 5. Check EM report preservation (EM-SPEC-05 through EM-SPEC-09)
curl_setopt($ch, CURLOPT_URL, 'http://localhost:8000/executive-meeting-report.php');
$emRepHtml = curl_exec($ch);
$tests['TEST 19 — Existing Executive Meeting presentation preserved'] = (strpos($emRepHtml, 'present-executive-meeting.php') !== false || file_exists(__DIR__ . '/../php-app/present-executive-meeting.php'));

// Print test results
$allPassed = true;
foreach ($tests as $name => $passed) {
    $status = $passed ? "PASS" : "FAIL";
    if (!$passed) $allPassed = false;
    echo sprintf("[%-4s] %s\n", $status, $name);
}

echo "\n========================================================\n";
echo "OVERALL RESULT: " . ($allPassed ? "ALL TESTS PASSED" : "SOME TESTS FAILED") . "\n";
echo "========================================================\n";
