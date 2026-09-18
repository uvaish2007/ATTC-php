<?php
require_once __DIR__ . '/../php-app/inc/auth.php';

$func = new ReflectionFunction('active_academic_year');
echo "active_academic_year defined at: " . $func->getFileName() . ":" . $func->getStartLine() . "\n";

$ays = db()->query("SELECT * FROM academic_years")->fetchAll(PDO::FETCH_ASSOC);
print_r($ays);
