<?php
require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/models/Record.php';

$pdo = db();
$all = record_types();
$seenTables = [];
$counts = [];

foreach ($all as $k => $info) {
    $tbl = $info['table'];
    if (!isset($seenTables[$tbl])) {
        try {
            $cnt = (int) $pdo->query("SELECT COUNT(*) FROM `{$tbl}`")->fetchColumn();
            $seenTables[$tbl] = $cnt;
        } catch (\PDOException $e) {
            $seenTables[$tbl] = -1;
        }
    }
    $counts[$k] = $seenTables[$tbl];
    echo sprintf("%-35s [%-25s]: %d\n", $k, $tbl, $seenTables[$tbl]);
}

file_put_contents(__DIR__ . '/baseline_counts.json', json_encode($seenTables, JSON_PRETTY_PRINT));
echo "\nTotal unique tables checked: " . count($seenTables) . "\n";
