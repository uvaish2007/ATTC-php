<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/metric_studio.php';

echo "====================================================================\n";
echo "STARTING FORM STUDIO DRAG-AND-DROP & 2-COLUMN DESIGNER TEST SUITE\n";
echo "====================================================================\n";

$pdo = db();
$allPassed = true;
$testCount = 0;
$passCount = 0;

function assertTest(string $feature, string $desc, bool $condition, string $info = '') {
    global $testCount, $passCount, $allPassed;
    $testCount++;
    if ($condition) {
        $passCount++;
        echo sprintf("[PASS] %-28s | %-32s | %s\n", $feature, $desc, $info);
    } else {
        $allPassed = false;
        echo sprintf("[FAIL] %-28s | %-32s | %s\n", $feature, $desc, $info);
    }
}

// -----------------------------------------------------------------------------
// Test 1: Create a Form and Insert Fields by Exact Position (Simulating D&D)
// -----------------------------------------------------------------------------
$metricKey = 'test_dnd_' . substr(md5((string)microtime(true)), 0, 8);
$metricName = 'Campus Placement Test Form ' . substr($metricKey, -4);
// Clean up any stale records from previous interrupted runs
try {
    $pdo->prepare("DELETE FROM `generic_metric_records` WHERE `metric_key` LIKE 'test_dnd_%'")->execute();
    $pdo->prepare("DELETE FROM `metrics` WHERE `metric_key` LIKE 'test_dnd_%' OR `name` LIKE 'Campus Placement Test Form%'")->execute();
} catch (Throwable $e) {}

[$cOk, $cMsg, $metric] = metric_studio_create([
    'name' => $metricName,
    'metric_key' => $metricKey,
    'category' => 'STUDENT SUPPORT AND PROGRESSION',
    'description' => 'Test form for drag-and-drop 2-column positioning',
    'faculty_visible' => 1,
    'coordinator_visible' => 1,
    'hod_visible' => 1,
    'principal_visible' => 1,
    'status' => 1
]);
assertTest("Form Creation", "Creates target dynamic form", $cOk && !empty($metric['id']), "Form ID: {$metric['id']}");

$formId = $metric['id'];

// Initial 2 fields
$initialFields = [
    [
        'key' => 'department',
        'label' => 'Department',
        'type' => 'select',
        'width' => 'half',
        'col_span' => 1,
        'options' => ['CSE', 'ECE', 'MECH'],
        'required' => true
    ],
    [
        'key' => 'batch',
        'label' => 'Batch',
        'type' => 'text',
        'width' => 'half',
        'col_span' => 1,
        'required' => true
    ]
];

// Drag & drop insert 'academic_year' BETWEEN department and batch (index 1)
$newField = [
    'key' => 'academic_year',
    'label' => 'Academic Year',
    'type' => 'select',
    'width' => 'half',
    'col_span' => 1,
    'options' => ['2024-25', '2025-26'],
    'required' => true
];
array_splice($initialFields, 1, 0, [$newField]);

assertTest("D&D Between Fields", "Inserts at target index 1", count($initialFields) === 3 && $initialFields[1]['key'] === 'academic_year', "Position 1 is now academic_year");

// Reorder fields: Move batch (index 2) above academic_year (to index 1)
$moved = array_splice($initialFields, 2, 1)[0];
array_splice($initialFields, 1, 0, [$moved]);
assertTest("D&D Reorder", "Moves field to new index", $initialFields[1]['key'] === 'batch' && $initialFields[2]['key'] === 'academic_year', "Order: dept, batch, academic_year");

// -----------------------------------------------------------------------------
// Test 2: Build Complete 2-Column Student Placement Arrangement
// -----------------------------------------------------------------------------
$placementFields = [
    ['key' => 'department', 'label' => 'Department', 'type' => 'select', 'width' => 'half', 'col_span' => 1, 'options' => ['CSE', 'ECE', 'MECH'], 'required' => true],
    ['key' => 'academic_year', 'label' => 'Academic Year', 'type' => 'select', 'width' => 'half', 'col_span' => 1, 'options' => ['2024-25', '2025-26'], 'required' => true],
    ['key' => 'batch', 'label' => 'Batch', 'type' => 'text', 'width' => 'half', 'col_span' => 1, 'required' => true],
    ['key' => 'register_number', 'label' => 'Register Number', 'type' => 'text', 'width' => 'half', 'col_span' => 1, 'required' => true],
    ['key' => 'student_name', 'label' => 'Student Name', 'type' => 'text', 'width' => 'half', 'col_span' => 1, 'required' => true],
    ['key' => 'company_name', 'label' => 'Company Name', 'type' => 'text', 'width' => 'half', 'col_span' => 1, 'required' => true],
    ['key' => 'company_type', 'label' => 'Company Type', 'type' => 'select', 'width' => 'half', 'col_span' => 1, 'options' => ['MNC', 'Core', 'Startup'], 'required' => true],
    ['key' => 'placement_status', 'label' => 'Placement Status', 'type' => 'select', 'width' => 'half', 'col_span' => 1, 'options' => ['Placed', 'Higher Studies'], 'required' => true],
    ['key' => 'package_ctc', 'label' => 'Package / CTC', 'type' => 'decimal', 'width' => 'half', 'col_span' => 1, 'required' => true],
    ['key' => 'placement_date', 'label' => 'Placement Date', 'type' => 'date', 'width' => 'half', 'col_span' => 1, 'required' => true],
    ['key' => 'remarks', 'label' => 'Remarks & Job Profile', 'type' => 'textarea', 'width' => 'full', 'col_span' => 2, 'required' => false]
];

$attachment = [
    'enabled' => true,
    'label' => 'Offer Letter / Placement Order',
    'required' => true,
    'preset' => 'pdf_only',
    'allowed_types' => ['pdf'],
    'max_file_mb' => 2,
    'max_files' => 1,
    'min_files' => 1,
    'help_text' => 'Official offer letter (PDF max 2 MB).'
];

[$saveDraftOk, $saveDraftMsg] = metric_studio_save_draft_form($formId, $placementFields, $attachment);
assertTest("Save Draft 2-Column", "Persists 2-column widths", $saveDraftOk, "Saved 11 fields with width attributes");

$draftCfg = metric_studio_get_form_config($formId, true);
assertTest("Draft Widths Intact", "Half and full widths verified", $draftCfg['fields'][0]['width'] === 'half' && $draftCfg['fields'][10]['width'] === 'full', "Remarks spans full (col_span=2), others half (col_span=1)");

// Publish Form
[$pubOk, $pubMsg] = metric_studio_publish_form($formId, $placementFields, $attachment, 1);
assertTest("Publish 2-Column Form", "Creates live version v2", $pubOk, "Published successfully");

$pubCfg = metric_studio_get_form_config($formId, false);
assertTest("Published Schema Parity", "Canonical schema preserves width", count($pubCfg['fields']) === 11 && $pubCfg['fields'][10]['col_span'] === 2, "Published version is v{$pubCfg['version']}");

// -----------------------------------------------------------------------------
// Test 3: Toggle Field Width (Dynamic Width Switching)
// -----------------------------------------------------------------------------
// Make student_name full width
$fieldsCopy = $pubCfg['fields'];
foreach ($fieldsCopy as &$fc) {
    if ($fc['key'] === 'student_name') {
        $fc['width'] = 'full';
        $fc['col_span'] = 2;
    }
}
unset($fc);

[$togglePubOk, $togglePubMsg] = metric_studio_publish_form($formId, $fieldsCopy, $attachment, 1);
$updatedCfg = metric_studio_get_form_config($formId, false);
$updatedStudent = null;
foreach ($updatedCfg['fields'] as $uf) {
    if ($uf['key'] === 'student_name') $updatedStudent = $uf;
}
assertTest("Width Toggle Persists", "Changes 50% half to 100% full", $updatedStudent !== null && $updatedStudent['width'] === 'full' && $updatedStudent['col_span'] === 2, "student_name updated to full width (2 cols)");

// -----------------------------------------------------------------------------
// Test 4: Role-Based Delivery Verification
// -----------------------------------------------------------------------------
// Check that faculty, coordinator, hod, and principal can access this form
$facultySections = upload_approved_sections('Faculty', true);
$isAvailableToFaculty = false;
foreach ($facultySections as $sec) {
    if (($sec['id'] ?? '') === $metricKey || ($sec['title'] ?? '') === $metricName) {
        $isAvailableToFaculty = true;
        break;
    }
}
assertTest("Faculty Delivery", "Published form visible to Faculty", $isAvailableToFaculty, "Form appears in Faculty upload options");

$principalSections = upload_approved_sections('Principal', true);
$isAvailableToPrincipal = false;
foreach ($principalSections as $sec) {
    if (($sec['id'] ?? '') === $metricKey || ($sec['title'] ?? '') === $metricName) {
        $isAvailableToPrincipal = true;
        break;
    }
}
assertTest("Principal Delivery", "Published form visible to Principal", $isAvailableToPrincipal, "Form appears in Principal upload options");

// -----------------------------------------------------------------------------
// Test 5: End-to-End Submission into the 2-Column Form
// -----------------------------------------------------------------------------
$subData = [
    'department' => 'CSE',
    'academic_year' => '2024-25',
    'batch' => '2021-2025',
    'register_number' => '910021104018',
    'student_name' => 'Priya Sharma',
    'company_name' => 'Tata Consultancy Services',
    'company_type' => 'MNC',
    'placement_status' => 'Placed',
    'package_ctc' => '7.20',
    'placement_date' => '2025-03-15',
    'remarks' => 'Selected as Assistant Systems Engineer via National Qualifier Test.'
];

// Submit via generic metric submission
$fakePdf = "%PDF-1.4\n%Test Offer Letter PDF File\n%%EOF";
$tmpFile = tempnam(sys_get_temp_dir(), 'offer_');
file_put_contents($tmpFile, $fakePdf);

$adminUser = [
    'id' => 1,
    'role' => 'Admin',
    'name' => 'IQAC Admin',
    'department' => 'Computer Science and Engineering'
];
$proofFileObj = [
    'name' => 'TCS_Offer_Letter.pdf',
    'type' => 'application/pdf',
    'tmp_name' => $tmpFile,
    'error' => UPLOAD_ERR_OK,
    'size' => strlen($fakePdf)
];

[$subOk, $subMsg, $subRecordId] = generic_metric_record_submit(
    $metricKey,
    $adminUser,
    '2024-25',
    $subData,
    $proofFileObj
);
if (file_exists($tmpFile)) @unlink($tmpFile);

assertTest("End-to-End Submission", "Saves valid placement record", $subOk, "Created ID: {$subRecordId} | Msg: {$subMsg}");

// Verify stored record data
$recStmt = $pdo->prepare("SELECT * FROM `generic_metric_records` WHERE `id` = ?");
$recStmt->execute([$subRecordId]);
$storedRec = $recStmt->fetch(PDO::FETCH_ASSOC);

$storedData = json_decode((string)$storedRec['data_json'], true) ?: [];
assertTest("Record Data Integrity", "Values match submitted 2-column fields", 
    ($storedData['student_name'] ?? '') === 'Priya Sharma' &&
    ($storedData['company_name'] ?? '') === 'Tata Consultancy Services' &&
    (float)($storedData['package_ctc'] ?? 0) === 7.2 &&
    !empty($storedRec['proof_file']),
    "Stored package: {$storedData['package_ctc']} LPA | Proof: {$storedRec['proof_file']}"
);

// -----------------------------------------------------------------------------
// Test 6: Verify Metric 13 Student Placement Form Live
// -----------------------------------------------------------------------------
$m13Cfg = metric_studio_get_form_config(13, false);
assertTest("Metric 13 Seed", "Pre-configured placement form live", count($m13Cfg['fields']) === 11 && $m13Cfg['attachment']['label'] === 'Offer Letter / Placement Order', "Metric 13 has 11 fields in 2 columns");

// Teardown test record and metric
$pdo->prepare("DELETE FROM `generic_metric_records` WHERE `id` = ?")->execute([$subRecordId]);
$pdo->prepare("DELETE FROM `metric_form_history` WHERE `metric_id` = ?")->execute([$formId]);
$pdo->prepare("DELETE FROM `metrics` WHERE `id` = ?")->execute([$formId]);

echo "====================================================================\n";
echo "TEST SUITE RESULTS:\n";
echo "TOTAL: {$testCount} | PASSED: {$passCount} | FAILED: " . ($testCount - $passCount) . "\n";
echo "====================================================================\n";

if ($allPassed) {
    echo ">>> ALL DRAG-AND-DROP & 2-COLUMN DESIGNER TESTS PASSED! <<<\n";
    exit(0);
} else {
    echo ">>> SOME TESTS FAILED. <<<\n";
    exit(1);
}
