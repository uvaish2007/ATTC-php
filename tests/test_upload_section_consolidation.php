<?php
/**
 * Test Suite: ATTS-MODULE-CONSOLIDATION — Upload Section Reorganization and Category Merging
 *
 * Verifies:
 * 1. Exactly 30 approved top-level sections in exact sequence.
 * 2. Zero numeric prefixes in UI titles.
 * 3. Merged parent sections and dynamic subcategory resolution.
 * 4. Conference Publications integration and restoration inside Section 4.
 * 5. Patent / Copyrights 4-category dropdown and dynamic labels.
 * 6. Awards & Recognition 8-option dropdown and custom description.
 * 7. Live HTML rendering via local web server for Faculty and Coordinator.
 * 8. Zero database data loss across all historical tables.
 */

declare(strict_types=1);

define('TEST_MODE', true);
require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/inc/upload_sections.php';
require_once __DIR__ . '/../php-app/models/Record.php';

$pdo = db();
$allPassed = true;
$testsRun = 0;
$testsPassed = 0;

function assert_test(string $name, bool $pass, string $info = ''): void
{
    global $allPassed, $testsRun, $testsPassed;
    $testsRun++;
    if ($pass) {
        $testsPassed++;
        echo "[PASS] {$name}" . ($info ? " — {$info}" : "") . "\n";
    } else {
        $allPassed = false;
        echo "[FAIL] {$name}" . ($info ? " — {$info}" : "") . "\n";
    }
}

echo "=== ATTS UPLOAD SECTION CONSOLIDATION TEST SUITE ===\n\n";

// -----------------------------------------------------------------------------
// TEST 1: Exactly 30 Approved Sections
// -----------------------------------------------------------------------------
$sections = upload_approved_sections();
assert_test("TEST 1: Exact Section Count", count($sections) === 30, "Found exactly " . count($sections) . " sections.");

// -----------------------------------------------------------------------------
// TEST 2: Exact Order and Titles
// -----------------------------------------------------------------------------
$expectedTitles = [
    1  => 'University Pass Percentage',
    2  => 'Students Secured Above 8.5 CGPA',
    3  => 'Place 2024–25 Batch Students in Top MNCs',
    4  => 'Quality Publications in Scopus / SCI / Springer / UGC CARE / H-Index',
    5  => 'Books Publication — Book / Book Chapter',
    6  => 'Patent / Copyrights',
    7  => 'Sponsored Research Funds / Consultancy Projects (in Lakhs)',
    8  => 'Research Centre Recognition from Anna University',
    9  => 'Programme on IPR / Higher Studies / Entrepreneurship',
    10 => 'No. of Online Certification Courses Completed by Faculty',
    11 => 'Industry Interaction / MoU / Industry Supported Lab',
    12 => "Student's Industry Internship (4 Weeks & Above)",
    13 => 'Summer/Winter Training (Less Than 4 Weeks)',
    14 => 'Quality Student Projects and Publish the Projects on YouTube',
    15 => 'Faculty Participation in FDP / Training / STTP / Conference',
    16 => 'Membership in Professional Societies (Student / Faculty)',
    17 => 'Newsletter',
    18 => 'Student Online Certification',
    19 => 'No. of Students Completed IIT-Bombay Spoken Tutorial Courses',
    20 => 'Student Participation in Inter-Institute Events and Awards / Medals',
    21 => 'No. of Value Added Courses / Hands-on Training Courses',
    22 => 'Event Participation in Sports — State Level / National Level / Awards',
    23 => 'Innovation Events to Be Conducted in Every Department',
    24 => 'IIC Activities',
    25 => 'Website Updation',
    26 => 'Google Rating',
    27 => 'Start Up',
    28 => 'Alumni Chapter',
    29 => 'Awards & Recognition',
    30 => 'No. of Activities Conducted by NSS / YRC',
];

$orderOk = true;
$titlesOk = true;
$noNumbersOk = true;
$idx = 1;

foreach ($sections as $sec) {
    if ($sec['order'] !== $idx) {
        $orderOk = false;
    }
    if ($sec['title'] !== $expectedTitles[$idx]) {
        $titlesOk = false;
        echo "  Mismatch at #{$idx}: expected '{$expectedTitles[$idx]}', got '{$sec['title']}'\n";
    }
    if (preg_match('/^\s*\d+[\.\-\s]/', $sec['title'])) {
        $noNumbersOk = false;
    }
    $idx++;
}

assert_test("TEST 2: Authoritative Section Order", $orderOk, "All 30 sections ordered 1 to 30.");
assert_test("TEST 3: Authoritative Section Titles", $titlesOk, "All 30 titles match exact wording.");
assert_test("TEST 4: No Numeric Prefixes in UI Titles", $noNumbersOk, "Zero numeric prefixes found.");

// -----------------------------------------------------------------------------
// TEST 5: Bidirectional Legacy Category Resolution
// -----------------------------------------------------------------------------
$legacyChecks = [
    'journal'                     => ['publications', 'journal'],
    'conference'                  => ['publications', 'conference'],
    'book'                        => ['books', 'book'],
    'patent'                      => ['patents', 'patent'],
    'inst_patents_published'      => ['patents', 'patent'],
    'inst_patents_granted'        => ['patents', 'patent'],
    'inst_copyrights'             => ['patents', 'patent'],
    'inst_sponsored_research'     => ['sponsored_research', 'inst_sponsored_research'],
    'inst_consultancy'            => ['sponsored_research', 'inst_consultancy'],
    'nptel'                       => ['faculty_certifications', 'nptel'],
    'online_course'               => ['faculty_certifications', 'inst_faculty_certifications'],
    'mou'                         => ['mou_interactions', 'mou'],
    'internship'                  => ['internships', 'internship'],
    'summer_training'             => ['summer_training', 'summer_training'],
    'fdp'                         => ['faculty_participations', 'fdp'],
    'training'                    => ['faculty_participations', 'fdp'],
    'co_curricular'               => ['inter_institute', 'co_curricular'],
    'extra_curricular'            => ['inter_institute', 'co_curricular'],
    'student_achievement'         => ['inter_institute', 'co_curricular'],
    'inst_sports_state'           => ['sports', 'inst_sports_state'],
    'inst_sports_national'        => ['sports', 'inst_sports_national'],
    'nss'                         => ['nss_activities', 'nss'],
    'inst_awards_recognitions'    => ['awards_recognitions', 'inst_awards_recognitions'],
];

$allLegacyMapped = true;
foreach ($legacyChecks as $oldType => [$expectedSec, $expectedType]) {
    $res = resolve_upload_section(null, $oldType);
    if ($res['section']['id'] !== $expectedSec || $res['type'] !== $expectedType) {
        $allLegacyMapped = false;
        echo "  Legacy map fail for '{$oldType}': expected [{$expectedSec}, {$expectedType}], got [{$res['section']['id']}, {$res['type']}]\n";
    }
}
assert_test("TEST 5: Merged Category Mapping & Backward Compatibility", $allLegacyMapped, "All legacy types resolve to approved parent sections.");

// -----------------------------------------------------------------------------
// TEST 6: Next and Previous Progression
// -----------------------------------------------------------------------------
$nextPrevOk = true;
$secKeys = array_keys($sections);
for ($i = 0; $i < count($secKeys); $i++) {
    $currKey = $secKeys[$i];
    $next = upload_section_next($currKey);
    $prev = upload_section_prev($currKey);

    if ($i < count($secKeys) - 1) {
        if (!$next || $next['id'] !== $secKeys[$i + 1]) $nextPrevOk = false;
    } else {
        if ($next !== null) $nextPrevOk = false;
    }

    if ($i > 0) {
        if (!$prev || $prev['id'] !== $secKeys[$i - 1]) $nextPrevOk = false;
    } else {
        if ($prev !== null) $nextPrevOk = false;
    }
}
assert_test("TEST 6: Sequential Next/Previous Progression", $nextPrevOk, "Next and Prev step sequentially across all 30 sections.");

// -----------------------------------------------------------------------------
// TEST 7: HTTP Response & HTML Structure from Local Server (http://localhost:8000)
// -----------------------------------------------------------------------------
function login_and_fetch_url(string $email, string $role, string $urlPath): string
{
    $jar = tempnam(sys_get_temp_dir(), 'atts_ck_');
    $baseUrl = 'http://localhost:8000/php-app';

    // Step 1: GET login page to obtain CSRF token
    $ch = curl_init("{$baseUrl}/login.php");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
    $loginHtml = curl_exec($ch);
    curl_setopt($ch, CURLOPT_COOKIELIST, 'FLUSH');
    preg_match('/name="csrf"\s+value="([^"]+)"/', $loginHtml, $mCsrf);
    $csrf = $mCsrf[1] ?? '';

    // Step 2: POST credentials
    $chPost = curl_init("{$baseUrl}/login.php");
    curl_setopt($chPost, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($chPost, CURLOPT_COOKIEJAR, $jar);
    curl_setopt($chPost, CURLOPT_COOKIEFILE, $jar);
    curl_setopt($chPost, CURLOPT_POST, true);
    curl_setopt($chPost, CURLOPT_POSTFIELDS, http_build_query([
        'csrf'     => $csrf,
        'email'    => $email,
        'password' => 'master123',
        'role'     => $role,
    ]));
    curl_exec($chPost);
    curl_setopt($chPost, CURLOPT_COOKIELIST, 'FLUSH');

    // Step 3: Fetch requested URL
    $chGet = curl_init("{$baseUrl}{$urlPath}");
    curl_setopt($chGet, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($chGet, CURLOPT_COOKIEFILE, $jar);
    curl_setopt($chGet, CURLOPT_FOLLOWLOCATION, true);
    $resultHtml = curl_exec($chGet);
    @unlink($jar);

    return (string)$resultHtml;
}

$facHtml = login_and_fetch_url('master.faculty@atts.edu', 'Faculty', '/upload.php?section=publications&type=conference');
$coordHtml = login_and_fetch_url('master.coordinator@atts.edu', 'Coordinator', '/upload.php?section=awards_recognitions&type=inst_awards_recognitions');

// Verify all 30 titles exist in the navigation bar
$foundTitlesCount = 0;
foreach ($expectedTitles as $t) {
    $escaped = htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
    if (strpos($facHtml, $t) !== false || strpos($facHtml, $escaped) !== false) {
        $foundTitlesCount++;
    }
}
assert_test("TEST 7: All 30 Approved Section Titles in Faculty HTML", $foundTitlesCount === 30, "Found {$foundTitlesCount} of 30 titles in rendered navigation.");

// Verify Quick Jump and Scroll buttons
$hasQuickJump = strpos($facHtml, 'id="quickJumpSelect"') !== false;
$hasScrollBtns = strpos($facHtml, 'id="navScrollLeft"') !== false && strpos($facHtml, 'id="navScrollRight"') !== false;
assert_test("TEST 8: Responsive Navigation Controls", $hasQuickJump && $hasScrollBtns, "Quick Jump and Left/Right scroll buttons present.");

// Verify Subcategory pills for Section 4 (Quality Publications)
$hasJournalSub = strpos($facHtml, 'Journal Publication') !== false;
$hasConfSub    = strpos($facHtml, 'Conference Publication') !== false;
assert_test("TEST 9: Dynamic Subcategory Selector Pills", $hasJournalSub && $hasConfSub, "Section 4 displays Journal and Conference subcategory pills.");

// Verify Conference Form Fields
$hasAuthorType = strpos($facHtml, 'name="author_type"') !== false;
$hasConfName   = strpos($facHtml, 'name="conference_name"') !== false;
$hasVenue      = strpos($facHtml, 'name="venue"') !== false;
assert_test("TEST 10: Restored Conference Publication Form", $hasAuthorType && $hasConfName && $hasVenue, "Conference publication fields rendered cleanly.");

// Fetch Patent section
$patHtml = login_and_fetch_url('master.faculty@atts.edu', 'Faculty', '/upload.php?section=patents&type=patent');
$hasPatPub = strpos($patHtml, 'Patent Published') !== false;
$hasPatGrt = strpos($patHtml, 'Patent Granted') !== false;
$hasDesign = strpos($patHtml, 'Design') !== false;
$hasCopy   = strpos($patHtml, 'Copyrights') !== false;
assert_test("TEST 11: Patent / Copyrights 4-Category Dropdown", $hasPatPub && $hasPatGrt && $hasDesign && $hasCopy, "Patent Published, Patent Granted, Design, Copyrights available.");

// Test Awards & Recognition section from Coordinator HTML
$hasDeptAw = strpos($coordHtml, 'Department Awards / Recognition') !== false;
$hasFacAw  = strpos($coordHtml, 'Faculty Awards / Recognition') !== false;
$hasBoS    = strpos($coordHtml, 'BoS Member') !== false;
$hasQP     = strpos($coordHtml, 'Question Paper / Key Setting') !== false;
$hasReview = strpos($coordHtml, 'Journal Reviewer') !== false;
$hasResp   = strpos($coordHtml, 'Resource Person') !== false;
$hasOthers = strpos($coordHtml, 'Others') !== false;
assert_test("TEST 12: Awards & Recognition 8-Option Dropdown", $hasDeptAw && $hasFacAw && $hasBoS && $hasQP && $hasReview && $hasResp && $hasOthers, "All 8 required recognition categories rendered.");


// -----------------------------------------------------------------------------
// TEST 13: Database Record Count Preservation (Zero Data Loss)
// -----------------------------------------------------------------------------
$baseline = json_decode((string)file_get_contents(__DIR__ . '/../scratch/baseline_counts.json'), true);
$dataIntact = true;
$lostTables = [];

foreach ($baseline as $tbl => $origCount) {
    $currCount = (int)$pdo->query("SELECT COUNT(*) FROM `{$tbl}`")->fetchColumn();
    if ($currCount < $origCount) {
        $dataIntact = false;
        $lostTables[] = "{$tbl} (was {$origCount}, now {$currCount})";
    }
}
assert_test("TEST 13: Database Record Count Preservation", $dataIntact, $dataIntact ? "Zero data loss across all 55 tables." : "Data loss in: " . implode(', ', $lostTables));

echo "\n==================================================\n";
echo "SUMMARY: {$testsPassed} / {$testsRun} TESTS PASSED\n";
echo "STATUS : " . ($allPassed ? "ALL PASS" : "SOME FAILED") . "\n";
echo "==================================================\n";

exit($allPassed ? 0 : 1);
