<?php
require_once __DIR__ . '/../php-app/inc/auth.php';
require_once __DIR__ . '/../php-app/models/Record.php';

$pdo = db();
$admin = $pdo->query("SELECT * FROM users WHERE role = 'Admin' LIMIT 1")->fetch();
$opts = publication_main_author_options($admin);
echo "Count: " . count($opts) . "\n";
foreach (array_slice($opts, 0, 5) as $o) {
    echo "ID: {$o['id']} | Name: {$o['name']} | Dept: '{$o['department']}'\n";
}
