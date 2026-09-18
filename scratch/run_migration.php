<?php
require_once __DIR__ . '/../php-app/inc/db.php';

try {
    $sql = file_get_contents(__DIR__ . '/../php-app/sql/workflow_edit_requests.sql');
    db()->exec($sql);
    echo "MIGRATION SUCCESS: Tables edit_requests and workflow_audit_logs created!\n";
} catch (PDOException $e) {
    echo "MIGRATION ERROR: " . $e->getMessage() . "\n";
}
