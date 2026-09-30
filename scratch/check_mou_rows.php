<?php
require_once __DIR__ . '/../php-app/inc/env.php';
require_once __DIR__ . '/../php-app/inc/db.php';
$pdo = db();
$rows = $pdo->query('SELECT * FROM mou')->fetchAll(PDO::FETCH_ASSOC);
echo "Total MOU rows: " . count($rows) . "\n";
foreach ($rows as $r) {
    echo "ID: {$r['id']} | Dept: {$r['department']} | Org: {$r['organization']} | Status: {$r['status']} | Exam/Academic Session: " . ($r['exam_session'] ?? 'N/A') . " | Proof: " . ($r['proof_file'] ?? 'N/A') . "\n";
}
echo "\nColumns in mou table:\n";
foreach ($pdo->query('DESCRIBE mou')->fetchAll(PDO::FETCH_ASSOC) as $c) {
    echo "  {$c['Field']} ({$c['Type']})\n";
}
