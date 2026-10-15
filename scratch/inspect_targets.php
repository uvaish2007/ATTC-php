<?php
require_once __DIR__ . '/../php-app/inc/config.php';
require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/models/Setting.php';
require_once __DIR__ . '/../php-app/models/Target.php';
require_once __DIR__ . '/../php-app/models/Record.php';
require_once __DIR__ . '/../php-app/models/Dashboard.php';

$activeYear = active_academic_year();
echo "Active Academic Year: $activeYear\n";

$targets = db()->query("SELECT * FROM targets WHERE academic_year = " . db()->quote($activeYear))->fetchAll(PDO::FETCH_ASSOC);
echo "Targets count for $activeYear: " . count($targets) . "\n";

$allYears = db()->query("SELECT academic_year, count(*) as cnt FROM targets GROUP BY academic_year")->fetchAll(PDO::FETCH_ASSOC);
echo "All years in targets:\n";
print_r($allYears);

$targetsSample = array_slice($targets, 0, 5);
echo "Sample targets in $activeYear:\n";
print_r($targetsSample);
