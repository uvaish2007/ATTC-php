<?php
require_once __DIR__ . '/../php-app/inc/config.php';
require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/inc/upload_sections.php';
require_once __DIR__ . '/../php-app/models/Record.php';

$sections = upload_approved_sections();
$recordTypes = record_types();

echo "Checking records for active academic year: 2026-27\n";

foreach ($sections as $sId => $sec) {
    $totalApproved = 0;
    $typesWithCounts = [];
    foreach ($sec['types'] as $tKey) {
        if (!isset($recordTypes[$tKey])) continue;
        $table = $recordTypes[$tKey]['table'];
        try {
            // Check if table exists and has academic_year
            $cols = db()->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
            $hasYear = in_array('academic_year', $cols, true);
            $hasStatus = in_array('status', $cols, true);
            
            $sql = "SELECT COUNT(*) FROM `$table` WHERE 1=1";
            $args = [];
            if ($hasStatus) {
                $sql .= " AND status = 'Approved'";
            }
            if ($hasYear) {
                $sql .= " AND academic_year = '2026-27'";
            }
            $st = db()->prepare($sql);
            $st->execute($args);
            $cnt = (int) $st->fetchColumn();
            // Also total across all years
            $totSql = "SELECT COUNT(*) FROM `$table`" . ($hasStatus ? " WHERE status='Approved'" : "");
            $totCnt = (int) db()->query($totSql)->fetchColumn();
            
            if ($cnt > 0 || $totCnt > 0) {
                $typesWithCounts[] = "$tKey(2026-27: $cnt, all: $totCnt)";
            }
            $totalApproved += $cnt;
        } catch (\PDOException $e) {
            // Table might not exist
        }
    }
    echo "Section {$sec['order']}: {$sec['title']} [{$sec['id']}] -> approved 2026-27: $totalApproved | " . implode(', ', $typesWithCounts) . "\n";
}
