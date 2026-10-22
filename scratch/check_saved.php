<?php
require_once __DIR__ . '/../php-app/inc/auth.php';
$pdo = db();
$row = $pdo->query("SELECT * FROM conference_publications WHERE paper_title = 'DEEP LEARNING IN ATTS OPTIMIZATION 2060' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
print_r($row);
