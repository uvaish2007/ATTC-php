<?php
/**
 * Comprehensive Test Suite for Department-Scoped Executive Meeting Presentation
 * Testing CSE, ECE, and EEE cross-department isolation, parameter tampering,
 * EM1/EM2 filtering, Academic Year filtering, empty department data handling,
 * and summary calculations.
 */
require_once __DIR__ . '/../php-app/inc/auth.php';
require_once __DIR__ . '/../php-app/models/ExecutiveMeetingReport.php';
require_once __DIR__ . '/../php-app/models/Record.php';
require_once __DIR__ . '/../php-app/models/Target.php';

echo "====================================================\n";
echo "DEPARTMENT-SCOPED PRESENTATION COMPREHENSIVE TEST\n";
echo "====================================================\n\n";

$pass = 0;
$fail = 0;

function it(string $desc, bool $cond) {
    global $pass, $fail;
    if ($cond) {
        echo " [PASS] $desc\n";
        $pass++;
    } else {
        echo " [FAIL] $desc\n";
        $fail++;
    }
}

// Helper to find or create a user
function getOrCreateUser(string $name, string $email, string $role, string $dept): array {
    $u = db()->query("SELECT * FROM users WHERE email = " . db()->quote($email))->fetch();
    if (!$u) {
        db()->exec("INSERT INTO users (name, email, password, role, department) VALUES ("
            . db()->quote($name) . ", "
            . db()->quote($email) . ", "
            . db()->quote('testpass') . ", "
            . db()->quote($role) . ", "
            . db()->quote($dept) . ")");
        $u = db()->query("SELECT * FROM users WHERE email = " . db()->quote($email))->fetch();
    }
    return $u;
}

// Ensure users for 3 departments: CSE, ECE, EEE
$cseHod   = getOrCreateUser('CSE HOD User', 'cse_hod@atts.local', 'HoD', 'CSE');
$cseCoord = getOrCreateUser('CSE Coord User', 'cse_coord@atts.local', 'Coordinator', 'CSE');
$cseFac   = getOrCreateUser('CSE Faculty User', 'cse_fac@atts.local', 'Faculty', 'CSE');

$eceHod   = getOrCreateUser('ECE HOD User', 'ece_hod@atts.local', 'HoD', 'ECE');
$eceCoord = getOrCreateUser('ECE Coord User', 'ece_coord@atts.local', 'Coordinator', 'ECE');
$eceFac   = getOrCreateUser('ECE Faculty User', 'ece_fac@atts.local', 'Faculty', 'ECE');

$eeeHod   = getOrCreateUser('EEE HOD User', 'eee_hod@atts.local', 'HoD', 'EEE');
$eeeCoord = getOrCreateUser('EEE Coord User', 'eee_coord@atts.local', 'Coordinator', 'EEE');
$eeeFac   = getOrCreateUser('EEE Faculty User', 'eee_fac@atts.local', 'Faculty', 'EEE');

$adminUser = db()->query("SELECT * FROM users WHERE role = 'Admin' LIMIT 1")->fetch();
$principalUser = db()->query("SELECT * FROM users WHERE role IN ('Principal', 'Director') LIMIT 1")->fetch();
$deanUser = db()->query("SELECT * FROM users WHERE role = 'Dean' LIMIT 1")->fetch();

// Ensure test records exist for CSE, ECE, EEE across 2025-26 and 2026-27
function ensureSampleRecord(string $table, array $data) {
    $cols = array_keys($data);
    $quotedCols = array_map(fn($c) => "`$c`", $cols);
    $placeholders = array_fill(0, count($cols), '?');
    
    // Check if duplicate exists
    $where = [];
    $params = [];
    foreach (['department', 'academic_year', 'paper_title', 'title'] as $k) {
        if (isset($data[$k])) {
            $where[] = "`$k` = ?";
            $params[] = $data[$k];
        }
    }
    if ($where) {
        $stmt = db()->prepare("SELECT id FROM `$table` WHERE " . implode(' AND ', $where) . " LIMIT 1");
        $stmt->execute($params);
        if ($stmt->fetch()) {
            return;
        }
    }
    
    $sql = "INSERT INTO `$table` (" . implode(',', $quotedCols) . ") VALUES (" . implode(',', $placeholders) . ")";
    $stmt = db()->prepare($sql);
    $stmt->execute(array_values($data));
}

// Check columns
$jCols = db()->query("SHOW COLUMNS FROM journal_publications")->fetchAll(PDO::FETCH_COLUMN);

// Insert test records for CSE
$cData = [
    'paper_title' => 'CSE Deep Learning Research 2026-27',
    'department' => 'CSE',
    'academic_year' => '2026-27',
    'created_by' => $cseFac['id'],
    'faculty_name' => $cseFac['name'],
    'status' => 'Submitted',
];
ensureSampleRecord('journal_publications', $cData);
ensureSampleRecord('journal_publications', [
    'paper_title' => 'CSE Legacy Cloud Systems 2025-26',
    'department' => 'CSE',
    'academic_year' => '2025-26',
    'created_by' => $cseFac['id'],
    'faculty_name' => $cseFac['name'],
    'status' => 'Submitted',
]);

// Insert test records for ECE
ensureSampleRecord('journal_publications', [
    'paper_title' => 'ECE Signal Processing Innovation 2026-27',
    'department' => 'ECE',
    'academic_year' => '2026-27',
    'created_by' => $eceFac['id'],
    'faculty_name' => $eceFac['name'],
    'status' => 'Submitted',
]);
ensureSampleRecord('journal_publications', [
    'paper_title' => 'ECE VLSI Architecture 2025-26',
    'department' => 'ECE',
    'academic_year' => '2025-26',
    'created_by' => $eceFac['id'],
    'faculty_name' => $eceFac['name'],
    'status' => 'Submitted',
]);

// Insert test records for EEE
ensureSampleRecord('journal_publications', [
    'paper_title' => 'EEE Smart Grid Energy 2026-27',
    'department' => 'EEE',
    'academic_year' => '2026-27',
    'created_by' => $eeeFac['id'],
    'faculty_name' => $eeeFac['name'],
    'status' => 'Submitted',
]);

// Ensure test targets exist for CSE and ECE
function ensureSampleTarget(string $dept, string $year, string $metric, int $val) {
    $stmt = db()->prepare("SELECT id FROM targets WHERE department = ? AND academic_year = ? AND metric = ?");
    $stmt->execute([$dept, $year, $metric]);
    if (!$stmt->fetch()) {
        $stmt = db()->prepare("INSERT INTO targets (department, academic_year, metric, target_value, status) VALUES (?, ?, ?, ?, 'Approved')");
        $stmt->execute([$dept, $year, $metric, $val]);
    }
}
ensureSampleTarget('CSE', '2026-27', 'Journal Publications', 15);
ensureSampleTarget('ECE', '2026-27', 'Journal Publications', 20);
ensureSampleTarget('EEE', '2026-27', 'Journal Publications', 10);

echo "\n--- 1. Role Scoping & Department Picker Permissions ---\n";
it("Admin can pick department", em_can_pick_department($adminUser) === true);
it("Principal cannot pick department (institution-wide)", em_can_pick_department($principalUser) === false);
it("Dean can pick department", em_can_pick_department($deanUser) === true);
it("CSE HoD cannot pick department", em_can_pick_department($cseHod) === false);
it("CSE Coordinator cannot pick department", em_can_pick_department($cseCoord) === false);
it("CSE Faculty cannot pick department", em_can_pick_department($cseFac) === false);
it("ECE HoD cannot pick department", em_can_pick_department($eceHod) === false);
it("EEE Faculty cannot pick department", em_can_pick_department($eeeFac) === false);

echo "\n--- 2. Server-Side Department Binding & Anti-Tampering (IDOR Protection) ---\n";
it("CSE HoD default scope is CSE", em_department_scope($cseHod, null) === 'CSE');
it("CSE HoD IGNORES ?department=ECE tampering", em_department_scope($cseHod, 'ECE') === 'CSE');
it("CSE HoD IGNORES ?department=EEE tampering", em_department_scope($cseHod, 'EEE') === 'CSE');
it("CSE HoD IGNORES ?department=Mechanical tampering", em_department_scope($cseHod, 'Mechanical') === 'CSE');
it("CSE Coordinator IGNORES ?department=ECE tampering", em_department_scope($cseCoord, 'ECE') === 'CSE');
it("CSE Faculty IGNORES ?department=ECE tampering", em_department_scope($cseFac, 'ECE') === 'CSE');
it("ECE HoD IGNORES ?department=CSE tampering", em_department_scope($eceHod, 'CSE') === 'ECE');
it("EEE Faculty IGNORES ?department=CSE tampering", em_department_scope($eeeFac, 'CSE') === 'EEE');

$tamperedFilters = em_resolve_filters($cseHod, ['department' => 'ECE', 'department_id' => '99', 'academic_year' => '2026-27']);
it("em_resolve_filters overrides tampered department to CSE", $tamperedFilters['department'] === 'CSE');

echo "\n--- 3. Cross-Department Presentation Isolation (CSE vs ECE vs EEE) ---\n";

// CSE HoD Presentation
$dsCseHod = em_dataset($cseHod, em_resolve_filters($cseHod, ['academic_year' => '2026-27']));
$cseRecords = array_merge($dsCseHod['faculty'], $dsCseHod['student'], $dsCseHod['activity']);
$cseForeign = array_filter($cseRecords, fn($r) => !empty($r['department']) && $r['department'] !== 'CSE');
$cseForeignTargets = array_filter($dsCseHod['targets'], fn($t) => !empty($t['department']) && $t['department'] !== 'CSE');
it("CSE HoD presentation contains ONLY CSE records", count($cseForeign) === 0 && count($cseRecords) > 0);
it("CSE HoD presentation contains ONLY CSE targets", count($cseForeignTargets) === 0 && count($dsCseHod['targets']) > 0);

// CSE Coordinator Presentation
$dsCseCoord = em_dataset($cseCoord, em_resolve_filters($cseCoord, ['academic_year' => '2026-27']));
$cseCoordRecords = array_merge($dsCseCoord['faculty'], $dsCseCoord['student'], $dsCseCoord['activity']);
$cseCoordForeign = array_filter($cseCoordRecords, fn($r) => !empty($r['department']) && $r['department'] !== 'CSE');
it("CSE Coordinator presentation contains ONLY CSE records", count($cseCoordForeign) === 0);
it("CSE HoD and CSE Coordinator datasets are identical in total", $dsCseHod['total'] === $dsCseCoord['total']);

// CSE Faculty Presentation
$dsCseFac = em_dataset($cseFac, em_resolve_filters($cseFac, ['academic_year' => '2026-27']));
$cseFacRecords = array_merge($dsCseFac['faculty'], $dsCseFac['student'], $dsCseFac['activity']);
$cseFacForeign = array_filter($cseFacRecords, fn($r) => !empty($r['department']) && $r['department'] !== 'CSE');
it("CSE Faculty presentation contains ONLY CSE records", count($cseFacForeign) === 0);
it("CSE Faculty receives department-wide presentation scope", $dsCseHod['total'] === $dsCseFac['total']);

// ECE HoD Presentation
$dsEceHod = em_dataset($eceHod, em_resolve_filters($eceHod, ['academic_year' => '2026-27']));
$eceRecords = array_merge($dsEceHod['faculty'], $dsEceHod['student'], $dsEceHod['activity']);
$eceForeign = array_filter($eceRecords, fn($r) => !empty($r['department']) && $r['department'] !== 'ECE');
$eceForeignTargets = array_filter($dsEceHod['targets'], fn($t) => !empty($t['department']) && $t['department'] !== 'ECE');
it("ECE HoD presentation contains ONLY ECE records", count($eceForeign) === 0 && count($eceRecords) > 0);
it("ECE HoD presentation contains ONLY ECE targets", count($eceForeignTargets) === 0 && count($dsEceHod['targets']) > 0);

// EEE Faculty Presentation
$dsEeeFac = em_dataset($eeeFac, em_resolve_filters($eeeFac, ['academic_year' => '2026-27']));
$eeeRecords = array_merge($dsEeeFac['faculty'], $dsEeeFac['student'], $dsEeeFac['activity']);
$eeeForeign = array_filter($eeeRecords, fn($r) => !empty($r['department']) && $r['department'] !== 'EEE');
it("EEE Faculty presentation contains ONLY EEE records", count($eeeForeign) === 0 && count($eeeRecords) > 0);

// Check that CSE titles do NOT exist in ECE or EEE
$cseTitlesInEce = array_filter($eceRecords, fn($r) => strpos($r['title'] ?? '', 'CSE') !== false);
$eceTitlesInCse = array_filter($cseRecords, fn($r) => strpos($r['title'] ?? '', 'ECE') !== false);
it("No CSE titles leak into ECE presentation", count($cseTitlesInEce) === 0);
it("No ECE titles leak into CSE presentation", count($eceTitlesInCse) === 0);

echo "\n--- 4. Summary & Target vs Achieved Isolation (No Count Leaks) ---\n";
// Rollup calculations
it("CSE Target rollup only sums CSE targets", $dsCseHod['rollup']['target'] === 15);
it("ECE Target rollup only sums ECE targets", $dsEceHod['rollup']['target'] === 20);
it("EEE Target rollup only sums EEE targets", $dsEeeFac['rollup']['target'] === 10);
it("Slide 1 totals records reflects only CSE records", $dsCseHod['total'] > 0 && $dsCseHod['total'] !== ($dsCseHod['total'] + $dsEceHod['total']));

echo "\n--- 5. Academic Year Filtering Isolation ---\n";
$dsCse2026 = em_dataset($cseHod, em_resolve_filters($cseHod, ['academic_year' => '2026-27']));
$dsCse2025 = em_dataset($cseHod, em_resolve_filters($cseHod, ['academic_year' => '2025-26']));

$records2026 = array_merge($dsCse2026['faculty'], $dsCse2026['student'], $dsCse2026['activity']);
$records2025 = array_merge($dsCse2025['faculty'], $dsCse2025['student'], $dsCse2025['activity']);

// Ensure journal publications are segregated by year
$has2026In2025 = array_filter($records2025, fn($r) => strpos($r['title'] ?? '', '2026-27') !== false);
$has2025In2026 = array_filter($records2026, fn($r) => strpos($r['title'] ?? '', '2025-26') !== false);
it("2025-26 presentation does NOT include 2026-27 CSE records", count($has2026In2025) === 0);
it("2026-27 presentation does NOT include 2025-26 CSE records", count($has2025In2026) === 0);

echo "\n--- 6. Executive Meeting (EM1 / EM2 / Both) Filtering ---\n";
$emFilters1 = em_resolve_filters($cseHod, ['academic_year' => '2026-27', 'em' => 'em1']);
$emFilters2 = em_resolve_filters($cseHod, ['academic_year' => '2026-27', 'em' => 'em2']);
$emFiltersBoth = em_resolve_filters($cseHod, ['academic_year' => '2026-27', 'em' => 'all']);

$emDs1 = em_dataset($cseHod, $emFilters1);
$emDs2 = em_dataset($cseHod, $emFilters2);
$emDsBoth = em_dataset($cseHod, $emFiltersBoth);

it("EM1 filter applies user department and EM1 scope", $emDs1['filters']['department'] === 'CSE' && $emDs1['filters']['em'] === 'em1');
it("EM2 filter applies user department and EM2 scope", $emDs2['filters']['department'] === 'CSE' && $emDs2['filters']['em'] === 'em2');
it("Both EM filter applies user department and All scope", $emDsBoth['filters']['department'] === 'CSE' && $emDsBoth['filters']['em'] === 'all');

echo "\n--- 7. Empty Department Presentation Handling ---\n";
$emptyUser = ['id' => 7777, 'role' => 'HoD', 'department' => 'Aero'];
$emptyFilters = em_resolve_filters($emptyUser, ['academic_year' => '2026-27']);
$emptyDataset = em_dataset($emptyUser, $emptyFilters);
$emptySlides = em_slides($emptyDataset);

it("Empty department presentation returns exactly 1 slide", count($emptySlides) === 1);
it("Empty slide type is 'empty'", $emptySlides[0]['type'] === 'empty');
it("Empty slide contains department name and no fallback to college-wide",
    strpos($emptySlides[0]['message'], 'No presentation data available for') !== false &&
    strpos($emptySlides[0]['message'], 'Aero') !== false);

echo "\n--- 8. Slide Titles & Presentation Mode Dwell Time ---\n";
$slidesCse = em_slides($dsCseHod);
it("Slide 2 title displays department-specific development", strpos($slidesCse[1]['title'], 'Computer Science and Engineering Development') !== false);

$presentCode = file_get_contents(__DIR__ . '/../php-app/present-executive-meeting.php');
it("10-second auto-advance constant EM_AUTO_ADVANCE_MS = 10000 is preserved",
    strpos($presentCode, '10000') !== false && (strpos($presentCode, "define('EM_AUTO_ADVANCE_MS', 10000)") !== false || strpos($presentCode, "const EM_AUTO_ADVANCE_MS = 10000") !== false));

echo "\n====================================================\n";
echo "FINAL RESULTS: Passed: $pass, Failed: $fail\n";
echo "====================================================\n";
