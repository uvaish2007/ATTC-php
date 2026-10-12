<?php
/**
 * Test Suite for SmartBulk Import History & Audit Log
 */

require_once __DIR__ . '/../php-app/inc/config.php';
require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/inc/helpers.php';
require_once __DIR__ . '/../php-app/models/BulkImport.php';

echo "=== ATTS-SMARTBULK IMPORT HISTORY TEST STARTING ===\n\n";

$passCount = 0;
function assertHistory($condition, $message) {
    global $passCount;
    if ($condition) {
        echo "[PASS] $message\n";
        $passCount++;
    } else {
        echo "[FAIL] $message\n";
        exit(1);
    }
}

// 1. Check countHistory
$totalInt = BulkImport::countHistory('internship', null);
$totalSum = BulkImport::countHistory('summer_training', null);
assertHistory($totalInt >= 1, "Count history for internship (all depts) is at least 1 (found $totalInt)");
assertHistory($totalSum >= 1, "Count history for summer_training (all depts) is at least 1 (found $totalSum)");

// 2. Check getHistory filtering
$cseHistory = BulkImport::getHistory('internship', 'CSE', 10);
assertHistory(count($cseHistory) >= 1, "getHistory returns records for CSE");
assertHistory($cseHistory[0]['department'] === 'CSE', "First CSE record has department == 'CSE'");
assertHistory(isset($cseHistory[0]['uploader_name']), "Record includes joined uploader_name");

// 3. Test Seed a batch for CSBS
$csbsBatchId = 'BULK_INT_CSBS_TEST_' . bin2hex(random_bytes(3));
$errorsSample = [
    [
        'row' => 4,
        'field' => 'Proof Certificate (ZIP)',
        'problem' => 'Compulsory certificate missing in ZIP archive: 711322104999.pdf',
        'value' => 'Missing'
    ]
];
BulkImport::logHistory([
    'batch_id' => $csbsBatchId,
    'module' => 'internship',
    'file_name' => 'csbs_internship_batch.xlsx',
    'department' => 'CSBS',
    'academic_year' => '2025-26',
    'uploaded_by' => 60028, // Master (Faculty)
    'total_rows' => 4,
    'valid_rows' => 3,
    'imported_records' => 3,
    'duplicate_records' => 0,
    'failed_rows' => 1,
    'status' => 'Completed',
    'errors_json' => json_encode($errorsSample),
]);

$csbsCount = BulkImport::countHistory('internship', 'CSBS');
assertHistory($csbsCount >= 1, "CSBS batch successfully recorded in bulk_import_history (found $csbsCount)");

$csbsHistory = BulkImport::getHistory('internship', 'CSBS', 10);
assertHistory(count($csbsHistory) >= 1, "getHistory returns CSBS batch");
assertHistory($csbsHistory[0]['batch_id'] === $csbsBatchId, "Batch ID matches seeded CSBS batch");
assertHistory($csbsHistory[0]['uploader_name'] === 'Master (Faculty)', "Uploader name matches Master (Faculty)");

// 4. Test Error Report Generation from history batch
$xlsxErrorReport = BulkImport::generateErrorReport($errorsSample, 'internship');
assertHistory(strlen($xlsxErrorReport) > 1000, "Error report spreadsheet generated from history errors_json (length: " . strlen($xlsxErrorReport) . " bytes)");

// 5. Test Scope 'all' returns both CSE and CSBS
$allHistory = BulkImport::getHistory('internship', null, 50);
$foundCSE = false;
$foundCSBS = false;
foreach ($allHistory as $row) {
    if ($row['department'] === 'CSE') $foundCSE = true;
    if ($row['department'] === 'CSBS') $foundCSBS = true;
}
assertHistory($foundCSE && $foundCSBS, "Scope 'all' successfully returns records across multiple departments");

echo "\n=== ALL $passCount IMPORT HISTORY ASSERTIONS PASSED! ===\n";
