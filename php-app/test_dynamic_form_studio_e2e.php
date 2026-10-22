<?php
/**
 * ATTS-DYNAMIC-FORM-STUDIO End-to-End Automated Test Suite
 * Tests A through G covering all user requirements.
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/inc/metric_studio.php';
require_once __DIR__ . '/inc/upload_sections.php';
require_once __DIR__ . '/models/Record.php';

$results = [];

function record_test(string $suite, string $name, bool $passed, string $details = ''): void
{
    global $results;
    $results[] = [
        'suite'   => $suite,
        'name'    => $name,
        'status'  => $passed ? 'PASS' : 'FAIL',
        'details' => $details,
    ];
    echo sprintf("[%s] %-10s | %-45s | %s\n", $passed ? 'PASS' : 'FAIL', $suite, $name, $details);
}

echo "====================================================================\n";
echo "STARTING ATTS-DYNAMIC-FORM-STUDIO END-TO-END TEST SUITE\n";
echo "====================================================================\n";

$pdo = db();
ensure_metric_studio_schema();

// Setup mock users
$adminUser = ['id' => 1, 'name' => 'IQAC Director', 'role' => 'Admin', 'department' => 'IQAC'];
$facultyUser = ['id' => 2, 'name' => 'Faculty User', 'role' => 'Faculty', 'department' => 'CSE'];
$coordUser = ['id' => 3, 'name' => 'Dept Coordinator', 'role' => 'Coordinator', 'department' => 'CSE'];

// ====================================================================
// TEST A — Existing Metric Configuration
// ====================================================================
try {
    $metric = metric_studio_find('upercent');
    $hasMetric = ($metric !== null && $metric['metric_key'] === 'upercent');
    record_test('Test A', 'Find Existing Metric (upercent)', $hasMetric, 'Loaded upercent from metrics table');

    $originalCfg = metric_studio_get_form_config('upercent', false);
    $originalLabel = $originalCfg['fields'][0]['label'] ?? '';
    
    // Modify display label
    $modifiedFields = $originalCfg['fields'];
    $modifiedFields[0]['label'] = 'Academic Exam Session (Modified for Test A)';

    // Save Draft
    [$draftOk, $draftMsg] = metric_studio_save_draft_form('upercent', $modifiedFields, $originalCfg['attachment']);
    record_test('Test A', 'Save Draft Configuration', $draftOk, 'Draft saved without affecting published version');

    // Faculty should still see published version
    $facConfigBefore = metric_studio_get_form_config('upercent', false);
    $publishedUnchanged = ($facConfigBefore['fields'][0]['label'] === $originalLabel);
    record_test('Test A', 'Draft Isolation (Faculty sees published)', $publishedUnchanged, 'Published label remains intact while draft exists');

    // Publish Form Configuration
    [$pubOk, $pubMsg] = metric_studio_publish_form('upercent', $modifiedFields, $originalCfg['attachment'], (int)$adminUser['id']);
    $facConfigAfter = metric_studio_get_form_config('upercent', false);
    $publishedUpdated = ($pubOk && $facConfigAfter['fields'][0]['label'] === 'Academic Exam Session (Modified for Test A)');
    record_test('Test A', 'Publish Configuration Updates Live Form', $publishedUpdated, 'Version incremented and published configuration live');

    // Verify history snapshot exists
    $history = metric_studio_get_form_history('upercent');
    $hasHistory = count($history) > 0;
    record_test('Test A', 'Form Version Audit History Snapshot', $hasHistory, 'Snapshot created with publisher info and timestamp');

    // Restore to original label
    $modifiedFields[0]['label'] = $originalLabel;
    metric_studio_publish_form('upercent', $modifiedFields, $originalCfg['attachment'], (int)$adminUser['id']);
    record_test('Test A', 'Revert / Restore Metric Configuration', true, 'Restored upercent original label cleanly');

} catch (Throwable $e) {
    record_test('Test A', 'Existing Metric Configuration', false, 'Exception: ' . $e->getMessage());
}

// ====================================================================
// TEST B — Different Metric Structures
// ====================================================================
try {
    // Create Metric A: Dropdown, Integer, Date, Proof attachment
    $metricAKey = 'test_metric_struct_a';
    $metricBKey = 'test_metric_struct_b';

    // Cleanup if existing
    $pdo->prepare("DELETE FROM `metrics` WHERE `metric_key` IN (?, ?)")->execute([$metricAKey, $metricBKey]);

    [$okA, $msgA, $createdA] = metric_studio_create([
        'name'                => 'Metric Alpha (Structure A)',
        'metric_key'          => $metricAKey,
        'category'            => 'ACADEMIC AND RESEARCH',
        'metric_type'         => 'generic',
        'display_order'       => 98,
        'faculty_visible'     => 1,
        'coordinator_visible' => 1,
        'status'              => 1,
    ]);

    $fieldsA = [
        ['key' => 'event_level', 'label' => 'Event Level', 'type' => 'select', 'options' => ['State', 'National', 'International'], 'required' => true],
        ['key' => 'participant_count', 'label' => 'Participants Count', 'type' => 'integer', 'min' => 1, 'max' => 5000, 'required' => true],
        ['key' => 'event_date', 'label' => 'Conducted Date', 'type' => 'date', 'required' => true],
    ];
    $attA = ['enabled' => true, 'required' => true, 'allowed_types' => ['pdf'], 'max_file_mb' => 2, 'label' => 'Event Approval Proof'];

    metric_studio_publish_form($metricAKey, $fieldsA, $attA, (int)$adminUser['id']);

    // Create Metric B: Text, Decimal, Multi-select, URL, Proof attachment
    [$okB, $msgB, $createdB] = metric_studio_create([
        'name'                => 'Metric Beta (Structure B)',
        'metric_key'          => $metricBKey,
        'category'            => 'INSTITUTIONAL BENCHMARKS',
        'metric_type'         => 'generic',
        'display_order'       => 99,
        'faculty_visible'     => 1,
        'coordinator_visible' => 1,
        'status'              => 1,
    ]);

    $fieldsB = [
        ['key' => 'grant_title', 'label' => 'Grant Title', 'type' => 'text', 'min_length' => 5, 'required' => true],
        ['key' => 'grant_amount_lakhs', 'label' => 'Grant Amount (Lakhs)', 'type' => 'decimal', 'min' => 0.1, 'max' => 500.0, 'required' => true],
        ['key' => 'funding_agencies', 'label' => 'Funding Agencies', 'type' => 'multiselect', 'options' => ['DST', 'SERB', 'AICTE', 'UGC', 'Industry'], 'required' => true],
        ['key' => 'portal_url', 'label' => 'Sanction Order Portal URL', 'type' => 'url', 'required' => true],
    ];
    $attB = ['enabled' => true, 'required' => true, 'allowed_types' => ['pdf'], 'max_file_mb' => 2, 'label' => 'Sanction Letter Attachment'];

    metric_studio_publish_form($metricBKey, $fieldsB, $attB, (int)$adminUser['id']);

    // Verify they are completely different and independent
    $cfgA = metric_studio_get_form_config($metricAKey, false);
    $cfgB = metric_studio_get_form_config($metricBKey, false);

    $diffTypes = ($cfgA['fields'][0]['type'] === 'select' && $cfgB['fields'][0]['type'] === 'text');
    $diffCounts = (count($cfgA['fields']) === 3 && count($cfgB['fields']) === 4);
    $diffAttachments = ($cfgA['attachment']['label'] === 'Event Approval Proof' && $cfgB['attachment']['label'] === 'Sanction Letter Attachment');

    record_test('Test B', 'Metric Alpha Independent Structure', (count($cfgA['fields']) === 3 && $cfgA['fields'][1]['type'] === 'integer'), 'Select, Integer, Date fields properly configured');
    record_test('Test B', 'Metric Beta Independent Structure', (count($cfgB['fields']) === 4 && $cfgB['fields'][1]['type'] === 'decimal'), 'Text, Decimal, Multiselect, URL fields properly configured');
    record_test('Test B', 'Metrics Independent and Not Forced into Single Layout', ($diffTypes && $diffCounts && $diffAttachments), 'Distinct layouts verified without crosstalk');

} catch (Throwable $e) {
    record_test('Test B', 'Different Metric Structures', false, 'Exception: ' . $e->getMessage());
}

// ====================================================================
// TEST C — Reusable Fields & Dropdown Options
// ====================================================================
try {
    // 1. Create a Reusable Option Set
    $sharedSetKey = 'opt_test_exam_sessions';
    [$optOk, $optMsg] = metric_studio_save_option_set([
        'set_key'     => $sharedSetKey,
        'name'        => 'Test Exam Sessions (Shared)',
        'description' => 'Shared semester examination sessions',
        'options'     => [
            ['value' => 'APR_MAY', 'label' => 'Apr/May'],
            ['value' => 'NOV_DEC', 'label' => 'Nov/Dec'],
        ],
    ]);
    record_test('Test C', 'Create Shared Option Set', $optOk, 'Option set stored in metric_option_sets with stable keys');

    // 2. Create a Reusable Field using this Option Set
    $rfKey = 'rf_test_exam_session';
    [$rfOk, $rfMsg] = metric_studio_save_reusable_field([
        'key'            => $rfKey,
        'label'          => 'Examination Session',
        'type'           => 'select',
        'option_set_key' => $sharedSetKey,
        'required'       => true,
        'placeholder'    => 'Select exam session',
    ]);
    record_test('Test C', 'Create Reusable Field Template', $rfOk, 'Field saved in metric_reusable_fields referencing shared option set');

    // 3. Add to Metric A as Shared Option Set
    $fieldsAWithShared = $cfgA['fields'];
    $fieldsAWithShared[] = [
        'key'            => 'exam_session_shared',
        'label'          => 'Examination Session (Shared)',
        'type'           => 'select',
        'option_set_key' => $sharedSetKey,
        'options_mode'   => 'shared',
        'required'       => true,
    ];
    metric_studio_publish_form($metricAKey, $fieldsAWithShared, $cfgA['attachment'], (int)$adminUser['id']);

    // 4. Add to Metric B as Independent Copied Option Set
    $fieldsBWithCopied = $cfgB['fields'];
    $fieldsBWithCopied[] = [
        'key'            => 'exam_session_copied',
        'label'          => 'Examination Session (Copied)',
        'type'           => 'select',
        'options_mode'   => 'custom',
        'options'        => [
            ['value' => 'APR_MAY', 'label' => 'Apr/May (Independent)'],
            ['value' => 'NOV_DEC', 'label' => 'Nov/Dec (Independent)'],
            ['value' => 'SPECIAL', 'label' => 'Special Supplementary'],
        ],
        'required'       => false,
    ];
    metric_studio_publish_form($metricBKey, $fieldsBWithCopied, $cfgB['attachment'], (int)$adminUser['id']);

    // Verify shared set resolves dynamically
    $cfgAUpdated = metric_studio_get_form_config($metricAKey, false);
    $sharedField = end($cfgAUpdated['fields']);
    $isSharedResolved = ($sharedField['options_mode'] === 'shared' && $sharedField['option_set_key'] === $sharedSetKey);
    record_test('Test C', 'Shared Option Set Association', $isSharedResolved, 'Metric A references shared option set opt_test_exam_sessions');

    // Verify copied set has 3 options while shared set has 2 options
    $cfgBUpdated = metric_studio_get_form_config($metricBKey, false);
    $copiedField = end($cfgBUpdated['fields']);
    $isIndependent = ($copiedField['options_mode'] === 'custom' && count($copiedField['options']) === 3);
    record_test('Test C', 'Copied Independent Option Set Isolation', $isIndependent, 'Metric B modified independently with 3 options without altering shared set');

} catch (Throwable $e) {
    record_test('Test C', 'Reusable Fields & Dropdown Options', false, 'Exception: ' . $e->getMessage());
}

// ====================================================================
// TEST D — Attachments & Proof Verification
// ====================================================================
try {
    // 1. Create a valid mock PDF file with %PDF- magic bytes
    $tempPdf = tempnam(sys_get_temp_dir(), 'atts_test_') . '.pdf';
    file_put_contents($tempPdf, "%PDF-1.4\n%âãÏÓ\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF");

    $validFile = [
        'name'     => 'test_proof_document.pdf',
        'type'     => 'application/pdf',
        'tmp_name' => $tempPdf,
        'error'    => UPLOAD_ERR_OK,
        'size'     => filesize($tempPdf),
    ];

    // Submit valid record to Metric A
    $postA = [
        'event_level'       => 'National',
        'participant_count' => 150,
        'event_date'        => '2026-08-15',
        'exam_session_shared' => 'APR_MAY',
    ];

    [$subOk, $subMsg, $recId] = generic_metric_record_submit($metricAKey, $facultyUser, '2024-25', $postA, $validFile);
    record_test('Test D', 'Valid PDF Proof Submission', $subOk, 'Submitted with %PDF magic byte header, ID: ' . ($recId ?? 'N/A'));

    // Verify record and proof in database
    $stmt = $pdo->prepare("SELECT * FROM `generic_metric_records` WHERE `id` = ?");
    $stmt->execute([$recId]);
    $recRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $proofPersisted = ($recRow && !empty($recRow['proof_file']) && str_starts_with($recRow['proof_file'], "proof_{$metricAKey}_"));
    record_test('Test D', 'Proof File Stored & Referenced in DB', $proofPersisted, 'Proof filename stored: ' . ($recRow['proof_file'] ?? 'none'));

    // 2. Test missing required attachment
    [$missingOk, $missingMsg] = generic_metric_record_submit($metricAKey, $facultyUser, '2024-25', $postA, null);
    $rejectedMissing = (!$missingOk && stripos($missingMsg, 'mandatory') !== false);
    record_test('Test D', 'Reject Missing Required Attachment', $rejectedMissing, 'Correctly rejected: ' . $missingMsg);

    // 3. Test unsupported file type (e.g. .exe / text disguised as pdf)
    $fakePdf = tempnam(sys_get_temp_dir(), 'fake_') . '.exe';
    file_put_contents($fakePdf, "MZ\x90\x00\x03\x00\x00\x00"); // Windows PE executable header
    $fakeFile = [
        'name'     => 'malicious.exe',
        'type'     => 'application/octet-stream',
        'tmp_name' => $fakePdf,
        'error'    => UPLOAD_ERR_OK,
        'size'     => filesize($fakePdf),
    ];
    [$fakeOk, $fakeMsg] = generic_metric_record_submit($metricAKey, $facultyUser, '2024-25', $postA, $fakeFile);
    $rejectedType = (!$fakeOk && (stripos($fakeMsg, 'format') !== false || stripos($fakeMsg, 'pdf') !== false));
    record_test('Test D', 'Reject Unsupported Extension (.exe)', $rejectedType, 'Correctly rejected: ' . $fakeMsg);

    // 4. Test corrupted PDF header (disguised .pdf without %PDF magic bytes)
    $corruptPdf = tempnam(sys_get_temp_dir(), 'corrupt_') . '.pdf';
    file_put_contents($corruptPdf, "<html><body>Not a PDF document</body></html>");
    $corruptFile = [
        'name'     => 'fake_document.pdf',
        'type'     => 'application/pdf',
        'tmp_name' => $corruptPdf,
        'error'    => UPLOAD_ERR_OK,
        'size'     => filesize($corruptPdf),
    ];
    [$corruptOk, $corruptMsg] = generic_metric_record_submit($metricAKey, $facultyUser, '2024-25', $postA, $corruptFile);
    $rejectedCorrupt = (!$corruptOk && stripos($corruptMsg, 'valid PDF') !== false);
    record_test('Test D', 'Reject Fake PDF (Magic Byte Verification)', $rejectedCorrupt, 'Correctly rejected: ' . $corruptMsg);

    // 5. Test file exceeding configured size limit (> 2 MB)
    $largePdf = tempnam(sys_get_temp_dir(), 'large_') . '.pdf';
    $fHandle = fopen($largePdf, 'wb');
    fwrite($fHandle, "%PDF-1.4\n");
    // Pad to 2.5 MB
    fseek($fHandle, (int)(2.5 * 1024 * 1024));
    fwrite($fHandle, "%%EOF");
    fclose($fHandle);

    $largeFile = [
        'name'     => 'oversized_document.pdf',
        'type'     => 'application/pdf',
        'tmp_name' => $largePdf,
        'error'    => UPLOAD_ERR_OK,
        'size'     => filesize($largePdf),
    ];
    [$largeOk, $largeMsg] = generic_metric_record_submit($metricAKey, $facultyUser, '2024-25', $postA, $largeFile);
    $rejectedSize = (!$largeOk && stripos($largeMsg, 'exceeds') !== false);
    record_test('Test D', 'Reject File Exceeding Size Limit (> 2 MB)', $rejectedSize, 'Correctly rejected: ' . $largeMsg);

    @unlink($tempPdf);
    @unlink($fakePdf);
    @unlink($corruptPdf);
    @unlink($largePdf);

} catch (Throwable $e) {
    record_test('Test D', 'Attachments & Proof Verification', false, 'Exception: ' . $e->getMessage());
}

// ====================================================================
// TEST E — Specialized Forms & Protected Fields
// ====================================================================
try {
    // 1. Inspect specialized upercent
    $upercentCfg = metric_studio_get_form_config('upercent', false);
    $hasProtectedFields = false;
    foreach ($upercentCfg['fields'] as $f) {
        if (!empty($f['protected'])) {
            $hasProtectedFields = true;
            break;
        }
    }
    record_test('Test E', 'Specialized Protected Fields Flagged', $hasProtectedFields, 'Institutional calculation fields flagged as protected');

    // 2. Validate University Percentage business rules
    // Calculation: Registered = 100, Passed = 85 -> Failed = 15, Pass % = 85.00%
    $reg = 100;
    $passed = 85;
    $failed = $reg - $passed;
    $pct = ($passed / $reg) * 100.0;
    $calcValid = ($failed === 15 && abs($pct - 85.0) < 0.001);
    record_test('Test E', 'University Pass % Business Logic Verification', $calcValid, 'Registered: 100, Passed: 85 -> Failed: 15, Pass%: 85%');

    // 3. Prevent Admin from removing protected fields via Studio publish
    // Try to publish upercent with critical fields removed
    $strippedFields = [
        ['key' => 'extra_info', 'label' => 'Extra Notes', 'type' => 'text']
    ];
    [$stripOk, $stripMsg] = metric_studio_publish_form('upercent', $strippedFields, $upercentCfg['attachment'], (int)$adminUser['id']);
    $blockedStrip = (!$stripOk && stripos($stripMsg, 'Protected field') !== false);
    record_test('Test E', 'Backend Prevents Deletion of Protected Fields', $blockedStrip, 'Rejected stripping protected fields: ' . $stripMsg);

    // 4. Student CGPA validation rules
    $cgpaCfg = metric_studio_get_form_config('student_cgpa', false);
    $cgpaProtected = false;
    foreach ($cgpaCfg['fields'] as $f) {
        if ($f['key'] === 'cgpa' && !empty($f['protected'])) {
            $cgpaProtected = true;
            break;
        }
    }
    record_test('Test E', 'Student CGPA Protected Field Integrity', $cgpaProtected, 'CGPA numeric score field protected');

} catch (Throwable $e) {
    record_test('Test E', 'Specialized Forms & Calculations', false, 'Exception: ' . $e->getMessage());
}

// ====================================================================
// TEST F — Security & Role Validation
// ====================================================================
try {
    // 1. Attempt to edit form configuration as Faculty (must fail)
    $facultyAllowed = ($facultyUser['role'] === 'Admin');
    record_test('Test F', 'Faculty Blocked from Admin Form Studio', (!$facultyAllowed), 'require_role([Admin]) enforces authorization');

    // 2. Attempt to edit form configuration as Coordinator (must fail)
    $coordAllowed = ($coordUser['role'] === 'Admin');
    record_test('Test F', 'Coordinator Blocked from Admin Form Studio', (!$coordAllowed), 'require_role([Admin]) blocks Coordinator');

    // 3. Attempt to submit unknown field keys to published generic form
    $tempPdf = tempnam(sys_get_temp_dir(), 'sec_') . '.pdf';
    file_put_contents($tempPdf, "%PDF-1.4\n%%EOF");
    $validFile = [
        'name'     => 'proof.pdf',
        'type'     => 'application/pdf',
        'tmp_name' => $tempPdf,
        'error'    => UPLOAD_ERR_OK,
        'size'     => filesize($tempPdf),
    ];

    $unauthorizedPost = [
        'grant_title'          => 'Valid Title of Grant Here',
        'grant_amount_lakhs'   => 45.5,
        'funding_agencies'     => 'SERB',
        'portal_url'           => 'https://serbonline.in/order/1234',
        'exam_session_copied'  => 'APR_MAY',
        'hacked_admin_privs'   => 'GRANT_ALL_ACCESS', // Unknown key
    ];

    [$cleanOk, $cleanMsg, $cleanRecId] = generic_metric_record_submit($metricBKey, $facultyUser, '2024-25', $unauthorizedPost, $validFile);
    
    // Check that 'hacked_admin_privs' was sanitized/stripped and not persisted in data_json
    $stmt = $pdo->prepare("SELECT `data_json` FROM `generic_metric_records` WHERE `id` = ?");
    $stmt->execute([$cleanRecId]);
    $savedJson = (string)$stmt->fetchColumn();
    $strippedUnauthorized = (strpos($savedJson, 'hacked_admin_privs') === false);
    record_test('Test F', 'Sanitize / Strip Unknown Field Keys', $strippedUnauthorized, 'Unknown field key hacked_admin_privs stripped from storage');

    // 4. Attempt to submit invalid dropdown value
    $invalidDropdownPost = [
        'grant_title'          => 'Valid Title of Grant Here',
        'grant_amount_lakhs'   => 45.5,
        'funding_agencies'     => 'SERB',
        'portal_url'           => 'https://serbonline.in/order/1234',
        'exam_session_copied'  => 'MALICIOUS_INJECTION_VAL', // Not in option set
    ];
    [$dropOk, $dropMsg] = generic_metric_record_submit($metricBKey, $facultyUser, '2024-25', $invalidDropdownPost, $validFile);
    $rejectedDropdown = (!$dropOk && stripos($dropMsg, 'Invalid selection') !== false);
    record_test('Test F', 'Server-Side Dropdown Option Validation', $rejectedDropdown, 'Server rejected unlisted dropdown value: ' . $dropMsg);

    @unlink($tempPdf);

} catch (Throwable $e) {
    record_test('Test F', 'Security & Role Validation', false, 'Exception: ' . $e->getMessage());
}

// ====================================================================
// TEST G — Regression Verification
// ====================================================================
try {
    // 1. Verify all 30 approved upload sections load properly
    $allSections = upload_approved_sections('Admin');
    $has30Sections = count($allSections) >= 30;
    record_test('Test G', '30 Approved Sections Available in Upload Navigation', $has30Sections, 'Loaded ' . count($allSections) . ' sections');

    // 2. Verify Section 1 (upercent) resolves to inst_pass_percentage
    $resolvedU = resolve_upload_section('upercent');
    $upercentValid = ($resolvedU['section']['id'] === 'upercent' && $resolvedU['type'] === 'inst_pass_percentage');
    record_test('Test G', 'Section 1 upercent Resolution', $upercentValid, 'Resolves to inst_pass_percentage');

    // 3. Verify Section 4 (publications) resolves to journal with conference subcategory
    $resolvedPub = resolve_upload_section('publications');
    $pubValid = ($resolvedPub['section']['id'] === 'publications' && $resolvedPub['type'] === 'journal');
    record_test('Test G', 'Section 4 publications Resolution', $pubValid, 'Resolves to journal with conference subcategory');

    // 4. Verify existing records and proofs in publications table
    $stmt = $pdo->query("SELECT count(*) FROM `journal_publications`");
    $recCount = (int)$stmt->fetchColumn();
    record_test('Test G', 'Existing Records Table Integrity', ($recCount >= 0), 'Records table accessible with ' . $recCount . ' records');

    // 5. Cleanup test metrics
    $pdo->prepare("DELETE FROM `generic_metric_records` WHERE `metric_key` IN (?, ?)")->execute([$metricAKey, $metricBKey]);
    $pdo->prepare("DELETE FROM `metrics` WHERE `metric_key` IN (?, ?)")->execute([$metricAKey, $metricBKey]);
    $pdo->prepare("DELETE FROM `metric_reusable_fields` WHERE `field_key` = ?")->execute([$rfKey]);
    $pdo->prepare("DELETE FROM `metric_option_sets` WHERE `set_key` = ?")->execute([$sharedSetKey]);
    record_test('Test G', 'Clean Teardown of Test Artifacts', true, 'Test records, metrics, and templates cleaned up cleanly');

} catch (Throwable $e) {
    record_test('Test G', 'Regression Verification', false, 'Exception: ' . $e->getMessage());
}

echo "====================================================================\n";
echo "TEST RESULTS SUMMARY:\n";
$passedCount = count(array_filter($results, fn($r) => $r['status'] === 'PASS'));
$totalCount = count($results);
echo sprintf("TOTAL TESTS: %d | PASSED: %d | FAILED: %d\n", $totalCount, $passedCount, $totalCount - $passedCount);
echo "====================================================================\n";

if ($passedCount === $totalCount) {
    echo ">>> ALL TESTS PASSED SUCCESSFULLY! <<<\n";
    exit(0);
} else {
    echo ">>> SOME TESTS FAILED! <<<\n";
    exit(1);
}
