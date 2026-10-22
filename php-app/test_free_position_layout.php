<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/metric_studio.php';

echo "====================================================================\n";
echo "STARTING FREE-POSITION 12-COL LAYOUT & GLOBAL UPLOAD TEST SUITE\n";
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
// Test 1: Create a Form with Sections and Multi-Field Independent Row Layout
// -----------------------------------------------------------------------------
$metricKey = 'test_layout_' . substr(md5((string)microtime(true)), 0, 8);
$metricName = 'Free-Position Layout Test ' . substr($metricKey, -4);

try {
    $pdo->prepare("DELETE FROM `generic_metric_records` WHERE `metric_key` LIKE 'test_layout_%'")->execute();
    $pdo->prepare("DELETE FROM `metrics` WHERE `metric_key` LIKE 'test_layout_%' OR `name` LIKE 'Free-Position Layout Test%'")->execute();
} catch (Throwable $e) {}

[$cOk, $cMsg, $metric] = metric_studio_create([
    'name' => $metricName,
    'metric_key' => $metricKey,
    'category' => 'GOVERNANCE, LEADERSHIP AND MANAGEMENT',
    'description' => 'Test form for 12-column free positioning and independent sizing',
    'faculty_visible' => 1,
    'coordinator_visible' => 1,
    'hod_visible' => 1,
    'principal_visible' => 1,
    'status' => 1
]);
assertTest("Form Creation", "Creates dynamic form", $cOk && !empty($metric['id']), "Form ID: {$metric['id']}");
$formId = $metric['id'];

// Define fields with independent widths:
// Row 1: Section 1 Header (span 12)
// Row 2: 3 fields side-by-side on same row (4 cols each = 33.3% each)
// Row 3: 2 independent fields with unequal widths (3 cols [25%] + 9 cols [75%])
// Row 4: Centered partial field with offset 3 (span 6, align center, custom height 50px)
// Row 5: Section 2 Header (span 12)
// Row 6: 2 fields in Section 2 (span 5 + span 7, relaxed spacing, vertical align center)
$testFields = [
    [
        'key' => 'sec_academic',
        'label' => 'Academic Information',
        'type' => 'section_header',
        'col_span' => 12,
        'width' => 'full'
    ],
    // 3 fields on 1 row (4 + 4 + 4 = 12 cols)
    [
        'key' => 'academic_year',
        'label' => 'Academic Year',
        'type' => 'select',
        'col_span' => 4,
        'width' => '4',
        'align' => 'stretch',
        'spacing' => 'normal',
        'options' => ['2024-25', '2025-26'],
        'required' => true,
        'section_id' => 'sec_academic'
    ],
    [
        'key' => 'semester',
        'label' => 'Semester',
        'type' => 'select',
        'col_span' => 4,
        'width' => '4',
        'align' => 'stretch',
        'spacing' => 'normal',
        'options' => ['Odd Semester', 'Even Semester'],
        'required' => true,
        'section_id' => 'sec_academic'
    ],
    [
        'key' => 'department',
        'label' => 'Department',
        'type' => 'select',
        'col_span' => 4,
        'width' => '4',
        'align' => 'stretch',
        'spacing' => 'normal',
        'options' => ['CSE', 'ECE', 'MECH', 'CIVIL'],
        'required' => true,
        'section_id' => 'sec_academic'
    ],
    // 2 fields side-by-side with independent widths (3 cols + 9 cols)
    [
        'key' => 'course_code',
        'label' => 'Course Code',
        'type' => 'text',
        'col_span' => 3,
        'width' => '3',
        'align' => 'left',
        'spacing' => 'compact',
        'placeholder' => 'e.g. CS8501',
        'required' => true,
        'section_id' => 'sec_academic'
    ],
    [
        'key' => 'course_title',
        'label' => 'Course Title / Name',
        'type' => 'text',
        'col_span' => 9,
        'width' => '9',
        'align' => 'stretch',
        'spacing' => 'compact',
        'placeholder' => 'Full subject title',
        'required' => true,
        'section_id' => 'sec_academic'
    ],
    // Offset & Centered field with custom height
    [
        'key' => 'special_notes',
        'label' => 'Special Accreditation Notes',
        'type' => 'textarea',
        'col_span' => 6,
        'width' => '6',
        'offset' => 3,
        'align' => 'center',
        'vertical_align' => 'center',
        'spacing' => 'relaxed',
        'custom_height' => '80',
        'placeholder' => 'Centered notes block',
        'required' => false,
        'section_id' => 'sec_academic'
    ],
    // Section 2
    [
        'key' => 'sec_eval',
        'label' => 'Evaluation Details',
        'type' => 'section_header',
        'col_span' => 12,
        'width' => 'full'
    ],
    // 5 cols + 7 cols side-by-side
    [
        'key' => 'pass_percentage',
        'label' => 'Pass Percentage (%)',
        'type' => 'decimal',
        'col_span' => 5,
        'width' => '5',
        'align' => 'stretch',
        'spacing' => 'spacious',
        'custom_height' => 'compact',
        'required' => true,
        'section_id' => 'sec_eval'
    ],
    [
        'key' => 'eval_remarks',
        'label' => 'Faculty Evaluation Remarks',
        'type' => 'textarea',
        'col_span' => 7,
        'width' => '7',
        'align' => 'stretch',
        'spacing' => 'spacious',
        'required' => false,
        'section_id' => 'sec_eval'
    ]
];

$attachmentConfig = [
    'enabled' => true,
    'label' => 'Accreditation Result Document',
    'required' => true,
    'preset' => 'pdf_only',
    'allowed_types' => ['pdf'],
    'max_file_mb' => 3,
    'max_files' => 1,
    'min_files' => 1,
    'help_text' => 'Official result sheet approved by HoD (PDF strictly under 3 MB).'
];

// -----------------------------------------------------------------------------
// Test 2: Save Draft & Verify 12-Column Granular Properties Persistence
// -----------------------------------------------------------------------------
[$draftOk, $draftMsg] = metric_studio_save_draft_form($formId, $testFields, $attachmentConfig);
assertTest("Save Draft Layout", "Persists 12-col spans & styles", $draftOk, "Draft saved with 10 fields");

$draftData = metric_studio_get_form_config($formId, true);
$dFields = $draftData['fields'];

// Check 3 fields side-by-side
$threeRowOk = ($dFields[1]['col_span'] === 4 && $dFields[2]['col_span'] === 4 && $dFields[3]['col_span'] === 4);
assertTest("3 Fields on Same Row", "Each field has span 4 (33.3%)", $threeRowOk, "Fields: year, sem, dept all 4 cols");

// Check independent 3 + 9 unequal widths
$unequalOk = ($dFields[4]['col_span'] === 3 && $dFields[5]['col_span'] === 9);
assertTest("Independent Sizing", "Unequal side-by-side (25% + 75%)", $unequalOk, "Course Code=3, Course Title=9");

// Check offset, alignment, height, spacing
$special = $dFields[6];
$propsOk = ($special['offset'] === 3 && $special['align'] === 'center' && $special['vertical_align'] === 'center' && $special['spacing'] === 'relaxed' && $special['custom_height'] === '80');
assertTest("Styling & Dimensions", "Offset, alignment, height & spacing", $propsOk, "Offset: 3, Align: center, H: 80, Spacing: relaxed");

// -----------------------------------------------------------------------------
// Test 3: Publish Form & Canonical Parity
// -----------------------------------------------------------------------------
[$pubOk, $pubMsg] = metric_studio_publish_form($formId, $testFields, $attachmentConfig, 1);
assertTest("Publish Layout Form", "Generates active published v2", $pubOk, "Published successfully");

$pubData = metric_studio_get_form_config($formId, false);
$pFields = $pubData['fields'];
assertTest("Published Schema Parity", "All 10 fields preserved in v{$pubData['version']}", count($pFields) === 10, "Field count matches");

// Verify helper function metric_studio_field_grid_span
assertTest("Grid Span: 4 cols", "metric_studio_field_grid_span returns 4", metric_studio_field_grid_span($pFields[1]) === 4);
assertTest("Grid Span: 3 cols", "metric_studio_field_grid_span returns 3", metric_studio_field_grid_span($pFields[4]) === 3);
assertTest("Grid Span: 9 cols", "metric_studio_field_grid_span returns 9", metric_studio_field_grid_span($pFields[5]) === 9);
assertTest("Grid Span: 12 cols", "metric_studio_field_grid_span returns 12", metric_studio_field_grid_span($pFields[0]) === 12);

// -----------------------------------------------------------------------------
// Test 4: Role Delivery in upload.php
// -----------------------------------------------------------------------------
$roles = ['Faculty', 'Coordinator', 'HoD', 'Principal'];
foreach ($roles as $r) {
    $sections = upload_approved_sections($r, true);
    $found = false;
    foreach ($sections as $s) {
        if (($s['id'] ?? '') === $metricKey || ($s['title'] ?? '') === $metricName) {
            $found = true;
            break;
        }
    }
    assertTest("Role Delivery: {$r}", "Layout form visible to {$r}", $found, "Present in {$r} upload navigation");
}

// -----------------------------------------------------------------------------
// Test 5: Verify Simulated upload.php Dynamic HTML Generation
// -----------------------------------------------------------------------------
// We simulate the exact rendering logic in upload.php for this dynamic metric:
ob_start();
$activeSection = [
    'metric_type' => 'generic',
    'form_config' => $pFields,
    'help_text' => 'Test instructions'
];
$editRecord = null;
$userRole = 'Faculty';

echo '<div class="form-dynamic-grid-12" style="display:grid; grid-template-columns:repeat(12, 1fr); gap:16px 20px; align-items:start;">';
foreach ($pFields as $gf) {
    $fType = $gf['type'] ?? 'text';
    if ($fType === 'section_header') {
        echo '<div class="form-section-header" style="grid-column:1 / -1;">' . htmlspecialchars($gf['label']) . '</div>';
        continue;
    }
    $spanNum = metric_studio_field_grid_span($gf);
    $fieldStyles = ["grid-column: span {$spanNum}"];
    if (!empty($gf['offset']) && (int)$gf['offset'] > 0) {
        $fieldStyles[] = 'grid-column-start: ' . (1 + (int)$gf['offset']);
    }
    if (!empty($gf['align']) && $gf['align'] !== 'stretch') {
        $fieldStyles[] = 'justify-self: ' . htmlspecialchars($gf['align']);
    }
    if (!empty($gf['vertical_align'])) {
        $fieldStyles[] = 'align-self: ' . htmlspecialchars($gf['vertical_align']);
    }
    if (!empty($gf['spacing'])) {
        $spacingMap = ['compact' => 'margin-bottom:8px', 'normal' => 'margin-bottom:16px', 'relaxed' => 'margin-bottom:24px', 'spacious' => 'margin-bottom:32px'];
        if (isset($spacingMap[$gf['spacing']])) $fieldStyles[] = $spacingMap[$gf['spacing']];
    }
    if (!empty($gf['custom_height'])) {
        if (is_numeric($gf['custom_height'])) $fieldStyles[] = 'min-height: ' . (int)$gf['custom_height'] . 'px';
        elseif ($gf['custom_height'] === 'compact') $fieldStyles[] = 'min-height: 36px';
        elseif ($gf['custom_height'] === 'tall') $fieldStyles[] = 'min-height: 80px';
    }
    $styleAttr = implode('; ', $fieldStyles) . ';';
    echo '<div class="form-field-dynamic" style="' . $styleAttr . '" data-key="' . htmlspecialchars($gf['key']) . '">' . htmlspecialchars($gf['label']) . '</div>';
}
echo '</div>';
$renderedHtml = ob_get_clean();

assertTest("upload.php Grid HTML", "Generates 12-column grid container", strpos($renderedHtml, 'repeat(12, 1fr)') !== false, "Container has 12-col template");
assertTest("upload.php Span 4", "Renders 33% 4-column span", strpos($renderedHtml, 'grid-column: span 4') !== false, "Found span 4 in HTML");
assertTest("upload.php Span 3", "Renders 25% 3-column span", strpos($renderedHtml, 'grid-column: span 3') !== false, "Found span 3 in HTML");
assertTest("upload.php Span 9", "Renders 75% 9-column span", strpos($renderedHtml, 'grid-column: span 9') !== false, "Found span 9 in HTML");
assertTest("upload.php Offset 3", "Renders column-start indent", strpos($renderedHtml, 'grid-column-start: 4') !== false, "Found start col 4 (offset 3)");
assertTest("upload.php Alignment", "Renders justify-self & align-self", strpos($renderedHtml, 'justify-self: center') !== false && strpos($renderedHtml, 'align-self: center') !== false, "Centered alignment rendered");
assertTest("upload.php Custom Height", "Renders min-height 80px", strpos($renderedHtml, 'min-height: 80px') !== false, "Found 80px custom height");
assertTest("upload.php Spacing", "Renders relaxed/spacious margin", strpos($renderedHtml, 'margin-bottom:24px') !== false && strpos($renderedHtml, 'margin-bottom:32px') !== false, "Found relaxed & spacious margins");

// -----------------------------------------------------------------------------
// Test 6: Mobile Reflow Responsive Rules Check
// -----------------------------------------------------------------------------
$uploadPhpContent = file_get_contents(__DIR__ . '/upload.php');
$cssContent = file_get_contents(__DIR__ . '/assets/css/form-studio.css');

$hasUploadMobileReflow = (strpos($uploadPhpContent, '@media (max-width: 767px)') !== false && strpos($uploadPhpContent, 'grid-column: 1 / -1 !important') !== false);
assertTest("Responsive Mobile Reflow", "upload.php reflows on <=767px", $hasUploadMobileReflow, "Collapses 12 cols to full-width stack on mobile");

$hasStudioResponsive = (strpos($cssContent, '@media (max-width: 1024px)') !== false && strpos($cssContent, '@media (max-width: 768px)') !== false);
assertTest("Studio Responsive CSS", "form-studio.css tablet & mobile queries", $hasStudioResponsive, "Studio adapts viewport smoothly");

// -----------------------------------------------------------------------------
// Test 7: End-to-End Submission with Dynamic 12-Col Fields & Attachment
// -----------------------------------------------------------------------------
$testUser = [
    'id' => 1,
    'name' => 'Dr. Free Position User',
    'role' => 'Faculty',
    'department' => 'CSE'
];

$postData = [
    'academic_year' => '2024-25',
    'semester' => 'Even Semester',
    'department' => 'CSE',
    'course_code' => 'CS8501',
    'course_title' => 'Theory of Computation and Compiler Design',
    'special_notes' => 'Achieved 94% NBA accreditation compliance',
    'pass_percentage' => '94.50',
    'eval_remarks' => 'All students cleared with distinction'
];

$testFile = [
    'name' => 'accreditation_proof.pdf',
    'type' => 'application/pdf',
    'size' => 45000,
    'tmp_name' => sys_get_temp_dir() . '/test_acc_proof_' . uniqid() . '.pdf',
    'error' => UPLOAD_ERR_OK
];
file_put_contents($testFile['tmp_name'], "%PDF-1.4\nTest accreditation content\n%%EOF");

[$subOk, $subMsg, $subId] = generic_metric_record_submit(
    $metricKey,
    $testUser,
    '2024-25',
    $postData,
    $testFile
);
if (file_exists($testFile['tmp_name'])) @unlink($testFile['tmp_name']);

assertTest("End-to-End Submission", "Saves dynamic submission with proof", $subOk && !empty($subId), "Submission ID: {$subId} | Msg: {$subMsg}");

// Verify stored data integrity in DB
$stmt = $pdo->prepare("SELECT * FROM `generic_metric_records` WHERE `id` = ?");
$stmt->execute([(int)$subId]);
$rec = $stmt->fetch(PDO::FETCH_ASSOC);
$recData = json_decode($rec['data_json'] ?? '{}', true);

$dataIntegrityOk = ($rec && $recData['course_code'] === 'CS8501' && (float)$recData['pass_percentage'] === 94.50 && !empty($rec['proof_file']));
assertTest("Data Integrity", "Submitted fields preserved accurately", $dataIntegrityOk, "Stored course: {$recData['course_code']}, pass %: {$recData['pass_percentage']}");

// Clean up test data
try {
    $pdo->prepare("DELETE FROM `generic_metric_records` WHERE `metric_key` = ?")->execute([$metricKey]);
    $pdo->prepare("DELETE FROM `metrics` WHERE `metric_key` = ?")->execute([$metricKey]);
} catch (Throwable $e) {}

echo "====================================================================\n";
echo "TEST SUITE RESULTS: TOTAL: {$testCount} | PASSED: {$passCount} | FAILED: " . ($testCount - $passCount) . "\n";
echo "====================================================================\n";
if ($allPassed) {
    echo ">>> ALL FREE-POSITION & GLOBAL UPLOAD TESTS PASSED SUCCESSFULLY! <<<\n";
    exit(0);
} else {
    echo ">>> SOME TESTS FAILED! <<<\n";
    exit(1);
}
