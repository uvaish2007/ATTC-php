<?php
require_once __DIR__ . '/../php-app/inc/helpers.php';
require_once __DIR__ . '/../php-app/inc/auth.php';

$pdo = db();
$rows = $pdo->query("SELECT id, student_name, participation_type, achievement_type, position_award, award_medal FROM inst_inter_inst_within")->fetchAll(PDO::FETCH_ASSOC);
echo "Count: " . count($rows) . "\n";
print_r($rows);
