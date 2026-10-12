<?php
require_once __DIR__ . '/../php-app/inc/record_specs.php';
require_once __DIR__ . '/../php-app/models/Record.php';

$specs = record_report_specs();
$types = record_types();
$pdo = db();

echo "Auditing 56 record tables:\n";
foreach ($specs as $key => $spec) {
    $typeInfo = $types[$key] ?? null;
    if (!$typeInfo) {
        echo "MISSING TYPE INFO: $key\n";
        continue;
    }
    $table = $typeInfo['table'];
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
        $hasDept = in_array('department', $cols, true) ? 'Y' : 'N';
        $hasYear = in_array('academic_year', $cols, true) ? 'Y' : 'N';
        $hasStatus = in_array('status', $cols, true) ? 'Y' : 'N';
        $hasCreatedBy = in_array('created_by', $cols, true) ? 'Y' : 'N';
        $hasFacultyId = in_array('faculty_id', $cols, true) ? 'Y' : 'N';
        $hasRegNo = in_array('reg_no', $cols, true) ? 'Y' : 'N';
        
        $totalRows = (int) $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
        
        echo sprintf("%-28s | tbl:%-25s | dept:%s | yr:%s | stat:%s | cr_by:%s | rows:%d\n",
            $key, $table, $hasDept, $hasYear, $hasStatus, $hasCreatedBy, $totalRows);
    } catch (\Throwable $e) {
        echo "$key ($table) ERROR: " . $e->getMessage() . "\n";
    }
}
