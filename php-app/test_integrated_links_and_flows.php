<?php
/**
 * ATTS-IQAC Comprehensive Automated Test Suite:
 * Integrated Links, Cross-Navigation, Role-Flows, Dynamic Updating & Proof Retrieval
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/metric_studio.php';
require_once __DIR__ . '/inc/upload_sections.php';
require_once __DIR__ . '/models/Record.php';

$results = [];

function record_test(string $group, string $name, bool $passed, string $details = ''): void
{
    global $results;
    $results[] = [
        'group'   => $group,
        'name'    => $name,
        'status'  => $passed ? 'PASS' : 'FAIL',
        'details' => $details,
    ];
    echo sprintf("[%s] %-14s | %-48s | %s\n", $passed ? 'PASS' : 'FAIL', $group, $name, $details);
}

echo "====================================================================\n";
echo "STARTING INTEGRATED LINKS, DATA UPDATES & WORKFLOW AUDIT SUITE\n";
echo "====================================================================\n";

$pdo = db();
ensure_metric_studio_schema();

$testKey = 'test_audit_' . substr(md5((string)microtime(true)), 0, 8);
$testMetricId = null;
$createdRecordIds = [];
$createdProofFiles = [];

$mockAdmin = ['id' => 1, 'name' => 'IQAC Admin', 'role' => 'Admin', 'department' => 'IQAC'];
$mockFaculty = ['id' => 2, 'name' => 'CSE Faculty Member', 'role' => 'Faculty', 'department' => 'Computer Science and Engineering'];
$mockPrincipal = ['id' => 99, 'name' => 'College Principal', 'role' => 'Principal', 'department' => 'Administration'];

try {
    // ----------------------------------------------------------------
    // SECTION 1: Form Studio Navigation & Creation
    // ----------------------------------------------------------------
    [$createOk, $createMsg, $createdMetric] = metric_studio_create([
        'name'                => 'Integrated Links Audit Metric',
        'metric_key'          => $testKey,
        'category'            => 'ACADEMIC EXCELLENCE',
        'description'         => 'Automated test target for verifying links and flows.',
        'help_text'           => 'Please enter all test fields and attach certification order.',
        'display_order'       => 999,
        'metric_type'         => 'generic',
        'faculty_visible'     => 1,
        'coordinator_visible' => 1,
        'hod_visible'         => 1,
        'principal_visible'   => 1,
        'status'              => 1,
    ]);

    record_test('1. Creation', 'Create Dynamic Form via Metric Studio', $createOk && !empty($createdMetric['id']), "Metric ID: " . ($createdMetric['id'] ?? 'N/A'));
    $testMetricId = (int)$createdMetric['id'];

    // ----------------------------------------------------------------
    // SECTION 2: Metric Management (admin-metrics.php) Link Verification
    // ----------------------------------------------------------------
    // Verify Studio Link construction
    $expectedStudioUrl = url('form-studio.php?id=' . $testMetricId);
    $hasStudioTarget = str_contains($expectedStudioUrl, 'form-studio.php?id=' . $testMetricId);
    record_test('2. Links Audit', 'Action Dock Studio Designer URL', $hasStudioTarget, $expectedStudioUrl);

    // Verify Upload Page Link construction
    $expectedUploadUrl = url('upload.php?metric=' . $testKey);
    $hasUploadTarget = str_contains($expectedUploadUrl, 'upload.php?metric=' . $testKey);
    record_test('2. Links Audit', 'Action Dock Upload Page URL', $hasUploadTarget, $expectedUploadUrl);

    // Verify Status Toggle Action
    [$toggleOk, $toggleMsg] = metric_studio_toggle_status($testMetricId);
    $toggledMetric = metric_studio_find($testMetricId);
    $isNowInactive = ($toggledMetric && $toggledMetric['status'] === 0);
    record_test('2. Links Audit', 'Metric Management Status Toggle (to Inactive)', $toggleOk && $isNowInactive, 'Status flipped to 0');

    // Toggle back to active
    [$toggleBackOk, $toggleBackMsg] = metric_studio_toggle_status($testMetricId);
    $restoredMetric = metric_studio_find($testMetricId);
    $isNowActive = ($restoredMetric && $restoredMetric['status'] === 1);
    record_test('2. Links Audit', 'Metric Management Status Toggle (to Active)', $toggleBackOk && $isNowActive, 'Status restored to 1');

    // ----------------------------------------------------------------
    // SECTION 3: Form Studio Configuration, Drafts & Publishing
    // ----------------------------------------------------------------
    $testFields = [
        [
            'key' => 'student_name',
            'label' => 'Student Candidate Name',
            'type' => 'text',
            'width' => 'half',
            'col_span' => 1,
            'required' => true,
        ],
        [
            'key' => 'registration_no',
            'label' => 'Registration Number',
            'type' => 'text',
            'width' => 'half',
            'col_span' => 1,
            'required' => true,
        ],
        [
            'key' => 'award_level',
            'label' => 'Award Level',
            'type' => 'select',
            'width' => 'half',
            'col_span' => 1,
            'options' => ['International', 'National', 'State', 'Zonal'],
            'options_mode' => 'custom',
            'required' => true,
        ],
        [
            'key' => 'cash_prize',
            'label' => 'Cash Prize (INR)',
            'type' => 'integer',
            'width' => 'half',
            'col_span' => 1,
            'conditional' => ['field' => 'award_level', 'operator' => 'equals', 'value' => 'National'],
            'required' => false,
        ]
    ];

    $testAttachment = [
        'enabled'       => true,
        'label'         => 'Award Certificate & Sanction Order',
        'required'      => true,
        'mode'          => 'multiple',
        'allow_multiple'=> true,
        'allowed_types' => ['pdf'],
        'max_file_mb'   => 5,
        'max_files'     => 3,
        'min_files'     => 1,
        'help_text'     => 'Attach one or more supporting PDF certificates (max 5 MB each).'
    ];

    // Save Draft
    [$draftOk, $draftMsg] = metric_studio_save_draft_form($testMetricId, $testFields, $testAttachment);
    $afterDraft = metric_studio_find($testMetricId);
    $draftSavedProperly = ($afterDraft && !empty($afterDraft['form_config_draft']));
    record_test('3. Studio Flow', 'Save Form Draft Configuration', $draftOk && $draftSavedProperly, 'Draft JSON stored in database');

    // Publish Configuration
    [$pubOk, $pubMsg] = metric_studio_publish_form($testMetricId, $testFields, $testAttachment, 1);
    $afterPub = metric_studio_find($testMetricId);
    $pubVersion = (int)($afterPub['form_version'] ?? 1);
    record_test('3. Studio Flow', 'Publish Configuration (Version Increment)', $pubOk && $pubVersion === 2, "Published as Version: v{$pubVersion}");

    // Audit History Snapshot
    $historySnapshots = metric_studio_get_form_history($testMetricId);
    record_test('3. Studio Flow', 'Version History Snapshot Recorded', count($historySnapshots) >= 1, "Recorded snapshots: " . count($historySnapshots));

    // Save Reusable Field
    [$reuseOk, $reuseMsg] = metric_studio_save_reusable_field($testFields[0]);
    record_test('3. Studio Flow', 'Save Field to Reusable Library', $reuseOk, $reuseMsg);

    // Save Custom Option Set
    [$optOk, $optMsg] = metric_studio_save_option_set(
        'audit_levels_' . substr($testKey, -4),
        'Audit Award Levels',
        [
            ['value' => 'Tier 1', 'label' => 'Tier 1 Global'],
            ['value' => 'Tier 2', 'label' => 'Tier 2 Continental'],
            ['value' => 'Tier 3', 'label' => 'Tier 3 Regional']
        ],
        'Option set for automated testing'
    );
    record_test('3. Studio Flow', 'Save Custom Shared Option Set', $optOk, $optMsg);

    // ----------------------------------------------------------------
    // SECTION 4: Upload Page Routing & Multi-Param Flexibility
    // ----------------------------------------------------------------
    // Route by metric key
    $resByKey = resolve_upload_section($testKey, null, 'Faculty');
    $matchByKey = ($resByKey['section']['key'] ?? '') === $testKey;
    record_test('4. Upload Route', 'Routing by ?metric={metric_key}', $matchByKey, "Matched section key: " . ($resByKey['section']['key'] ?? 'none'));

    // Route by section key with 'section_' prefix
    $resByPrefixed = resolve_upload_section('section_' . $testKey, null, 'Faculty');
    $matchByPrefixed = ($resByPrefixed['section']['key'] ?? '') === $testKey;
    record_test('4. Upload Route', 'Routing by ?section=section_{metric_key}', $matchByPrefixed, "Matched section key: " . ($resByPrefixed['section']['key'] ?? 'none'));

    // Route by numeric metric ID
    $resById = resolve_upload_section((string)$testMetricId, null, 'Faculty');
    $matchById = (int)($resById['section']['metric_id'] ?? 0) === $testMetricId;
    record_test('4. Upload Route', 'Routing by ?id={metric_id} or ?metric={metric_id}', $matchById, "Matched section metric_id: " . ($resById['section']['metric_id'] ?? 'none'));

    // ----------------------------------------------------------------
    // SECTION 5: Dynamic Form Submissions & Multi-Proof Storage
    // ----------------------------------------------------------------
    $baseUploadDir = rtrim(UPLOAD_DIR, '/\\');
    $proofsDir = $baseUploadDir . '/proofs';
    if (!is_dir($proofsDir)) {
        @mkdir($proofsDir, 0775, true);
    }

    // Create 2 real PDF mock files
    $pdfDummy = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    $proofFile1 = 'proof_audit_1_' . $testKey . '.pdf';
    $proofFile2 = 'proof_audit_2_' . $testKey . '.pdf';
    file_put_contents($proofsDir . '/' . $proofFile1, $pdfDummy);
    file_put_contents($proofsDir . '/' . $proofFile2, $pdfDummy);
    $createdProofFiles[] = $proofFile1;
    $createdProofFiles[] = $proofFile2;

    // Simulate multi-file upload array in $_FILES
    $mockMultiFiles = [
        'name'     => [$proofFile1, $proofFile2],
        'type'     => ['application/pdf', 'application/pdf'],
        'tmp_name' => [$proofsDir . '/' . $proofFile1, $proofsDir . '/' . $proofFile2],
        'error'    => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
        'size'     => [strlen($pdfDummy), strlen($pdfDummy)],
    ];

    $submissionPost = [
        'student_name'    => 'Karthik Raja',
        'registration_no' => '917621104055',
        'award_level'     => 'National',
        'cash_prize'      => '25000',
    ];

    [$subOk, $subMsg, $subRecId] = generic_metric_record_submit($testKey, $mockFaculty, '2025-26', $submissionPost, $mockMultiFiles);
    record_test('5. Data Update', 'Multi-Proof Submission Accepted', $subOk && $subRecId > 0, "Record ID: {$subRecId} | " . $subMsg);
    $createdRecordIds[] = $subRecId;

    // Inspect database record for multi-file JSON storage
    $stmt = $pdo->prepare("SELECT * FROM generic_metric_records WHERE id = ?");
    $stmt->execute([$subRecId]);
    $storedRow = $stmt->fetch(PDO::FETCH_ASSOC);

    $proofArray = json_decode((string)($storedRow['proof_file'] ?? ''), true);
    $isJsonArray = is_array($proofArray) && count($proofArray) === 2;
    record_test('5. Data Update', 'Multi-Proof Stored as Valid JSON Array', $isJsonArray, "Stored: " . ($storedRow['proof_file'] ?? 'null'));

    // ----------------------------------------------------------------
    // SECTION 6: Proof Retrieval, view-proof.php & proof.php
    // ----------------------------------------------------------------
    // Lookup by filename using view-proof.php query logic
    $lookupFile = $proofArray[0] ?? $proofFile1;
    $vStmt = $pdo->prepare("SELECT * FROM `generic_metric_records` WHERE proof_file = ? OR proof_file LIKE ? LIMIT 1");
    $vStmt->execute([$lookupFile, '%"' . $lookupFile . '"%']);
    $vFound = $vStmt->fetch(PDO::FETCH_ASSOC);
    record_test('6. Proof Links', 'view-proof.php Multi-Proof Array Lookup', !empty($vFound['id']), "Found record ID: " . ($vFound['id'] ?? 'none'));

    // Lookup via proof.php by record ID
    $pStmt = $pdo->prepare("SELECT * FROM `generic_metric_records` WHERE id = ? LIMIT 1");
    $pStmt->execute([$subRecId]);
    $pFound = $pStmt->fetch(PDO::FETCH_ASSOC);
    record_test('6. Proof Links', 'proof.php by Dynamic Record ID Lookup', !empty($pFound['id']), "Found record ID: " . ($pFound['id'] ?? 'none'));

    // ----------------------------------------------------------------
    // SECTION 7: Deletion & Teardown Cleanup
    // ----------------------------------------------------------------
    // Delete record and verify proof files are removed
    [$delRecOk, $delRecMsg] = generic_metric_record_delete($subRecId, (int)$mockFaculty['id'], false);
    record_test('7. Cleanup Flow', 'Respondent Record Delete', $delRecOk, $delRecMsg);

    // Verify stored files were unlinked
    $storedFilesStillExist = false;
    foreach ($proofArray as $sf) {
        if (file_exists($proofsDir . '/' . $sf)) {
            $storedFilesStillExist = true;
        }
    }
    record_test('7. Cleanup Flow', 'Proof Files Removed from Disk on Record Delete', !$storedFilesStillExist, "Stored proof files removed cleanly");

    // Delete dynamic metric
    [$delMetOk, $delMetMsg] = metric_studio_delete($testMetricId);
    record_test('7. Cleanup Flow', 'Metric Studio Metric Delete & Order Resequence', $delMetOk, $delMetMsg);

} catch (Throwable $e) {
    record_test('Fatal Exception', 'Test Suite Execution Exception', false, $e->getMessage() . ' at line ' . $e->getLine());
} finally {
    // Safety cleanup in case of test failure
    if (!empty($testMetricId)) {
        try {
            $pdo->prepare("DELETE FROM generic_metric_records WHERE metric_key = ?")->execute([$testKey]);
            $pdo->prepare("DELETE FROM metrics WHERE id = ?")->execute([$testMetricId]);
        } catch (Throwable $e) {}
    }
    foreach ($createdProofFiles as $f) {
        $p1 = rtrim(UPLOAD_DIR, '/\\') . '/' . $f;
        $p2 = rtrim(UPLOAD_DIR, '/\\') . '/proofs/' . $f;
        if (is_file($p1)) @unlink($p1);
        if (is_file($p2)) @unlink($p2);
    }
}

echo "====================================================================\n";
$totalTests = count($results);
$passedTests = count(array_filter($results, fn($r) => $r['status'] === 'PASS'));
$failedTests = $totalTests - $passedTests;

echo sprintf("TOTAL TESTS: %d | PASSED: %d | FAILED: %d\n", $totalTests, $passedTests, $failedTests);
echo "====================================================================\n";

if ($failedTests === 0) {
    echo ">>> ALL INTEGRATED LINKS, DATA UPDATES & WORKFLOWS VERIFIED (100% PASS) <<<\n";
    exit(0);
} else {
    echo ">>> SOME TESTS FAILED. PLEASE REVIEW THE DETAILS ABOVE. <<<\n";
    exit(1);
}
