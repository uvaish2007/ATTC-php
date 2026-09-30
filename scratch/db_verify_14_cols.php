<?php
require_once __DIR__ . '/../php-app/inc/env.php';
require_once __DIR__ . '/../php-app/inc/db.php';

$pdo = db();
$t = 'DB Controlled Verify ' . time();

// Insert via standard application model / DB
$stmt = $pdo->prepare('INSERT INTO fdp (faculty_name, department, academic_year, academic_session, event_type, title, mode, organized_by, from_date, to_date, duration, certificate_link, proof_file, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$stmt->execute(['Dr. Verify', 'CSE', '2026-27', 'Nov-Dec', 'Conference', $t, 'Online', 'Verification Body', '2026-10-01', '2026-10-07', '7 days', 'https://example.com/cert', 'proof_test.pdf', 'Submitted', 1]);

$row = $pdo->query('SELECT * FROM fdp WHERE title = ' . $pdo->quote($t))->fetch(PDO::FETCH_ASSOC);

$requiredCols = [
    'faculty_name' => 'Dr. Verify',
    'department' => 'CSE',
    'academic_year' => '2026-27',
    'academic_session' => 'Nov-Dec',
    'event_type' => 'Conference',
    'title' => $t,
    'mode' => 'Online',
    'organized_by' => 'Verification Body',
    'from_date' => '2026-10-01',
    'to_date' => '2026-10-07',
    'duration' => '7 days',
    'certificate_link' => 'https://example.com/cert',
    'proof_file' => 'proof_test.pdf',
    'status' => 'Submitted',
];

$allPassed = true;
foreach ($requiredCols as $col => $expected) {
    $actual = $row[$col] ?? null;
    $match = ($actual === $expected);
    if (!$match) $allPassed = false;
    echo sprintf("[%s] %-18s => Expected: %-25s | Actual: %s\n", $match ? "PASS" : "FAIL", $col, $expected, $actual);
}

// Clean up
$pdo->prepare('DELETE FROM fdp WHERE title = ?')->execute([$t]);

echo "\nDATABASE VERIFICATION: " . ($allPassed ? "PASS" : "FAIL") . "\n";
