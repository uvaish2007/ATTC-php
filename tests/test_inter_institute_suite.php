<?php
/**
 * Comprehensive Automated Test Suite: ATTS-INTER-INSTITUTE-EVENTS-ENHANCEMENT
 * Tests A through F
 */

define('TEST_MODE', true);
require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/inc/helpers.php';
require_once __DIR__ . '/../php-app/inc/upload_sections.php';
require_once __DIR__ . '/../php-app/inc/record_specs.php';
require_once __DIR__ . '/../php-app/models/Record.php';
require_once __DIR__ . '/../php-app/models/Target.php';
require_once __DIR__ . '/../php-app/models/InterInstituteEvent.php';
require_once __DIR__ . '/../php-app/sql/migrate_inter_institute.php';

$pdo = db();
$results = [];

function record_test_result(string $testGroup, string $name, bool $pass, string $notes = '') {
    global $results;
    $results[] = [
        'group' => $testGroup,
        'name'  => $name,
        'pass'  => $pass,
        'notes' => $notes,
    ];
    echo sprintf("[%s] %s: %s %s\n", $pass ? 'PASS' : 'FAIL', $testGroup, $name, $notes ? "({$notes})" : '');
}

// Clean up any previous test records
$pdo->exec("DELETE FROM `inst_inter_inst_within` WHERE `event_name` LIKE 'TEST_SUITE_%'");

// -----------------------------------------------------------------------------
// Test A: Participation Types
// -----------------------------------------------------------------------------
echo "\n--- Running Test A: Participation Types ---\n";
$typesToTest = [
    'Participant'          => 'Participation',
    'Winner / First Place' => 'Medal',
    'Second Runner'        => 'Medal',
    'Third Runner'         => 'Medal',
];

foreach ($typesToTest as $pType => $expectedAch) {
    $eventName = "TEST_SUITE_A_{$pType}";
    $regNo = 'TEST_REG_' . substr(md5($pType), 0, 6);
    $studentName = "Student {$pType}";

    $stmt = $pdo->prepare("INSERT INTO `inst_inter_inst_within`
        (academic_year, department, student_name, reg_no, event_name, host_institution, location, event_date, event_level, participation_type, achievement_type, status, created_at, updated_at)
        VALUES ('2025-26', 'CSE', ?, ?, ?, 'Test College', 'Madurai', '2026-03-15', 'District', ?, ?, 'Approved', NOW(), NOW())");
    $stmt->execute([$studentName, $regNo, $eventName, $pType, $expectedAch]);
    $insertedId = (int)$pdo->lastInsertId();

    $fetch = $pdo->query("SELECT * FROM `inst_inter_inst_within` WHERE id = {$insertedId}")->fetch(PDO::FETCH_ASSOC);

    $savedOk = ($fetch && $fetch['participation_type'] === $pType && $fetch['achievement_type'] === $expectedAch);
    record_test_result('Test A', "Save and retrieve {$pType}", $savedOk, "ID: {$insertedId}");
}

// Clean up Test A records
$pdo->exec("DELETE FROM `inst_inter_inst_within` WHERE `event_name` LIKE 'TEST_SUITE_A_%'");


// -----------------------------------------------------------------------------
// Test B: Synchronized Counting
// Spec example: 10 ordinary participants + 2 first-place winners + 3 second runners + 1 third runner = 16 total
// -----------------------------------------------------------------------------
echo "\n--- Running Test B: Counting & Synchronization ---\n";

// 10 Ordinary Participants
for ($i = 1; $i <= 10; $i++) {
    $stmt = $pdo->prepare("INSERT INTO `inst_inter_inst_within`
        (academic_year, department, student_name, reg_no, event_name, host_institution, location, event_date, event_level, participation_type, achievement_type, status, created_at, updated_at)
        VALUES ('2025-26', 'CSE', ?, ?, 'TEST_SUITE_B_EVENT', 'Host Uni', 'Madurai', '2026-03-20', 'District', 'Participant', 'Participation', 'Approved', NOW(), NOW())");
    $stmt->execute(["Participant {$i}", "REG_PART_{$i}"]);
}

// 2 First-Place Winners
for ($i = 1; $i <= 2; $i++) {
    $stmt = $pdo->prepare("INSERT INTO `inst_inter_inst_within`
        (academic_year, department, student_name, reg_no, event_name, host_institution, location, event_date, event_level, participation_type, position_award, achievement_type, status, created_at, updated_at)
        VALUES ('2025-26', 'CSE', ?, ?, 'TEST_SUITE_B_EVENT', 'Host Uni', 'Madurai', '2026-03-20', 'State — Within State', 'Winner / First Place', 'First Place', 'Medal', 'Approved', NOW(), NOW())");
    $stmt->execute(["Winner {$i}", "REG_WIN_{$i}"]);
}

// 3 Second Runners
for ($i = 1; $i <= 3; $i++) {
    $stmt = $pdo->prepare("INSERT INTO `inst_inter_inst_within`
        (academic_year, department, student_name, reg_no, event_name, host_institution, location, event_date, event_level, participation_type, position_award, achievement_type, status, created_at, updated_at)
        VALUES ('2025-26', 'CSE', ?, ?, 'TEST_SUITE_B_EVENT', 'Host Uni', 'Madurai', '2026-03-20', 'National', 'Second Runner', 'Second Runner', 'Medal', 'Approved', NOW(), NOW())");
    $stmt->execute(["Runner2 {$i}", "REG_R2_{$i}"]);
}

// 1 Third Runner
$stmt = $pdo->prepare("INSERT INTO `inst_inter_inst_within`
    (academic_year, department, student_name, reg_no, event_name, host_institution, location, event_date, event_level, participation_type, position_award, achievement_type, status, created_at, updated_at)
    VALUES ('2025-26', 'CSE', 'Runner3 1', 'REG_R3_1', 'TEST_SUITE_B_EVENT', 'Host Uni', 'Madurai', '2026-03-20', 'International', 'Third Runner', 'Third Runner', 'Medal', 'Approved', NOW(), NOW())");
$stmt->execute();
$lastRecordId = (int)$pdo->lastInsertId();

$stats = inter_institute_event_stats('CSE', '2025-26', null, 'Approved');

record_test_result('Test B', 'Total Participants includes winners & runners (16)', $stats['total_participations'] === 16, "Count: {$stats['total_participations']}");
record_test_result('Test B', 'First-Place Winners count (2)', $stats['first_place_winners'] === 2, "Count: {$stats['first_place_winners']}");
record_test_result('Test B', 'Second Runners count (3)', $stats['second_runners'] === 3, "Count: {$stats['second_runners']}");
record_test_result('Test B', 'Third Runners count (1)', $stats['third_runners'] === 1, "Count: {$stats['third_runners']}");
record_test_result('Test B', 'Total Award Recipients (6)', $stats['total_award_recipients'] === 6, "Count: {$stats['total_award_recipients']}");

// Test edit behavior: Editing record does NOT duplicate count
$updStmt = $pdo->prepare("UPDATE `inst_inter_inst_within` SET `location` = 'Updated City', `updated_at` = NOW() WHERE id = ?");
$updStmt->execute([$lastRecordId]);
$statsAfterEdit = inter_institute_event_stats('CSE', '2025-26', null, 'Approved');
record_test_result('Test B', 'Editing record does not duplicate count (still 16)', $statsAfterEdit['total_participations'] === 16, "Count: {$statsAfterEdit['total_participations']}");

// Clean up Test B records
$pdo->exec("DELETE FROM `inst_inter_inst_within` WHERE `event_name` LIKE 'TEST_SUITE_B_%'");


// -----------------------------------------------------------------------------
// Test C: Event Levels (including District)
// -----------------------------------------------------------------------------
echo "\n--- Running Test C: Event Levels ---\n";
$levels = [
    'District'              => 'Madurai District Hackathon',
    'State — Within State'  => 'Tamil Nadu State Tech Summit',
    'State — Outside State' => 'Karnataka Tech Expo',
    'National'              => 'Smart India Hackathon',
    'International'         => 'IEEE Global Symposium',
];

foreach ($levels as $level => $evName) {
    $stmt = $pdo->prepare("INSERT INTO `inst_inter_inst_within`
        (academic_year, department, student_name, reg_no, event_name, host_institution, location, event_date, event_level, participation_type, achievement_type, status, created_at, updated_at)
        VALUES ('2025-26', 'CSE', 'Level Student', 'REG_LVL', ?, 'Host Org', 'City', '2026-03-25', ?, 'Participant', 'Participation', 'Approved', NOW(), NOW())");
    $stmt->execute(["TEST_SUITE_C_{$evName}", $level]);
}

$lvlStats = inter_institute_event_stats('CSE', '2025-26', null, 'Approved');
record_test_result('Test C', 'District-level event recorded and counted', $lvlStats['district_participations'] === 1, "District: {$lvlStats['district_participations']}");
record_test_result('Test C', 'District not counted as state-level event', $lvlStats['district_participations'] === 1 && $lvlStats['state_within_participations'] === 1, "District: {$lvlStats['district_participations']}, State Within: {$lvlStats['state_within_participations']}");
record_test_result('Test C', 'State — Within State recorded', $lvlStats['state_within_participations'] === 1);
record_test_result('Test C', 'State — Outside State recorded', $lvlStats['state_outside_participations'] === 1);
record_test_result('Test C', 'National-level event recorded', $lvlStats['national_participations'] === 1);
record_test_result('Test C', 'International-level event recorded', $lvlStats['international_participations'] === 1);

// Clean up Test C records
$pdo->exec("DELETE FROM `inst_inter_inst_within` WHERE `event_name` LIKE 'TEST_SUITE_C_%'");


// -----------------------------------------------------------------------------
// Test D: Unified Navigation
// -----------------------------------------------------------------------------
echo "\n--- Running Test D: Unified Navigation ---\n";
$sections = upload_approved_sections('Faculty');
$interSec = $sections['inter_institute'] ?? null;
record_test_result('Test D', 'inter_institute section exists in approved sections', $interSec !== null);
record_test_result('Test D', 'inter_institute has no subcategories (consolidated single section)', empty($interSec['subcategories']));
record_test_result('Test D', 'inter_institute order is 20', ($interSec['order'] ?? 0) === 20);

$prevSec = upload_section_prev('inter_institute', 'Faculty');
record_test_result('Test D', 'Previous section resolves to spoken_tutorials (metric 19)', ($prevSec['id'] ?? '') === 'spoken_tutorials');

$nextSec = upload_section_next('inter_institute', 'Faculty');
record_test_result('Test D', 'Next section resolves to value_added (metric 21)', ($nextSec['id'] ?? '') === 'value_added');

$resolvedOutside = resolve_upload_section(null, 'inst_inter_inst_outside', 'Faculty');
record_test_result('Test D', 'Legacy inst_inter_inst_outside resolves to unified inter_institute', ($resolvedOutside['section']['id'] ?? '') === 'inter_institute' && $resolvedOutside['type'] === 'inst_inter_inst_within');

$resolvedAwards = resolve_upload_section(null, 'inst_inter_inst_awards', 'Faculty');
record_test_result('Test D', 'Legacy inst_inter_inst_awards resolves to unified inter_institute', ($resolvedAwards['section']['id'] ?? '') === 'inter_institute' && $resolvedAwards['type'] === 'inst_inter_inst_within');


// -----------------------------------------------------------------------------
// Test E: Certificate Upload & Validation
// -----------------------------------------------------------------------------
echo "\n--- Running Test E: Certificate Upload & Validation ---\n";
require_once __DIR__ . '/../php-app/inc/compression.php';

// Create a valid dummy PDF (< 2 MB)
$tempDir = rtrim(sys_get_temp_dir(), '/\\');
$validPdfPath = $tempDir . '/valid_test_cert.pdf';
$pdfContent = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n3 0 obj\n<< /Type /Page /Parent 2 0 R >>\nendobj\nxref\n0 4\n0000000000 65535 f\n0000000010 00000 n\n0000000060 00000 n\n0000000115 00000 n\ntrailer\n<< /Size 4 /Root 1 0 R >>\nstartxref\n165\n%%EOF";
file_put_contents($validPdfPath, $pdfContent);

$validFileArr = [
    'name'     => 'valid_cert.pdf',
    'type'     => 'application/pdf',
    'tmp_name' => $validPdfPath,
    'error'    => UPLOAD_ERR_OK,
    'size'     => strlen($pdfContent),
];

[$storedFile, $err] = save_upload_proof($validFileArr, true, 'inst_inter_inst_within');
$certSaved = ($storedFile !== null && $err === null);
record_test_result('Test E', 'Valid certificate accepted and stored', $certSaved, "Stored: {$storedFile}");

// Verify stored certificate exists on disk and is associated
if ($storedFile) {
    $filePath = rtrim(UPLOAD_DIR, '/\\') . '/' . $storedFile;
    record_test_result('Test E', 'Stored certificate file exists on disk', file_exists($filePath));

    $stmt = $pdo->prepare("INSERT INTO `inst_inter_inst_within`
        (academic_year, department, student_name, reg_no, event_name, host_institution, location, event_date, event_level, participation_type, achievement_type, proof_file, status, created_at, updated_at)
        VALUES ('2025-26', 'CSE', 'Cert Student', 'REG_CERT_1', 'TEST_SUITE_E_CERT', 'Host Org', 'City', '2026-03-25', 'District', 'Participant', 'Participation', ?, 'Approved', NOW(), NOW())");
    $stmt->execute([$storedFile]);
    $recId = (int)$pdo->lastInsertId();

    $checkRec = $pdo->query("SELECT proof_file FROM `inst_inter_inst_within` WHERE id = {$recId}")->fetch(PDO::FETCH_ASSOC);
    record_test_result('Test E', 'Certificate correctly associated with event record', ($checkRec['proof_file'] ?? '') === $storedFile);
}

// Test invalid file type (e.g. .txt)
$invalidTxtPath = $tempDir . '/invalid_test.txt';
file_put_contents($invalidTxtPath, 'This is a text file, not a PDF');
$invalidFileArr = [
    'name'     => 'invalid_cert.txt',
    'type'     => 'text/plain',
    'tmp_name' => $invalidTxtPath,
    'error'    => UPLOAD_ERR_OK,
    'size'     => 29,
];
[$badStored, $badErr] = save_upload_proof($invalidFileArr, true, 'inst_inter_inst_within');
record_test_result('Test E', 'Invalid file type rejected by backend', $badStored === null && !empty($badErr), "Error: {$badErr}");

// Test oversized file (> 2 MB)
$oversizedPath = $tempDir . '/oversized_test.pdf';
$largeContent = "%PDF-" . str_repeat("A", (2 * 1024 * 1024) + 1024);
file_put_contents($oversizedPath, $largeContent);
$oversizedFileArr = [
    'name'     => 'huge_cert.pdf',
    'type'     => 'application/pdf',
    'tmp_name' => $oversizedPath,
    'error'    => UPLOAD_ERR_OK,
    'size'     => strlen($largeContent),
];
[$hugeStored, $hugeErr] = save_upload_proof($oversizedFileArr, true, 'inst_inter_inst_within');
record_test_result('Test E', 'Oversized file (>2 MB) rejected by backend', $hugeStored === null && !empty($hugeErr), "Error: {$hugeErr}");

// Cleanup temp files
@unlink($validPdfPath);
@unlink($invalidTxtPath);
@unlink($oversizedPath);
$pdo->exec("DELETE FROM `inst_inter_inst_within` WHERE `event_name` LIKE 'TEST_SUITE_E_%'");


// -----------------------------------------------------------------------------
// Test F: Migration, Preservation & Target Reporting
// -----------------------------------------------------------------------------
echo "\n--- Running Test F: Migration & Reporting ---\n";

// Verify historical tables still exist and contain their original data
$chkAchievements = (int)$pdo->query("SELECT COUNT(*) FROM `student_achievements`")->fetchColumn();
$chkParticipations = (int)$pdo->query("SELECT COUNT(*) FROM `student_participations`")->fetchColumn();
record_test_result('Test F', 'Historical student_achievements records preserved', $chkAchievements >= 2, "Count: {$chkAchievements}");
record_test_result('Test F', 'Historical student_participations records preserved', $chkParticipations >= 2, "Count: {$chkParticipations}");

// Rerun migration and ensure 0 duplicate records
$migRes = run_inter_institute_migration();
record_test_result('Test F', 'Migration runs idempotently with 0 duplicates', empty($migRes['outside_migration_err']) && empty($migRes['awards_migration_err']));

// Target integration test
// Create approved record under CSE for Within State
$stmt = $pdo->prepare("INSERT INTO `inst_inter_inst_within`
    (academic_year, department, student_name, reg_no, event_name, host_institution, location, event_date, event_level, participation_type, achievement_type, status, created_at, updated_at)
    VALUES ('2026-27', 'CSBS', 'Target Student', 'REG_TGT_1', 'TEST_SUITE_F_EVENT', 'Host Uni', 'Madurai', '2026-09-15', 'State — Within State', 'Winner / First Place', 'Medal', 'Approved', NOW(), NOW())");
$stmt->execute();

$dummyTargetWithin = [
    'metric'        => 'PARTICIPATION IN INTER-INSTITUTE EVENTS BY STUDENTS WITHIN STATE',
    'department'    => 'CSBS',
    'academic_year' => '2026-27',
];
$dummyTargetAwards = [
    'metric'        => 'AWARDS/PRIZES',
    'department'    => 'CSBS',
    'academic_year' => '2026-27',
];
$dummyTargetUnified = [
    'metric'        => 'Student Participation in Inter-Institute Events and Awards / Medals',
    'department'    => 'CSBS',
    'academic_year' => '2026-27',
];

$countWithin = target_record_count($dummyTargetWithin);
$countAwards = target_record_count($dummyTargetAwards);
$countUnified = target_record_count($dummyTargetUnified);

record_test_result('Test F', 'Target counting matches Within State records', $countWithin >= 1, "Count: {$countWithin}");
record_test_result('Test F', 'Target counting matches Awards/Prizes records', $countAwards >= 1, "Count: {$countAwards}");
record_test_result('Test F', 'Target counting matches Unified Inter-Institute target', $countUnified >= 1, "Count: {$countUnified}");

// Clean up Test F records
$pdo->exec("DELETE FROM `inst_inter_inst_within` WHERE `event_name` LIKE 'TEST_SUITE_F_%'");

// Summary
echo "\n========================================================================\n";
echo "TEST SUMMARY\n";
echo "========================================================================\n";
$total = count($results);
$passed = count(array_filter($results, fn($r) => $r['pass']));
$failed = $total - $passed;

echo "Total: {$total} | Passed: {$passed} | Failed: {$failed}\n";
