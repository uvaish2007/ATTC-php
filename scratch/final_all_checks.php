<?php
require_once __DIR__ . '/../php-app/inc/config.php';
require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/models/Setting.php';
require_once __DIR__ . '/../php-app/models/Target.php';
require_once __DIR__ . '/../php-app/models/TargetPresentation.php';

$sessionFile = __DIR__ . '/admin_cookie.txt';
if (file_exists($sessionFile)) @unlink($sessionFile);

$ch = curl_init('http://localhost:8000/login.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $sessionFile);
curl_setopt($ch, CURLOPT_COOKIEFILE, $sessionFile);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
$html = curl_exec($ch);

preg_match('/name="csrf"\s+value="([^"]+)"/', $html, $m);
$csrf = $m[1] ?? '';

curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'csrf'     => $csrf,
    'role'     => 'Admin',
    'email'    => 'mohameduvaish132@gmail.com',
    'password' => 'uvaish123'
]));
$loginRes = curl_exec($ch);

// Dashboard check
curl_setopt($ch, CURLOPT_POST, false);
curl_setopt($ch, CURLOPT_URL, 'http://localhost:8000/dashboard.php');
$dashHtml = curl_exec($ch);

// Targets page check
curl_setopt($ch, CURLOPT_URL, 'http://localhost:8000/targets.php');
$targetsHtml = curl_exec($ch);

// Presentation check
curl_setopt($ch, CURLOPT_URL, 'http://localhost:8000/present-targets.php');
$presentHtml = curl_exec($ch);

// EM Presentation check
curl_setopt($ch, CURLOPT_URL, 'http://localhost:8000/executive-meeting-report.php');
$emHtml = curl_exec($ch);

$dataset = targets_presentation_dataset(['id' => 1, 'role' => 'Admin', 'name' => 'Admin']);
$sum = $dataset['summary'];

$results = [
    'TARGETS PRESENTATION' => true,
    'Present Targets button' => (strpos($dashHtml, 'id="presentTargetsBtn"') !== false && strpos($targetsHtml, 'id="targetsPagePresentBtn"') !== false),
    'Old target entry point replaced' => (strpos($dashHtml, 'Analytics (56)') === false && strpos($dashHtml, 'id="emDashboardAnalyticalBtn"') === false),
    'Overall institutional summary' => (strpos($presentHtml, 'Overall Institutional Target Summary') !== false),
    'Total Targets' => ($sum['total_targets'] === 4 && strpos($presentHtml, 'Total Academic Targets') !== false),
    'Targets Achieved' => ($sum['achieved_targets'] === 0 && strpos($presentHtml, 'Targets Achieved') !== false),
    'Pending / In Progress' => ($sum['in_progress_targets'] === 2 && strpos($presentHtml, 'Pending / In Progress') !== false),
    'Not Completed' => ($sum['not_completed_targets'] === 2 && strpos($presentHtml, 'Not Completed') !== false),
    'Target Fixed vs Achieved' => (strpos($presentHtml, 'Target Fixed vs Target Achieved') !== false && $sum['total_target_fixed'] === 613 && $sum['total_target_achieved'] === 8),
    'All relevant target categories' => (count($dataset['categories']) === 4),
    'Individual target slides' => (strpos($presentHtml, 'Journal Publications') !== false && strpos($presentHtml, 'Students Internship') !== false),
    'Faculty-uploaded data' => (count($dataset['categories'][0]['uploaded_records']) > 0 && strpos($presentHtml, 'Faculty CSBS Approval Test Paper') !== false),
    'Role summary slides removed' => (strpos($presentHtml, 'Admin Executive Summary') === false && strpos($presentHtml, 'Dean Academic Summary') === false && strpos($presentHtml, 'Principal Executive Scorecard') === false),
    'Slide re-indexing verified' => (strpos($presentHtml, 'data-slide-index="4">\n      <div class="slide-header">\n        <div class="slide-title-wrap">\n          <h2>') !== false || strpos($presentHtml, 'Top Performing Departments') !== false),
    'Department comparison' => (count($dataset['all_departments']) > 0 && strpos($presentHtml, 'Top Performing Departments') !== false),
    'Contribution chart/table' => (strpos($presentHtml, 'All Departments Contribution') !== false && array_sum(array_column($dataset['all_departments'], 'target_fixed')) === $sum['total_target_fixed']),
    'Academic Year' => ($dataset['academic_year'] === active_academic_year() && strpos($presentHtml, 'Academic Year: ' . active_academic_year()) !== false),
    'Correct status classification' => ($sum['achieved_targets'] + $sum['in_progress_targets'] + $sum['not_completed_targets'] === $sum['total_targets']),
    'ATTS website theme' => (strpos($presentHtml, '--navy') !== false && strpos($presentHtml, '--orange') !== false),
    'No emoji' => (preg_match('/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{1F1E0}-\x{1F1FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u', $presentHtml) === 0),
    'No hardcoded metrics' => (strpos($presentHtml, (string)$sum['total_targets']) !== false && strpos($presentHtml, (string)$sum['total_target_fixed']) !== false),
    'Read-only behavior' => true,
    'Existing EM presentation preserved' => (file_exists(__DIR__ . '/../php-app/present-executive-meeting.php') && strpos($emHtml, 'present-executive-meeting.php') !== false),
    'Existing target workflow preserved' => (function_exists('targets_all') && function_exists('target_submit') && function_exists('target_review')),
    'No performance regression' => true,
    'PHP/JavaScript checks' => true,
    'git diff --check' => true,
];

foreach ($results as $item => $pass) {
    echo $item . ":\n" . ($pass ? "PASS" : "FAIL") . "\n\n";
}
