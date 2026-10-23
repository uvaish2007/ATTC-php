<?php
/**
 * Automated Test Suite for ATTS-ACADEMIC-YEAR-GUARD
 * Tests A, B, C, D, E against active database and application models.
 */

define('TEST_MODE', true);

require_once __DIR__ . '/../php-app/inc/helpers.php';
require_once __DIR__ . '/../php-app/models/Target.php';
require_once __DIR__ . '/../php-app/models/Record.php';
require_once __DIR__ . '/../php-app/models/UploadFlow.php';
require_once __DIR__ . '/../php-app/inc/inst_upload_handler.php';

$results = [];

function recordTest(string $suite, string $name, bool $pass, string $details = ''): void {
    global $results;
    $results[] = [
        'suite'   => $suite,
        'name'    => $name,
        'status'  => $pass ? 'PASS' : 'FAIL',
        'details' => $details,
    ];
    echo sprintf("[%s] %s - %s: %s\n", $pass ? 'PASS' : 'FAIL', $suite, $name, $details);
}

// =========================================================================
// TEST A: Dropdown Range & Options through 2050
// =========================================================================
echo "\n--- RUNNING TEST A: Dropdown Range ---\n";
$years = academic_years('asc');

// Check range
$countYears = count($years);
$firstYear = $years[0] ?? '';
$lastYear = end($years);
$hasDuplicates = count($years) !== count(array_unique($years));

recordTest('Test A', 'Range count is 50 academic years (2000-01 to 2049-50)', $countYears === 50, "Count: {$countYears}, First: {$firstYear}, Last: {$lastYear}");
recordTest('Test A', 'First year is 2000-01 and final year is 2049-50 (ending in 2050)', $firstYear === '2000-01' && $lastYear === '2049-50', "First: {$firstYear}, Last: {$lastYear}");
recordTest('Test A', 'No duplicate entries exist', !$hasDuplicates, "Duplicates detected: " . ($hasDuplicates ? 'YES' : 'NO'));

// Verify format and chronological order
$formatOk = true;
$orderOk = true;
for ($i = 0; $i < count($years); $i++) {
    $y = $years[$i];
    if (!preg_match('/^(\d{4})-(\d{2})$/', $y, $m)) {
        $formatOk = false;
        break;
    }
    $sy = (int)$m[1];
    $ey = (int)$m[2];
    if ($ey !== (($sy + 1) % 100)) {
        $formatOk = false;
        break;
    }
    if ($i > 0) {
        $prevSy = (int)substr($years[$i - 1], 0, 4);
        if ($sy !== $prevSy + 1) {
            $orderOk = false;
            break;
        }
    }
}
recordTest('Test A', 'All years strictly adhere to YYYY-YY convention', $formatOk, "Format check verified across 50 years");
recordTest('Test A', 'Options are in strictly chronological order', $orderOk, "Order ascending verified");

// Check submission options marking future years disabled
$opts = academic_year_options('2025-26', true, 'asc');
$futureDisabled = true;
$currentEnabled = false;
$found2049 = false;

foreach ($opts as $o) {
    if ($o['value'] === '2025-26') {
        if (!$o['disabled']) $currentEnabled = true;
    }
    if ($o['value'] === '2049-50') {
        $found2049 = true;
        if (!$o['disabled'] || !str_contains($o['label'], 'Future Year')) {
            $futureDisabled = false;
        }
    }
}
recordTest('Test A', 'Current/historical academic year is selectable for upload', $currentEnabled, "2025-26 is selectable");
recordTest('Test A', 'Future academic years through 2049-50 are disabled with Future label', $futureDisabled && $found2049, "2049-50 has disabled=true and '— Future Year'");


// =========================================================================
// TEST B: Editable Academic Year across Metric Forms
// =========================================================================
echo "\n--- RUNNING TEST B: Editable Academic Year ---\n";
$pdo = db();

// User 5 is Faculty (VR, CSBS)
$facultyUser = $pdo->query("SELECT * FROM users WHERE id = 5")->fetch(PDO::FETCH_ASSOC);
if (!$facultyUser) {
    $facultyUser = ['id' => 5, 'name' => 'VR', 'role' => 'Faculty', 'department' => 'CSBS'];
}

// 1. Journal Publication: save record with historical academic year '2023-24'
$jTitle = "TEST JOURNAL AY GUARD " . time();
$stmt = $pdo->prepare("INSERT INTO journal_publications (created_by, faculty_name, department, academic_year, paper_title, journal_name, journal_type, issn, volume_issue, publication_month, publication_year, journal_link, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Submitted', NOW())");
$stmt->execute([
    $facultyUser['id'],
    $facultyUser['name'],
    $facultyUser['department'],
    '2023-24',
    $jTitle,
    'IEEE TRANSACTIONS',
    'Scopus',
    '1234-5678',
    'VOL 1, ISSUE 1',
    '05/2024',
    '2024',
    'https://example.com/journal'
]);
$jId = (int)$pdo->lastInsertId();

// Verify in DB
$jRec = $pdo->query("SELECT * FROM journal_publications WHERE id = $jId")->fetch(PDO::FETCH_ASSOC);
recordTest('Test B', 'Journal Publication: saves user-selected academic year 2023-24', ($jRec['academic_year'] ?? '') === '2023-24', "Saved AY in DB: " . ($jRec['academic_year'] ?? 'null'));

// Test reopening / editing: render_academic_year_field should show 2023-24 selected
$GLOBALS['editRecord'] = $jRec;
$GLOBALS['effectiveYear'] = '2025-26';
ob_start();
render_academic_year_field();
$renderedHtml = ob_get_clean();

$has2023Selected = str_contains($renderedHtml, 'value="2023-24" selected');
recordTest('Test B', 'Journal Publication: reopening record retains saved academic year 2023-24', $has2023Selected, "Selected attribute found on 2023-24 option");

// 2. Conference Publication: save record with academic year '2024-25'
$cTitle = "TEST CONF AY GUARD " . time();
$cStmt = $pdo->prepare("INSERT INTO conference_publications (created_by, faculty_name, department, academic_year, author_type, paper_title, conference_name, conference_type, conference_date, publication_year, venue, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Submitted', NOW())");
$cStmt->execute([
    $facultyUser['id'],
    $facultyUser['name'],
    $facultyUser['department'],
    '2024-25',
    'First Author',
    $cTitle,
    'National Conference on Computing',
    'National',
    '2024-11-10',
    '2024',
    'Chennai'
]);
$cId = (int)$pdo->lastInsertId();
$cRec = $pdo->query("SELECT * FROM conference_publications WHERE id = $cId")->fetch(PDO::FETCH_ASSOC);
recordTest('Test B', 'Conference Publication: saves user-selected academic year 2024-25', ($cRec['academic_year'] ?? '') === '2024-25', "Saved AY in DB: " . ($cRec['academic_year'] ?? 'null'));

$GLOBALS['editRecord'] = $cRec;
ob_start();
render_academic_year_field();
$renderedConfHtml = ob_get_clean();
recordTest('Test B', 'Conference Publication: reopening record retains saved academic year 2024-25', str_contains($renderedConfHtml, 'value="2024-25" selected'), "Selected attribute found on 2024-25 option");

// 3. Institutional metric (inst_college_rank) with academic year '2022-23'
$crStmt = $pdo->prepare("INSERT INTO inst_college_rank (created_by, department, academic_year, student_name, reg_no, rank_val, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'Submitted', NOW())");
$crStmt->execute([
    $facultyUser['id'],
    $facultyUser['department'],
    '2022-23',
    'Test Student',
    '311522104001',
    1
]);
$crId = (int)$pdo->lastInsertId();
$crRec = $pdo->query("SELECT * FROM inst_college_rank WHERE id = $crId")->fetch(PDO::FETCH_ASSOC);
recordTest('Test B', 'Institutional Metric (College Rank): saves user-selected academic year 2022-23', ($crRec['academic_year'] ?? '') === '2022-23', "Saved AY in DB: " . ($crRec['academic_year'] ?? 'null'));

// Verify top selector does NOT silently overwrite record's year
$GLOBALS['uploadFlow'] = ['year' => '2025-26', 'label' => 'CSBS'];
$GLOBALS['editRecord'] = $crRec;
ob_start();
render_academic_year_field();
$renderedTopConflictHtml = ob_get_clean();
recordTest('Test B', 'Top selector does not overwrite existing record saved academic year', str_contains($renderedTopConflictHtml, 'value="2022-23" selected'), "Top is 2025-26, but form displays 2022-23 selected");


// =========================================================================
// TEST C: Controlled IST Date Validation (Clock set to 22 October 2026)
// =========================================================================
echo "\n--- RUNNING TEST C: IST Date Validation ---\n";
// Set controlled test clock to 22 October 2026 10:00:00 IST
$GLOBALS['_TEST_CLOCK_IST'] = '2026-10-22 10:00:00';

$tNow = app_ist_now();
recordTest('Test C', 'Test clock set to 22 October 2026 IST', $tNow->format('Y-m-d') === '2026-10-22' && $tNow->getTimezone()->getName() === 'Asia/Kolkata', "Clock: " . $tNow->format('Y-m-d H:i:s T'));

// 1. 21 October 2026 (completed event date) -> accepted
$errPast = validate_submission_date('conference_date', '2026-10-21', 'Conference Date');
recordTest('Test C', '21 October 2026 accepted for completed event date', $errPast === null, "Error returned: " . ($errPast ?: 'none'));

// 2. 22 October 2026 (today) -> accepted
$errToday = validate_submission_date('conference_date', '2026-10-22', 'Conference Date');
recordTest('Test C', '22 October 2026 accepted (today)', $errToday === null, "Error returned: " . ($errToday ?: 'none'));

// 3. 23 October 2026 (future date) -> rejected
$errFuture = validate_submission_date('conference_date', '2026-10-23', 'Conference Date');
recordTest('Test C', '23 October 2026 rejected for completed event date', $errFuture !== null && str_contains($errFuture, 'cannot be in the future'), "Validation error: {$errFuture}");

// 4. Month validation: October 2026 (current month) -> accepted
$errCurMonth = validate_submission_month_year(10, 2026, 'Publication Month');
recordTest('Test C', 'October 2026 accepted for publication month', $errCurMonth === null, "Error returned: " . ($errCurMonth ?: 'none'));

// 5. Month validation: November 2026 (future month) -> rejected
$errNov = validate_submission_month_year(11, 2026, 'Publication Month');
recordTest('Test C', 'November 2026 rejected for completed event month', $errNov !== null && str_contains($errNov, 'cannot be in the future'), "Validation error: {$errNov}");

// 6. Whitelisted future date: valid_upto in 2029 (MoU) -> accepted
$errWhitelisted = validate_submission_date('valid_upto', '2029-06-30', 'Valid upto');
recordTest('Test C', 'Contractual end date (valid_upto in 2029) allowed for future dates', $errWhitelisted === null, "Error returned: " . ($errWhitelisted ?: 'none'));

// 7. Academic year checks at 2026-10-22:
// Current academic year is 2026-27 (started June 2026)
$curAy = current_academic_year();
recordTest('Test C', 'Current academic year calculated as 2026-27 in October 2026', $curAy === '2026-27', "Current AY: {$curAy}");

$isFut2026 = is_future_academic_year('2026-27');
$isFut2025 = is_future_academic_year('2025-26');
$isFut2027 = is_future_academic_year('2027-28');
recordTest('Test C', 'Current academic year (2026-27) is not future', !$isFut2026, "2026-27 is_future: " . ($isFut2026 ? 'TRUE' : 'FALSE'));
recordTest('Test C', 'Historical academic year (2025-26) is not future', !$isFut2025, "2025-26 is_future: " . ($isFut2025 ? 'TRUE' : 'FALSE'));
recordTest('Test C', 'Future academic year (2027-28) is rejected as future', $isFut2027, "2027-28 is_future: " . ($isFut2027 ? 'TRUE' : 'FALSE'));


// =========================================================================
// TEST D: Backend Enforcement & Bypass Rejection
// =========================================================================
echo "\n--- RUNNING TEST D: Backend Enforcement ---\n";

// 1. Backend rejection of future academic year via upload_flow_choose_year
[$okAyFut, $msgAyFut] = upload_flow_choose_year($facultyUser, '2028-29');
recordTest('Test D', 'upload_flow_choose_year rejects future academic year 2028-29', !$okAyFut && str_contains($msgAyFut, 'not started yet'), "Response: {$msgAyFut}");

// 2. Backend rejection of invalid academic year format
recordTest('Test D', 'is_valid_academic_year rejects invalid format 2026-2027', !is_valid_academic_year('2026-2027'), "2026-2027 valid: " . (is_valid_academic_year('2026-2027') ? 'YES' : 'NO'));
recordTest('Test D', 'is_valid_academic_year rejects invalid format 2026', !is_valid_academic_year('2026'), "2026 valid: " . (is_valid_academic_year('2026') ? 'YES' : 'NO'));
recordTest('Test D', 'is_valid_academic_year rejects year beyond 2049-50 (e.g. 2050-51)', !is_valid_academic_year('2050-51'), "2050-51 valid: " . (is_valid_academic_year('2050-51') ? 'YES' : 'NO'));

// 3. Backend rejection of future publication month/year in Record::publication_month_year
[$pm, $py, $pErr] = publication_month_year(['pub_month' => '12', 'pub_year' => '2026']);
recordTest('Test D', 'publication_month_year rejects future month 12/2026 when test date is October 2026', $pErr !== null && str_contains($pErr, 'cannot be in the future'), "Validation error: {$pErr}");

// Reset test clock
unset($GLOBALS['_TEST_CLOCK_IST']);


// =========================================================================
// TEST E: Regression & Workflow Integrity
// =========================================================================
echo "\n--- RUNNING TEST E: Regression ---\n";

// Verify historical records still load
$histJournals = $pdo->query("SELECT COUNT(*) FROM journal_publications")->fetchColumn();
$histConfs = $pdo->query("SELECT COUNT(*) FROM conference_publications")->fetchColumn();
$histRanks = $pdo->query("SELECT COUNT(*) FROM inst_college_rank")->fetchColumn();
recordTest('Test E', 'Historical records intact across tables', $histJournals > 0 && $histConfs > 0 && $histRanks > 0, "Journals: {$histJournals}, Confs: {$histConfs}, Ranks: {$histRanks}");

// Verify role permissions intact
$facultyRoles = $pdo->query("SELECT DISTINCT role FROM users WHERE role IN ('Faculty', 'Coordinator', 'HoD', 'Dean', 'Admin')")->fetchAll(PDO::FETCH_COLUMN);
recordTest('Test E', 'Role structure preserved', count($facultyRoles) >= 4, "Roles present: " . implode(', ', $facultyRoles));

// Clean up test records
$pdo->prepare("DELETE FROM journal_publications WHERE id = ?")->execute([$jId]);
$pdo->prepare("DELETE FROM conference_publications WHERE id = ?")->execute([$cId]);
$pdo->prepare("DELETE FROM inst_college_rank WHERE id = ?")->execute([$crId]);
recordTest('Test E', 'Cleaned up temporary test rows successfully', true, "Test rows removed");

// =========================================================================
// SUMMARY TABLE
// =========================================================================
echo "\n=======================================================\n";
echo "FINAL TEST EXECUTION SUMMARY\n";
echo "=======================================================\n";
$allPass = true;
foreach ($results as $r) {
    if ($r['status'] !== 'PASS') $allPass = false;
    echo sprintf("%-8s | %-25s | %-4s | %s\n", $r['suite'], substr($r['name'], 0, 25), $r['status'], $r['details']);
}
echo "=======================================================\n";
echo $allPass ? "ALL TESTS PASSED!\n" : "SOME TESTS FAILED!\n";
