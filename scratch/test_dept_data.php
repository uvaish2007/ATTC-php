<?php
require_once __DIR__ . '/../php-app/inc/config.php';
require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/inc/upload_sections.php';
require_once __DIR__ . '/../php-app/models/Record.php';
require_once __DIR__ . '/../php-app/models/Target.php';
require_once __DIR__ . '/../php-app/models/Department.php';

function test_dept_data(string $dept, string $year) {
    echo "====================================\n";
    echo "DEPARTMENT: $dept | YEAR: $year\n";
    echo "====================================\n";
    
    // 1. Targets in targets table
    $targets = targets_all($dept, $year);
    echo "Configured targets in targets table: " . count($targets) . "\n";
    foreach ($targets as $t) {
        echo " - [{$t['id']}] {$t['metric']} | Target: {$t['target_value']} | Achieved: {$t['achieved_value']} | Status: {$t['status']} | Deadline: {$t['target_deadline']}\n";
    }
    
    // 2. Approved records across tables for this department & year
    $recTypes = record_types();
    $totalApproved = 0;
    $facultyContrib = [];
    
    foreach ($recTypes as $tKey => $tInfo) {
        $table = $tInfo['table'];
        try {
            $cols = target_record_table_columns($table);
            if (!in_array('status', $cols, true) || !in_array('department', $cols, true)) continue;
            
            $sql = "SELECT r.*, u.name as creator_name FROM `{$table}` r LEFT JOIN users u ON r.created_by = u.id WHERE r.department = ? AND r.status = 'Approved'";
            $args = [$dept];
            if (in_array('academic_year', $cols, true)) {
                $sql .= " AND r.academic_year = ?";
                $args[] = $year;
            }
            $stmt = db()->prepare($sql);
            $stmt->execute($args);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($rows as $r) {
                $totalApproved++;
                $facName = $r['faculty_name'] ?? ($r['creator_name'] ?? 'Faculty');
                if (!isset($facultyContrib[$facName])) {
                    $facultyContrib[$facName] = [
                        'count' => 0,
                        'types' => []
                    ];
                }
                $facultyContrib[$facName]['count']++;
                $facultyContrib[$facName]['types'][$tInfo['label']] = ($facultyContrib[$facName]['types'][$tInfo['label']] ?? 0) + 1;
            }
        } catch (\PDOException $e) {}
    }
    
    echo "Total approved records for $dept: $totalApproved\n";
    echo "Faculty contributions:\n";
    foreach ($facultyContrib as $fac => $info) {
        echo " - $fac: {$info['count']} achievements (" . json_encode($info['types']) . ")\n";
    }
}

test_dept_data('CSBS', '2026-27');
test_dept_data('ECE', '2026-27');
test_dept_data('CSE', '2026-27');
