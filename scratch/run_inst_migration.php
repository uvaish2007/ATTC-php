<?php
/**
 * Run the institutional_achievements.sql migration.
 * Use multi-query approach for TiDB compatibility.
 */
require_once __DIR__ . '/../php-app/inc/db.php';

$sqlFile = file_get_contents(__DIR__ . '/../php-app/sql/institutional_achievements.sql');
$pdo = db();

// Parse statements properly: handle multi-line statements, skip comments
$lines = explode("\n", $sqlFile);
$currentStmt = '';
$ok = 0; $err = 0;

foreach ($lines as $line) {
    $trimmed = trim($line);
    // Skip pure comment lines and empty lines
    if ($trimmed === '' || str_starts_with($trimmed, '--')) {
        continue;
    }
    $currentStmt .= ' ' . $trimmed;
    // If this line ends with a semicolon, execute
    if (str_ends_with($trimmed, ';')) {
        $stmt = trim(rtrim(trim($currentStmt), ';'));
        $currentStmt = '';
        if ($stmt === '' || str_starts_with($stmt, 'SET')) {
            $ok++;
            continue;
        }
        try {
            $pdo->exec($stmt);
            if (str_contains($stmt, 'CREATE TABLE')) {
                preg_match('/CREATE TABLE IF NOT EXISTS (\w+)/', $stmt, $m);
                echo "[OK] Created " . ($m[1] ?? '?') . "\n";
            }
            $ok++;
        } catch (\PDOException $e) {
            echo "[ERR] " . $e->getMessage() . "\n";
            echo "  SQL: " . substr($stmt, 0, 200) . "\n\n";
            $err++;
        }
    }
}

echo "\nDone: $ok OK, $err errors\n";

// Verify
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$inst = array_filter($tables, fn($t) => str_starts_with($t, 'inst_'));
echo "inst_ tables now: " . count($inst) . "\n";
foreach (array_values($inst) as $t) { echo "  $t\n"; }
