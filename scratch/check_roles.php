<?php
require_once __DIR__ . '/../php-app/inc/env.php';
require_once __DIR__ . '/../php-app/inc/db.php';

$pdo = db();
$roles = $pdo->query('SELECT DISTINCT job_title FROM placements WHERE job_title IS NOT NULL AND job_title != ""')->fetchAll(PDO::FETCH_COLUMN);
echo "Existing job_titles in DB:\n";
print_r($roles);
$scales = $pdo->query('SELECT DISTINCT pay_scale FROM placements')->fetchAll(PDO::FETCH_COLUMN);
echo "Existing pay_scales in DB:\n";
print_r($scales);
$sessions = $pdo->query('SELECT DISTINCT exam_session FROM placements')->fetchAll(PDO::FETCH_COLUMN);
echo "Existing exam_sessions in DB:\n";
print_r($sessions);
