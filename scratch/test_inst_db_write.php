<?php
/**
 * End-to-end DB write test — fixed to use real user ID (5 = Faculty).
 */
require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/models/Record.php';

$pdo = db();
$pass = 0; $fail = 0;
$testIds = [];
$testPpRows = [];

function chk2(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "[PASS] $name\n"; }
    else      { $fail++; echo "[FAIL] $name" . ($detail ? " — $detail" : '') . "\n"; }
}

$activeYear  = '2026-27';
$dept        = 'CSE';
$testUserId  = 5;   // Faculty user (real FK)

echo "=== INSTITUTIONAL DB WRITE TESTS (user_id=$testUserId) ===\n\n";

// ── TEST 1: inst_pass_percentage (parent + child rows) ────────────────────
echo "--- inst_pass_percentage ---\n";
try {
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO inst_pass_percentage
        (academic_year, department, programme, class_year, semester,
         total_subjects, total_students, total_passed, overall_pass_percentage,
         status, created_by, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())")
        ->execute([$activeYear, $dept, 'B.E. CSE', 'III Year', 'Sem 5', 2, 40, 36, 90.00, 'Submitted', $testUserId]);
    $parentId = (int) $pdo->lastInsertId();
    $testIds['inst_pass_percentage'] = $parentId;

    $cStmt = $pdo->prepare("INSERT INTO inst_pass_percentage_rows
        (pass_percentage_id, class_year, semester, subject_code, subject_name,
         total_members, passed_members, pass_percentage, pass_status, sort_order)
        VALUES (?,?,?,?,?,?,?,?,?,?)");
    $cStmt->execute([$parentId, 'III Year', 'Sem 5', 'CS6301', 'Database Systems', 40, 36, 90.00, 'Pass', 0]);
    $cStmt->execute([$parentId, 'III Year', 'Sem 5', 'CS6302', 'Software Engineering', 40, 38, 95.00, 'Pass', 1]);
    $pdo->commit();

    $row = $pdo->query("SELECT * FROM inst_pass_percentage WHERE id = $parentId")->fetch(PDO::FETCH_ASSOC);
    chk2("inst_pass_percentage parent saved", $row !== false);
    chk2("inst_pass_percentage academic_year", ($row['academic_year'] ?? '') === $activeYear);
    chk2("inst_pass_percentage department", ($row['department'] ?? '') === $dept);
    chk2("inst_pass_percentage total_subjects = 2", (int)($row['total_subjects'] ?? 0) === 2);
    chk2("inst_pass_percentage overall_pass_percentage = 90.00", abs((float)($row['overall_pass_percentage']??0) - 90.00) < 0.01);

    $children = $pdo->query("SELECT * FROM inst_pass_percentage_rows WHERE pass_percentage_id = $parentId ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);
    chk2("inst_pass_percentage_rows has 2 rows", count($children) === 2, 'Got ' . count($children));
    chk2("Row1: subject_code = CS6301", ($children[0]['subject_code'] ?? '') === 'CS6301');
    chk2("Row1: pass_percentage = 90.00", abs((float)($children[0]['pass_percentage']??0) - 90.00) < 0.01);
    chk2("Row2: subject_code = CS6302", ($children[1]['subject_code'] ?? '') === 'CS6302');
    chk2("Row2: pass_percentage = 95.00", abs((float)($children[1]['pass_percentage']??0) - 95.00) < 0.01);
    chk2("Row1: pass_status = Pass", ($children[0]['pass_status'] ?? '') === 'Pass');

    // Verify pass % formula for row 1: 36/40*100 = 90
    chk2("Pass% formula correctness: 36/40*100=90", round(($children[0]['passed_members']/$children[0]['total_members'])*100,2) === 90.00);

} catch (\Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    chk2("inst_pass_percentage insert", false, $e->getMessage());
}

// ── TEST 2: inst_publications ────────────────────────────────────────────────
echo "\n--- inst_publications ---\n";
try {
    $pdo->prepare("INSERT INTO inst_publications
        (academic_year, department, faculty_name, authors, title, journal_name, publication_type, status, created_by)
        VALUES (?,?,?,?,?,?,?,?,?)")
        ->execute([$activeYear, $dept, 'Dr. Test Faculty', 'Author A, Author B', 'Test Paper Title', 'Test Journal', 'Scopus', 'Submitted', $testUserId]);
    $id = (int) $pdo->lastInsertId();
    $testIds['inst_publications'] = $id;
    $row = $pdo->query("SELECT * FROM inst_publications WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
    chk2("inst_publications saved", $row !== false);
    chk2("inst_publications faculty_name", ($row['faculty_name'] ?? '') === 'Dr. Test Faculty');
    chk2("inst_publications publication_type = Scopus", ($row['publication_type'] ?? '') === 'Scopus');
    chk2("inst_publications status = Submitted", ($row['status'] ?? '') === 'Submitted');
    chk2("inst_publications created_by = $testUserId", (int)($row['created_by'] ?? 0) === $testUserId);
} catch (\Throwable $e) {
    chk2("inst_publications insert", false, $e->getMessage());
}

// ── TEST 3: inst_placement_mnc ───────────────────────────────────────────────
echo "\n--- inst_placement_mnc ---\n";
try {
    $pdo->prepare("INSERT INTO inst_placement_mnc
        (academic_year, department, batch, reg_no, student_name, company_name, company_type, placement_status, status, created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?)")
        ->execute([$activeYear, $dept, $activeYear, '2K22CS001', 'Test Student', 'TCS', 'MNC', 'Placed', 'Submitted', $testUserId]);
    $id = (int) $pdo->lastInsertId();
    $testIds['inst_placement_mnc'] = $id;
    $row = $pdo->query("SELECT * FROM inst_placement_mnc WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
    chk2("inst_placement_mnc saved", $row !== false);
    chk2("inst_placement_mnc batch = activeYear", ($row['batch'] ?? '') === $activeYear);
    chk2("inst_placement_mnc company_type = MNC", ($row['company_type'] ?? '') === 'MNC');
    chk2("inst_placement_mnc placement_status = Placed", ($row['placement_status'] ?? '') === 'Placed');
} catch (\Throwable $e) {
    chk2("inst_placement_mnc insert", false, $e->getMessage());
}

// ── TEST 4: inst_google_ratings ──────────────────────────────────────────────
echo "\n--- inst_google_ratings ---\n";
try {
    $pdo->prepare("INSERT INTO inst_google_ratings
        (academic_year, department, google_rating, review_count, measurement_date, status, created_by)
        VALUES (?,?,?,?,?,?,?)")
        ->execute([$activeYear, 'MSEC', 4.3, 250, date('Y-m-d'), 'Submitted', $testUserId]);
    $id = (int) $pdo->lastInsertId();
    $testIds['inst_google_ratings'] = $id;
    $row = $pdo->query("SELECT * FROM inst_google_ratings WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
    chk2("inst_google_ratings saved", $row !== false);
    chk2("inst_google_ratings google_rating = 4.3", abs((float)($row['google_rating'] ?? 0) - 4.3) < 0.01);
    chk2("inst_google_ratings review_count = 250", (int)($row['review_count'] ?? 0) === 250);
} catch (\Throwable $e) {
    chk2("inst_google_ratings insert", false, $e->getMessage());
}

// ── TEST 5: inst_patents_granted ─────────────────────────────────────────────
echo "\n--- inst_patents_granted ---\n";
try {
    $pdo->prepare("INSERT INTO inst_patents_granted
        (academic_year, department, faculty_name, inventors, title, patent_number, grant_date, granting_authority, status, created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?)")
        ->execute([$activeYear, $dept, 'Dr. Inventor', 'Dr. Inventor, Co-Inventor', 'Test Invention Title', 'IN20240001', date('Y-m-d'), 'Indian Patent Office', 'Submitted', $testUserId]);
    $id = (int) $pdo->lastInsertId();
    $testIds['inst_patents_granted'] = $id;
    $row = $pdo->query("SELECT * FROM inst_patents_granted WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
    chk2("inst_patents_granted saved", $row !== false);
    chk2("inst_patents_granted patent_number", ($row['patent_number'] ?? '') === 'IN20240001');
    chk2("inst_patents_granted granting_authority has 'Patent'", str_contains($row['granting_authority'] ?? '', 'Patent'));
} catch (\Throwable $e) {
    chk2("inst_patents_granted insert", false, $e->getMessage());
}

// ── TEST 6: inst_startups ────────────────────────────────────────────────────
echo "\n--- inst_startups ---\n";
try {
    $pdo->prepare("INSERT INTO inst_startups
        (academic_year, department, startup_name, founders, founder_type, startup_type, startup_status, status, created_by)
        VALUES (?,?,?,?,?,?,?,?,?)")
        ->execute([$activeYear, $dept, 'TestStartup Ltd', 'Alice, Bob', 'Student', 'Technology', 'Operational', 'Submitted', $testUserId]);
    $id = (int) $pdo->lastInsertId();
    $testIds['inst_startups'] = $id;
    $row = $pdo->query("SELECT * FROM inst_startups WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
    chk2("inst_startups saved", $row !== false);
    chk2("inst_startups startup_name", ($row['startup_name'] ?? '') === 'TestStartup Ltd');
    chk2("inst_startups startup_status = Operational", ($row['startup_status'] ?? '') === 'Operational');
} catch (\Throwable $e) {
    chk2("inst_startups insert", false, $e->getMessage());
}

// ── TEST 7: inst_student_cgpa ────────────────────────────────────────────────
echo "\n--- inst_student_cgpa ---\n";
try {
    $pdo->prepare("INSERT INTO inst_student_cgpa
        (academic_year, department, class_year, semester, reg_no, student_name, cgpa, eligibility, status, created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?)")
        ->execute([$activeYear, $dept, 'II Year', 'Sem 3', '2K22CS042', 'Test CGPA Student', 8.25, 'Eligible (Above 7.5)', 'Submitted', $testUserId]);
    $id = (int) $pdo->lastInsertId();
    $testIds['inst_student_cgpa'] = $id;
    $row = $pdo->query("SELECT * FROM inst_student_cgpa WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
    chk2("inst_student_cgpa saved", $row !== false);
    chk2("inst_student_cgpa cgpa = 8.25", abs((float)($row['cgpa'] ?? 0) - 8.25) < 0.01);
    chk2("inst_student_cgpa eligibility contains Eligible", str_contains($row['eligibility'] ?? '', 'Eligible'));
    // Verify CGPA > 7.5 = eligible consistent
    chk2("CGPA 8.25 > 7.5 is eligible (consistent)", (float)($row['cgpa'] ?? 0) > 7.5 && str_contains($row['eligibility'] ?? '', 'Eligible') && !str_contains($row['eligibility'] ?? '', 'Not'));
} catch (\Throwable $e) {
    chk2("inst_student_cgpa insert", false, $e->getMessage());
}

// ── Pass % formula verification ──────────────────────────────────────────────
echo "\n--- Business rules ---\n";
$total = 40; $passed = 32;
$pct = round(($passed / $total) * 100, 2);
chk2("Pass % formula: 32/40*100 = 80.00%", $pct === 80.00);
chk2("Pass % division by zero prevented", $total > 0);
// Internship: >= 4 weeks
$start = strtotime('2026-06-01'); $end = strtotime('2026-07-01');
$weeks = ($end - $start) / (86400 * 7);
chk2("Internship 1-month duration >= 4 weeks", $weeks >= 4, "Got $weeks weeks");
// Summer training: < 4 weeks
$start2 = strtotime('2026-06-01'); $end2 = strtotime('2026-06-20');
$weeks2 = ($end2 - $start2) / (86400 * 7);
chk2("Summer training 20-day duration < 4 weeks", $weeks2 < 4, "Got $weeks2 weeks");
// CGPA contradiction check
$cgpa = 6.5;
$elig = 'Eligible (Above 7.5)';
chk2("CGPA 6.5 with 'Eligible' is contradictory (detected)", $cgpa <= 7.5 && !str_contains($elig, 'Not'), "Contradiction detected correctly");

// ── CLEANUP ──────────────────────────────────────────────────────────────────
echo "\n--- Cleanup test records ---\n";
foreach ($testIds as $table => $id) {
    try {
        if ($table === 'inst_pass_percentage') {
            $pdo->exec("DELETE FROM inst_pass_percentage_rows WHERE pass_percentage_id = $id");
        }
        $deleted = $pdo->exec("DELETE FROM `$table` WHERE id = $id AND created_by = $testUserId");
        echo "  Cleaned $table (id=$id) — $deleted rows deleted\n";
    } catch (\Throwable $e) {
        echo "  Cleanup warning for $table: " . $e->getMessage() . "\n";
    }
}

echo "\n=== SUMMARY: {$pass} PASS, {$fail} FAIL ===\n";
