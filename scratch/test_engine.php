<?php
require_once __DIR__ . '/../php-app/inc/record_specs.php';
require_once __DIR__ . '/../php-app/models/Record.php';
require_once __DIR__ . '/../php-app/models/Department.php';
require_once __DIR__ . '/../php-app/models/Target.php';
require_once __DIR__ . '/../php-app/inc/helpers.php';
require_once __DIR__ . '/../php-app/inc/db.php';

// Let's test the target and achievement resolution for CSE
$pdo = db();
$activeYear = '2025-26';

// Let's load all targets for CSE for 2025-26
$deptVars = department_variants('CSE');
$inPh = implode(',', array_fill(0, count($deptVars), '?'));
$stmt = $pdo->prepare("SELECT * FROM targets WHERE department IN ($inPh) AND academic_year = ?");
$stmt->execute(array_merge($deptVars, [$activeYear]));
$cseTargets = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($cseTargets) . " targets for CSE.\n";

// Let's test journal count for CSE
$jCount = (int) $pdo->query("SELECT count(*) FROM journal_publications WHERE department IN ('CSE', 'Computer Science and Engineering') AND academic_year='2025-26'")->fetchColumn();
echo "CSE Journal publications: $jCount\n";
