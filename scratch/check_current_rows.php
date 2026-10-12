<?php
require_once __DIR__ . '/../php-app/inc/env.php';
require_once __DIR__ . '/../php-app/inc/db.php';
$pdo = db();
$pdo->exec("UPDATE placements SET exam_session = '2026-27' WHERE exam_session IS NULL OR exam_session = ''");
$rows = $pdo->query('SELECT id, reg_no, student_name, job_title, department, academic_year, exam_session, academic_session, pay_scale FROM placements')->fetchAll(PDO::FETCH_ASSOC);
print_r($rows);
