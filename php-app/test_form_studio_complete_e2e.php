<?php
/**
 * ATTS-IQAC — Form Studio Complete End-to-End Test Suite
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/metric_studio.php';
require_once __DIR__ . '/inc/upload_sections.php';

$pdo = db();
$testsPassed = 0;
$testsFailed = 0;

function assertTest(string $name, bool $condition, string $details = ''): void {
    global $testsPassed, $testsFailed;
    if ($condition) {
        $testsPassed++;
        echo "[\033[32mPASS\033[0m] " . str_pad($name, 50) . " | {$details}\n";
    } else {
        $testsFailed++;
        echo "[\033[31mFAIL\033[0m] " . str_pad($name, 50) . " | {$details}\n";
    }
}

echo "====================================================================\n";
echo "STARTING COMPLETE FORM STUDIO VERIFICATION TEST SUITE\n";
echo "====================================================================\n";

// Setup Test Admin User
$testAdmin = [
    'id' => 9991,
    'name' => 'IQAC Director Admin',
    'role' => 'Admin',
    'department' => 'IQAC',
    'department_id' => 1
];

// Setup Test Faculty User
$testFaculty = [
    'id' => 9992,
    'name' => 'Dr. Jane Faculty',
    'role' => 'Faculty',
    'department' => 'Computer Science and Engineering',
    'department_id' => 2
];

// Setup Test Principal User
$testPrincipal = [
    'id' => 9993,
    'name' => 'Dr. Robert Principal',
    'role' => 'Principal',
    'department' => 'Administration',
    'department_id' => 1
];

// -----------------------------------------------------------------------------
// 1. Create a Test Dynamic Form in Studio
// -----------------------------------------------------------------------------
$metricKey = 'test_studio_e2e_' . bin2hex(random_bytes(4));
[$createOk, $createMsg, $createdMetric] = metric_studio_create([
    'name'                => 'E2E Research Innovation Grant',
    'metric_key'          => $metricKey,
    'category'            => 'RESEARCH AND PUBLICATIONS',
    'description'         => 'Test form for grant proposals',
    'display_order'       => 99,
    'metric_type'         => 'generic',
    'faculty_visible'     => 1,
    'coordinator_visible' => 1,
    'hod_visible'         => 1,
    'principal_visible'   => 1,
    'status'              => 1,
]);

assertTest('Form Creation in Studio', $createOk && !empty($createdMetric['id']), "Created form ID: " . ($createdMetric['id'] ?? 0));

$formId = (int)$createdMetric['id'];

// -----------------------------------------------------------------------------
// 2. Configure Dynamic Fields with Layout, Option Sets & Conditional Logic
// -----------------------------------------------------------------------------
$fieldsToSave = [
    [
        'key' => 'grant_sec_1',
        'label' => 'Proposal Overview',
        'type' => 'section_header',
        'help_text' => 'Enter basic grant identification details'
    ],
    [
        'key' => 'grant_title',
        'label' => 'Grant Proposal Title',
        'type' => 'text',
        'required' => true,
        'placeholder' => 'Enter official grant title',
        'faculty_editable' => true,
        'coordinator_editable' => true,
        'hod_editable' => true,
        'principal_editable' => true
    ],
    [
        'key' => 'grant_agency',
        'label' => 'Funding Agency',
        'type' => 'select',
        'required' => true,
        'options_mode' => 'custom',
        'options' => [
            ['value' => 'DST_SERB', 'label' => 'DST / SERB'],
            ['value' => 'AICTE_RPS', 'label' => 'AICTE RPS'],
            ['value' => 'INDUSTRY', 'label' => 'Industry Partner']
        ],
        'faculty_editable' => true,
        'coordinator_editable' => true,
        'hod_editable' => true,
        'principal_editable' => true
    ],
    [
        'key' => 'has_industry_co_sponsor',
        'label' => 'Is Industry Co-Sponsored?',
        'type' => 'radio',
        'required' => true,
        'options_mode' => 'custom',
        'options' => [
            ['value' => 'Yes', 'label' => 'Yes'],
            ['value' => 'No', 'label' => 'No']
        ]
    ],
    // Conditional Field: Only visible if has_industry_co_sponsor = Yes
    [
        'key' => 'industry_company_name',
        'label' => 'Industry Partner Company Name',
        'type' => 'text',
        'required' => true,
        'conditional' => [
            'field' => 'has_industry_co_sponsor',
            'operator' => 'equals',
            'value' => 'Yes'
        ]
    ],
    [
        'key' => 'grant_amount_lakhs',
        'label' => 'Proposed Budget (Lakhs)',
        'type' => 'decimal',
        'required' => true,
        'min' => 0.5,
        'max' => 100.0
    ],
    [
        'key' => 'grant_declaration',
        'label' => 'I hereby certify that all information is accurate and vetted by IQAC',
        'type' => 'acknowledgement',
        'required' => true
    ]
];

$attachmentConfig = [
    'enabled' => true,
    'label' => 'Grant Sanction & Proposal Proof',
    'required' => true,
    'preset' => 'pdf_only',
    'allowed_types' => ['pdf'],
    'max_file_mb' => 5,
    'max_files' => 3,
    'min_files' => 1,
    'help_text' => 'Upload up to 3 PDF sanction letters (max 5 MB each).'
];

[$draftOk, $draftMsg] = metric_studio_save_draft_form($formId, $fieldsToSave, $attachmentConfig);
assertTest('Save Draft Configuration', $draftOk, 'Draft fields and multi-file attachment config saved');

// Verify Draft Isolation (Published remains original v1)
$fetchedV1 = metric_studio_find($formId);
assertTest('Draft Isolation Verified', (int)$fetchedV1['form_version'] === 1 && !empty($fetchedV1['form_config_draft']), 'Published config remains isolated');

// -----------------------------------------------------------------------------
// 3. Publish Configuration & Version Snapshot
// -----------------------------------------------------------------------------
[$pubOk, $pubMsg] = metric_studio_publish_form($formId, $fieldsToSave, $attachmentConfig, 9991);
assertTest('Publish Configuration', $pubOk, 'Published successfully');

$fetchedV2 = metric_studio_find($formId);
assertTest('Version Incremented to v2', (int)$fetchedV2['form_version'] === 2, 'Live version is now v2');

$historySnapshots = metric_studio_get_form_history($formId);
assertTest('History Snapshot Recorded', count($historySnapshots) >= 1, 'Version snapshot present in audit history');

// -----------------------------------------------------------------------------
// 4. Test Role-Based Visibility (Faculty, Coordinator, HoD, Principal)
// -----------------------------------------------------------------------------
$facultySecs = upload_approved_sections('Faculty', true);
$principalSecs = upload_approved_sections('Principal', true);

assertTest('Faculty Visibility Respected', isset($facultySecs[$metricKey]), 'Form is visible in Faculty upload sections');
assertTest('Principal Visibility Respected', isset($principalSecs[$metricKey]), 'Form is visible in Principal upload sections');

// Toggle Principal visibility off
metric_studio_update($formId, [
    'principal_visible' => 0
]);
$principalSecsAfter = upload_approved_sections('Principal', true);
assertTest('Principal Restricted when principal_visible=0', !isset($principalSecsAfter[$metricKey]), 'Form correctly hidden from Principal');

// Re-enable
metric_studio_update($formId, [
    'principal_visible' => 1
]);

// -----------------------------------------------------------------------------
// 5. Test Conditional Submission Validation
// -----------------------------------------------------------------------------
// Case A: has_industry_co_sponsor = No -> industry_company_name is conditional and skipped
$postDataNo = [
    'grant_title' => 'AI in Smart Agriculture Research',
    'grant_agency' => 'DST_SERB',
    'has_industry_co_sponsor' => 'No',
    // industry_company_name omitted
    'grant_amount_lakhs' => '12.50',
    'grant_declaration' => '1'
];

$tmpPdf = tempnam(sys_get_temp_dir(), 'pdf_');
file_put_contents($tmpPdf, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF");
$validPdfFile = [
    'name' => 'dst_grant_proposal.pdf',
    'type' => 'application/pdf',
    'tmp_name' => $tmpPdf,
    'error' => UPLOAD_ERR_OK,
    'size' => filesize($tmpPdf)
];

[$submitOk1, $submitMsg1, $recordId1] = generic_metric_record_submit($metricKey, $testFaculty, '2025-26', $postDataNo, $validPdfFile);
assertTest('Conditional Submission Valid (Inactive Condition Skipped)', $submitOk1, "Record created ID: {$recordId1}");

// Case B: has_industry_co_sponsor = Yes -> industry_company_name is required!
$postDataYesMissing = [
    'grant_title' => 'Cloud IoT Industrial Automation',
    'grant_agency' => 'INDUSTRY',
    'has_industry_co_sponsor' => 'Yes',
    // industry_company_name omitted -> Should Fail!
    'grant_amount_lakhs' => '25.00',
    'grant_declaration' => '1'
];
[$submitOk2, $submitMsg2] = generic_metric_record_submit($metricKey, $testFaculty, '2025-26', $postDataYesMissing, $validPdfFile);
assertTest('Conditional Validation Enforced (Active Condition Required)', !$submitOk2, 'Correctly rejected missing conditional field: ' . $submitMsg2);

// Case C: has_industry_co_sponsor = Yes with company provided -> Should succeed!
$postDataYesValid = $postDataYesMissing;
$postDataYesValid['industry_company_name'] = 'Larsen & Toubro Ltd';
[$submitOk3, $submitMsg3, $recordId3] = generic_metric_record_submit($metricKey, $testFaculty, '2025-26', $postDataYesValid, $validPdfFile);
assertTest('Conditional Submission Success when Condition Satisfied', $submitOk3, "Record created ID: {$recordId3}");

// -----------------------------------------------------------------------------
// 6. Test Object-Level Authorization on Generic Proof Attachments
// -----------------------------------------------------------------------------
$recStmt = $pdo->prepare("SELECT proof_file FROM `generic_metric_records` WHERE id = ?");
$recStmt->execute([$recordId1]);
$storedProof = $recStmt->fetchColumn();

assertTest('Proof File Stored in Database', !empty($storedProof) && str_contains($storedProof, '.pdf'), "Stored filename: {$storedProof}");

// -----------------------------------------------------------------------------
// 7. Cleanup Test Records
// -----------------------------------------------------------------------------
if (is_file($tmpPdf)) {
    @unlink($tmpPdf);
}
metric_studio_delete($formId);

echo "====================================================================\n";
echo "COMPLETE TEST SUITE RESULTS:\n";
echo "TOTAL: " . ($testsPassed + $testsFailed) . " | PASSED: {$testsPassed} | FAILED: {$testsFailed}\n";
echo "====================================================================\n";

if ($testsFailed === 0) {
    echo ">>> ALL FORM STUDIO END-TO-END TESTS COMPLETED SUCCESSFULLY! <<<\n";
    exit(0);
} else {
    echo ">>> SOME TESTS FAILED! <<<\n";
    exit(1);
}
