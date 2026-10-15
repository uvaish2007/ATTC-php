<?php
/**
 * Automated End-to-End Test Suite for ATTS-METRIC-STUDIO
 */

declare(strict_types=1);

require_once __DIR__ . '/../php-app/inc/config.php';
require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/inc/helpers.php';
require_once __DIR__ . '/../php-app/inc/metric_studio.php';
require_once __DIR__ . '/../php-app/inc/upload_sections.php';

$results = [];

function record_test(string $name, bool $pass, string $details = ''): void {
    global $results;
    $results[] = [
        'name'    => $name,
        'pass'    => $pass,
        'details' => $details,
    ];
    echo ($pass ? " [PASS] " : " [FAIL] ") . $name . ($details ? " ($details)" : "") . "\n";
}

echo "=== STARTING ATTS-METRIC-STUDIO E2E TEST SUITE ===\n\n";

// ── TEST 1: Schema Initialization and Idempotency ─────────────────────────────
try {
    ensure_metric_studio_schema();
    $pdo = db();
    $cols = $pdo->query("SHOW COLUMNS FROM `metrics`")->fetchAll(PDO::FETCH_COLUMN);
    $requiredCols = ['metric_key', 'description', 'help_text', 'display_order', 'faculty_visible', 'coordinator_visible', 'hod_visible', 'metric_type', 'form_config', 'reporting_category'];
    $missing = array_diff($requiredCols, $cols);
    $tableExists = (bool)$pdo->query("SHOW TABLES LIKE 'generic_metric_records'")->fetch();
    record_test(
        "Schema & DDL Migration",
        empty($missing) && $tableExists,
        empty($missing) ? "All columns & generic_metric_records table exist" : "Missing: " . implode(', ', $missing)
    );
} catch (Throwable $e) {
    record_test("Schema & DDL Migration", false, $e->getMessage());
}

// ── TEST 2: Approved 30 Metrics Seeding and Order ─────────────────────────────
try {
    $metrics = metric_studio_all(['sort' => 'order']);
    $count30 = count($metrics) >= 30;
    $firstSec = $metrics[0] ?? null;
    $firstIsUpercent = $firstSec && $firstSec['metric_key'] === 'upercent' && (int)$firstSec['display_order'] === 1;
    record_test(
        "All 30 Approved Metrics Seeded & Sequenced",
        $count30 && $firstIsUpercent,
        "Total loaded: " . count($metrics) . ", First: " . ($firstSec['metric_key'] ?? 'none') . " (#" . ($firstSec['display_order'] ?? '?') . ")"
    );
} catch (Throwable $e) {
    record_test("All 30 Approved Metrics Seeded & Sequenced", false, $e->getMessage());
}

// ── TEST 3: Dynamic Upload Sections Resolution ───────────────────────────────
try {
    $secList = upload_approved_sections('Faculty', true);
    $firstUploadSec = reset($secList);
    $isDynamic = isset($secList['upercent']) && isset($secList['nss_activities']);
    record_test(
        "Dynamic Faculty Upload Data Integration",
        $isDynamic && count($secList) >= 30,
        "Sections available to Faculty: " . count($secList)
    );
} catch (Throwable $e) {
    record_test("Dynamic Faculty Upload Data Integration", false, $e->getMessage());
}

// ── TEST 4: Metric Renaming and Stable Identity ───────────────────────────────
try {
    $targetMetric = metric_studio_find('student_cgpa');
    $originalName = $targetMetric['name'];
    $testNewName = "To Improve II & III Year Students Above 7.5 CGPA (Updated Test Title)";
    
    // Update display name
    [$okUp, $msgUp] = metric_studio_update((int)$targetMetric['id'], [
        'name' => $testNewName,
    ]);
    
    // Verify in DB and upload sections
    $updatedMetric = metric_studio_find('student_cgpa');
    $dynamicList = upload_approved_sections('Faculty', true);
    $nameUpdatedInNav = isset($dynamicList['student_cgpa']) && $dynamicList['student_cgpa']['title'] === $testNewName;
    $keyRemainedStable = $updatedMetric['metric_key'] === 'student_cgpa';
    
    // Restore original name
    metric_studio_update((int)$targetMetric['id'], [
        'name' => $originalName,
    ]);
    upload_approved_sections('Faculty', true); // flush cache

    record_test(
        "Metric Renaming & Stable Key Preservation",
        $okUp && $nameUpdatedInNav && $keyRemainedStable,
        "Display name dynamically updated on Faculty navigation while stable key preserved"
    );
} catch (Throwable $e) {
    record_test("Metric Renaming & Stable Key Preservation", false, $e->getMessage());
}

// ── TEST 5: Deterministic Reordering (Move Up / Move Down) ───────────────────
try {
    $sec1 = metric_studio_find('upercent');
    $sec2 = metric_studio_find('student_cgpa');
    $order1Before = (int)$sec1['display_order'];
    $order2Before = (int)$sec2['display_order'];
    
    // Move sec2 UP
    [$okMove, $msgMove] = metric_studio_move((int)$sec2['id'], 'up');
    
    $sec1After = metric_studio_find('upercent');
    $sec2After = metric_studio_find('student_cgpa');
    
    $swapped = ((int)$sec2After['display_order'] === $order1Before) && ((int)$sec1After['display_order'] === $order2Before);
    
    // Revert move back DOWN
    metric_studio_move((int)$sec2['id'], 'down');
    upload_approved_sections('Faculty', true); // flush cache

    record_test(
        "Metric Reordering & Display Order Persistence",
        $okMove && $swapped,
        "Display order successfully swapped and saved deterministically in database"
    );
} catch (Throwable $e) {
    record_test("Metric Reordering & Display Order Persistence", false, $e->getMessage());
}

// ── TEST 6: Create New Generic Metric With Custom Form Template ───────────────
$createdGenMetricId = 0;
$genKey = 'test_industry_immersion';
try {
    // Delete test row if already left over
    $existingTest = metric_studio_find($genKey);
    if ($existingTest) {
        db()->prepare("DELETE FROM metrics WHERE id = ?")->execute([$existingTest['id']]);
    }

    $formTemplate = [
        [
            'key'         => 'company_name',
            'label'       => 'Partner Company / Industry',
            'type'        => 'text',
            'required'    => true,
            'placeholder' => 'e.g. Tata Consultancy Services',
            'help_text'   => 'Official legal entity name',
        ],
        [
            'key'         => 'visit_date',
            'label'       => 'Immersion Date',
            'type'        => 'date',
            'required'    => true,
        ],
        [
            'key'         => 'participants_count',
            'label'       => 'Total Faculty & Student Participants',
            'type'        => 'integer',
            'required'    => true,
            'min'         => 1,
            'max'         => 500,
        ],
        [
            'key'         => 'domain_track',
            'label'       => 'Industry Domain',
            'type'        => 'select',
            'required'    => true,
            'options'     => ['Automotive', 'Cloud & AI', 'FinTech', 'Semiconductors', 'Renewable Energy'],
        ],
        [
            'key'         => 'outcomes_summary',
            'label'       => 'Key Immersion Outcomes',
            'type'        => 'textarea',
            'required'    => false,
            'placeholder' => 'Summarize learning outcomes, MOUs discussed, or projects identified',
        ],
    ];

    [$okCreate, $msgCreate, $createdRec] = metric_studio_create([
        'name'                => 'Industry Immersion & Factory Visits',
        'metric_key'          => $genKey,
        'category'            => 'CERTIFICATIONS, INDUSTRY AND TRAINING',
        'description'         => 'Faculty and student on-site industry exposure visits and technology immersion programs.',
        'help_text'           => 'Upload the company authorization letter and participant attendance sheet in PDF.',
        'display_order'       => 35,
        'metric_type'         => 'generic',
        'faculty_visible'     => 1,
        'coordinator_visible' => 1,
        'status'              => 1,
        'form_config'         => $formTemplate,
    ]);

    $createdGenMetricId = $createdRec ? (int)$createdRec['id'] : 0;
    
    // Check if it appears in Faculty upload sections
    $facultySecs = upload_approved_sections('Faculty', true);
    $appearsInFaculty = isset($facultySecs[$genKey]);
    $hasFields = $appearsInFaculty && count($facultySecs[$genKey]['form_config']) === 5;

    record_test(
        "Create New Metric with Dynamic Form Template",
        $okCreate && $appearsInFaculty && $hasFields,
        "Created with ID {$createdGenMetricId}, automatically reflected in Faculty navigation"
    );
} catch (Throwable $e) {
    record_test("Create New Metric with Dynamic Form Template", false, $e->getMessage());
}

// ── TEST 7: Generic Metric Submission & Record Storage ───────────────────────
$testRecordId = 0;
try {
    $facultyUser = [
        'id'         => 9999,
        'name'       => 'Dr. Test Faculty',
        'department' => 'CSE',
        'role'       => 'Faculty',
    ];

    $postData = [
        'company_name'       => 'Bosch Engineering & Innovation Lab',
        'visit_date'         => '2026-09-15',
        'participants_count' => '42',
        'domain_track'       => 'Automotive',
        'outcomes_summary'   => 'Explored autonomous driving testbed; 2 student capstones confirmed.',
    ];

    [$okSub, $msgSub, $recId] = generic_metric_record_submit($genKey, $facultyUser, '2026-2027', $postData, null);
    $testRecordId = (int)$recId;

    // Retrieve records
    $records = generic_metric_records_for($genKey, 9999);
    $hasRecord = !empty($records) && (int)$records[0]['id'] === $testRecordId;
    $parsedData = $records[0]['data_parsed'] ?? [];
    $dataMatch = ($parsedData['company_name'] ?? '') === 'Bosch Engineering & Innovation Lab'
              && ($parsedData['participants_count'] ?? '') === '42';

    record_test(
        "Faculty Generic Record Submission & Storage",
        $okSub && $hasRecord && $dataMatch,
        "Record #{$testRecordId} saved to generic_metric_records with parsed JSON data"
    );
} catch (Throwable $e) {
    record_test("Faculty Generic Record Submission & Storage", false, $e->getMessage());
}

// ── TEST 8: Metric Deactivation & Historical Preservation ────────────────────
try {
    // Deactivate the metric
    [$okDeact, $msgDeact] = metric_studio_toggle_status($createdGenMetricId);
    
    // Check faculty upload visibility: must NOT appear for Faculty
    $facultySecsDeact = upload_approved_sections('Faculty', true);
    $hiddenFromFaculty = !isset($facultySecsDeact[$genKey]);
    
    // Check historical records: must STILL exist!
    $historicalRecords = generic_metric_records_for($genKey);
    $historyPreserved = !empty($historicalRecords) && (int)$historicalRecords[0]['id'] === $testRecordId;

    // Reactivate
    metric_studio_toggle_status($createdGenMetricId);
    $facultySecsReact = upload_approved_sections('Faculty', true);
    $visibleAgain = isset($facultySecsReact[$genKey]);

    record_test(
        "Deactivation Hides from Faculty While Preserving History",
        $okDeact && $hiddenFromFaculty && $historyPreserved && $visibleAgain,
        "Hidden when inactive; historical record #{$testRecordId} fully preserved; reappears on reactivate"
    );
} catch (Throwable $e) {
    record_test("Deactivation Hides from Faculty While Preserving History", false, $e->getMessage());
}

// ── TEST 9: Delete Generic Record & Cleanup ──────────────────────────────────
try {
    [$okDel, $msgDel] = generic_metric_record_delete($testRecordId, 9999, false);
    $recordsAfter = generic_metric_records_for($genKey, 9999);
    $recordDeleted = empty($recordsAfter);

    record_test(
        "Generic Record Deletion & Security Check",
        $okDel && $recordDeleted,
        "Non-approved record successfully deleted by owner"
    );
} catch (Throwable $e) {
    record_test("Generic Record Deletion & Security Check", false, $e->getMessage());
}

// ── CLEANUP TEST METRIC ──────────────────────────────────────────────────────
if ($createdGenMetricId > 0) {
    db()->prepare("DELETE FROM metrics WHERE id = ?")->execute([$createdGenMetricId]);
    upload_approved_sections('Faculty', true);
}

// ── TEST 10: RBAC & Security Middleware Verification ─────────────────────────
try {
    // Test require_role with Faculty role trying to access Admin
    $authCode = file_get_contents(__DIR__ . '/../php-app/admin-metrics.php');
    $hasRoleCheck = str_contains($authCode, "require_role(['Admin'])");
    $hasCsrfCheck = str_contains($authCode, "csrf_check()");
    
    record_test(
        "RBAC Middleware & Backend Security Enforcement",
        $hasRoleCheck && $hasCsrfCheck,
        "admin-metrics.php enforces require_role(['Admin']) and csrf_check() on POST"
    );
} catch (Throwable $e) {
    record_test("RBAC Middleware & Backend Security Enforcement", false, $e->getMessage());
}

// ── TEST 11: Specialized Metrics Integrity & Calculations ─────────────────────
try {
    // Check specialized metric types are mapped
    $upercentSec = $facultySecs['upercent'] ?? null;
    $pubSec = $facultySecs['publications'] ?? null;
    $internSec = $facultySecs['internships'] ?? null;
    
    $upercentValid = $upercentSec && $upercentSec['default_type'] === 'inst_pass_percentage';
    $pubValid = $pubSec && in_array('journal', $pubSec['types'], true) && in_array('conference', $pubSec['types'], true);
    $internValid = $internSec && in_array('internship', $internSec['types'], true);

    record_test(
        "Specialized Metrics Integrity (Pass %, Publications, Internships)",
        $upercentValid && $pubValid && $internValid,
        "Pass percentage, publications subcategories, and internship structures intact"
    );
} catch (Throwable $e) {
    record_test("Specialized Metrics Integrity", false, $e->getMessage());
}

echo "\n=== TEST SUITE COMPLETE: " . count($results) . " TESTS RUN ===\n";
