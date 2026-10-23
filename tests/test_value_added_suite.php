<?php
/**
 * Automated test suite for ATTS-RESOURCE-PERSON-DETAILS
 * (Tests A, B, C, D)
 */
require_once __DIR__ . '/../php-app/inc/helpers.php';
require_once __DIR__ . '/../php-app/inc/auth.php';
require_once __DIR__ . '/../php-app/inc/record_specs.php';
require_once __DIR__ . '/../php-app/models/Record.php';
require_once __DIR__ . '/../php-app/models/Target.php';
require_once __DIR__ . '/../php-app/sql/migrate_value_added.php';

$pdo = db();
$testResults = [];

function record_test(string $cat, string $name, bool $pass, string $note = ''): void
{
    global $testResults;
    $testResults[] = ['cat' => $cat, 'name' => $name, 'pass' => $pass, 'note' => $note];
    echo sprintf("[%s] %s: %s %s\n", $pass ? 'PASS' : 'FAIL', $cat, $name, $note ? "({$note})" : '');
}

echo "========================================================================\n";
echo "STARTING TEST SUITE: ATTS-RESOURCE-PERSON-DETAILS\n";
echo "========================================================================\n\n";

// Ensure migration has been executed
run_value_added_migration();

// -----------------------------------------------------------------------------
// Test A: Resource Person Fields
// -----------------------------------------------------------------------------
echo "--- Running Test A: Resource Person Fields ---\n";

$testCourseTitle = 'TEST_VA_SUITE_ADVANCED_AI_' . time();
$stmt = $pdo->prepare("INSERT INTO `value_added_courses`
    (academic_year, department, from_date, to_date, course_title, mode, participants,
     resource_person_name, designation, company_name, resource_person_address, company_address,
     contact_number, email, resource_person, status, created_by, created_at, updated_at)
    VALUES ('2025-26', 'CSE', '2026-03-01', '2026-03-05', ?, 'Offline', 40,
     'Dr. Jane Smith', 'Principal AI Scientist', 'Nexus AI Labs', '12 Research Blvd, Tech City', 'Campus Tower 4, Cyber Park',
     '+91 98765 43210', 'jane.smith@nexusai.org', 'Dr. Jane Smith, Principal AI Scientist, Nexus AI Labs', 'Approved', 1, NOW(), NOW())");
$stmt->execute([$testCourseTitle]);
$recordIdA = (int)$pdo->lastInsertId();

$recA = record_find('value_added', $recordIdA);
record_test('Test A', 'Resource Person Name persists', ($recA['resource_person_name'] ?? '') === 'Dr. Jane Smith', $recA['resource_person_name'] ?? '');
record_test('Test A', 'Designation persists', ($recA['designation'] ?? '') === 'Principal AI Scientist', $recA['designation'] ?? '');
record_test('Test A', 'Company / Institution persists', ($recA['company_name'] ?? '') === 'Nexus AI Labs', $recA['company_name'] ?? '');
record_test('Test A', 'Resource Person Address persists', ($recA['resource_person_address'] ?? '') === '12 Research Blvd, Tech City', $recA['resource_person_address'] ?? '');
record_test('Test A', 'Company Address persists', ($recA['company_address'] ?? '') === 'Campus Tower 4, Cyber Park', $recA['company_address'] ?? '');
record_test('Test A', 'Contact Number persists', ($recA['contact_number'] ?? '') === '+91 98765 43210', $recA['contact_number'] ?? '');
record_test('Test A', 'Email Address persists', ($recA['email'] ?? '') === 'jane.smith@nexusai.org', $recA['email'] ?? '');
record_test('Test A', 'Synthesized resource_person string populated for legacy queries', str_contains($recA['resource_person'] ?? '', 'Dr. Jane Smith'), $recA['resource_person'] ?? '');

// -----------------------------------------------------------------------------
// Test B: Event Report Attachment
// -----------------------------------------------------------------------------
echo "\n--- Running Test B: Event Report Attachment ---\n";

// Create a valid dummy PDF file in temp
$testUploadDir = __DIR__ . '/../php-app/uploads';
if (!is_dir($testUploadDir)) {
    @mkdir($testUploadDir, 0777, true);
}
$dummyPdfName = 'event_report_test_' . md5((string)time()) . '.pdf';
$dummyPdfPath = $testUploadDir . '/' . $dummyPdfName;
$pdfContent = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF";
file_put_contents($dummyPdfPath, $pdfContent);

// Update record with Event Report attachment
$updStmt = $pdo->prepare("UPDATE `value_added_courses` SET `proof_file` = ?, `event_report_file` = ? WHERE id = ?");
$updStmt->execute([$dummyPdfName, $dummyPdfName, $recordIdA]);

$recB = record_find('value_added', $recordIdA);
record_test('Test B', 'Event Report file reference persists in proof_file', ($recB['proof_file'] ?? '') === $dummyPdfName);
record_test('Test B', 'Event Report file reference persists in event_report_file', ($recB['event_report_file'] ?? '') === $dummyPdfName);
record_test('Test B', 'Uploaded report document exists on disk', file_exists($dummyPdfPath));

// Backend validation test: mock invalid file type (.txt)
require_once __DIR__ . '/../php-app/inc/compression.php';
$fakeTxt = [
    'name'     => 'malicious.txt',
    'type'     => 'text/plain',
    'tmp_name' => tempnam(sys_get_temp_dir(), 'test_txt_'),
    'error'    => UPLOAD_ERR_OK,
    'size'     => 100,
];
file_put_contents($fakeTxt['tmp_name'], 'plain text');
[$txtStored, $txtErr] = save_upload_proof($fakeTxt, true, 'value_added');
record_test('Test B', 'Invalid file type rejected with Event Report label', $txtStored === null && str_contains($txtErr ?? '', 'Event Report must be a PDF'), $txtErr ?? '');
@unlink($fakeTxt['tmp_name']);

// Mock oversized file (> 2 MB)
$fakeOversized = [
    'name'     => 'huge.pdf',
    'type'     => 'application/pdf',
    'tmp_name' => tempnam(sys_get_temp_dir(), 'test_huge_'),
    'error'    => UPLOAD_ERR_OK,
    'size'     => 3 * 1024 * 1024, // 3 MB
];
file_put_contents($fakeOversized['tmp_name'], "%PDF-1.4\n" . str_repeat('A', 1000));
[$hugeStored, $hugeErr] = save_upload_proof($fakeOversized, true, 'value_added');
record_test('Test B', 'Oversized file rejected with Event Report 2 MB limit', $hugeStored === null && str_contains($hugeErr ?? '', 'Event Report exceeds the 2 MB size limit'), $hugeErr ?? '');
@unlink($fakeOversized['tmp_name']);

// -----------------------------------------------------------------------------
// Test C: Editing Existing Records & Historical Compatibility
// -----------------------------------------------------------------------------
echo "\n--- Running Test C: Editing Existing Records ---\n";

// Insert a historical record with only combined resource_person and report_link
$histTitle = 'HISTORICAL_COURSE_TEST_' . time();
$histStmt = $pdo->prepare("INSERT INTO `value_added_courses`
    (academic_year, department, from_date, to_date, course_title, mode, participants,
     resource_person, report_link, status, created_by, created_at, updated_at)
    VALUES ('2025-26', 'CSE', '2026-01-10', '2026-01-15', ?, 'Online', 50,
     'Mr. Legacy Expert, VP Engineering, TechCorp (Ph: 9998887776)', 'https://msec.edu/reports/legacy_vac_01.pdf', 'Approved', 1, NOW(), NOW())");
$histStmt->execute([$histTitle]);
$recordIdC = (int)$pdo->lastInsertId();

$recC = record_find('value_added', $recordIdC);
record_test('Test C', 'Historical record loads with combined resource_person', !empty($recC['resource_person']), $recC['resource_person'] ?? '');
record_test('Test C', 'Historical record loads with report_link', !empty($recC['report_link']), $recC['report_link'] ?? '');
record_test('Test C', 'Historical structured fields are initially null', empty($recC['resource_person_name']));

// Simulate an edit on an unrelated field (e.g. updating participants from 50 to 55)
$editUpd = $pdo->prepare("UPDATE `value_added_courses` SET `participants` = 55, `updated_at` = NOW() WHERE id = ?");
$editUpd->execute([$recordIdC]);

$recCAfter = record_find('value_added', $recordIdC);
record_test('Test C', 'Editing unrelated field preserves historical resource_person', ($recCAfter['resource_person'] ?? '') === $recC['resource_person']);
record_test('Test C', 'Editing unrelated field preserves historical report_link', ($recCAfter['report_link'] ?? '') === $recC['report_link']);
record_test('Test C', 'Updated field persists', (int)$recCAfter['participants'] === 55);

// -----------------------------------------------------------------------------
// Test D: Regression & Reporting
// -----------------------------------------------------------------------------
echo "\n--- Running Test D: Regression & Reporting ---\n";

$spec = record_report_spec('value_added');
record_test('Test D', 'record_report_spec exists for value_added', $spec !== null);

$colFields = array_column($spec['columns'] ?? [], 1);
record_test('Test D', 'Report columns include resource_person_name', in_array('resource_person_name', $colFields, true));
record_test('Test D', 'Report columns include designation', in_array('designation', $colFields, true));
record_test('Test D', 'Report columns include company_name', in_array('company_name', $colFields, true));
record_test('Test D', 'Report columns include proof_file (Event Report)', in_array('proof_file', $colFields, true));
record_test('Test D', 'Report columns include contact_number', in_array('contact_number', $colFields, true));
record_test('Test D', 'Report columns include email', in_array('email', $colFields, true));

$attrs = record_display_attributes('value_added', $recA);
$attrMap = array_column($attrs, 'label', 'key');
record_test('Test D', 'record_display_attributes maps Resource Person Name label', ($attrMap['resource_person_name'] ?? '') === 'Resource Person Name');
record_test('Test D', 'record_display_attributes maps Designation label', ($attrMap['designation'] ?? '') === 'Designation');
record_test('Test D', 'record_display_attributes maps Company label', str_contains($attrMap['company_name'] ?? '', 'Company'));

// Clean up test records
$pdo->prepare("DELETE FROM `value_added_courses` WHERE id IN (?, ?)")->execute([$recordIdA, $recordIdC]);
if (file_exists($dummyPdfPath)) {
    @unlink($dummyPdfPath);
}

// Summary
$total = count($testResults);
$passed = count(array_filter($testResults, fn($r) => $r['pass']));
$failed = $total - $passed;

echo "\n========================================================================\n";
echo "TEST SUMMARY\n";
echo "========================================================================\n";
echo "Total: {$total} | Passed: {$passed} | Failed: {$failed}\n\n";

if ($failed > 0) {
    exit(1);
}
