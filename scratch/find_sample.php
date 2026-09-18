<?php
require_once __DIR__ . '/../php-app/inc/config.php';
require_once __DIR__ . '/../php-app/models/Record.php';

foreach (record_types() as $k => $t) {
    try {
        $stmt = db()->prepare("SELECT id, proof_file, department FROM `{$t['table']}` WHERE proof_file IS NOT NULL AND proof_file != '' LIMIT 1");
        $stmt->execute();
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($r) {
            echo "Type: $k, Table: {$t['table']}, ID: {$r['id']}, File: {$r['proof_file']}, Dept: {$r['department']}\n";
            break;
        }
    } catch (\Exception $e) {}
}
