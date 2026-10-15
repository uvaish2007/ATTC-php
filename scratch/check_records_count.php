<?php
require_once __DIR__ . '/../php-app/inc/config.php';
require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/models/Target.php';
require_once __DIR__ . '/../php-app/models/Record.php';

$types = record_types();
$activeYear = active_academic_year();
echo "Active year: $activeYear\n";

foreach ($types as $key => $info) {
    $table = $info['table'];
    try {
        $cTotal = (int) db()->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
        $cols = db()->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
        $hasYear = in_array('academic_year', $cols, true);
        $hasStatus = in_array('status', $cols, true);
        $cYear = 0;
        $cAppr = 0;
        if ($hasYear) {
            $stmt = db()->prepare("SELECT COUNT(*) FROM `$table` WHERE academic_year = ?");
            $stmt->execute([$activeYear]);
            $cYear = (int) $stmt->fetchColumn();
            if ($hasStatus) {
                $stmt = db()->prepare("SELECT COUNT(*) FROM `$table` WHERE academic_year = ? AND status = 'Approved'");
                $stmt->execute([$activeYear]);
                $cAppr = (int) $stmt->fetchColumn();
            }
        }
        if ($cTotal > 0) {
            echo sprintf("%-25s | Total: %4d | In %s: %3d | Approved in %s: %3d\n", $key, $cTotal, $activeYear, $cYear, $activeYear, $cAppr);
        }
    } catch (\Exception $e) {
        // table error
    }
}
